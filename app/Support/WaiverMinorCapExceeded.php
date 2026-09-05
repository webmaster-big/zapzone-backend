<?php

namespace App\Support;

use RuntimeException;

class WaiverMinorCapExceeded extends RuntimeException
{
    public function __construct(public readonly int $cap)
    {
        parent::__construct('This waiver covers up to ' . $cap . ' dependents per signer.');
    }
}
