<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['part_issue_line_id', 'part_location_id', 'location', 'quantity'])]
class PartIssueAllocation extends Model
{
    public function line(): BelongsTo
    {
        return $this->belongsTo(PartIssueLine::class, 'part_issue_line_id');
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
