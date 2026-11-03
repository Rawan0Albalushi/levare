<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Part;
use App\Models\PartLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PartController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $part = $this->save(new Part, $request->validate($this->rules(null)));

        return response()->json(['part' => $part->toCatalogArray()], 201);
    }

    public function update(Request $request, Part $part): JsonResponse
    {
        $part = $this->save($part, $request->validate($this->rules($part)));

        return response()->json(['part' => $part->toCatalogArray()]);
    }

    public function destroy(Part $part): JsonResponse
    {
        $part->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function save(Part $part, array $data): Part
    {
        return DB::transaction(function () use ($part, $data): Part {
            $part->fill([
                'item_code' => $data['item_code'] ?? null,
                'part_number' => $data['part_number'] ?? null,
                'description' => $data['description'],
                'uom' => $data['uom'] ?? null,
                'store_type' => $data['store_type'] ?? null,
                'categories' => array_values($data['categories'] ?? []),
                'priority' => $data['priority'] ?? null,
                'notes' => $data['notes'] ?? null,
                'is_critical' => (bool) ($data['critical'] ?? false),
            ]);

            if (! $part->exists) {
                $part->sort_order = ((int) Part::query()->max('sort_order')) + 1;
            }

            if ($part->exists) {
                Part::query()->whereKey($part->id)->lockForUpdate()->first();
            }

            $part->save();
            $this->syncLocations($part, $data['locations'] ?? []);

            return $part->load('locations');
        });
    }

    /**
     * A quantity sent for an existing location is added to the stored quantity.
     * A quantity sent for a new location is its starting quantity.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function syncLocations(Part $part, array $rows): void
    {
        $existing = $part->locations()->lockForUpdate()->get()->keyBy('id');
        $kept = [];

        foreach (array_values($rows) as $position => $row) {
            $added = round((float) $row['quantity'], 2);

            if (! empty($row['id'])) {
                /** @var PartLocation|null $location */
                $location = $existing->get((int) $row['id']);

                if ($location === null) {
                    throw ValidationException::withMessages([
                        "locations.{$position}.id" => 'The location does not belong to this part.',
                    ]);
                }

                $location->fill([
                    'position' => $position,
                    'location' => $row['location'],
                    'quantity' => round((float) $location->quantity + $added, 2),
                    'quantity_raw' => $added === 0.0 ? $location->quantity_raw : null,
                    'updated_on' => $added === 0.0 ? $location->updated_on : now()->toDateString(),
                    'condition' => $row['condition'] ?? $location->condition,
                    'status' => $row['status'] ?? $location->status,
                    'reference' => $row['reference'] ?? $location->reference,
                ])->save();

                $kept[] = $location->id;

                continue;
            }

            $created = $part->locations()->create([
                'position' => $position,
                'location' => $row['location'],
                'quantity' => $added,
                'quantity_raw' => null,
                'updated_on' => $added === 0.0 ? null : now()->toDateString(),
                'condition' => $row['condition'] ?? null,
                'status' => $row['status'] ?? null,
                'reference' => $row['reference'] ?? null,
            ]);
            $kept[] = $created->id;
        }

        if ($kept === []) {
            $part->locations()->delete();

            return;
        }

        $part->locations()->whereNotIn('id', $kept)->delete();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(?Part $part): array
    {
        $locationId = ['nullable', 'integer', 'distinct'];

        if ($part instanceof Part) {
            $locationId[] = Rule::exists('part_locations', 'id')->where('part_id', $part->id);
        } else {
            $locationId[] = 'prohibited';
        }

        return [
            'item_code' => ['nullable', 'string', 'max:120', 'required_without:part_number'],
            'part_number' => ['nullable', 'string', 'max:120', 'required_without:item_code'],
            'description' => ['required', 'string', 'max:5000'],
            'uom' => ['nullable', 'string', 'max:32'],
            'store_type' => ['nullable', 'string', 'max:2000'],
            'categories' => ['nullable', 'array', 'max:4'],
            'categories.*' => ['distinct', 'in:pump,seal,motor,shaft'],
            'priority' => ['nullable', 'string', 'max:32'],
            'critical' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'locations' => ['nullable', 'array', 'max:40'],
            'locations.*.id' => $locationId,
            'locations.*.location' => ['required', 'string', 'max:120'],
            'locations.*.quantity' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'locations.*.condition' => ['nullable', 'string', 'max:80'],
            'locations.*.status' => ['nullable', 'string', 'max:80'],
            'locations.*.reference' => ['nullable', 'string', 'max:120'],
        ];
    }
}
