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
 * Match priority (for PR2): email match, email+phone match.
 * Phone-alone is never sufficient (anti-false-link guard).
 * In-Reply-To matching against outgoing messages is deferred to PR4.
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
