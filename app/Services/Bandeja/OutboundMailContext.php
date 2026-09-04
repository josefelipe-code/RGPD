<?php

namespace App\Services\Bandeja;

use App\Models\Expedient;
use App\Models\MailAccount;
use App\Models\MailMessage;
use App\Models\User;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final readonly class OutboundMailContext
{
    /**
     * @param  array<string, mixed>  $origin
     * @param  array<int, string>  $cc
     * @param  array<int, string>  $bcc
     */
    public function __construct(
        public MailAccount $account,
        public User $actor,
        /** @var 'reply'|'forward' */
        public string $mode,
        public string $folder,
        public int $imapUid,
        public ?int $originMessageId,
        public array $origin,
        public ?Expedient $expedient,
        public string $recipient,
        public array $cc,
        public array $bcc,
        public string $subject,
        public string $body,
        public ?string $signature,
        public ?int $signatureId,
        public ?CarbonInterface $deadline,
    ) {
        if (! in_array($this->mode, ['reply', 'forward'], true)) {
            throw new InvalidArgumentException('Invalid outbound mail mode.');
        }

        if ($this->folder === '' || $this->imapUid < 1) {
            throw new InvalidArgumentException('Outbound mail requires a valid IMAP folder and UID.');
        }
    }

    /**
     * Build a context from a transient inbox envelope.
     *
     * @param  array<string, mixed>  $origin
     * @param  array<int, string>  $cc
     * @param  array<int, string>  $bcc
     */
    public static function fromInbox(
        MailAccount $account,
        User $actor,
        string $mode,
        string $folder,
        int $imapUid,
        array $origin,
        string $recipient = '',
        array $cc = [],
        array $bcc = [],
        string $subject = '',
        string $body = '',
        ?string $signature = null,
        ?CarbonInterface $deadline = null,
        ?int $signatureId = null,
    ): self {
        return new self(
            account: $account,
            actor: $actor,
            mode: $mode,
            folder: $folder,
            imapUid: $imapUid,
            originMessageId: null,
            origin: $origin,
            expedient: null,
            recipient: $recipient,
            cc: $cc,
            bcc: $bcc,
            subject: $subject,
            body: $body,
            signature: $signature,
            signatureId: $signatureId,
            deadline: $deadline,
        );
    }

    /**
     * Build a context from a persisted message associated with an expedient.
     *
     * @param  array<int, string>  $cc
     * @param  array<int, string>  $bcc
     */
    public static function fromExpedient(
        MailAccount $account,
        User $actor,
        string $mode,
        string $folder,
        int $imapUid,
        Expedient $expedient,
        MailMessage $origin,
        string $recipient = '',
        array $cc = [],
        array $bcc = [],
        string $subject = '',
        string $body = '',
        ?string $signature = null,
        ?CarbonInterface $deadline = null,
        ?int $signatureId = null,
    ): self {
        return new self(
            account: $account,
            actor: $actor,
            mode: $mode,
            folder: $folder,
            imapUid: $imapUid,
            originMessageId: $origin->id,
            origin: [
                'message_id' => $origin->message_id,
                'references' => $origin->references,
                'subject' => $origin->subject,
                'from_email' => $origin->from_email,
                'from_name' => $origin->from_name,
                'folder' => $origin->folder,
                'imap_uid' => $origin->imap_uid,
            ],
            expedient: $expedient,
            recipient: $recipient,
            cc: $cc,
            bcc: $bcc,
            subject: $subject,
            body: $body,
            signature: $signature,
            signatureId: $signatureId,
            deadline: $deadline,
        );
    }

    public function hasExpedient(): bool
    {
        return $this->expedient !== null;
    }

    /**
     * @param  array<string, mixed>  $origin
     */
    public function withResolvedOrigin(array $origin): self
    {
        return $this->copy(origin: $origin);
    }

    public function withFormDefaults(string $recipient, string $subject): self
    {
        return $this->copy(recipient: $recipient, subject: $subject);
    }

    public function withResolvedSignature(?string $signature): self
    {
        return $this->copy(signature: $signature, replaceSignature: true);
    }

    /**
     * @param  array<string, mixed>|null  $origin
     */
    private function copy(
        ?array $origin = null,
        ?string $recipient = null,
        ?string $subject = null,
        ?string $signature = null,
        bool $replaceSignature = false,
    ): self {
        return new self(
            account: $this->account,
            actor: $this->actor,
            mode: $this->mode,
            folder: $this->folder,
            imapUid: $this->imapUid,
            originMessageId: $this->originMessageId,
            origin: $origin ?? $this->origin,
            expedient: $this->expedient,
            recipient: $recipient ?? $this->recipient,
            cc: $this->cc,
            bcc: $this->bcc,
            subject: $subject ?? $this->subject,
            body: $this->body,
            signature: $replaceSignature ? $signature : $this->signature,
            signatureId: $this->signatureId,
            deadline: $this->deadline,
        );
    }
}
