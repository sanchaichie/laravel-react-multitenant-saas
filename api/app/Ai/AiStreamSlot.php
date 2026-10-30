<?php

namespace App\Ai;

use Illuminate\Contracts\Cache\Lock;

/**
 * The slots one stream holds (AiStreamSlots): released together when it ends.
 */
final class AiStreamSlot
{
    /**
     * @param  list<Lock>  $locks
     */
    public function __construct(private readonly array $locks) {}

    public function release(): void
    {
        foreach ($this->locks as $lock) {
            $lock->release();
        }
    }
}
