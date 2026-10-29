<?php

namespace Database\Factories;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Create tasks inside inOrganization($org, fn () => ...): BelongsToOrganization
 * sets organization_id from the current organization.
 *
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'status' => TaskStatus::Todo,
            'priority' => TaskPriority::Medium,
            'due_date' => null,
        ];
    }

    public function overdue(): static
    {
        return $this->state(['due_date' => today()->subDays(2)->toDateString()]);
    }

    public function done(): static
    {
        return $this->state(['status' => TaskStatus::Done]);
    }

    public function assignedTo(User $user): static
    {
        return $this->state(['assignee_id' => $user->id]);
    }

    public function createdBy(User $user): static
    {
        return $this->state(fn () => ['created_by' => $user->id]);
    }
}
