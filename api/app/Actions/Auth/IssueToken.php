<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Support\DeviceName;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class IssueToken
{
    public function __construct(private readonly Request $request) {}

    /**
     * A new API token for the user, labelled with the device it was issued to (the
     * client's device_name, or one read from the User-Agent), with the IP address and
     * User-Agent for the list of signed-in devices. Every way of logging in goes
     * through here, so later checks (two-factor, suspension) have one place to live.
     */
    public function handle(User $user, ?string $deviceName = null): string
    {
        $userAgent = $this->request->userAgent();

        $token = $user->createToken($deviceName ?: DeviceName::fromUserAgent($userAgent));
        $token->accessToken->forceFill([
            'ip_address' => $this->request->ip(),
            'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 509),
        ])->save();

        return $token->plainTextToken;
    }
}
