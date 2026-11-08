<?php

namespace App\Actions;

use App\AssemblySection;
use App\Models\Assembly;
use App\Models\AssemblyLine;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ImportAssemblies
{
    public function fromJson(string $path): int
    {
        $payload = json_decode((string) file_get_contents($path), true);
        $assemblies = $this->assemblies($payload);
        $rows = [];

        foreach ($assemblies as $assembly) {
            if (! is_array($assembly)) {
                throw new RuntimeException('The assembly file is not in the expected format.');
            }

            $lines = $assembly['lines'] ?? null;

            if (! is_array($lines) || $lines === []) {
                throw new RuntimeException('The assembly file is not in the expected format.');
            }

            $preparedLines = [];

            foreach ($lines as $line) {
                $preparedLines[] = $this->line($line);
            }

            $rows[] = [
                'part_number' => $this->requiredString($assembly['part_number'] ?? null),
                'description' => $this->requiredString($assembly['description'] ?? null),
                'lines' => $preparedLines,
            ];
        }

        DB::transaction(function () use ($rows): void {
            AssemblyLine::query()->delete();
            Assembly::query()->delete();

            foreach ($rows as $row) {
                $assembly = Assembly::query()->create([
                    'part_number' => $row['part_number'],
                    'description' => $row['description'],
                ]);
                $assembly->lines()->createMany($row['lines']);
            }
        });

        return count($rows);
    }

    /**
     * @return list<mixed>
     */
    private function assemblies(mixed $payload): array
    {
        if (is_array($payload) && array_is_list($payload)) {
            return $payload;
        }

        if (is_array($payload) && isset($payload['assemblies']) && is_array($payload['assemblies'])) {
            return $payload['assemblies'];
        }

        throw new RuntimeException('The assembly file is not in the expected format.');
    }

    /**
     * @return array{section: string, position: int, item_code: ?string, part_number: ?string, description: string, uom: ?string, quantity: int|float}
     */
    private function line(mixed $line): array
    {
        if (! is_array($line)) {
            throw new RuntimeException('The assembly file is not in the expected format.');
        }

        $section = AssemblySection::tryFrom((string) ($line['section'] ?? ''));
        $position = $line['position'] ?? null;
        $quantity = $line['quantity'] ?? null;

        if (! ($section instanceof AssemblySection) || ! is_int($position) || $position < 1 || (! is_int($quantity) && ! is_float($quantity)) || $quantity < 0) {
            throw new RuntimeException('The assembly file is not in the expected format.');
        }

        return [
            'section' => $section->value,
            'position' => $position,
            'item_code' => $this->nullableString($line['item_code'] ?? null),
            'part_number' => $this->nullableString($line['part_number'] ?? null),
            'description' => $this->requiredString($line['description'] ?? null),
            'uom' => $this->nullableString($line['uom'] ?? null),
            'quantity' => $quantity,
        ];
    }

    private function requiredString(mixed $value): string
    {
        $text = $this->nullableString($value);

        if ($text === null) {
            throw new RuntimeException('The assembly file is not in the expected format.');
        }

        return $text;
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
