<?php

namespace App\Console\Commands;

use App\Actions\ImportCouplings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('couplings:import {file?}')]
#[Description('Import coupling pumps and part numbers from the coupling workbook')]
class ImportCouplingCatalog extends Command
{
    public function handle(ImportCouplings $import): int
    {
        $file = $this->argument('file') ?: database_path('data/coupling.xlsx');

        try {
            $count = $import->fromWorkbook($file);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Imported {$count['pumps']} pumps and {$count['rules']} couplings.");

        return self::SUCCESS;
    }
}
