<?php

namespace App\Actions;

use RuntimeException;

class InsufficientAssemblyStock extends RuntimeException
{
    /**
     * @param  list<array{n: int, need: int|float, have: int|float}>  $short
     */
    public function __construct(public readonly array $short)
    {
        parent::__construct('Some lines do not have enough stock.');
    }
}
