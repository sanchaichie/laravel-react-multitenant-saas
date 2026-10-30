<?php

namespace App\Enums;

/**
 * pending (the job hasn't finished) → draft (waiting for review) → approved or
 * rejected; failed when it couldn't be generated.
 */
enum TaskSummaryStatus: string
{
    case Pending = 'pending';
    case Draft = 'draft';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Failed = 'failed';
}
