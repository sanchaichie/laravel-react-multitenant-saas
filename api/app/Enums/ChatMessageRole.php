<?php

namespace App\Enums;

/**
 * Who wrote a chat message: the person, or the AI's answer.
 */
enum ChatMessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
}
