<?php

namespace App\Enums;

/**
 * What AI writing help does with a task's description.
 */
enum WritingHelpMode: string
{
    case Improve = 'improve';
    case Expand = 'expand';
    case Shorten = 'shorten';
}
