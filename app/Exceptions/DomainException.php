<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Base class for expected, business-rule failures (e.g. "plot is already sold",
 * "cheque already cleared").
 *
 * Convention: domain code throws a subclass of this; the exception handler
 * renders it as a 422 with the message, and Livewire components catch it to
 * flash a toast. Never use it for programmer errors — let those bubble.
 */
class DomainException extends RuntimeException
{
    public function __construct(string $message, protected int $status = 422)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public static function make(string $message, int $status = 422): static
    {
        return new static($message, $status);
    }
}
