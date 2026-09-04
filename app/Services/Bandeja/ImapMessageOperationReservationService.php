<?php

namespace App\Services\Bandeja;

use App\Models\ImapMessageOperationReservation;
use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class ImapMessageOperationReservationService
{
    public function acquire(MailAccount $account, User $actor, string $folder, int $uid): ImapMessageOperationReservation
    {
        $this->assertAccountAccess($account, $actor);

        return DB::transaction(function () use ($account, $actor, $folder, $uid): ImapMessageOperationReservation {
            $now = now();

            DB::table('imap_message_operation_reservations')->insertOrIgnore([
                'mail_account_id' => $account->id,
                'folder' => $folder,
                'imap_uid' => $uid,
                'user_id' => $actor->id,
                'expires_at' => $now->copy()->addMinutes(5),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $reservation = ImapMessageOperationReservation::query()
                ->where('mail_account_id', $account->id)
                ->where('folder', $folder)
                ->where('imap_uid', $uid)
                ->lockForUpdate()
                ->firstOrFail();

            if ($reservation->isActive()) {
                if ($reservation->user_id !== $actor->id) {
                    throw new AuthorizationException($this->heldByMessage($reservation));
                }

                return $reservation;
            }

            $reservation->forceFill([
                'user_id' => $actor->id,
                'expires_at' => now()->addMinutes(5),
            ])->save();

            return $reservation;
        });
    }

    public function assertHeldBy(MailAccount $account, User $actor, string $folder, int $uid): ImapMessageOperationReservation
    {
        $this->assertAccountAccess($account, $actor);

        $reservation = ImapMessageOperationReservation::query()
            ->where('mail_account_id', $account->id)
            ->where('folder', $folder)
            ->where('imap_uid', $uid)
            ->with('operator:id,name')
            ->first();

        if ($reservation === null || ! $reservation->isActive()) {
            throw new AuthorizationException('The message operation reservation has expired.');
        }

        if ($reservation->user_id !== $actor->id) {
            throw new AuthorizationException($this->heldByMessage($reservation));
        }

        return $reservation;
    }

    private function assertAccountAccess(MailAccount $account, User $actor): void
    {
        if (! $account->isAccessibleBy($actor)) {
            throw new AuthorizationException('You are not authorized to use this mail account.');
        }
    }

    private function heldByMessage(ImapMessageOperationReservation $reservation): string
    {
        $operatorName = $reservation->relationLoaded('operator')
            ? $reservation->operator?->name
            : $reservation->operator()->value('name');

        return 'This message is being managed by '.($operatorName ?? 'another operator').'.';
    }
}
