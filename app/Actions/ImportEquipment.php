<?php

namespace App\Actions;

use App\Models\Equipment;
use Illuminate\Support\Facades\DB;

class ImportEquipment
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function store(array $rows): int
    {
        $now = now();
        $payload = [];

        foreach ($rows as $row) {
            $payload[] = [
                'port_number' => $row['port_number'],
                'serial_number' => $this->blank($row['serial_number'] ?? null),
                'description' => $this->blank($row['description'] ?? null),
                'assigned_well' => $this->blank($row['assigned_well'] ?? null),
                'recorded_on' => $row['recorded_on'],
                'location' => $this->blank($row['location'] ?? null),
                'condition' => $row['condition'],
                'tested_at' => $this->blank($row['tested_at'] ?? null),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($payload): void {
            foreach (array_chunk($payload, 500) as $chunk) {
                Equipment::query()->insert($chunk);
            }
        });

        return count($payload);
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
