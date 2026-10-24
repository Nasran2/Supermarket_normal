<?php

namespace App\Exceptions;

use RuntimeException;

class DemoBaselineMismatchException extends RuntimeException
{
    public function __construct(string $message, public readonly array $state)
    {
        parent::__construct($message);
    }
}
