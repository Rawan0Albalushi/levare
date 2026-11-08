<?php

namespace App\Models;

use App\AssemblySection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'section',
    'position',
    'item_code',
    'part_number',
    'description',
    'uom',
    'quantity',
])]
class AssemblyLine extends Model
{
    public function assembly(): BelongsTo
    {
        return $this->belongsTo(Assembly::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'section' => AssemblySection::class,
            'quantity' => 'decimal:2',
        ];
    }

    /**
     * The warehouse item code wins. The part number is only used when that code is absent.
     *
     * @param  array<string, Part>  $partsByCode
     * @param  array<string, Part>  $partsByNumber
     */
    public function matchedPart(array $partsByCode, array $partsByNumber): ?Part
    {
        $code = $this->item_code;

        if (is_string($code) && $code !== '' && isset($partsByCode[$code])) {
            return $partsByCode[$code];
        }

        $number = $this->part_number;

        if (is_string($number) && $number !== '' && isset($partsByNumber[$number])) {
            return $partsByNumber[$number];
        }

        return null;
    }
}
