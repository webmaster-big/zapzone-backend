<?php

namespace App\Support;

class EscapeRoomException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }
}
