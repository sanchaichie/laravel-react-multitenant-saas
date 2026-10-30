<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Task;
use App\Models\User;
use App\Support\CurrentOrganization;

/**
 * Tasks of the current organization. The task was found through the organization
 * scope before these run, so they never check organization_id. Permissions plus
 * who created or is assigned the task, never role names.
 */
class TaskPolicy
{
    public function __construct(private readonly CurrentOrganization $currentOrganization) {}

    public function viewAny(User $user): bool
    {
        return $this->isMember();
    }

    public function view(User $user, Task $task): bool
    {
        return $this->isMember();
    }

    public function create(User $user): bool
    {
        return $this->currentOrganization->can(Permission::TasksCreate);
    }

    // The assignee may edit too: that's how a member moves their task to done. A role
    // without tasks.create (say, a viewer) only reads, even tasks assigned to them.
    public function update(User $user, Task $task): bool
    {
        return $this->currentOrganization->can(Permission::TasksManage)
            || ($this->currentOrganization->can(Permission::TasksCreate) && in_array($user->id, [$task->created_by, $task->assignee_id], true));
    }

    // AI writing help for a task's description: in the create form (no task) or the
    // edit form of one you may edit. It's AI work, so it also needs ai.use.
    public function writeWithAi(User $user, ?Task $task = null): bool
    {
        return $this->currentOrganization->can(Permission::AiUse)
            && ($task === null ? $this->create($user) : $this->update($user, $task));
    }

    // Your own private AI chat about a task you can see (reading, asking, clearing).
    public function chat(User $user, Task $task): bool
    {
        return $this->view($user, $task) && $this->currentOrganization->can(Permission::AiUse);
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->currentOrganization->can(Permission::TasksManage)
            || ($this->currentOrganization->can(Permission::TasksCreate) && $task->created_by === $user->id);
    }

    private function isMember(): bool
    {
        return $this->currentOrganization->role() !== null;
    }
}
