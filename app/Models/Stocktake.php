<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['public_id', 'name', 'status', 'payload'])]
class Stocktake extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDocument(): array
    {
        return $this->payload;
    }
}
