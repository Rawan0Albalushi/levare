<?php

namespace App\Http\Controllers\Api;

use App\Actions\ImportCouplings;
use App\Actions\SuggestCoupling;
use App\CouplingEnd;
use App\Http\Controllers\Controller;
use App\Models\CouplingPump;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CouplingController extends Controller
{
    public function index(): JsonResponse
    {
        $pumps = CouplingPump::query()
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (CouplingPump $pump): array => $pump->toDocument())
            ->all();

        return response()->json([
            'pumps' => $pumps,
            'ends' => array_map(fn (CouplingEnd $end): string => $end->value, CouplingEnd::cases()),
        ]);
    }

    public function template(): BinaryFileResponse
    {
        $path = database_path('data/coupling-template.xlsx');
        abort_unless(is_file($path), 404);

        return response()->download($path, 'Levare-coupling-template.xlsx');
    }

    public function import(Request $request, ImportCouplings $import): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'extensions:xlsx', 'max:10240'],
        ]);

        $stored = $data['file']->storeAs('imports', uniqid('coupling-', true).'.xlsx');
        if (! is_string($stored) || $stored === '') {
            return response()->json(['message' => 'The coupling file could not be stored.', 'code' => 'store'], 422);
        }

        try {
            if (! $this->isSpreadsheetZip(Storage::path($stored))) {
                return response()->json([
                    'message' => 'The coupling file is not in the expected format.',
                    'code' => 'format',
                ], 422);
            }

            $count = $import->fromWorkbook(Storage::path($stored));
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'format',
            ], 422);
        } finally {
            Storage::delete($stored);
        }

        return response()->json($count);
    }

    private function isSpreadsheetZip(string $path): bool
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $header = fread($handle, 4);
        fclose($handle);

        return in_array($header, ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true);
    }

    public function suggest(Request $request, SuggestCoupling $suggest): JsonResponse
    {
        $data = $request->validate([
            'series' => ['nullable', Rule::in(['400', '538'])],
            'from' => ['required', 'array'],
            'from.end' => ['required', Rule::enum(CouplingEnd::class)],
            'from.pump' => ['nullable', 'string', 'max:120'],
            'to' => ['required', 'array'],
            'to.end' => ['required', Rule::enum(CouplingEnd::class)],
            'to.pump' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json($suggest->pair($data));
    }

    public function chain(Request $request, SuggestCoupling $suggest): JsonResponse
    {
        $data = $request->validate([
            'series' => ['nullable', Rule::in(['400', '538'])],
            'components' => ['required', 'array', 'min:2', 'max:12'],
            'components.*.end' => ['required', Rule::enum(CouplingEnd::class)],
            'components.*.pump' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json($suggest->chain($data));
    }
}
