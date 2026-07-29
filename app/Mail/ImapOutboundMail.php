<?php

namespace App\Mail;

use App\Models\MailAccount;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

class ImapOutboundMail extends Mailable
{
    /**
     * @param  array<int, string>  $references
     */
    public function __construct(
        public MailAccount $account,
        public string $recipientEmail,
        /** @var array<int, string> */
        public array $ccRecipients,
        /** @var array<int, string> */
        public array $bccRecipients,
        public string $mailSubject,
        public string $mailBody,
        public string $messageId,
        public ?string $inReplyTo = null,
        public array $references = [],
        public ?string $signature = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->account->email_address, $this->account->label ?? $this->account->email_address),
            to: [new Address($this->recipientEmail)],
            cc: $this->addresses($this->ccRecipients),
            bcc: $this->addresses($this->bccRecipients),
            subject: $this->mailSubject,
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

    /**
     * @param  array<int, string>  $recipients
     * @return array<int, Address>
     */
    private function addresses(array $recipients): array
    {
        return array_map(fn (string $recipient): Address => new Address($recipient), $recipients);
    }
}
