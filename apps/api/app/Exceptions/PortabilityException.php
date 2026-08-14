<?php

namespace App\Exceptions;

use RuntimeException;

class PortabilityException extends RuntimeException
{
    public function __construct(public readonly string $issue)
    {
        parent::__construct($issue);
    }
}
