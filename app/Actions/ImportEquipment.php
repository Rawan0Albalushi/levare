<?php

namespace App\Actions;

use App\Models\Equipment;
use Illuminate\Support\Facades\DB;

class ImportEquipment
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{imported: int, skipped: int}
     */
    public function store(array $rows): array
    {
        $prepared = [];

        foreach ($rows as $row) {
            $prepared[] = [
                'port_number' => $this->blank($row['port_number'] ?? null),
                'serial_number' => $this->blank($row['serial_number'] ?? null),
                'description' => $this->blank($row['description'] ?? null),
                'assigned_well' => $this->blank($row['assigned_well'] ?? null),
                'recorded_on' => $this->blank($row['recorded_on'] ?? null),
                'location' => $this->blank($row['location'] ?? null),
                'condition' => $this->blank($row['condition'] ?? null),
                'tested_at' => $this->blank($row['tested_at'] ?? null),
            ];
        }

        $fresh = $this->freshRows($prepared);

        if ($fresh !== []) {
            $now = now();
            $payload = [];

            foreach ($fresh as $row) {
                $row['created_at'] = $now;
                $row['updated_at'] = $now;
                $payload[] = $row;
            }

            DB::transaction(function () use ($payload): void {
                foreach (array_chunk($payload, 500) as $chunk) {
                    Equipment::query()->insert($chunk);
                }
            });
        }

        return [
            'imported' => count($fresh),
            'skipped' => count($prepared) - count($fresh),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function freshRows(array $rows): array
    {
        $serials = [];
        $hasBlankSerial = false;

        foreach ($rows as $row) {
            if (is_string($row['serial_number']) && $row['serial_number'] !== '') {
                $serials[$this->serialKey($row['serial_number'])] = $row['serial_number'];
            } else {
                $hasBlankSerial = true;
            }
        }

        $knownSerials = $this->knownSerials(array_values($serials));
        $knownRows = $hasBlankSerial ? $this->knownBlankRows() : [];
        $seenSerials = [];
        $seenRows = [];
        $fresh = [];

        foreach ($rows as $row) {
            $serial = $row['serial_number'];

            if (is_string($serial) && $serial !== '') {
                $key = $this->serialKey($serial);
                if (isset($knownSerials[$key]) || isset($seenSerials[$key])) {
                    continue;
                }
                $seenSerials[$key] = true;
                $fresh[] = $row;

                continue;
            }

            $key = $this->fingerprint($row);
            if (isset($knownRows[$key]) || isset($seenRows[$key])) {
                continue;
            }
            $seenRows[$key] = true;
            $fresh[] = $row;
        }

        return $fresh;
    }

    /**
     * @param  list<string>  $serials
     * @return array<string, true>
     */
    private function knownSerials(array $serials): array
    {
        $known = [];

        foreach (array_chunk($serials, 500) as $chunk) {
            $lowered = array_map(fn (string $serial): string => $this->serialKey($serial), $chunk);
            $placeholders = implode(', ', array_fill(0, count($lowered), '?'));
            $matches = Equipment::query()
                ->whereNotNull('serial_number')
                ->whereRaw('lower(serial_number) in ('.$placeholders.')', $lowered)
                ->pluck('serial_number');

            foreach ($matches as $serial) {
                if (is_string($serial)) {
                    $known[$this->serialKey($serial)] = true;
                }
            }
        }

        return $known;
    }

    /**
     * @return array<string, true>
     */
    private function knownBlankRows(): array
    {
        $known = [];

        Equipment::query()
            ->whereNull('serial_number')
            ->select(['id', 'port_number', 'description', 'assigned_well', 'recorded_on', 'location', 'condition', 'tested_at'])
            ->chunkById(500, function ($rows) use (&$known): void {
                foreach ($rows as $row) {
                    $known[$this->fingerprint([
                        'port_number' => $row->port_number,
                        'description' => $row->description,
                        'assigned_well' => $row->assigned_well,
                        'recorded_on' => $row->recorded_on?->toDateString(),
                        'location' => $row->location,
                        'condition' => $row->condition,
                        'tested_at' => $row->tested_at?->toDateString(),
                    ])] = true;
                }
            });

        return $known;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function fingerprint(array $row): string
    {
        $parts = [
            $row['port_number'] ?? '',
            $row['description'] ?? '',
            $row['assigned_well'] ?? '',
            $row['recorded_on'] ?? '',
            $row['location'] ?? '',
            $row['condition'] ?? '',
            $row['tested_at'] ?? '',
        ];

        return implode("\x1f", array_map(static fn (mixed $value): string => trim((string) $value), $parts));
    }

    private function serialKey(string $serial): string
    {
        return mb_strtolower($serial);
    }

    private function blank(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
