<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'part_issue_id',
    'part_id',
    'position',
    'item_code',
    'part_number',
    'description',
    'uom',
    'quantity',
])]
class PartIssueLine extends Model
{
    public function issue(): BelongsTo
    {
        return $this->belongsTo(PartIssue::class, 'part_issue_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PartIssueAllocation::class)->orderBy('id');
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
