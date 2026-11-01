<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'part_id',
    'position',
    'location',
    'quantity',
    'quantity_raw',
    'updated_on',
    'condition',
    'status',
    'reference',
])]
class PartLocation extends Model
{
    public $timestamps = false;

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
        ];
    }
}
