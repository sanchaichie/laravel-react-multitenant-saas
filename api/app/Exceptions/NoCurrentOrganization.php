<?php

namespace App\Exceptions;

use RuntimeException;

class NoCurrentOrganization extends RuntimeException
{
    public static function forCreating(string $model): self
    {
        return new self("Cannot create [{$model}] without a current organization.");
    }
}
