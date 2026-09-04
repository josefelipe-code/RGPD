<?php

namespace App\Mail;

use App\Models\MailAccount;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

class OutboundMail extends Mailable
{
    /**
     * Build the operational outbound message with all delivery headers explicit.
     *
     * @param  array<int, string>  $ccAddresses
     * @param  array<int, string>  $bccAddresses
     * @param  array<int, string>  $references
     */
    public function __construct(
        public MailAccount $account,
        public string $recipientEmail,
        public string $mailSubject,
        public string $mailBody,
        public string $messageId,
        public array $ccAddresses = [],
        public array $bccAddresses = [],
        public ?string $inReplyTo = null,
        public array $references = [],
        public ?string $signature = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->account->email_address, $this->account->label ?? $this->account->email_address),
            to: [new Address($this->recipientEmail)],
            subject: $this->mailSubject,
            cc: array_map(fn (string $email) => new Address($email), $this->ccAddresses),
            bcc: array_map(fn (string $email) => new Address($email), $this->bccAddresses),
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'mail.outbound',
            text: 'mail.outbound-text',
            with: [
                'body' => $this->mailBody,
                'signature' => $this->signature,
            ],
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            messageId: $this->messageId,
            references: $this->references,
            text: $this->inReplyTo === null ? [] : ['In-Reply-To' => $this->inReplyTo],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
