<?php

namespace App\Actions\Auth;

use App\Actions\Organizations\CreateOrganization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RegisterUser
{
    public function __construct(
        private readonly CreateOrganization $createOrganization,
        private readonly IssueToken $issueToken,
    ) {}

    /**
     * Create a user with a personal organization (they're its Owner, and it's
     * current), send the email verification link and issue their first API token.
     *
     * @param  array{name: string, email: string, password: string, device_name?: string|null}  $data
     * @return array{user: User, token: string}
     */
    public function handle(array $data): array
    {
        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            $this->createOrganization->handle($user, self::personalOrganizationName($user->name));

            return $user;
        });

        // Queued: inviting people needs a verified email (the 'verified' middleware).
        $user->sendEmailVerificationNotification();

        return ['user' => $user, 'token' => $this->issueToken->handle($user, $data['device_name'] ?? null)];
    }

    /**
     * "Ada's organization", shortening the name so the whole thing fits the
     * 255-character column (a name may be up to 255 characters itself).
     */
    private static function personalOrganizationName(string $userName): string
    {
        $suffix = "'s organization";

        return mb_substr($userName, 0, 255 - mb_strlen($suffix)).$suffix;
    }
}
