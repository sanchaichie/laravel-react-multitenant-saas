<?php

namespace App\Actions\Auth;

use App\Models\User;

class RegisterUser
{
    public function __construct(private readonly IssueToken $issueToken) {}

    /**
     * Create a user and issue their first API token.
     *
     * @param  array{name: string, email: string, password: string, device_name?: string|null}  $data
     * @return array{user: User, token: string}
     */
    public function handle(array $data): array
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        return ['user' => $user, 'token' => $this->issueToken->handle($user, $data['device_name'] ?? null)];
    }
}
