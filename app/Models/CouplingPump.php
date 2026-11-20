<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'name',
    'series',
    'shaft',
    'kind',
    'position',
])]
class CouplingPump extends Model
{
    /**
     * @return array<string, mixed>
     */
    public function toDocument(): array
    {
        return [
            'name' => $this->name,
            'series' => $this->series,
            'shaft' => $this->shaft,
            'kind' => $this->kind,
        ];
    }
}
