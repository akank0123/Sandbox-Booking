<?php

namespace App\Exceptions;

use RuntimeException;

/** A business-rule rejection with a machine-readable code and HTTP status. */
class BookingRejected extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
