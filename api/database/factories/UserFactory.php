<?php

namespace Database\Factories;

use App\Models\User;
use App\Support\RecoveryCodes;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Two-factor on, with a known secret and two recovery codes.
     *
     * @param  list<string>  $recoveryCodes
     */
    public function withTwoFactor(string $secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP', array $recoveryCodes = ['aaaaa-bbbbb', 'ccccc-ddddd']): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => RecoveryCodes::hashAll($recoveryCodes),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * A platform admin (in the app, only `admin:grant` sets it). Admin routes also
     * need two-factor: combine with withTwoFactor(), or use platformAdmin() in tests.
     */
    public function platformAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_platform_admin' => true,
        ]);
    }
}
