<?php

namespace App\Actions;

use App\Models\CatalogSource;
use App\Models\Part;
use App\Models\PartLocation;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

class ImportParts
{
    public function fromSpreadsheets(string $storePath, ?string $criticalPath = null): int
    {
        $jsonPath = database_path('data/parts.json');
        $command = [PHP_OS_FAMILY === 'Windows' ? 'py' : 'python', base_path('scripts/build_catalog.py'), '--out', $jsonPath];

        if (is_file($storePath)) {
            $command[] = $storePath;
        }

        if ($criticalPath && is_file($criticalPath)) {
            $command[] = $criticalPath;
        }

        $process = new Process($command, base_path());
        $process->setTimeout(180);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput()."\n".$process->getOutput()) ?: 'The spreadsheet could not be read.');
        }

        return $this->fromJson($jsonPath);
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

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
