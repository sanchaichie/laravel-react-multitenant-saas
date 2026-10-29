<?php

namespace App\Enums;

enum TaskPriority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** For sorting: higher is more urgent. */
    public function rank(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
        };
    }
}
