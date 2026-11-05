<?php

namespace App\Webhooks;

use RuntimeException;

/**
 * A webhook URL the server won't call. The message is shown to the user (422 on
 * url) and kept as a failed delivery's error.
 */
class UnsafeWebhookUrl extends RuntimeException {}
