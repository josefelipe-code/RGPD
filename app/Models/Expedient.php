<?php

namespace App\Models;

use App\Enums\CaseStatus;
use Database\Factories\ExpedientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        return $this->hasMany(CaseMilestone::class, 'case_id');
    }

    /**
     * Get the mail messages associated with this expedient.
     */
    public function mailMessages(): HasMany
    {
        return $this->hasMany(MailMessage::class, 'case_id');
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
}
