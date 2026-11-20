<?php

namespace Database\Seeders;

use App\Actions\ImportCouplings;
use Illuminate\Database\Seeder;
use RuntimeException;

class CouplingSeeder extends Seeder
{
    public function run(ImportCouplings $import): void
    {
        $path = database_path('data/coupling.xlsx');

        if (! is_file($path)) {
            throw new RuntimeException('database/data/coupling.xlsx is missing.');
        }

        $count = $import->fromWorkbook($path);
        $this->command?->info("Seeded {$count['rules']} couplings.");
    }
}
