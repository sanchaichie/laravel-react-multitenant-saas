<?php

namespace App\Actions\Auth;

use App\Models\Organization;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\DeviceName;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IssueToken
{
    public function __construct(private readonly Request $request) {}

    /**
     * A new API token for the user, labelled with the device it was issued to (the
     * client's device_name, or one read from the User-Agent), with the IP address and
     * User-Agent for the list of signed-in devices. Every way of logging in goes
     * through here, so later checks (two-factor, suspension) have one place to live.
     * $twoFactor: issued after a code from the authenticator app (not a recovery
     * code), which admin routes need recently (EnsurePlatformAdmin).
     *
     * @throws ValidationException a suspended user (each login path checks first; this
     *                             keeps a new one from forgetting)
     */
    public function handle(User $user, ?string $deviceName = null, bool $twoFactor = false): string
    {
        if ($user->isSuspended()) {
            throw ValidationException::withMessages(['email' => [User::SUSPENDED_MESSAGE]]);
        }

        $userAgent = $this->request->userAgent();

        $token = $user->createToken($deviceName ?: DeviceName::fromUserAgent($userAgent));
        $token->accessToken->forceFill([
            'ip_address' => $this->request->ip(),
            'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 509),
            'two_factor_at' => $twoFactor ? now() : null,
        ])->save();

        return $token->plainTextToken;
    }

    /**
     * A platform admin's read-only "view as user" session (StartImpersonation): a
     * token for $user that names the admin, looks at $organization and expires at
     * $expiresAt. Never two_factor_at, so it can't reach admin routes.
     *
     * @return array{0: string, 1: PersonalAccessToken} the plain token and its row
     */
    public function impersonation(User $user, User $admin, Organization $organization, CarbonInterface $expiresAt): array
    {
        $userAgent = $this->request->userAgent();

        $token = $user->createToken('Support view', ['*'], $expiresAt);
        /** @var PersonalAccessToken $accessToken */
        $accessToken = $token->accessToken;
        $accessToken->forceFill([
            'ip_address' => $this->request->ip(),
            'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 509),
            'impersonator_id' => $admin->id,
            'organization_id' => $organization->id,
        ])->save();

        return [$token->plainTextToken, $accessToken];
    }
}
