<?php

namespace App\Exceptions;

use RuntimeException;

class NoCurrentOrganization extends RuntimeException
{
    public static function forCreating(string $model): self
    {
        return new self("Cannot create [{$model}] without a current organization.");
    }

    public static function forAiCall(): self
    {
        return new self('Cannot call AI without a current organization: nobody would be billed.');
    }
}
