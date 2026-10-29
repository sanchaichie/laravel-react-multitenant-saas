<x-mail::message>
# You're invited to {{ $organizationName }}

{{ $inviterName }} invited you to join **{{ $organizationName }}** as {{ $roleName }}.

<x-mail::button :url="$url">
Accept the invitation
</x-mail::button>

Log in, or create an account with this email address, to accept. The link expires on {{ $expiresAt }}.

If you weren't expecting this, you can ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
