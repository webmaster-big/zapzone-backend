<?php

namespace App\Services;

class ResendException extends \RuntimeException
{
    public const RECIPIENT = 'recipient';

    public const TRANSIENT = 'transient';

    public const ACCOUNT = 'account';

    public function __construct(
        string $message,
        public readonly int $status,
        public readonly string $errorName,
        public readonly string $scope,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $status, $previous);
    }

    public function stopsSending(): bool
    {
        return $this->scope !== self::RECIPIENT;
    }

    public function isTransient(): bool
    {
        return $this->scope === self::TRANSIENT;
    }
}
