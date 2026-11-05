<?php

namespace App\Enums;

enum WebhookDeliveryStatus: string
{
    // Waiting for its first or next attempt.
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    // Every attempt failed.
    case Failed = 'failed';
    // Not sent: the endpoint was disabled, deleted or unsubscribed before it went out.
    case Canceled = 'canceled';
}
