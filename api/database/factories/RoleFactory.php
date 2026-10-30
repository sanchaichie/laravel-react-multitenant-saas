<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Create inside the organization (inOrganization() in tests): Role is tenant-owned.
 *
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->word()),
            'permissions' => [],
        ];
    }
}
