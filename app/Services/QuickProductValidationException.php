<?php

namespace App\Services;

use InvalidArgumentException;

class QuickProductValidationException extends InvalidArgumentException
{
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Quick product validation failed.');
    }
}
