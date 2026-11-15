<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Part;
use App\Models\PartLocation;
use App\Models\PartQuantityAdjustment;
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

    public function adjustments(Part $part): JsonResponse
    {
        $adjustments = $part->adjustments()
            ->limit(100)
            ->get()
            ->map(fn (PartQuantityAdjustment $adjustment): array => $adjustment->toDocument())
            ->values();

        return response()->json(['adjustments' => $adjustments]);
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
     * When replace is set, that quantity becomes the stored quantity instead.
     * A quantity sent for a new location is its starting quantity.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function syncLocations(Part $part, array $rows): void
    {
        $existing = $part->locations()->lockForUpdate()->get()->keyBy('id');
        $kept = [];

        foreach (array_values($rows) as $position => $row) {
            $sent = round((float) $row['quantity'], 2);
            $replace = (bool) ($row['replace'] ?? false);

            if (! empty($row['id'])) {
                /** @var PartLocation|null $location */
                $location = $existing->get((int) $row['id']);

                if ($location === null) {
                    throw ValidationException::withMessages([
                        "locations.{$position}.id" => 'The location does not belong to this part.',
                    ]);
                }

                $before = round((float) $location->quantity, 2);
                $after = $replace ? $sent : round($before + $sent, 2);
                $changed = $this->quantityChanged($before, $after);

                $location->fill([
                    'position' => $position,
                    'location' => $row['location'],
                    'quantity' => $after,
                    'quantity_raw' => $changed ? null : $location->quantity_raw,
                    'updated_on' => $changed ? now()->toDateString() : $location->updated_on,
                    'condition' => $row['condition'] ?? $location->condition,
                    'status' => $row['status'] ?? $location->status,
                    'reference' => $row['reference'] ?? $location->reference,
                ])->save();

                if ($changed) {
                    $this->recordAdjustment($part, $location, $replace ? 'set' : 'add', $before, $after);
                }

                $kept[] = $location->id;

                continue;
            }

            $created = $part->locations()->create([
                'position' => $position,
                'location' => $row['location'],
                'quantity' => $sent,
                'quantity_raw' => null,
                'updated_on' => $this->quantityChanged(0, $sent) ? now()->toDateString() : null,
                'condition' => $row['condition'] ?? null,
                'status' => $row['status'] ?? null,
                'reference' => $row['reference'] ?? null,
            ]);

            if ($this->quantityChanged(0, $sent)) {
                $this->recordAdjustment($part, $created, 'open', 0, $sent);
            }

            $kept[] = $created->id;
        }

        foreach ($existing->except($kept) as $location) {
            $before = round((float) $location->quantity, 2);

            if ($this->quantityChanged($before, 0)) {
                $this->recordAdjustment($part, $location, 'remove', $before, 0);
            }
        }

        if ($kept === []) {
            $part->locations()->delete();

            return;
        }

        $part->locations()->whereNotIn('id', $kept)->delete();
    }

    private function quantityChanged(float $before, float $after): bool
    {
        return number_format($before, 2, '.', '') !== number_format($after, 2, '.', '');
    }

    private function recordAdjustment(Part $part, PartLocation $location, string $action, float $before, float $after): void
    {
        $part->adjustments()->create([
            'part_location_id' => $location->id,
            'location' => $location->location,
            'action' => $action,
            'quantity_before' => round($before, 2),
            'quantity_after' => round($after, 2),
        ]);
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
            'locations.*.replace' => ['sometimes', 'boolean'],
            'locations.*.condition' => ['nullable', 'string', 'max:80'],
            'locations.*.status' => ['nullable', 'string', 'max:80'],
            'locations.*.reference' => ['nullable', 'string', 'max:120'],
        ];
    }
}
