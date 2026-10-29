<?php

namespace Database\Factories;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
        ];
    }

    /**
     * Add the user as a member with the given role.
     */
    public function withMember(User $user, OrganizationRole $role = OrganizationRole::Member): static
    {
        return $this->afterCreating(fn (Organization $organization) => $organization->members()->attach($user, ['role' => $role]));
    }
}
