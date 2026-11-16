<?php

namespace App\Actions;

use App\Models\CatalogSource;
use App\Models\Part;
use App\Models\PartLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

class ImportParts
{
    /** @var array<string, Part> */
    private array $partsByItemCode = [];

    /** @var array<string, Part> */
    private array $partsByPartNumber = [];

    /** @var array<string, Part> */
    private array $partsByDescription = [];

    private int $nextSortOrder = 1;

    public function fromSpreadsheets(string $storePath, ?string $criticalPath = null): int
    {
        $jsonPath = database_path('data/parts.json');
        $command = $this->pythonCommand($jsonPath);

        if (is_file($storePath)) {
            $command[] = $storePath;
        }

        if ($criticalPath && is_file($criticalPath)) {
            $command[] = $criticalPath;
        }

        $this->runCatalogScript($command);

        return $this->fromJson($jsonPath);
    }

    /**
     * Update parts that appear in the uploaded workbooks and leave every other part in place.
     *
     * @param  list<string>  $paths
     */
    public function mergeUploaded(array $paths): int
    {
        $jsonPath = storage_path('app/imports/'.uniqid('parts-', true).'.json');
        File::ensureDirectoryExists(dirname($jsonPath));
        $command = $this->pythonCommand($jsonPath);
        $command[] = '--no-defaults';
        $command[] = '--split';

        foreach ($paths as $path) {
            if (is_file($path)) {
                $command[] = $path;
            }
        }

        try {
            $this->runCatalogScript($command);

            return $this->mergeFromSplit($jsonPath);
        } finally {
            File::delete($jsonPath);
        }
    }

