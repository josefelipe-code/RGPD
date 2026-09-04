<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['mail_account_id', 'folder', 'imap_uid', 'user_id', 'expires_at'])]
class ImapMessageOperationReservation extends Model
{
    protected function casts(): array
    {
        return [
            'imap_uid' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function mailAccount(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isActive(): bool
    {
        return $this->expires_at->isFuture();
    }

    public function isHeldBy(User $user): bool
    {
        return $this->user_id === $user->id && $this->isActive();
    }
}
