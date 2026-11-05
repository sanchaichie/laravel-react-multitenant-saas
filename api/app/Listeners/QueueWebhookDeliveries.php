<?php

namespace App\Listeners;

use App\Actions\Webhooks\QueueDelivery;
use App\Enums\WebhookEvent;
use App\Events\TaskCreated;
use App\Events\TaskDeleted;
use App\Events\TasksUnassigned;
use App\Events\TaskUpdated;
use App\Models\Organization;
use App\Models\Task;
use App\Models\WebhookEndpoint;
use App\Support\CurrentOrganization;
use App\Webhooks\WebhookPayload;
use Closure;
use Illuminate\Support\Collection;

/**
 * Task events → one delivery per active endpoint subscribed to them. Runs in the
 * request after the change commits; it only writes rows and queues jobs. Under
 * rescue(): a failure here is reported, never turned into a failed task change.
 * Organizations without webhooks cost one query per event.
 */
class QueueWebhookDeliveries
{
    private const CHUNK = 200;

    public function __construct(
        private readonly QueueDelivery $queueDelivery,
        private readonly CurrentOrganization $currentOrganization,
    ) {}

    public function handleTaskCreated(TaskCreated $event): void
    {
        $this->forEndpoints($event->organizationId, WebhookEvent::TaskCreated, function (Collection $endpoints) use ($event) {
            $task = Task::query()->find($event->taskId);

            if ($task !== null) {
                $this->queueDelivery->toEndpoints($endpoints, WebhookEvent::TaskCreated->value, ['task' => WebhookPayload::task($task)]);
            }
        });
    }

    public function handleTaskUpdated(TaskUpdated $event): void
    {
        $this->forEndpoints($event->organizationId, WebhookEvent::TaskUpdated, function (Collection $endpoints) use ($event) {
            $task = Task::query()->find($event->taskId);

            if ($task !== null) {
                $this->queueDelivery->toEndpoints($endpoints, WebhookEvent::TaskUpdated->value, ['task' => WebhookPayload::task($task), 'changed' => $event->changed]);
            }
        });
    }

    /**
     * A task.updated per task, loaded in chunks with their (now empty) assignee.
     */
    public function handleTasksUnassigned(TasksUnassigned $event): void
    {
        $this->forEndpoints($event->organizationId, WebhookEvent::TaskUpdated, function (Collection $endpoints) use ($event) {
            foreach (array_chunk($event->taskIds, self::CHUNK) as $ids) {
                Task::query()->whereKey($ids)->with('assignee:id,name')->orderBy('id')->get()->each(
                    fn (Task $task) => $this->queueDelivery->toEndpoints($endpoints, WebhookEvent::TaskUpdated->value, [
                        'task' => WebhookPayload::task($task),
                        'changed' => ['assignee'],
                    ]),
                );
            }
        });
    }

    public function handleTaskDeleted(TaskDeleted $event): void
    {
        $this->forEndpoints($event->organizationId, WebhookEvent::TaskDeleted, function (Collection $endpoints) use ($event) {
            $this->queueDelivery->toEndpoints($endpoints, WebhookEvent::TaskDeleted->value, ['task' => $event->task]);
        });
    }

    /**
     * Run $queue with the organization's active endpoints subscribed to $event, inside
     * that organization; nothing at all when there are none.
     *
     * @param  Closure(Collection<int, WebhookEndpoint>): void  $queue
     */
    private function forEndpoints(int $organizationId, WebhookEvent $event, Closure $queue): void
    {
        $organization = Organization::find($organizationId);

        if ($organization === null) {
            return;
        }

        rescue(fn () => $this->currentOrganization->run($organization, function () use ($event, $queue) {
            $endpoints = WebhookEndpoint::query()
                ->whereNull('disabled_at')
                ->whereJsonContains('events', $event->value)
                ->orderBy('id')
                ->get();

            if ($endpoints->isNotEmpty()) {
                $queue($endpoints);
            }
        }));
    }
}
