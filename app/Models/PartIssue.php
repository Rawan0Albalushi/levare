<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['assembly_part_number', 'assembly_description'])]
class PartIssue extends Model
{
    public function lines(): HasMany
    {
        return $this->hasMany(PartIssueLine::class)->orderBy('position');
    }

    /**
     * @return array<string, mixed>
     */
    public function toDocument(): array
    {
        return [
            'id' => $this->id,
            'at' => $this->created_at?->toIso8601String(),
            'lines' => $this->lines->map(fn (PartIssueLine $line): array => [
                'n' => $line->position,
                'pn' => $line->part_number ?? '',
                'id' => $line->item_code ?? '',
                'd' => $line->description,
                'u' => $line->uom ?? '',
                'qty' => Part::quantityNumber($line->quantity),
                'locations' => $line->allocations->map(fn (PartIssueAllocation $allocation): array => [
                    'loc' => $allocation->location,
                    'qty' => Part::quantityNumber($allocation->quantity),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
