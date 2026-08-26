<?php

namespace App\Models;

use Database\Factories\SharedIncidentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

#[Fillable(['source', 'fingerprint', 'title', 'case_id'])]
class SharedIncident extends Model
{
    public const string StatusOpen = 'open';

    public const string StatusClaimed = 'claimed';

    /** @use HasFactory<SharedIncidentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'claimed_at' => 'datetime',
        ];
    }

    public function expedient(): BelongsTo
    {
        return $this->belongsTo(Expedient::class, 'case_id');
    }

    public function claimedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by_user_id');
    }

    /**
     * @param  Builder<SharedIncident>  $query
     * @return Builder<SharedIncident>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::StatusOpen);
    }

    public static function reportImapReconciliationFailure(Expedient $expedient, int $referenceId, int $stateId): self
    {
        return DB::transaction(function () use ($expedient, $referenceId, $stateId): self {
            Expedient::query()->lockForUpdate()->findOrFail($expedient->id);

            return static::query()->firstOrCreate([
                'fingerprint' => hash('sha256', "imap-reconciliation:{$expedient->id}:{$referenceId}:{$stateId}"),
            ], [
                'source' => 'imap_reconciliation',
                'title' => 'La sincronización del expediente requiere atención.',
                'case_id' => $expedient->id,
                'status' => self::StatusOpen,
            ]);
        }, attempts: 3);
    }

    public static function claim(int $incidentId, User $user): ?self
    {
        return DB::transaction(function () use ($incidentId, $user): ?self {
            $incident = static::query()->open()->lockForUpdate()->find($incidentId);

            if ($incident === null) {
                return null;
            }

            $incident->forceFill([
                'status' => self::StatusClaimed,
                'claimed_by_user_id' => $user->id,
                'claimed_at' => now(),
            ])->save();

            return $incident;
        }, attempts: 3);
    }
}
