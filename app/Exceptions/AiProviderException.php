<?php

namespace App\Exceptions;

use RuntimeException;

/** A failed call to an AI provider, carrying that provider's error code ("vertex_http_429", "openai_http_429"). */
class AiProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }

    /** The provider's per-minute quota is used up; the same call can succeed after a wait. */
    public function rateLimited(): bool
    {
        return str_ends_with($this->errorCode, '_http_429');
    }

    /** The connection failed or timed out with no answer, so nothing was billed. */
    public function connectionFailed(): bool
    {
        return str_ends_with($this->errorCode, '_connection_error');
    }
}
