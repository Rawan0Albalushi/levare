<?php

namespace App\Http\Controllers\Api;

use App\Actions\ImportEquipment;
use App\Http\Controllers\Controller;
use App\Models\Equipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EquipmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $condition = (new Equipment)->qualifyColumn('condition');
        $testedAt = (new Equipment)->qualifyColumn('tested_at');

        $summary = Equipment::query()
            ->filtered($filters, false)
            ->selectRaw('count(*) as total')
            ->selectRaw("sum(case when {$condition} = 'new' then 1 else 0 end) as condition_new")
            ->selectRaw("sum(case when {$condition} = 'repaired' then 1 else 0 end) as condition_repaired")
            ->selectRaw("sum(case when {$testedAt} is null then 1 else 0 end) as untested")
            ->first();

        $page = Equipment::query()
            ->filtered($filters)
            ->orderByDesc('recorded_on')
            ->orderByDesc('id')
            ->paginate(40);

        return response()->json([
            'equipment' => $page->getCollection()->map(fn (Equipment $item): array => $item->toDocument())->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
            ],
            'summary' => [
                'total' => (int) ($summary->total ?? 0),
                'new' => (int) ($summary->condition_new ?? 0),
                'repaired' => (int) ($summary->condition_repaired ?? 0),
                'untested' => (int) ($summary->untested ?? 0),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $equipment = Equipment::query()->create($data);

        return response()->json(['equipment' => $equipment->toDocument()], 201);
    }

    public function update(Request $request, Equipment $equipment): JsonResponse
    {
        $equipment->update($request->validate($this->rules(anyCondition: true)));

        return response()->json(['equipment' => $equipment->toDocument()]);
    }

    public function destroy(Equipment $equipment): JsonResponse
    {
        $equipment->delete();

        return response()->json(['deleted' => true]);
    }

    public function sendToTest(Equipment $equipment): JsonResponse
    {
        $equipment->recordTest();

        return response()->json(['equipment' => $equipment->toDocument()]);
    }

    public function returnFromTest(Equipment $equipment): JsonResponse
    {
        $equipment->clearTest();

        return response()->json(['equipment' => $equipment->toDocument()]);
    }

    public function import(Request $request, ImportEquipment $import): JsonResponse
    {
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:5000'],
            'rows.*.port_number' => ['nullable', 'string', 'max:80'],
            'rows.*.serial_number' => ['nullable', 'string', 'max:80'],
            'rows.*.description' => ['nullable', 'string', 'max:2000'],
            'rows.*.assigned_well' => ['nullable', 'string', 'max:120'],
            'rows.*.recorded_on' => ['nullable', 'date_format:Y-m-d'],
            'rows.*.location' => ['nullable', 'string', 'max:120'],
            'rows.*.condition' => ['nullable', 'string', 'max:120'],
            'rows.*.tested_at' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return response()->json($import->store($data['rows']), 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return $request->validate([
            'port_number' => ['nullable', 'string', 'max:80'],
            'serial_number' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:200'],
            'assigned_well' => ['nullable', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:120'],
            'condition' => ['nullable', 'in:new,repaired'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'tested_from' => ['nullable', 'date_format:Y-m-d'],
            'tested_to' => ['nullable', 'date_format:Y-m-d'],
            'tested' => ['nullable', 'in:yes,no'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function rules(bool $anyCondition = false): array
    {
        return [
            'port_number' => ['required', 'string', 'max:80'],
            'serial_number' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:2000'],
            'assigned_well' => ['nullable', 'string', 'max:120'],
            'recorded_on' => ['required', 'date_format:Y-m-d'],
            'location' => ['nullable', 'string', 'max:120'],
            'condition' => $anyCondition
                ? ['required', 'string', 'max:120']
                : ['required', 'string', 'in:new,repaired'],
        ];
    }
}
