<?php

namespace App\Models;

use App\Enums\MilestoneAction;
use Database\Factories\CaseMilestoneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'case_id',
    'user_id',
    'action',
    'notes',
    'mail_message_id',
])]
class CaseMilestone extends Model
{
    /** @use HasFactory<CaseMilestoneFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => MilestoneAction::class,
        ];
    }

    /**
     * Get the case this milestone belongs to.
     */
    public function case(): BelongsTo
    {
        return $this->belongsTo(Expedient::class);
    }

    /**
     * Get the user who performed this action.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the outbound mail message that triggered this milestone.
     */
    public function mailMessage(): BelongsTo
    {
        return $this->belongsTo(MailMessage::class);
    }

    /**
     * Scope a query to only actions of a specific type.
     */
    /** Limita hitos a una acción concreta para consultas del expediente. */
    public function scopeAction($query, MilestoneAction $action)
    {
        return $query->where('action', $action);
    }
}
