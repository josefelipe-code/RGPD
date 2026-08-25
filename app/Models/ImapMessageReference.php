<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['mail_account_id', 'folder', 'imap_uid', 'uid_validity', 'message_id', 'in_reply_to', 'references', 'subject', 'from_email', 'from_name', 'received_at', 'reconciliation_status', 'reconciliation_target_folder', 'reconciliation_error'])]
class ImapMessageReference extends Model
{
    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }

    public function mailAccount(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class);
    }

    public function expedients(): BelongsToMany
    {
        return $this->belongsToMany(Expedient::class, 'expedient_imap_message', 'imap_message_reference_id', 'case_id')
            ->withPivot(['confirmed_by', 'confirmed_at'])->withTimestamps();
    }
}
