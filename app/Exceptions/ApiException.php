<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A business-rule failure, with the HTTP status it should surface as in the native
 * exception code.
 *
 * Services throw this instead of calling abort() or building a response, so they stay
 * callable from a Job or a console command. The mapping to JSON lives in one place,
 * in bootstrap/app.php.
 */
class ApiException extends RuntimeException
{
    public function __construct(string $message, int $status = 409)
    {
        parent::__construct($message, $status);
    }
}
