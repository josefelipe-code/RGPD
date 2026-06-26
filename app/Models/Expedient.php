<?php

namespace App\Models;

use App\Enums\CaseStatus;
use App\Enums\MilestoneAction;
use Database\Factories\ExpedientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

#[Fillable([
    'case_number',
    'sender_email',
    'sender_phone',
    'provider_id',
    'mail_account_id',
    'assigned_user_id',
    'status',
    'request_type',
    'opened_at',
    'closed_at',
])]
class Expedient extends Model
{
    /** @use HasFactory<ExpedientFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'cases';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CaseStatus::class,
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Get the mail account this expedient belongs to.
     */
    public function mailAccount(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class);
    }

    /**
     * Get the user assigned to this expedient.
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * Get the provider contact for this expedient.
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'provider_id');
    }

    /**
     * Get the milestones for this expedient.
     */
    public function milestones(): HasMany
    {
        return $this->hasMany(CaseMilestone::class, 'case_id')->latest();
    }

    /**
     * Get the mail messages associated with this expedient.
     */
    public function mailMessages(): HasMany
    {
        return $this->hasMany(MailMessage::class, 'case_id');
    }

    /**
     * Open the expedient: stamp opened_at and create an Opened milestone.
     */
    public function open(User $creator): void
    {
        if ($this->opened_at !== null) {
            return;
        }

        DB::transaction(function () use ($creator) {
            $this->update(['opened_at' => now()]);
            $this->milestones()->create([
                'user_id' => $creator->id,
                'action' => MilestoneAction::Opened,
            ]);
        });
    }

    /**
     * Close the expedient: stamp closed_at and create a Closed milestone.
     */
    public function close(User $actor): CaseMilestone
    {
        return DB::transaction(function () use ($actor) {
            $this->update([
                'status' => CaseStatus::Concluded,
                'closed_at' => now(),
            ]);

            return $this->milestones()->create([
                'user_id' => $actor->id,
                'action' => MilestoneAction::Closed,
            ]);
        });
    }

    /**
     * Reopen a concluded expedient: clear closed_at and create a Reopened milestone.
     */
    public function reopen(CaseStatus $target, User $actor, ?string $notes = null): CaseMilestone
    {
        return DB::transaction(function () use ($target, $actor, $notes) {
            $this->update([
                'status' => $target,
                'closed_at' => null,
            ]);

            return $this->milestones()->create([
                'user_id' => $actor->id,
                'action' => MilestoneAction::Reopened,
                'notes' => $notes,
            ]);
        });
    }

    /**
     * Transition the expedient to a new status, triggering lifecycle side-effects.
     */
    public function transitionTo(CaseStatus $target, User $actor, ?string $notes = null): ?CaseMilestone
    {
        $wasConcluded = $this->status === CaseStatus::Concluded;
        $isConcluding = $target === CaseStatus::Concluded;

        if ($wasConcluded && ! $isConcluding) {
            return $this->reopen($target, $actor, $notes);
        }

        if ($isConcluding && ! $wasConcluded) {
            return $this->close($actor);
        }

        // Non-concluding status change — just update status
        if ($this->status !== $target) {
            $this->update(['status' => $target]);
        }

        return null;
    }

    /**
     * Reply to client: transition to PendingClient and create a RepliedClient milestone
     * linked to the outbound mail message.
     */
    public function replyClient(MailMessage $outgoing, User $actor): CaseMilestone
    {
        return DB::transaction(function () use ($outgoing, $actor) {
            $this->update(['status' => CaseStatus::PendingClient]);

            return $this->milestones()->create([
                'user_id' => $actor->id,
                'action' => MilestoneAction::RepliedClient,
                'mail_message_id' => $outgoing->id,
            ]);
        });
    }

    /**
     * Forward to provider: transition to PendingProvider and create a RepliedProvider
     * milestone linked to the outbound mail message.
     */
    public function forwardProvider(MailMessage $outgoing, User $actor): CaseMilestone
    {
        return DB::transaction(function () use ($outgoing, $actor) {
            $this->update(['status' => CaseStatus::PendingProvider]);

            return $this->milestones()->create([
                'user_id' => $actor->id,
                'action' => MilestoneAction::RepliedProvider,
                'mail_message_id' => $outgoing->id,
            ]);
        });
    }

    /**
     * Scope a query to only open expedients.
     */
    public function scopeOpen($query)
    {
        return $query->whereNot('status', CaseStatus::Concluded);
    }

    /**
     * Scope a query to only concluded expedients.
     */
    public function scopeConcluded($query)
    {
        return $query->where('status', CaseStatus::Concluded);
    }

    /**
     * Scope a query to expedients assigned to a specific user.
     */
    public function scopeAssignedTo($query, User $user)
    {
        return $query->where('assigned_user_id', $user->id);
    }

    /**
     * Scope a query to expedients belonging to a specific mail account.
     */
    public function scopeForMailAccount($query, int $mailAccountId)
    {
        return $query->where('mail_account_id', $mailAccountId);
    }

    /**
     * Scope a query to expedients sharing a sender email.
     */
    public function scopeRelatedByEmail($query, string $email)
    {
        return $query->where('sender_email', $email);
    }

    /**
     * Scope a query to expedients sharing a sender phone.
     */
    public function scopeRelatedByPhone($query, string $phone)
    {
        return $query->where('sender_phone', $phone);
    }

    /**
     * Scope a query to expedients related by email or phone,
     * excluding self and soft-deleted records, capped at $limit.
     */
    public function scopeRelatedTo($query, ?string $email, ?string $phone, int $limit = 5, ?int $excludeId = null)
    {
        $excludeId = $excludeId ?? $this->id ?? 0;

        return $query->where(function ($q) use ($email, $phone) {
            if ($email) {
                $q->orWhere('sender_email', $email);
            }
            if ($phone) {
                $q->orWhere('sender_phone', $phone);
            }
        })->where('id', '!=', $excludeId)
            ->latest()
            ->limit($limit);
    }
}
