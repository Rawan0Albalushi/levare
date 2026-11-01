<?php

namespace App\Console\Commands;

use App\Actions\ImportParts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('parts:import {store?} {critical?}')]
#[Description('Import spare parts from the store workbook and the critical parts list')]
class ImportPartsCatalog extends Command
{
    public function handle(ImportParts $import): int
    {
        $store = $this->argument('store') ?: base_path('STORE SPARE PARTS 4.10.26.xlsb');
        $critical = $this->argument('critical') ?: base_path('Critical Parts List 30.7.26.xlsx');

        try {
            $count = $import->fromSpreadsheets($store, $critical);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Imported {$count} parts.");

        return self::SUCCESS;
    }
}