    public function fromJson(string $path): int
    {
        $payload = json_decode((string) file_get_contents($path), true);

        if (! is_array($payload) || ! isset($payload['parts']) || ! is_array($payload['parts'])) {
            throw new RuntimeException('The catalog file is not in the expected format.');
        }

        $partRows = [];
        $locationRows = [];

        foreach (array_values($payload['parts']) as $index => $part) {
            $partRows[] = [
                'item_code' => $this->nullableString($part['id'] ?? null),
                'part_number' => $this->nullableString($part['pn'] ?? null),
                'description' => (string) ($part['d'] ?? ''),
                'uom' => $this->nullableString($part['u'] ?? null),
                'store_type' => $this->nullableString($part['t'] ?? null),
                'categories' => json_encode($part['cats'] ?? [], JSON_UNESCAPED_UNICODE),
                'priority' => $this->nullableString($part['pr'] ?? null),
                'below_minimum' => ! empty($part['w']),
                'min_qty' => $this->nullableString($part['mn'] ?? null),
                'crit_stock' => $this->nullableString($part['cs'] ?? null),
                'reused' => $this->nullableString($part['ru'] ?? null),
                'single_eq' => $this->nullableString($part['se'] ?? null),
                'plan_90' => $this->nullableString($part['p90'] ?? null),
                'plan_6' => $this->nullableString($part['p6'] ?? null),
                'backup' => $this->nullableString($part['bk'] ?? null),
                'used_in' => $this->nullableString($part['us'] ?? null),
                'notes' => $this->nullableString($part['no'] ?? null),
                'alternative_note' => $this->nullableString($part['an'] ?? null),
                'alternatives' => isset($part['alt']) ? json_encode($part['alt'], JSON_UNESCAPED_UNICODE) : null,
                'is_critical' => ! empty($part['cr']),
                'sort_order' => $index,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            foreach (array_values($part['L'] ?? []) as $position => $location) {
                $locationRows[] = [
                    'sort_order' => $index,
                    'position' => $position,
                    'location' => (string) ($location[0] ?? ''),
                    'quantity' => is_numeric($location[1] ?? null) ? $location[1] : 0,
                    'quantity_raw' => $this->nullableString($location[2] ?? null),
                    'updated_on' => $this->nullableString($location[3] ?? null),
                    'condition' => $this->nullableString($location[4] ?? null),
                    'status' => $this->nullableString($location[5] ?? null),
                    'reference' => $this->nullableString($location[6] ?? null),
                ];
            }
        }

        DB::transaction(function () use ($partRows, $locationRows, $payload): void {
            PartLocation::query()->delete();
            Part::query()->delete();
            CatalogSource::query()->delete();

            foreach (array_chunk($partRows, 200) as $chunk) {
                Part::query()->insert($chunk);
            }

            $ids = Part::query()->pluck('id', 'sort_order');
            $rows = [];

            foreach ($locationRows as $location) {
                $partId = $ids[$location['sort_order']] ?? null;
                if (! $partId) {
                    continue;
                }
                unset($location['sort_order']);
                $location['part_id'] = $partId;
                $rows[] = $location;
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                PartLocation::query()->insert($chunk);
            }

            $source = $payload['source'] ?? [];
            CatalogSource::query()->create([
                'store_name' => $source['store'] ?? 'Store',
                'critical_name' => $source['critical'] ?? null,
                'imported_at' => now(),
            ]);
        });

        return count($partRows);
    }

    /**
     * Apply a split store/critical payload without deleting parts that are absent from it.
     */
    public function mergeFromSplit(string $path): int
    {
        $payload = json_decode((string) file_get_contents($path), true);

        if (! is_array($payload) || ! is_array($payload['store'] ?? null) || ! is_array($payload['critical'] ?? null)) {
            throw new RuntimeException('The catalog file is not in the expected format.');
        }

        /** @var array<string, mixed> $payload */
        return DB::transaction(function () use ($payload): int {
            $this->resetIndexes();
            Part::query()->with('locations')->orderBy('id')->get()->each(function (Part $part): void {
                $this->rememberPart($part);
            });

            $touched = [];

            foreach ($payload['store'] as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $part = $this->applyStoreItem($item);
                if ($part instanceof Part) {
                    $touched[$part->id] = true;
                }
            }

            foreach ($payload['critical'] as $key => $record) {
                if (! is_array($record)) {
                    continue;
                }

                $part = $this->applyCriticalItem((string) $key, $record);
                if ($part instanceof Part) {
                    $touched[$part->id] = true;
                }
            }

            $source = $payload['source'] ?? [];
            $this->rememberSource(is_array($source) ? $source : []);

            return count($touched);
        });
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function applyStoreItem(array $item): ?Part
    {
        $itemCode = $this->text($item['id'] ?? null);
        $partNumber = $this->text($item['pn'] ?? null);
        $description = $this->text($item['d'] ?? null);

        if ($description === '') {
            return null;
        }

        $part = $this->matchStorePart($itemCode, $partNumber, $description) ?? new Part;
        $creating = ! $part->exists;

        if ($part->exists) {
            Part::query()->whereKey($part->id)->lockForUpdate()->first();
        }

        if ($creating) {
            $part->categories = [];
            $part->is_critical = false;
            $part->below_minimum = false;
            $part->sort_order = $this->nextSortOrder;
            $this->nextSortOrder++;
        }

        $part->fill([
            'item_code' => $itemCode !== '' ? $itemCode : null,
            'part_number' => $partNumber !== '' ? $partNumber : null,
            'description' => $description,
            'uom' => $this->nullableString($item['u'] ?? null),
            'store_type' => $this->nullableString($item['t'] ?? null),
        ])->save();

        $this->mergeLocations($part, is_array($item['L'] ?? null) ? $item['L'] : []);
        $this->rememberPart($part);

        return $part;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function applyCriticalItem(string $key, array $record): ?Part
    {
        $partNumber = str_starts_with($key, 'd:') ? '' : $key;
        $description = $this->text($record['d'] ?? null);
        $part = $this->matchCriticalPart($partNumber, $description);

        if (! $part instanceof Part) {
            if ($description === '' && $partNumber === '') {
                return null;
            }

            $part = new Part([
                'item_code' => null,
                'part_number' => $partNumber !== '' ? $partNumber : null,
                'description' => $description !== '' ? $description : $partNumber,
                'uom' => 'EA',
                'categories' => [],
                'is_critical' => true,
                'below_minimum' => false,
                'sort_order' => $this->nextSortOrder,
            ]);
            $this->nextSortOrder++;
        }

        $this->fillCritical($part, $record);
        $part->save();
        $this->rememberPart($part);

        return $part;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function fillCritical(Part $part, array $record): void
    {
        $part->is_critical = true;

        if (isset($record['cats']) && is_array($record['cats'])) {
            $part->categories = array_values($record['cats']);
        }

        foreach ([
            'pr' => 'priority',
            'mn' => 'min_qty',
            'cs' => 'crit_stock',
            'ru' => 'reused',
            'se' => 'single_eq',
            'p90' => 'plan_90',
            'p6' => 'plan_6',
            'bk' => 'backup',
            'us' => 'used_in',
            'no' => 'notes',
            'an' => 'alternative_note',
        ] as $key => $column) {
            if (array_key_exists($key, $record)) {
                $part->{$column} = $this->nullableString($record[$key]);
            }
        }

        if (array_key_exists('alt', $record)) {
            $part->alternatives = is_array($record['alt']) ? array_values($record['alt']) : null;
        }

        if (array_key_exists('w', $record)) {
            $part->below_minimum = ! empty($record['w']);
        }
    }

    /**
     * @param  array<int, mixed>  $locations
     */
    private function mergeLocations(Part $part, array $locations): void
    {
        $existing = $part->locations()->orderBy('position')->get();
        $used = [];
        $highestPosition = $existing->max('position');
        $nextPosition = $highestPosition === null ? 0 : ((int) $highestPosition) + 1;

        foreach (array_values($locations) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = $this->text($row[0] ?? null);
            $quantity = is_numeric($row[1] ?? null) ? round((float) $row[1], 2) : 0.0;
            $match = $existing->first(function (PartLocation $location) use ($name, $used): bool {
                return ! in_array($location->id, $used, true) && trim($location->location) === $name;
            });

            if ($match instanceof PartLocation) {
                $used[] = $match->id;
                $before = round((float) $match->quantity, 2);
                $changed = $this->quantityChanged($before, $quantity);
                $fileDate = $this->nullableString($row[3] ?? null);

                $match->fill([
                    'location' => $name,
                    'quantity' => $quantity,
                    'quantity_raw' => $changed ? null : $match->quantity_raw,
                    'updated_on' => $changed ? now()->toDateString() : ($fileDate ?? $match->updated_on),
                    'condition' => $this->nullableString($row[4] ?? null),
                    'status' => $this->nullableString($row[5] ?? null),
                    'reference' => $this->nullableString($row[6] ?? null),
                ])->save();

                if ($changed) {
                    $this->recordAdjustment($part, $match, 'set', $before, $quantity);
                }

                continue;
            }

            $created = $part->locations()->create([
                'position' => $nextPosition,
                'location' => $name,
                'quantity' => $quantity,
                'quantity_raw' => null,
                'updated_on' => $this->quantityChanged(0, $quantity) ? now()->toDateString() : $this->nullableString($row[3] ?? null),
                'condition' => $this->nullableString($row[4] ?? null),
                'status' => $this->nullableString($row[5] ?? null),
                'reference' => $this->nullableString($row[6] ?? null),
            ]);
            $nextPosition++;

            if ($this->quantityChanged(0, $quantity)) {
                $this->recordAdjustment($part, $created, 'open', 0, $quantity);
            }
        }
    }

    private function matchStorePart(string $itemCode, string $partNumber, string $description): ?Part
    {
        if ($itemCode !== '' && isset($this->partsByItemCode[$itemCode])) {
            return $this->partsByItemCode[$itemCode];
        }

        if ($partNumber !== '' && isset($this->partsByPartNumber[$partNumber])) {
            return $this->partsByPartNumber[$partNumber];
        }

        if ($itemCode === '' && $partNumber === '' && $description !== '' && isset($this->partsByDescription[$description])) {
            return $this->partsByDescription[$description];
        }

        return null;
    }

    private function matchCriticalPart(string $partNumber, string $description): ?Part
    {
        if ($partNumber !== '') {
            return $this->partsByPartNumber[$partNumber] ?? null;
        }

        if ($description === '') {
            return null;
        }

        return $this->partsByDescription[$description] ?? null;
    }

    private function rememberPart(Part $part): void
    {
        $itemCode = $this->text($part->item_code);
        $partNumber = $this->text($part->part_number);
        $description = $this->text($part->description);

        if ($itemCode !== '') {
            $this->partsByItemCode[$itemCode] ??= $part;
        }

        if ($partNumber !== '') {
            $this->partsByPartNumber[$partNumber] ??= $part;
        }

        if ($itemCode === '' && $partNumber === '' && $description !== '') {
            $this->partsByDescription[$description] ??= $part;
        }
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function rememberSource(array $source): void
    {
        $current = CatalogSource::query()->latest('id')->first() ?? new CatalogSource([
            'store_name' => 'Store',
        ]);
        $storeName = $this->nullableString($source['store'] ?? null);
        $criticalName = $this->nullableString($source['critical'] ?? null);

        if ($storeName !== null) {
            $current->store_name = $storeName;
        }

        if ($criticalName !== null) {
            $current->critical_name = $criticalName;
        }

        if ($this->text($current->store_name) === '') {
            $current->store_name = 'Store';
        }

        $current->imported_at = now();
        $current->save();
    }

    private function resetIndexes(): void
    {
        $this->partsByItemCode = [];
        $this->partsByPartNumber = [];
        $this->partsByDescription = [];
        $highest = Part::query()->max('sort_order');
        $this->nextSortOrder = ($highest === null ? 0 : (int) $highest) + 1;
    }

    /**
     * @return list<string>
     */
    private function pythonCommand(string $jsonPath): array
    {
        return [PHP_OS_FAMILY === 'Windows' ? 'py' : 'python', base_path('scripts/build_catalog.py'), '--out', $jsonPath];
    }

    /**
     * @param  list<string>  $command
     */
    private function runCatalogScript(array $command): void
    {
        $process = new Process($command, base_path());
        $process->setTimeout(180);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput()."\n".$process->getOutput()) ?: 'The spreadsheet could not be read.');
        }
    }

    private function quantityChanged(float $before, float $after): bool
    {
        return number_format($before, 2, '.', '') !== number_format($after, 2, '.', '');
    }

    private function recordAdjustment(Part $part, PartLocation $location, string $action, float $before, float $after): void
    {
        $part->adjustments()->create([
            'part_location_id' => $location->id,
            'location' => $location->location,
            'action' => $action,
            'quantity_before' => round($before, 2),
            'quantity_after' => round($after, 2),
        ]);
    }

    private function text(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    private function nullableString(mixed $value): ?string
    {
        $text = $this->text($value);

        return $text === '' ? null : $text;
    }
}
