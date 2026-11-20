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

    public function import(Request $request, ImportCouplings $import): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'extensions:xlsx', 'mimes:xlsx', 'max:10240'],
        ]);

        $stored = $data['file']->storeAs('imports', uniqid('coupling-', true).'.xlsx');

        try {
            $count = $import->fromWorkbook(Storage::path($stored));
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } finally {
            Storage::delete($stored);
        }

        return response()->json($count);
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
