<?php

namespace App\Services\Bandeja;

use App\Enums\MailDirection;
use App\Enums\MailMessageStatus;
use App\Mail\OutboundMail;
use App\Models\Expedient;
use App\Models\MailAccount;
use App\Models\MailMessage;
use App\Models\User;
use App\Services\MailAccountConfigService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Transactional send pipeline for the mail bridge.
 *
 * Wraps SMTP send + outgoing MailMessage persistence + expedient transition
 * en una única transacción DB. Si SMTP falla, no se persiste nada (S16/S19).
 */
class MailBridgeService
{
    /** Recibe las dependencias usadas para enviar y registrar correos de expedientes. */
    public function __construct(
        protected MailAccountConfigService $configService,
    ) {}

    /**
     * Send outbound mail and transition the expedient.
     *
     * @param  'reply_client'|'forward_provider'  $mode
     * @param  array{to?: string, body: string, subject: string, cc?: array<int,string>, bcc?: array<int,string>, state_deadline?: string|null, template_id?: int}  $payload
     */
    /** Envía y registra un correo desde el compositor del expediente. */
    public function send(
        MailAccount $account,
        string $mode,
        MailMessage $origin,
        Expedient $expedient,
        User $actor,
        array $payload,
    ): MailMessage {
        if ($account->id !== $expedient->mail_account_id || $origin->case_id !== $expedient->id || $origin->mail_account_id !== $account->id) {
            throw new \LogicException('The mail account or source message does not belong to this expedient.');
        }

        if ($account->user_id !== $actor->id) {
            throw new AuthorizationException('You do not own this mail account.');
        }

        match ($mode) {
            'reply_client' => $expedient->assertCanReplyClient(),
            'forward_provider' => $expedient->assertCanForwardProvider(),
            default => throw new \InvalidArgumentException('Invalid expedient mail mode.'),
        };

        // La closure mantiene juntos el envío y el registro del mensaje del expediente.
        return DB::transaction(function () use ($account, $mode, $origin, $expedient, $actor, $payload) {
            $to = $mode === 'reply_client'
                ? ($payload['to'] ?? $origin->from_email ?? $expedient->sender_email)
                : ($payload['to'] ?? throw new \InvalidArgumentException('Forward requires "to" recipient'));

            $subject = $payload['subject'] ?? $origin->subject ?? '';
            $body = $payload['body'] ?? '';
            $cc = $payload['cc'] ?? [];
            $bcc = $payload['bcc'] ?? [];

            // Registra el mailer SMTP dinámico de esta cuenta.
            $mailerName = $this->configService->registerSmtpMailer($account);

            // Send the mail (may throw — transaction will rollback)
            Mail::mailer($mailerName)->send(new OutboundMail(
                account: $account,
                recipientEmail: $to,
                mailSubject: $subject,
                mailBody: $body,
                mailSignature: null, // Signature appended by composer if needed
                ccAddresses: $cc,
                bccAddresses: $bcc,
            ));

            // Generate our own Message-ID for threading (D6)
            $domain = parse_url(config('app.url'), PHP_URL_HOST) ?? 'localhost';
            $messageId = sha1($account->id.$expedient->id.uniqid()).'@'.$domain;

            // Record outgoing MailMessage
            $outgoing = MailMessage::create([
                'case_id' => $expedient->id,
                'mail_account_id' => $account->id,
                'direction' => MailDirection::Outgoing,
                'status' => MailMessageStatus::Associated,
                'to_email' => $to,
                'subject' => $subject,
                'from_email' => $account->email_address,
                'from_name' => $account->label ?? $account->email_address,
                'body_html' => $body,
                'body_text' => strip_tags($body),
                'sent_at' => now(),
                'received_at' => now(),
                'message_id' => $messageId,
                'in_reply_to' => $origin->message_id,
                'cc' => $cc,
                'bcc' => $bcc,
            ]);

            // Transition expedient and create milestone with mail_message_id link
            if ($mode === 'reply_client') {
                $expedient->replyClient($outgoing, $actor);
                $expedient->updateDeadline(
                    $actor,
                    filled($payload['state_deadline'] ?? null) ? Carbon::parse($payload['state_deadline']) : null,
                );
            } else {
                $expedient->forwardProvider(
                    $outgoing,
                    $actor,
                    filled($payload['state_deadline'] ?? null) ? Carbon::parse($payload['state_deadline']) : null,
                );
            }

            return $outgoing;
        });
    }
}
