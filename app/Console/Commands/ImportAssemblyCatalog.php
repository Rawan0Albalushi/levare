<?php

namespace App\Console\Commands;

use App\Actions\ImportAssemblies;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('assemblies:import {path?}')]
#[Description('Import equipment part lists from the assembly file')]
class ImportAssemblyCatalog extends Command
{
    public function handle(ImportAssemblies $import): int
    {
        $path = $this->argument('path') ?: database_path('data/assemblies.json');

        try {
            $count = $import->fromJson(is_string($path) ? $path : database_path('data/assemblies.json'));
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Imported {$count} assemblies.");

        return self::SUCCESS;
    }
}
