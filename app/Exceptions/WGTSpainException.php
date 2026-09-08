<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The single exception for every business-rule failure in this project.
 *
 * Carries the HTTP status in the native exception code and any extra detail in $context,
 * so there is no need for a class per error type. Services throw this instead of calling
 * abort() or building a response, which keeps them callable from a Job or a console
 * command; the mapping to JSON lives in one place, in bootstrap/app.php.
 */
class WGTSpainException extends RuntimeException
{
    /** @param array<string, mixed> $context Extra detail for the response and the logs. */
    public function __construct(string $message, int $code = 409, public readonly array $context = [])
    {
        parent::__construct($message, $code);
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return $this->context;
    }
}
