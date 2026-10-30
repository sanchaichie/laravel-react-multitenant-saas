<?php

namespace App\Ai;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Cache;

/**
 * Limits how many streamed answers run at once: per organization
 * (ai.concurrent_streams), per user (ai.concurrent_streams_per_user, so one person
 * can't add organizations to get more) and on the whole server
 * (ai.max_concurrent_streams, kept below the PHP workers). Each stream holds a PHP
 * worker until it ends, which the per-minute rate limits alone don't bound.
 */
class AiStreamSlots
{
    /**
     * Take a free slot of each kind; release the returned slot when the stream ends.
     * A slot that is never released frees itself after twice the stream timeout (a
     * stalled provider can hold one read for the whole timeout).
     *
     * @throws ThrottleRequestsException 429 when a kind has no free slot
     */
    public function acquire(Organization $organization, User $user): AiStreamSlot
    {
        $taken = [];

        try {
            $taken[] = $this->take("ai-stream:{$organization->id}", config('ai.concurrent_streams'),
                'Too many AI answers are being written for this organization. Try again when one finishes.');
            $taken[] = $this->take("ai-stream:user:{$user->id}", config('ai.concurrent_streams_per_user'),
                'You already have AI answers being written. Try again when one finishes.');
            $taken[] = $this->take('ai-stream:all', config('ai.max_concurrent_streams'),
                'The AI is busy right now. Try again in a moment.');
        } catch (ThrottleRequestsException $e) {
            array_walk($taken, fn (Lock $lock) => $lock->release());

            throw $e;
        }

        return new AiStreamSlot($taken);
    }

    private function take(string $prefix, int $slots, string $message): Lock
    {
        $seconds = 2 * config('ai.stream_timeout') + 30;

        for ($slot = 0; $slot < $slots; $slot++) {
            $lock = Cache::lock("{$prefix}:{$slot}", $seconds);

            if ($lock->get()) {
                return $lock;
            }
        }

        throw new ThrottleRequestsException($message);
    }
}
