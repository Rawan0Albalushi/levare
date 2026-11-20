<?php

namespace App\Actions;

use App\CouplingEnd;
use App\Models\CouplingPump;
use App\Models\CouplingRule;
use App\Models\Part;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class SuggestCoupling
{
    /**
     * Shaft on the side that does not vary by pump model.
     * The 400 motor follows the motor-to-motor row and the catalog description (30mm).
     * The seal-to-motor cell in the sheet says 35 and is kept as written.
     *
     * @var array<string, array<string, string>>
     */
    private const SHAFTS = [
        '400' => [
            'gs' => '22',
            'intake' => '25',
            'seal' => '25',
            'motor' => '30',
        ],
        '538' => [
            'gs' => '28',
            'intake' => '28',
            'seal' => '35',
            'motor' => '1-3/8',
        ],
    ];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function pair(array $data): array
    {
        $from = $this->identified($data['from'] ?? [], 'from.pump');
        $to = $this->identified($data['to'] ?? [], 'to.pump');
        $series = $this->chosenSeries($data['series'] ?? null, [$from, $to]);
        $from = $this->withShaft($from, $series);
        $to = $this->withShaft($to, $series);
        $chosen = $this->chooseRules($this->rules($series, $from['end'], $to['end']), $from, $to);

        return [
            'series' => $series,
            'from' => $this->publicComponent($from),
            'to' => $this->publicComponent($to),
            'matches' => $this->present($chosen, $this->catalog($chosen)),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function chain(array $data): array
    {
        $components = [];
        foreach ($data['components'] ?? [] as $index => $component) {
            if (! is_array($component)) {
                continue;
            }
            $components[] = $this->identified($component, 'components.'.$index.'.pump');
        }

        $series = $this->chosenSeries($data['series'] ?? null, $components);
        $components = array_map(fn (array $component): array => $this->withShaft($component, $series), $components);
        $joints = [];
        $chosenSets = [];

        for ($index = 0; $index < count($components) - 1; $index++) {
            $from = $components[$index];
            $to = $components[$index + 1];
            $chosen = $this->chooseRules($this->rules($series, $from['end'], $to['end']), $from, $to);
            $chosenSets[] = $chosen;
            $joints[] = [
                'from' => $this->publicComponent($from),
                'to' => $this->publicComponent($to),
            ];
        }

        $catalog = $this->catalog(array_merge(...($chosenSets === [] ? [[]] : $chosenSets)));

        foreach ($joints as $index => $joint) {
            $joints[$index]['matches'] = $this->present($chosenSets[$index], $catalog);
        }

        return [
            'series' => $series,
            'joints' => $joints,
        ];
    }

    /**
     * @param  array<string, mixed>  $component
     * @return array{end: string, pump: ?string, shaft: ?string, series: ?string}
     */
    private function identified(array $component, string $field): array
    {
        $end = CouplingEnd::from((string) ($component['end'] ?? ''));

        if ($end !== CouplingEnd::Pump && $end !== CouplingEnd::Vapro) {
            return [
                'end' => $end->value,
                'pump' => null,
                'shaft' => null,
                'series' => null,
            ];
        }

        $name = is_string($component['pump'] ?? null) ? trim($component['pump']) : '';
        $pump = $name === '' ? null : CouplingPump::query()->where('name', $name)->first();

        if (! $pump instanceof CouplingPump || $pump->kind !== $end->value) {
            throw ValidationException::withMessages([
                $field => 'Choose a pump from the coupling table.',
            ]);
        }

        return [
            'end' => $end->value,
            'pump' => $pump->name,
            'shaft' => $pump->shaft,
            'series' => $pump->series,
        ];
    }

    /**
     * @param  list<array{series: ?string}>  $components
     */
    private function chosenSeries(mixed $requested, array $components): string
    {
        if ($requested === '400' || $requested === '538') {
            return $requested;
        }

        foreach ($components as $component) {
            if (is_string($component['series']) && $component['series'] !== '') {
                return $component['series'];
            }
        }

        throw ValidationException::withMessages([
            'series' => 'Choose series 400 or 538.',
        ]);
    }

    /**
     * @param  array{end: string, pump: ?string, shaft: ?string, series: ?string}  $component
     * @return array{end: string, pump: ?string, shaft: ?string, series: ?string}
     */
    private function withShaft(array $component, string $series): array
    {
        if ($component['shaft'] === null) {
            $component['shaft'] = self::SHAFTS[$series][$component['end']] ?? null;
        }

        return $component;
    }

    /**
     * @return Collection<int, CouplingRule>
     */
    private function rules(string $series, string $from, string $to): Collection
    {
        return CouplingRule::query()
            ->where('series', $series)
            ->where('from_end', $from)
            ->where('to_end', $to)
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /**
     * A single sheet row for this pair is returned even when its shaft cell
     * does not match the calculated shaft, and the response marks that case.
     * Several rows and no shaft match stay empty so a part number is not guessed.
     *
     * @param  Collection<int, CouplingRule>  $candidates
     * @param  array{shaft: ?string}  $from
     * @param  array{shaft: ?string}  $to
     * @return list<array{rule: CouplingRule, used_only_row: bool}>
     */
    private function chooseRules(Collection $candidates, array $from, array $to): array
    {
        $left = $from['shaft'];
        $preferred = $left === null
            ? $candidates->values()
            : $candidates->filter(fn (CouplingRule $rule): bool => $rule->left_shaft === $left)->values();
        $usedOnlyRow = false;

        if ($preferred->isEmpty()) {
            if ($candidates->count() !== 1) {
                return [];
            }

            $preferred = $candidates->values();
            $usedOnlyRow = true;
        }

        $right = $to['shaft'];
        if (is_string($right) && $preferred->count() > 1) {
            $narrowed = $preferred->filter(fn (CouplingRule $rule): bool => $rule->right_shaft === $right)->values();
            if ($narrowed->isNotEmpty()) {
                $preferred = $narrowed;
            }
        }

        return $preferred->map(fn (CouplingRule $rule): array => [
            'rule' => $rule,
            'used_only_row' => $usedOnlyRow,
        ])->all();
    }

    /**
     * @param  list<array{rule: CouplingRule, used_only_row: bool}>  $chosen
     * @return array<string, list<array<string, mixed>>>
     */
    private function catalog(array $chosen): array
    {
        $numbers = [];

        foreach ($chosen as $item) {
            $rule = $item['rule'];
            $numbers[] = $rule->part_number;
            if (is_string($rule->old_part_number) && $rule->old_part_number !== '') {
                $numbers[] = $rule->old_part_number;
            }
            foreach ($rule->alternate_part_numbers ?? [] as $number) {
                if (is_string($number) && $number !== '') {
                    $numbers[] = $number;
                }
            }
        }

        $numbers = array_values(array_unique($numbers));
        if ($numbers === []) {
            return [];
        }

        $parts = Part::query()
            ->with('locations')
            ->where(function ($query) use ($numbers): void {
                $query->whereIn('part_number', $numbers)->orWhereIn('item_code', $numbers);
            })
            ->orderBy('id')
            ->get();

        $catalog = [];

        foreach ($parts as $part) {
            $document = $this->partDocument($part);
            $seen = [];
            foreach ([$part->part_number, $part->item_code] as $number) {
                if (! is_string($number) || $number === '' || isset($seen[$number])) {
                    continue;
                }
                $seen[$number] = true;
                $catalog[$number][] = $document;
            }
        }

        return $catalog;
    }

    /**
     * @return array<string, mixed>
     */
    private function partDocument(Part $part): array
    {
        $quantity = 0.0;
        $locations = [];

        foreach ($part->locations as $location) {
            $quantity += (float) $location->quantity;
            $locations[] = [
                'location' => $location->location,
                'quantity' => Part::quantityNumber($location->quantity),
            ];
        }

        return [
            'id' => $part->id,
            'part_number' => $part->part_number ?? '',
            'item_code' => $part->item_code ?? '',
            'description' => $part->description,
            'quantity' => Part::quantityNumber($quantity),
            'locations' => $locations,
        ];
    }

    /**
     * @param  list<array{rule: CouplingRule, used_only_row: bool}>  $chosen
     * @param  array<string, list<array<string, mixed>>>  $catalog
     * @return list<array<string, mixed>>
     */
    private function present(array $chosen, array $catalog): array
    {
        $matches = [];

        foreach ($chosen as $item) {
            $rule = $item['rule'];
            $numbers = [$this->number('current', $rule->part_number, $catalog)];

            if (is_string($rule->old_part_number) && $rule->old_part_number !== '') {
                $numbers[] = $this->number('old', $rule->old_part_number, $catalog);
            }

            foreach ($rule->alternate_part_numbers ?? [] as $number) {
                if (is_string($number) && $number !== '') {
                    $numbers[] = $this->number('alternate', $number, $catalog);
                }
            }

            $matches[] = [
                'part_number' => $rule->part_number,
                'old_part_number' => $rule->old_part_number,
                'description' => $rule->description,
                'shaft' => $rule->shaft(),
                'description_shaft' => $rule->descriptionShaft(),
                'used_only_row' => $item['used_only_row'],
                'wells' => $rule->wells,
                'numbers' => $numbers,
            ];
        }

        return $matches;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $catalog
     * @return array<string, mixed>
     */
    private function number(string $role, string $partNumber, array $catalog): array
    {
        $parts = $catalog[$partNumber] ?? [];

        return [
            'role' => $role,
            'part_number' => $partNumber,
            'found' => $parts !== [],
            'parts' => $parts,
        ];
    }

    /**
     * @param  array{end: string, pump: ?string, shaft: ?string}  $component
     * @return array{end: string, pump: ?string, shaft: ?string}
     */
    private function publicComponent(array $component): array
    {
        return [
            'end' => $component['end'],
            'pump' => $component['pump'],
            'shaft' => $component['shaft'],
        ];
    }
}
