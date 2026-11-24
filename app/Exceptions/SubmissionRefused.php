<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A submission FormHaven will not take, with the HTTP status, the sentence the visitor
 * reads, and a code plus field in the shape Formspree uses, so JavaScript written for
 * Formspree's errors array keeps working after the switch.
 */
class SubmissionRefused extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly string $errorCode = 'REFUSED',
        public readonly ?string $field = null,
    ) {
        parent::__construct($message);
    }
}
