<?php

namespace App\Actions;

use App\Models\Assembly;
use App\Models\AssemblyLine;
use App\Models\Part;
use App\Models\PartIssue;
use App\Models\PartLocation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IssueAssemblyStock
{
    /**
     * Deduct each selected assembly line from store locations, in location order, and record one issue.
     *
     * The required quantity is read from the assembly line. A short line rejects the whole request.
     *
     * @param  list<int>  $positions
     */
    public function issue(Assembly $assembly, array $positions): PartIssue
    {
        $positions = array_values(array_unique(array_map(intval(...), $positions)));
        sort($positions);

        return DB::transaction(function () use ($assembly, $positions): PartIssue {
            $lines = $assembly->lines()
                ->whereIn('position', $positions)
                ->orderBy('position')
                ->lockForUpdate()
                ->get()
                ->keyBy('position');

            if ($lines->count() !== count($positions)) {
                throw ValidationException::withMessages([
                    'positions' => 'One of the selected lines is not on this assembly.',
                ]);
            }

            $selected = [];

            foreach ($positions as $position) {
                /** @var AssemblyLine $line */
                $line = $lines->get($position);
                $need = round((float) $line->quantity, 2);

                if ($need <= 0) {
                    throw ValidationException::withMessages([
                        'positions' => 'A selected line has no quantity to deduct.',
                    ]);
                }

                $selected[] = ['line' => $line, 'need' => $need];
            }

            $parts = $this->partsFor($selected);
            $partIds = collect($parts)->filter()->map(fn (Part $part): int => $part->id)->unique()->sort()->values();
            $locations = $this->lockedLocations($partIds);
            $available = [];

            foreach ($locations as $location) {
                $available[$location->id] = round((float) $location->quantity, 2);
            }

            $short = [];
            $plans = [];

            foreach ($selected as $row) {
                /** @var AssemblyLine $line */
                $line = $row['line'];
                $part = $parts[$line->position] ?? null;
                $rows = $part instanceof Part
                    ? $locations->where('part_id', $part->id)->sortBy(fn (PartLocation $location): string => sprintf('%010d-%010d', $location->position, $location->id))->values()
                    : collect();
                $have = round((float) $rows->sum(fn (PartLocation $location): float => max($available[$location->id] ?? 0, 0)), 2);
                $remaining = $row['need'];
                $draft = $available;
                $allocations = [];

                foreach ($rows as $location) {
                    $onHand = $draft[$location->id] ?? 0;

                    if ($onHand <= 0 || $remaining <= 0) {
                        continue;
                    }

                    $take = round(min($onHand, $remaining), 2);
                    $allocations[] = ['location' => $location, 'quantity' => $take];
                    $draft[$location->id] = round($onHand - $take, 2);
                    $remaining = round($remaining - $take, 2);
                }

                if ($remaining > 0 || ! ($part instanceof Part)) {
                    $short[] = [
                        'n' => $line->position,
                        'need' => Part::quantityNumber($row['need']),
                        'have' => Part::quantityNumber($have),
                    ];

                    continue;
                }

                $available = $draft;
                $plans[] = ['line' => $line, 'part' => $part, 'allocations' => $allocations];
            }

            if ($short !== []) {
                throw new InsufficientAssemblyStock($short);
            }

            $issue = PartIssue::query()->create([
                'assembly_part_number' => $assembly->part_number,
                'assembly_description' => $assembly->description,
            ]);

            foreach ($plans as $plan) {
                /** @var AssemblyLine $line */
                $line = $plan['line'];
                /** @var Part $part */
                $part = $plan['part'];
                $issueLine = $issue->lines()->create([
                    'part_id' => $part->id,
                    'position' => $line->position,
                    'item_code' => $part->item_code,
                    'part_number' => $part->part_number,
                    'description' => $part->description,
                    'uom' => $part->uom ?: $line->uom,
                    'quantity' => $line->quantity,
                ]);

                foreach ($plan['allocations'] as $allocation) {
                    /** @var PartLocation $location */
                    $location = $allocation['location'];
                    $location->fill([
                        'quantity' => round((float) $location->quantity - $allocation['quantity'], 2),
                        'quantity_raw' => null,
                        'updated_on' => now()->toDateString(),
                    ])->save();

                    $issueLine->allocations()->create([
                        'part_location_id' => $location->id,
                        'location' => $location->location,
                        'quantity' => $allocation['quantity'],
                    ]);
                }
            }

            return $issue->load('lines.allocations');
        });
    }

    /**
     * @param  list<array{line: AssemblyLine, need: float}>  $selected
     * @return array<int, Part|null>
     */
    private function partsFor(array $selected): array
    {
        $codes = [];
        $numbers = [];

        foreach ($selected as $row) {
            $code = $row['line']->item_code;
            $number = $row['line']->part_number;

            if (is_string($code) && $code !== '') {
                $codes[] = $code;
            }

            if (is_string($number) && $number !== '') {
                $numbers[] = $number;
            }
        }

        $byCode = [];
        $byNumber = [];

        if ($codes !== [] || $numbers !== []) {
            $candidates = Part::query()
                ->where(function ($query) use ($codes, $numbers): void {
                    if ($codes !== []) {
                        $query->orWhereIn('item_code', array_values(array_unique($codes)));
                    }

                    if ($numbers !== []) {
                        $query->orWhereIn('part_number', array_values(array_unique($numbers)));
                    }
                })
                ->orderBy('id')
                ->get();

            foreach ($candidates as $part) {
                $code = $part->item_code;
                $number = $part->part_number;

                if (is_string($code) && $code !== '' && ! isset($byCode[$code])) {
                    $byCode[$code] = $part;
                }

                if (is_string($number) && $number !== '' && ! isset($byNumber[$number])) {
                    $byNumber[$number] = $part;
                }
            }
        }

        $resolved = [];

        foreach ($selected as $row) {
            $resolved[$row['line']->position] = $row['line']->matchedPart($byCode, $byNumber);
        }

        return $resolved;
    }

    /**
     * @param  Collection<int, int>  $partIds
     * @return Collection<int, PartLocation>
     */
    private function lockedLocations(Collection $partIds): Collection
    {
        if ($partIds->isEmpty()) {
            return collect();
        }

        Part::query()->whereIn('id', $partIds)->orderBy('id')->lockForUpdate()->get();

        return PartLocation::query()
            ->whereIn('part_id', $partIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }
}
