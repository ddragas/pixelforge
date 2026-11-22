<?php

namespace App\Pixelforge;

use RuntimeException;

/**
 * An error answer from Pixelforge, or no answer at all.
 */
class PixelforgeException extends RuntimeException
{
    /** Codes that mean "nothing was created, try again later". */
    private const TRANSIENT = ['rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded', 'connection_failed', 'server_error'];

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 0,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public function isTransient(): bool
    {
        return in_array($this->errorCode, self::TRANSIENT, true);
    }
}
