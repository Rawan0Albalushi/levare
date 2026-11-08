<?php

namespace Database\Seeders;

use App\Actions\ImportAssemblies;
use Illuminate\Database\Seeder;
use RuntimeException;

class AssemblySeeder extends Seeder
{
    public function run(ImportAssemblies $import): void
    {
        $path = database_path('data/assemblies.json');

        if (! is_file($path)) {
            throw new RuntimeException('database/data/assemblies.json is missing.');
        }

        $count = $import->fromJson($path);
        $this->command?->info("Seeded {$count} assemblies.");
    }
}
