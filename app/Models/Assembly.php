<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['part_number', 'description'])]
class Assembly extends Model
{
    public function lines(): HasMany
    {
        return $this->hasMany(AssemblyLine::class)->orderBy('position');
    }

    /**
     * @param  Collection<int, Part>  $parts
     * @return list<array<string, mixed>>
     */
    public static function catalogDocuments(Collection $parts): array
    {
        $partsByCode = [];
        $partsByNumber = [];

        foreach ($parts as $part) {
            $code = $part->item_code;
            $number = $part->part_number;

            if (is_string($code) && $code !== '' && ! isset($partsByCode[$code])) {
                $partsByCode[$code] = $part;
            }

            if (is_string($number) && $number !== '' && ! isset($partsByNumber[$number])) {
                $partsByNumber[$number] = $part;
            }
        }

        return self::query()
            ->with('lines')
            ->orderBy('part_number')
            ->orderBy('id')
            ->get()
            ->map(fn (self $assembly): array => $assembly->toCatalogArray($partsByCode, $partsByNumber))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, Part>  $partsByCode
     * @param  array<string, Part>  $partsByNumber
     * @return array<string, mixed>
     */
    public function toCatalogArray(array $partsByCode, array $partsByNumber): array
    {
        return [
            'pn' => $this->part_number,
            'd' => $this->description,
            'lines' => $this->lines->map(function (AssemblyLine $line) use ($partsByCode, $partsByNumber): array {
                $part = $line->matchedPart($partsByCode, $partsByNumber);

                return [
                    'section' => $line->section->value,
                    'n' => $line->position,
                    'id' => $part?->item_code ?? $line->item_code ?? '',
                    'pn' => $part?->part_number ?? $line->part_number ?? '',
                    'd' => $part?->description ?: $line->description,
                    'u' => $part?->uom ?: $line->uom ?? '',
                    'qty' => Part::quantityNumber($line->quantity),
                    'pk' => $part?->id,
                ];
            })->values()->all(),
        ];
    }
}
