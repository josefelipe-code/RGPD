<?php

namespace App\Services\Bandeja;

use App\Enums\MailDirection;
use App\Enums\MailMessageStatus;
use App\Mail\ImapOutboundMail;
use App\Models\MailAccount;
use App\Models\MailMessage;
use App\Services\MailAccountConfigService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class InboxOutboundMailService
{
    public function __construct(private readonly MailAccountConfigService $configService) {}

    /**
     * @param  array{message_id?: string|null, references?: string|array<int, string>|null}  $origin
     */
    public function send(MailAccount $account, string $mode, string $recipient, array $cc, array $bcc, string $subject, string $body, array $origin, ?string $signature = null): MailMessage
    {
        $messageId = $this->messageId($account);
        $inReplyTo = $mode === 'reply' ? $origin['message_id'] ?? null : null;
        $references = $mode === 'reply' ? $this->references($origin['references'] ?? null, $inReplyTo) : [];
        $mailerName = $this->configService->registerSmtpMailer($account);

        Mail::mailer($mailerName)->send(new ImapOutboundMail(
            account: $account,
            recipientEmail: $recipient,
            ccRecipients: $cc,
            bccRecipients: $bcc,
            mailSubject: $subject,
            mailBody: $body,
            signature: $signature,
            messageId: $messageId,
            inReplyTo: $inReplyTo,
            references: $references,
        ));

        return MailMessage::create([
            'mail_account_id' => $account->id,
            'message_id' => $messageId,
            'to_email' => $recipient,
            'cc' => $cc,
            'bcc' => $bcc,
            'subject' => $subject,
            'from_email' => $account->email_address,
            'from_name' => $account->label ?? $account->email_address,
            'received_at' => now(),
            'sent_at' => now(),
            'direction' => MailDirection::Outgoing,
            'status' => MailMessageStatus::New,
            'in_reply_to' => $inReplyTo,
            'references' => $references,
        ]);
    }

    private function messageId(MailAccount $account): string
    {
        $domain = parse_url(config('app.url'), PHP_URL_HOST) ?: 'localhost';

        return sprintf('%s@%s', Str::uuid(), $domain);
    }

    /**
     * @return array<int, string>
     */
    private function references(string|array|null $references, ?string $inReplyTo): array
    {
        $values = is_array($references)
            ? $references
            : preg_split('/\s+/', (string) $references, -1, PREG_SPLIT_NO_EMPTY);

        if ($inReplyTo !== null) {
            $values[] = $inReplyTo;
        }

        return array_values(array_unique($values));
    }
}
