<?php

namespace App\Services\Bandeja;

use App\Enums\MailDirection;
use App\Enums\MailMessageStatus;
use App\Mail\OutboundMail;
use App\Models\ImapMessageOperationReservation;
use App\Models\MailAccount;
use App\Models\MailMessage;
use App\Services\MailAccountConfigService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class OutboundMailService
{
    public function __construct(
        private readonly MailAccountConfigService $configService,
        private readonly ImapMessageOperationReservationService $reservationService,
    ) {}

    /**
     * Validate the source, lifecycle and reservation before the composer is shown.
     *
     * @return array{context: OutboundMailContext, reservation: ImapMessageOperationReservation}
     */
    public function prepare(OutboundMailContext $context, bool $applyDefaults = true): array
    {
        $preparedContext = $this->prepareContext($context, $applyDefaults);

        return [
            'context' => $preparedContext,
            'reservation' => $this->reservationService->acquire(
                $context->account,
                $context->actor,
                $context->folder,
                $context->imapUid,
            ),
        ];
    }

    public function send(OutboundMailContext $context): MailMessage
    {
        $context = $this->prepareContext($context, applyDefaults: false);
        $recipients = $this->normalizeRecipients($context);
        $this->assertSendable($context);

        return DB::transaction(function () use ($context, $recipients): MailMessage {
            $this->authorizeContext($context);
            $origin = $this->resolveOrigin($context);
            $this->assertLifecycle($context);
            $this->assertDeadline($context);
            $this->reservationService->assertHeldBy(
                $context->account,
                $context->actor,
                $context->folder,
                $context->imapUid,
            );

            [$inReplyTo, $references] = $this->threading($context, $origin);
            $messageId = $this->messageId($context->account);
            $mailerName = $this->configService->registerSmtpMailer($context->account);

            Mail::mailer($mailerName)->send(new OutboundMail(
                account: $context->account,
                recipientEmail: $recipients['to'],
                ccAddresses: $recipients['cc'],
                bccAddresses: $recipients['bcc'],
                mailSubject: $context->subject,
                mailBody: $context->body,
                messageId: $messageId,
                inReplyTo: $inReplyTo,
                references: $references,
                signature: $context->signature,
            ));

            $outgoing = $this->persistOutgoing(
                context: $context,
                recipient: $recipients['to'],
                cc: $recipients['cc'],
                bcc: $recipients['bcc'],
                messageId: $messageId,
                inReplyTo: $inReplyTo,
                references: $references,
            );

            $this->transitionExpedient($context, $outgoing);

            return $outgoing;
        });
    }

    private function prepareContext(OutboundMailContext $context, bool $applyDefaults): OutboundMailContext
    {
        $this->authorizeContext($context);
        $origin = $this->resolveOrigin($context);
        $this->assertLifecycle($context);
        $this->assertDeadline($context);

        $preparedContext = $context->withResolvedOrigin($origin);

        if ($applyDefaults) {
            $preparedContext = $preparedContext->withFormDefaults(
                recipient: $context->recipient !== '' ? $context->recipient : $this->defaultRecipient($context, $origin),
                subject: $context->subject !== '' ? $context->subject : $this->defaultSubject($context, $origin),
            );
        }

        if ($context->signatureId !== null) {
            $signature = $context->account->signatures()->active()->find($context->signatureId);

            if ($signature === null) {
                throw new AuthorizationException('The selected signature is not available for this mail account.');
            }

            $preparedContext = $preparedContext->withResolvedSignature($signature->body);
        }

        return $preparedContext;
    }

    private function authorizeContext(OutboundMailContext $context): void
    {
        if (! $context->account->exists || ! $context->account->is_active) {
            throw new AuthorizationException('The mail account is not available for sending.');
        }

        if (! $context->account->isAccessibleBy($context->actor)) {
            throw new AuthorizationException('You are not authorized to use this mail account.');
        }

        $ability = $context->hasExpedient() ? 'expedientes.actualizar' : 'bandeja.clasificar';

        if (! $context->actor->can($ability)) {
            throw new AuthorizationException('You are not authorized to send mail from this context.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveOrigin(OutboundMailContext $context): array
    {
        if ($context->hasExpedient()) {
            $expedient = $context->expedient;

            if ($expedient === null || $expedient->mail_account_id !== $context->account->id || $context->originMessageId === null) {
                throw new LogicException('The mail account or source message does not belong to this expedient.');
            }

            $origin = MailMessage::query()
                ->whereKey($context->originMessageId)
                ->where('case_id', $expedient->id)
                ->where('mail_account_id', $context->account->id)
                ->first();

            if ($origin === null) {
                throw new LogicException('The source message does not belong to this expedient.');
            }

            $data = $this->messageOrigin($origin);
        } else {
            $origin = MailMessage::query()
                ->where('mail_account_id', $context->account->id)
                ->where('folder', $context->folder)
                ->where('imap_uid', (string) $context->imapUid)
                ->first();

            $data = $origin === null ? $context->origin : $this->messageOrigin($origin);
        }

        $resolved = [
            'message_id' => $data['message_id'] ?? null,
            'references' => $data['references'] ?? null,
            'subject' => $data['subject'] ?? null,
            'from_email' => $data['from_email'] ?? null,
            'from_name' => $data['from_name'] ?? null,
            'folder' => $data['folder'] ?? $context->folder,
            'imap_uid' => $data['imap_uid'] ?? $context->imapUid,
        ];

        if ($resolved['folder'] !== $context->folder || (int) $resolved['imap_uid'] !== $context->imapUid) {
            throw new LogicException('The source message does not match the requested IMAP identity.');
        }

        return $resolved;
    }

    /**
     * @return array<string, mixed>
     */
    private function messageOrigin(MailMessage $origin): array
    {
        return [
            'message_id' => $origin->message_id,
            'references' => $origin->references,
            'subject' => $origin->subject,
            'from_email' => $origin->from_email,
            'from_name' => $origin->from_name,
            'folder' => $origin->folder,
            'imap_uid' => $origin->imap_uid,
        ];
    }

    /**
     * @param  array<string, mixed>  $origin
     */
    private function defaultRecipient(OutboundMailContext $context, array $origin): string
    {
        if ($context->mode !== 'reply') {
            return '';
        }

        return (string) ($origin['from_email'] ?? $context->expedient?->sender_email ?? '');
    }

    /**
     * @param  array<string, mixed>  $origin
     */
    private function defaultSubject(OutboundMailContext $context, array $origin): string
    {
        $subject = trim((string) ($origin['subject'] ?? ''));

        if ($subject === '') {
            return '';
        }

        return ($context->mode === 'reply' ? 'Re: ' : 'Fwd: ').$subject;
    }

    private function assertLifecycle(OutboundMailContext $context): void
    {
        if (! $context->hasExpedient()) {
            return;
        }

        match ($context->mode) {
            'reply' => $context->expedient->assertCanReplyClient(),
            'forward' => $context->expedient->assertCanForwardProvider(),
        };
    }

    private function assertDeadline(OutboundMailContext $context): void
    {
        if (! $context->hasExpedient() && $context->deadline !== null) {
            throw new InvalidArgumentException('An expedient is required to update a mail deadline.');
        }

        if ($context->deadline?->isPast()) {
            throw new InvalidArgumentException('The mail deadline must be in the future.');
        }
    }

    /**
     * @return array{to: string, cc: array<int, string>, bcc: array<int, string>}
     */
    private function normalizeRecipients(OutboundMailContext $context): array
    {
        return [
            'to' => $this->normalizeAddress($context->recipient, 'to'),
            'cc' => $this->normalizeAddressList($context->cc, 'cc'),
            'bcc' => $this->normalizeAddressList($context->bcc, 'bcc'),
        ];
    }

    private function normalizeAddress(string $address, string $field): string
    {
        $address = trim($address);

        if ($address === '' || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException("The {$field} recipient must be a valid email address.");
        }

        return $address;
    }

    /**
     * @param  array<int, string>  $addresses
     * @return array<int, string>
     */
    private function normalizeAddressList(array $addresses, string $field): array
    {
        $normalized = [];

        foreach ($addresses as $address) {
            $address = trim($address);

            if ($address === '') {
                continue;
            }

            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException("The {$field} recipients must be valid email addresses.");
            }

            $normalized[] = $address;
        }

        return array_values(array_unique($normalized));
    }

    private function assertSendable(OutboundMailContext $context): void
    {
        if (trim($context->subject) === '' || mb_strlen($context->subject) > 255) {
            throw new InvalidArgumentException('The mail subject is required and must not exceed 255 characters.');
        }

        if (trim($context->body) === '') {
            throw new InvalidArgumentException('The mail body is required.');
        }
    }

    /**
     * @param  array<string, mixed>  $origin
     * @return array{0: string|null, 1: array<int, string>}
     */
    private function threading(OutboundMailContext $context, array $origin): array
    {
        if ($context->mode !== 'reply') {
            return [null, []];
        }

        $inReplyTo = filled($origin['message_id'] ?? null) ? (string) $origin['message_id'] : null;
        $references = $origin['references'] ?? null;
        $values = is_array($references)
            ? $references
            : (preg_split('/\s+/', (string) $references, -1, PREG_SPLIT_NO_EMPTY) ?: []);

        $values = array_values(array_filter(array_map(
            fn (mixed $value): string => trim((string) $value),
            $values,
        ), fn (string $value): bool => $value !== ''));

        if ($inReplyTo !== null) {
            $values[] = $inReplyTo;
        }

        return [$inReplyTo, array_values(array_unique($values))];
    }

    private function messageId(MailAccount $account): string
    {
        $domain = parse_url(config('app.url'), PHP_URL_HOST) ?: 'localhost';

        return sprintf('%s@%s', Str::uuid(), $domain);
    }

    /**
     * @param  array<int, string>  $cc
     * @param  array<int, string>  $bcc
     * @param  array<int, string>  $references
     */
    private function persistOutgoing(
        OutboundMailContext $context,
        string $recipient,
        array $cc,
        array $bcc,
        string $messageId,
        ?string $inReplyTo,
        array $references,
    ): MailMessage {
        $sentAt = now();

        return MailMessage::create([
            'case_id' => $context->expedient?->id,
            'mail_account_id' => $context->account->id,
            'message_id' => $messageId,
            'to_email' => $recipient,
            'cc' => $cc,
            'bcc' => $bcc,
            'subject' => $context->subject,
            'from_email' => $context->account->email_address,
            'from_name' => $context->account->label ?? $context->account->email_address,
            'body_html' => $context->hasExpedient() ? $context->body : null,
            'body_text' => $context->hasExpedient() ? strip_tags($context->body) : null,
            'received_at' => $sentAt,
            'sent_at' => $sentAt,
            'direction' => MailDirection::Outgoing,
            'status' => $context->hasExpedient() ? MailMessageStatus::Associated : MailMessageStatus::New,
            'in_reply_to' => $inReplyTo,
            'references' => $references,
        ]);
    }

    private function transitionExpedient(OutboundMailContext $context, MailMessage $outgoing): void
    {
        if (! $context->hasExpedient()) {
            return;
        }

        if ($context->mode === 'reply') {
            $context->expedient->replyClient($outgoing, $context->actor);
            $context->expedient->updateDeadline($context->actor, $context->deadline);

            return;
        }

        $context->expedient->forwardProvider($outgoing, $context->actor, $context->deadline);
    }
}
