<?php

namespace Database\Seeders;

use App\Actions\ImportParts;
use Illuminate\Database\Seeder;
use RuntimeException;

class PartsCatalogSeeder extends Seeder
{
    public function run(ImportParts $import): void
    {
        $path = database_path('data/parts.json');

        if (! is_file($path)) {
            throw new RuntimeException('database/data/parts.json is missing. Run php artisan parts:import first.');
        }

        $count = $import->fromJson($path);
        $this->command?->info("Seeded {$count} parts.");
    }
}
