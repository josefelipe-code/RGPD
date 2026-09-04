<?php

namespace App\Services\Bandeja;

use App\Models\Expedient;
use App\Models\ImapMessageReference;
use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

class ImapExpedientBridgeService
{
    public function __construct(
        private ImapMessageOperationReservationService $reservationService,
    ) {}

    /**
     * Stores only remote identity/header evidence after explicit human confirmation.
     *
     * @param  array<string, mixed>  $envelope
     */
    public function associate(MailAccount $account, Expedient $expedient, User $actor, array $envelope): ImapMessageReference
    {
        if (! $account->isAccessibleBy($actor) || $expedient->mail_account_id !== $account->id) {
            throw new AuthorizationException('The account must be accessible to the current user and match the expedient.');
        }

        $this->reservationService->assertHeldBy($account, $actor, $envelope['folder'], (int) $envelope['uid']);

        $reference = ImapMessageReference::query()->firstOrCreate([
            'mail_account_id' => $account->id,
            'folder' => $envelope['folder'],
            'imap_uid' => (string) $envelope['uid'],
        ], [
            'uid_validity' => $envelope['uid_validity'] ?? null,
            'message_id' => $envelope['message_id'] ?? null,
            'in_reply_to' => $envelope['in_reply_to'] ?? null,
            'references' => $envelope['references'] ?? null,
            'subject' => $envelope['subject'] ?? null,
            'from_email' => $envelope['from_email'] ?? null,
            'from_name' => $envelope['from_name'] ?? null,
            'received_at' => $envelope['received_at'] ?? null,
        ]);

        $reference->expedients()->syncWithoutDetaching([$expedient->id => ['confirmed_by' => $actor->id, 'confirmed_at' => now()]]);

        return $reference;
    }

    /** @param array<string, mixed> $envelope */
    public function candidates(MailAccount $account, array $envelope, ?string $phone = null): Collection
    {
        $query = Expedient::query()->where('mail_account_id', $account->id)->with('state');
        $email = $envelope['from_email'] ?? null;
        $subject = $envelope['subject'] ?? null;
        $thread = array_filter([$envelope['message_id'] ?? null, $envelope['in_reply_to'] ?? null]);

        return $query->where(function ($matches) use ($email, $subject, $phone): void {
            if (filled($email)) {
                $matches->orWhere('sender_email', $email);
            }
            if (filled($phone)) {
                $matches->orWhere('sender_phone', $phone);
            }
            if (filled($subject)) {
                $matches->orWhere('request_type', 'like', '%'.$subject.'%');
            }
        })->latest()->limit(10)->get()->map(function (Expedient $expedient) use ($email, $phone, $subject, $thread): array {
            $reason = $expedient->sender_email === $email ? 'email match' : ($expedient->sender_phone === $phone ? 'phone match' : 'subject match');
            $confidence = $reason === 'email match' ? 'high' : ($reason === 'phone match' ? 'medium' : 'low');

            if ($thread !== []) {
                $outgoing = $expedient->mailMessages()->outgoing()->whereIn('message_id', $thread)->exists();
                if ($outgoing) {
                    $reason = 'thread match';
                    $confidence = 'highest';
                }
            }

            return compact('expedient', 'reason', 'confidence');
        })->sortByDesc(fn (array $candidate): int => match ($candidate['confidence']) {
            'highest' => 3, 'high' => 2, 'medium' => 1, default => 0
        })->values();
    }
}
