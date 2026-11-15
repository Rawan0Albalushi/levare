<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'part_id',
    'part_location_id',
    'location',
    'action',
    'quantity_before',
    'quantity_after',
])]
class PartQuantityAdjustment extends Model
{
    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function partLocation(): BelongsTo
    {
        return $this->belongsTo(PartLocation::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_before' => 'decimal:2',
            'quantity_after' => 'decimal:2',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDocument(): array
    {
        return [
            'id' => $this->id,
            'at' => $this->created_at?->toIso8601String(),
            'location' => $this->location,
            'action' => $this->action,
            'before' => Part::quantityNumber($this->quantity_before),
            'after' => Part::quantityNumber($this->quantity_after),
        ];
    }
}
