<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'item_code',
    'part_number',
    'description',
    'uom',
    'store_type',
    'categories',
    'priority',
    'below_minimum',
    'min_qty',
    'crit_stock',
    'reused',
    'single_eq',
    'plan_90',
    'plan_6',
    'backup',
    'used_in',
    'notes',
    'alternative_note',
    'alternatives',
    'is_critical',
    'sort_order',
])]
class Part extends Model
{
    public function locations(): HasMany
    {
        return $this->hasMany(PartLocation::class)->orderBy('position');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'categories' => 'array',
            'alternatives' => 'array',
            'below_minimum' => 'boolean',
            'is_critical' => 'boolean',
        ];
    }

    /**
     * Compact catalog shape used by the storefront search.
     *
     * @return array<string, mixed>
     */
    public function toCatalogArray(): array
    {
        $part = [
            'id' => $this->item_code ?? '',
            'pn' => $this->part_number ?? '',
            'd' => $this->description,
            'u' => $this->uom ?? '',
            't' => $this->store_type ?? '',
            'L' => $this->locations->map(fn (PartLocation $row): array => [
                $row->location,
                self::quantityNumber($row->quantity),
                $row->quantity_raw ?? '',
                $row->updated_on ?? '',
                $row->condition ?? '',
                $row->status ?? '',
                $row->reference ?? '',
            ])->all(),
            'cats' => $this->categories ?? [],
        ];

        foreach ([
            'pr' => $this->priority,
            'mn' => $this->min_qty,
            'cs' => $this->crit_stock,
            'ru' => $this->reused,
            'se' => $this->single_eq,
            'p90' => $this->plan_90,
            'p6' => $this->plan_6,
            'bk' => $this->backup,
            'us' => $this->used_in,
            'no' => $this->notes,
            'an' => $this->alternative_note,
        ] as $key => $value) {
            if ($value !== null && $value !== '') {
                $part[$key] = $value;
            }
        }

        if ($this->below_minimum) {
            $part['w'] = 1;
        }

        if ($this->alternatives) {
            $part['alt'] = $this->alternatives;
        }

        if ($this->is_critical) {
            $part['cr'] = 1;
        }

        return $part;
    }

    public static function quantityNumber(mixed $value): int|float
    {
        $number = round((float) $value, 2);

        return fmod($number, 1.0) === 0.0 ? (int) $number : $number;
    }
}
