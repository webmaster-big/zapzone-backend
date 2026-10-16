<?php

namespace App\Exceptions;

use RuntimeException;

class SlotTakenException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('That space was just booked for this start time. Please pick another time.');
    }
}
