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
 * Mailable de salida utilizado por el puente de correo.
 *
 * Renderiza el cuerpo con una firma opcional, se encola por defecto y se procesa después del commit; un fallo posterior del envío no revierte automáticamente la persistencia ya confirmada.
 */
class OutboundMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Construye el mensaje que envía el servicio MailBridgeService.
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
     * Define remitente, destinatarios y asunto para Laravel Mail.
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
     * Selecciona las vistas HTML y texto que renderizan el mensaje.
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
     * Genera el encabezado Message-ID para el mensaje saliente.
     * El valor cambia entre instancias porque incorpora {@see uniqid()}.
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
     * Devuelve los adjuntos del mensaje, actualmente ninguno.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
