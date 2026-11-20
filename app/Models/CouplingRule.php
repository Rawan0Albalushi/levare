<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'from_end',
    'to_end',
    'series',
    'left_shaft',
    'right_shaft',
    'shaft_label',
    'description',
    'part_number',
    'old_part_number',
    'alternate_part_numbers',
    'description_left_shaft',
    'description_right_shaft',
    'wells',
    'position',
])]
class CouplingRule extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'alternate_part_numbers' => 'array',
        ];
    }

    public function shaft(): string
    {
        return $this->left_shaft.'×'.$this->right_shaft;
    }

    public function descriptionShaft(): ?string
    {
        if (! is_string($this->description_left_shaft) || ! is_string($this->description_right_shaft)) {
            return null;
        }

        $described = $this->description_left_shaft.'×'.$this->description_right_shaft;

        return $described === $this->shaft() ? null : $described;
    }
}
