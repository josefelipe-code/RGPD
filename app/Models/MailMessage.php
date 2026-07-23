<?php

namespace App\Models;

use App\Enums\MailDirection;
use App\Enums\MailMessageStatus;
use Database\Factories\MailMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'case_id',
    'mail_account_id',
    'message_id',
    'imap_uid',
    'to_email',
    'subject',
    'from_email',
    'from_name',
    'sender_phone',
    'body_html',
    'body_text',
    'received_at',
    'sent_at',
    'direction',
    'status',
    'in_reply_to',
    'references',
    'cc',
    'bcc',
    'folder',
    'thread_id',
    'is_read',
])]
class MailMessage extends Model
{
    /** @use HasFactory<MailMessageFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'sent_at' => 'datetime',
            'direction' => MailDirection::class,
            'status' => MailMessageStatus::class,
            'cc' => 'array',
            'bcc' => 'array',
            'references' => 'array',
            'is_read' => 'boolean',
        ];
    }

    /**
     * Get the case this message belongs to.
     */
    public function case(): BelongsTo
    {
        return $this->belongsTo(Expedient::class);
    }

    /**
     * Get the mail account this message belongs to.
     */
    public function mailAccount(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class);
    }

    /**
     * Scope a query to only incoming messages.
     */
    public function scopeIncoming($query)
    {
        return $query->where('direction', MailDirection::Incoming);
    }

    /**
     * Scope a query to only outgoing messages.
     */
    public function scopeOutgoing($query)
    {
        return $query->where('direction', MailDirection::Outgoing);
    }

    /**
     * Scope a query to only new/unassociated messages.
     */
    public function scopeUnassociated($query)
    {
        return $query->whereNull('case_id');
    }

    /**
     * Get the original message this is a reply to (via in_reply_to → message_id).
     */
    public function to(): BelongsTo
    {
        return $this->belongsTo(MailMessage::class, 'in_reply_to', 'message_id');
    }
}
