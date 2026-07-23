<?php

namespace App\Services\Bandeja;

use App\Models\Expedient;
use App\Models\MailMessage;
use Illuminate\Support\Collection;

/**
 * Compute suggestion candidates for an inbound mail message.
 *
 * Returns a collection of arrays with keys: expedient, confidence, reason.
 * Suggestions are NEVER auto-linked — the user must confirm.
 *
 * Match priority (per spec REQ-MAIL-PROVIDER-DETECTION):
 *   1. In-Reply-To → outgoing message_id (highest confidence)
 *   2. Email anchor + phone corroboration (high/medium)
 *   3. Subject fallback (low — deferred)
 * Phone-alone is never sufficient (anti-false-link guard, D7).
 */
class InboundSuggestionService
{
    /**
     * Suggest expedientes that may be related to the given inbound message.
     *
     * @return Collection<int, array{expedient: Expedient, confidence: string, reason: string}>
     */
    public function suggest(MailMessage $message): Collection
    {
        // Priority 1: In-Reply-To match against outgoing messages (S19).
        $inReplyToMatch = $this->matchInReplyTo($message);
        if ($inReplyToMatch !== null) {
            return collect([$inReplyToMatch]);
        }

        $email = filled($message->from_email) ? $message->from_email : null;
        $phone = filled($message->sender_phone) ? $message->sender_phone : null;

        // D7 Anti-false-link guard: phone-alone is NEVER sufficient.
        // We only return phone matches when there is also an email match
        // somewhere in the result set to corroborate.
        if ($email === null) {
            return collect();
        }

        // Step 1: Find expedientes matching by email (the anchor).
        $emailMatches = $this->queryByEmail($message, $email);

        if ($emailMatches->isEmpty()) {
            return collect();
        }

        // Step 2: If message also has a phone, find additional matches by phone.
        // These are valid because the email anchor corroborates the set.
        $results = $emailMatches;

        if ($phone !== null) {
            $phoneMatches = $this->queryByPhone($message, $phone, $emailMatches->pluck('expedient.id')->all());
            $results = $emailMatches->merge($phoneMatches);
        }

        return $results->unique('expedient.id');
    }

    /**
     * Match inbound message by In-Reply-To header against outgoing message_id.
     *
     * Returns the highest-confidence suggestion if a match is found, or null.
     */
    protected function matchInReplyTo(MailMessage $message): ?array
    {
        if (blank($message->in_reply_to)) {
            return null;
        }

        $outgoing = MailMessage::query()
            ->outgoing()
            ->where('mail_account_id', $message->mail_account_id)
            ->where('message_id', $message->in_reply_to)
            ->whereNotNull('case_id')
            ->first();

        if ($outgoing === null || $outgoing->case_id === null) {
            return null;
        }

        $expedient = Expedient::query()
            ->where('id', $outgoing->case_id)
            ->open()
            ->first();

        if ($expedient === null) {
            return null;
        }

        return [
            'expedient' => $expedient,
            'confidence' => 'highest',
            'reason' => 'In-Reply-To match',
        ];
    }

    /**
     * Query expedientes matching by email, scoped to the message's mail account.
     *
     * @return Collection<int, array{expedient: Expedient, confidence: string, reason: string}>
     */
    protected function queryByEmail(MailMessage $message, string $email): Collection
    {
        $expedientes = Expedient::query()
            ->where('mail_account_id', $message->mail_account_id)
            ->open()
            ->where('sender_email', $email)
            ->latest()
            ->limit(5)
            ->get();

        if ($expedientes->isEmpty()) {
            return collect();
        }

        return $expedientes->map(fn (Expedient $e) => [
            'expedient' => $e,
            'confidence' => 'high',
            'reason' => 'email match',
        ]);
    }

    /**
     * Query expedientes matching by phone, excluding already-found IDs.
     *
     * @param  array<int>  $excludeIds
     * @return Collection<int, array{expedient: Expedient, confidence: string, reason: string}>
     */
    protected function queryByPhone(MailMessage $message, string $phone, array $excludeIds): Collection
    {
        $expedientes = Expedient::query()
            ->where('mail_account_id', $message->mail_account_id)
            ->open()
            ->where('sender_phone', $phone)
            ->whereNotIn('id', $excludeIds)
            ->latest()
            ->limit(5)
            ->get();

        if ($expedientes->isEmpty()) {
            return collect();
        }

        return $expedientes->map(fn (Expedient $e) => [
            'expedient' => $e,
            'confidence' => 'medium',
            'reason' => 'phone match (corroborated)',
        ]);
    }
}
