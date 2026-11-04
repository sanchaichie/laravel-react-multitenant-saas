<?php

namespace App\Actions\Admin\Concerns;

trait SearchesText
{
    /**
     * An ILIKE pattern matching $text anywhere, with \, % and _ taken literally.
     */
    private function containsPattern(string $text): string
    {
        return '%'.addcslashes($text, '\\%_').'%';
    }
}
