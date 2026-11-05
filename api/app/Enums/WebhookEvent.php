<?php

namespace App\Enums;

/**
 * What an endpoint can subscribe to. Ping (Send test event) isn't one: it goes to
 * the endpoint it's sent from, whatever it subscribes to.
 */
enum WebhookEvent: string
{
    case TaskCreated = 'task.created';
    case TaskUpdated = 'task.updated';
    case TaskDeleted = 'task.deleted';

    public function label(): string
    {
        return match ($this) {
            self::TaskCreated => 'Task created',
            self::TaskUpdated => 'Task updated',
            self::TaskDeleted => 'Task deleted',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $event) => $event->value, self::cases());
    }
}
