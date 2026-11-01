<?php

namespace App\Http\Controllers\Api;

use App\Actions\ImportParts;
use App\Http\Controllers\Controller;
use App\Models\CatalogSource;
use App\Models\Part;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class CatalogController extends Controller
{
    public function show(): JsonResponse
    {
        $source = CatalogSource::query()->latest('id')->first();

        $parts = Part::query()
            ->with('locations')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Part $part): array => $part->toCatalogArray())
            ->values();

        return response()->json([
            'source' => [
                'store' => $source?->store_name,
                'critical' => $source?->critical_name,
                'imported_at' => $source?->imported_at?->toIso8601String(),
            ],
            'parts' => $parts,
        ]);
    }

    public function import(Request $request, ImportParts $import): JsonResponse
    {
        $data = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:2'],
            'files.*' => ['file', 'extensions:xlsx,xlsb,xlsm,xls', 'max:20480'],
        ]);

        $absolute = [];
        foreach ($data['files'] as $file) {
            $stored = $file->storeAs('imports', uniqid('catalog-', true).'.'.$file->getClientOriginalExtension());
            $absolute[] = Storage::path($stored);
        }

        try {
            $count = $import->fromSpreadsheets($absolute[0], $absolute[1] ?? null);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['imported' => $count]);
    }
}
