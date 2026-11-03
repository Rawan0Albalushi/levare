<?php

namespace App\Models;

use Database\Factories\EquipmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'port_number',
    'serial_number',
    'description',
    'assigned_well',
    'recorded_on',
    'location',
    'condition',
])]
class Equipment extends Model
{
    /** @use HasFactory<EquipmentFactory> */
    use HasFactory;

    protected $table = 'equipment';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recorded_on' => 'date',
            'tested_at' => 'date',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function scopeFiltered(Builder $query, array $filters, bool $status = true): Builder
    {
        $this->whereContains($query, 'port_number', $filters['port_number'] ?? null);
        $this->whereContains($query, 'serial_number', $filters['serial_number'] ?? null);
        $this->whereContains($query, 'description', $filters['description'] ?? null);
        $this->whereContains($query, 'assigned_well', $filters['assigned_well'] ?? null);
        $this->whereContains($query, 'location', $filters['location'] ?? null);

        if (! empty($filters['date_from'])) {
            $query->whereDate('recorded_on', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('recorded_on', '<=', $filters['date_to']);
        }

        if (! empty($filters['tested_from'])) {
            $query->whereDate('tested_at', '>=', $filters['tested_from']);
        }

        if (! empty($filters['tested_to'])) {
            $query->whereDate('tested_at', '<=', $filters['tested_to']);
        }

        if ($status && ! empty($filters['condition'])) {
            $query->where('condition', $filters['condition']);
        }

        if ($status && ($filters['tested'] ?? null) === 'yes') {
            $query->whereNotNull('tested_at');
        }

        if ($status && ($filters['tested'] ?? null) === 'no') {
            $query->whereNull('tested_at');
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    public function toDocument(): array
    {
        return [
            'id' => $this->id,
            'port_number' => $this->port_number,
            'serial_number' => $this->serial_number,
            'description' => $this->description,
            'assigned_well' => $this->assigned_well,
            'recorded_on' => $this->recorded_on?->toDateString(),
            'location' => $this->location,
            'condition' => $this->condition,
            'tested_at' => $this->tested_at?->toDateString(),
        ];
    }

    private function whereContains(Builder $query, string $column, mixed $value): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $escaped = addcslashes(trim($value), '\\%_');
        $query->whereRaw($column.' like ? escape ?', ['%'.$escaped.'%', '\\']);
    }
}
