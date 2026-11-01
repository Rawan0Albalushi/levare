<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stocktake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StocktakeController extends Controller
{
    public function index(): JsonResponse
    {
        $stocktakes = Stocktake::query()
            ->orderByRaw("case when status = 'prog' then 0 else 1 end")
            ->latest()
            ->get()
            ->map(fn (Stocktake $stocktake): array => $stocktake->toDocument())
            ->values();

        return response()->json(['stocktakes' => $stocktakes]);
    }

    public function store(Request $request): JsonResponse
    {
        $document = $this->document($request);
        $stocktake = Stocktake::query()->create([
            'public_id' => $document['id'],
            'name' => $document['name'] ?? null,
            'status' => $document['status'],
            'payload' => $document,
        ]);

        return response()->json(['stocktake' => $stocktake->toDocument()], 201);
    }

    public function update(Request $request, Stocktake $stocktake): JsonResponse
    {
        $document = $this->document($request, $stocktake->public_id);
        $stocktake->update([
            'name' => $document['name'] ?? null,
            'status' => $document['status'],
            'payload' => $document,
        ]);

        return response()->json(['stocktake' => $stocktake->toDocument()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function document(Request $request, ?string $expectedId = null): array
    {
        $request->validate([
            'id' => ['required', 'string', 'max:40', 'regex:/^STK-[A-Z0-9]+$/', $expectedId ? Rule::in([$expectedId]) : Rule::unique('stocktakes', 'public_id')],
            'name' => ['nullable', 'string', 'max:200'],
            'status' => ['required', 'in:prog,done,cancel'],
            'days' => ['required', 'integer', 'between:1,365'],
            'start' => ['required', 'date_format:Y-m-d'],
            'cat' => ['nullable', 'string', 'max:32'],
            'created' => ['nullable', 'string', 'max:40'],
            'open' => ['nullable', 'string', 'max:40'],
            'finished' => ['nullable', 'string', 'max:40'],
            'v' => ['nullable', 'integer'],
            'locs' => ['required', 'array', 'max:20000'],
            'shifts' => ['present', 'array', 'max:5000'],
            'sur' => ['present', 'array', 'max:20000'],
        ]);

        return $request->only([
            'v', 'id', 'name', 'days', 'start', 'cat', 'created', 'status', 'open', 'finished', 'shifts', 'sur', 'locs',
        ]);
    }
}
