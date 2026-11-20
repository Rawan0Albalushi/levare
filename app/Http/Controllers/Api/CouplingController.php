<?php

namespace App\Http\Controllers\Api;

use App\Actions\SuggestCoupling;
use App\CouplingEnd;
use App\Http\Controllers\Controller;
use App\Models\CouplingPump;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
