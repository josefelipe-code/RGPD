<?php

namespace App\Models;

use App\Enums\CaseStatus;
use App\Enums\MilestoneAction;
use Carbon\CarbonInterface;
use Database\Factories\ExpedientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use LogicException;

#[Fillable([
    'case_number',
    'sender_email',
    'sender_phone',
    'provider_id',
    'mail_account_id',
    'assigned_user_id',
    'request_type',
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
            'phone_validated_at' => 'datetime',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'state_deadline' => 'datetime',
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

    public function state(): BelongsTo
    {
        return $this->belongsTo(ExpedientState::class, 'expedient_state_id');
    }

    public function imapMessageReferences(): BelongsToMany
    {
        return $this->belongsToMany(ImapMessageReference::class, 'expedient_imap_message', 'case_id', 'imap_message_reference_id')
            ->withPivot(['confirmed_by', 'confirmed_at'])->withTimestamps();
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
     * Abre el expediente, registra la fecha y crea el hito correspondiente.
     */
    /** Abre el expediente y registra la acción para la página de detalle. */
    public function open(User $creator, ?CarbonInterface $deadline = null): void
    {
        if ($this->opened_at !== null) {
            return;
        }

        DB::transaction(function () use ($creator, $deadline) {
            $this->forceFill([
                'status' => CaseStatus::PendingClient,
                'expedient_state_id' => $this->mailAccount->expedientStates()->where('key', 'pending_client')->value('id'),
                'opened_at' => now(),
                'closed_at' => null,
                'state_deadline' => $deadline,
            ])->save();
            $this->milestones()->create([
                'user_id' => $creator->id,
                'action' => MilestoneAction::Opened,
            ]);
        });
    }

    public function validatePhone(User $actor): CaseMilestone
    {
        return DB::transaction(function () use ($actor) {
            $this->assertStatus(CaseStatus::PendingClient, 'Phone validation is only available while awaiting the client.');

            if ($this->phone_validated_at !== null) {
                throw new LogicException('The client phone has already been validated.');
            }

            $this->forceFill(['phone_validated_at' => now()])->save();

            return $this->milestones()->create([
                'user_id' => $actor->id,
                'action' => MilestoneAction::PhoneValidated,
            ]);
        });
    }

    public function confirmProvider(User $actor): CaseMilestone
    {
        return $this->conclude($actor, MilestoneAction::ProviderConfirmed);
    }

    public function markClientFingerprintSent(User $actor): CaseMilestone
    {
        return $this->conclude($actor, MilestoneAction::ClientFingerprintSent);
    }

    /**
     * Reply to client: transition to PendingClient and create a RepliedClient milestone
     * linked to the outbound mail message.
     */
    /** Registra que el expediente recibió una respuesta dirigida al cliente. */
    public function replyClient(MailMessage $outgoing, User $actor): CaseMilestone
    {
        return DB::transaction(function () use ($outgoing, $actor) {
            $this->assertCanReplyClient();
            $this->assertOutgoingMessage($outgoing);

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
    /** Registra que el expediente recibió un envío dirigido al proveedor. */
    public function forwardProvider(MailMessage $outgoing, User $actor, ?CarbonInterface $deadline = null): CaseMilestone
    {
        return DB::transaction(function () use ($outgoing, $actor, $deadline) {
            $this->assertCanForwardProvider();
            $this->assertOutgoingMessage($outgoing);
            $this->forceFill([
                'status' => CaseStatus::PendingProvider,
                'expedient_state_id' => $this->mailAccount->expedientStates()->where('key', 'pending_provider')->value('id'),
                'state_deadline' => $deadline,
            ])->save();

            return $this->milestones()->create([
                'user_id' => $actor->id,
                'action' => MilestoneAction::RepliedProvider,
                'mail_message_id' => $outgoing->id,
                'notes' => $deadline === null
                    ? __('Deadline cleared.')
                    : __('Deadline set for :deadline.', ['deadline' => $deadline->format('Y-m-d H:i')]),
            ]);
        });
    }

    public function updateDeadline(User $actor, ?CarbonInterface $deadline): ?CaseMilestone
    {
        return DB::transaction(function () use ($actor, $deadline) {
            if ($this->status === CaseStatus::Concluded) {
                throw new LogicException('Concluded expedients cannot have an active deadline.');
            }

            if (($this->state_deadline === null && $deadline === null)
                || ($this->state_deadline !== null && $deadline !== null && $this->state_deadline->equalTo($deadline))) {
                return null;
            }

            $this->forceFill(['state_deadline' => $deadline])->save();

            return $this->milestones()->create([
                'user_id' => $actor->id,
                'action' => MilestoneAction::DeadlineUpdated,
                'notes' => $deadline === null
                    ? __('Deadline cleared.')
                    : __('Deadline set for :deadline.', ['deadline' => $deadline->format('Y-m-d H:i')]),
            ]);
        });
    }

    public function assertCanReplyClient(): void
    {
        $this->assertStatus(CaseStatus::PendingClient, 'Client replies are only available while awaiting the client.');
    }

    public function assertCanForwardProvider(): void
    {
        $this->assertStatus(CaseStatus::PendingClient, 'Provider outreach is only available while awaiting the client.');

        if ($this->phone_validated_at === null) {
            throw new LogicException('Validate the client phone before contacting the provider.');
        }
    }

    private function conclude(User $actor, MilestoneAction $action): CaseMilestone
    {
        return DB::transaction(function () use ($actor, $action) {
            $this->assertStatus(CaseStatus::PendingProvider, 'Only provider-pending expedients can be concluded.');
            $this->forceFill([
                'status' => CaseStatus::Concluded,
                'expedient_state_id' => $this->mailAccount->expedientStates()->where('is_final', true)->value('id'),
                'closed_at' => now(),
                'state_deadline' => null,
            ])->save();

            return $this->milestones()->create([
                'user_id' => $actor->id,
                'action' => $action,
            ]);
        });
    }

    private function assertStatus(CaseStatus $expected, string $message): void
    {
        if ($this->status !== $expected) {
            throw new LogicException($message);
        }
    }

    private function assertOutgoingMessage(MailMessage $outgoing): void
    {
        if ($outgoing->case_id !== $this->id || $outgoing->mail_account_id !== $this->mail_account_id) {
            throw new LogicException('The outgoing message does not belong to this expedient.');
        }
    }

    /**
     * Scope a query to only open expedients.
     */
    /** Limita consultas a expedientes que aún están abiertos. */
    public function scopeOpen($query)
    {
        return $query->whereNot('status', CaseStatus::Concluded);
    }

    /**
     * Scope a query to only concluded expedients.
     */
    /** Limita consultas a expedientes concluidos. */
    public function scopeConcluded($query)
    {
        return $query->where('status', CaseStatus::Concluded);
    }

    /**
     * Scope a query to expedients assigned to a specific user.
     */
    /** Limita consultas a expedientes asignados a un usuario concreto. */
    public function scopeAssignedTo($query, User $user)
    {
        return $query->where('assigned_user_id', $user->id);
    }

    /**
     * Scope a query to expedients belonging to a specific mail account.
     */
    /** Limita consultas a la cuenta de correo asociada. */
    public function scopeForMailAccount($query, int $mailAccountId)
    {
        return $query->where('mail_account_id', $mailAccountId);
    }

    /**
     * Scope a query to expedients sharing a sender email.
     */
    /** Busca expedientes cuyo remitente coincide con el correo indicado. */
    public function scopeRelatedByEmail($query, string $email)
    {
        return $query->where('sender_email', $email);
    }

    /**
     * Scope a query to expedients sharing a sender phone.
     */
    /** Busca expedientes cuyo teléfono coincide con el valor indicado. */
    public function scopeRelatedByPhone($query, string $phone)
    {
        return $query->where('sender_phone', $phone);
    }

    /**
     * Scope a query to expedients related by email or phone,
     * excluding self and soft-deleted records, capped at $limit.
     */
    /** Combina criterios de correo y teléfono para sugerir expedientes relacionados. */
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
