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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Transactional send pipeline for the mail bridge.
 *
 * Wraps SMTP send + outgoing MailMessage persistence + expedient transition
 * in a single DB::transaction. If SMTP throws, nothing persists (S16/S19).
 */
class MailBridgeService
{
    public function __construct(
        protected MailAccountConfigService $configService,
    ) {}

    /**
     * Send outbound mail and transition the expedient.
     *
     * @param  'reply_client'|'forward_provider'  $mode
     * @param  array{to?: string, body: string, subject: string, cc?: array<int,string>, bcc?: array<int,string>, template_id?: int}  $payload
     */
    public function send(
        MailAccount $account,
        string $mode,
        MailMessage $origin,
        Expedient $expedient,
        User $actor,
        array $payload,
    ): MailMessage {
        return DB::transaction(function () use ($account, $mode, $origin, $expedient, $actor, $payload) {
            $to = $mode === 'reply_client'
                ? ($payload['to'] ?? $origin->from_email ?? $expedient->sender_email)
                : ($payload['to'] ?? throw new \InvalidArgumentException('Forward requires "to" recipient'));

            $subject = $payload['subject'] ?? $origin->subject ?? '';
            $body = $payload['body'] ?? '';
            $cc = $payload['cc'] ?? [];
            $bcc = $payload['bcc'] ?? [];

            // Register dynamic SMTP mailer for this account
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
            } else {
                $expedient->forwardProvider($outgoing, $actor);
            }

            return $outgoing;
        });
    }
}
