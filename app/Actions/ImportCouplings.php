<?php

namespace App\Actions;

use App\Models\CouplingPump;
use App\Models\CouplingRule;
use Illuminate\Support\Facades\DB;

class ImportCouplings
{
    public function __construct(private ReadCouplingWorkbook $workbook) {}

    /**
     * @return array{pumps: int, rules: int}
     */
    public function fromWorkbook(string $path): array
    {
        $parsed = $this->workbook->read($path);

        return $this->store($parsed['pumps'], $parsed['rules']);
    }

    /**
     * Replace the coupling reference tables. Part stock is left untouched.
     *
     * @param  list<array<string, mixed>>  $pumps
     * @param  list<array<string, mixed>>  $rules
     * @return array{pumps: int, rules: int}
     */
    public function store(array $pumps, array $rules): array
    {
        $now = now()->toDateTimeString();

        DB::transaction(function () use ($pumps, $rules, $now): void {
            CouplingRule::query()->delete();
            CouplingPump::query()->delete();

            if ($pumps !== []) {
                CouplingPump::query()->insert($this->stamp($pumps, $now));
            }

            if ($rules !== []) {
                $payload = [];
                foreach ($rules as $rule) {
                    $rule['alternate_part_numbers'] = json_encode($rule['alternate_part_numbers'] ?? [], JSON_THROW_ON_ERROR);
                    $payload[] = $rule;
                }
                CouplingRule::query()->insert($this->stamp($payload, $now));
            }
        });

        return [
            'pumps' => count($pumps),
            'rules' => count($rules),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function stamp(array $rows, string $now): array
    {
        $stamped = [];

        foreach ($rows as $row) {
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            $stamped[] = $row;
        }

        return $stamped;
    }
}
