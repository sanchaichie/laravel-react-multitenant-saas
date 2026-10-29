<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Ada invited you to join Acme". Takes plain values, so the queued mail needs
 * nothing from the database. The link carries the only copy of the token.
 */
class OrganizationInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public readonly string $organizationName,
        public readonly string $inviterName,
        public readonly string $roleName,
        public readonly string $url,
        public readonly string $expiresAt,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->inviterName} invited you to join {$this->organizationName}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.organization-invitation');
    }
}
