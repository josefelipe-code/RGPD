<?php

namespace App\Mail;

use App\Models\MailAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * Outbound mailable for the mail bridge.
 *
 * Renders a template body with optional signature appended.
 * Generates a deterministic Message-ID for threading (D6).
 * Implements ShouldQueue so all dispatches are queued by default.
 */
class OutboundMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @param  array<int, string>  $cc
     * @param  array<int, string>  $bcc
     */
    public function __construct(
        public MailAccount $account,
        public string $recipientEmail,
        public string $mailSubject,
        public string $mailBody,
        public ?string $mailSignature = null,
        public array $ccAddresses = [],
        public array $bccAddresses = [],
    ) {
        $this->afterCommit();
    }

    /**
     * Get the message envelope.
     */
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

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            html: 'mail.outbound',
            text: 'mail.outbound-text',
            with: [
                'body' => $this->mailBody,
                'signature' => $this->mailSignature,
            ],
        );
    }

    /**
     * Get the message headers.
     */
    public function headers(): Headers
    {
        $domain = parse_url(config('app.url'), PHP_URL_HOST) ?? 'localhost';
        $messageId = sha1($this->account->id.$this->recipientEmail.uniqid()).'@'.$domain;

        return new Headers(
            messageId: $messageId,
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
