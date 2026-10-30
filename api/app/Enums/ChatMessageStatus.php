<?php

namespace App\Enums;

/**
 * A question is always complete. An answer is stopped until its stream ends (also
 * what a worker that dies mid-answer leaves), then complete, stopped (the user
 * cancelled; the text so far) or failed.
 */
enum ChatMessageStatus: string
{
    case Complete = 'complete';
    case Stopped = 'stopped';
    case Failed = 'failed';
}
