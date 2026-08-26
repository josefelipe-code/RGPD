<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the mail accounts for the user.
     */
    public function mailAccounts(): HasMany
    {
        return $this->hasMany(MailAccount::class);
    }

    /**
     * Get the cases assigned to this user.
     */
    public function assignedCases(): HasMany
    {
        return $this->hasMany(Expedient::class, 'assigned_user_id');
    }

    /**
     * Get the milestones performed by this user.
     */
    public function milestones(): HasMany
    {
        return $this->hasMany(CaseMilestone::class);
    }

    /**
     * Get the shared incidents claimed by this user.
     */
    public function claimedIncidents(): HasMany
    {
        return $this->hasMany(SharedIncident::class, 'claimed_by_user_id');
    }

    /**
     * Get the user's initials
     */
    /** Calcula las iniciales que muestran los menús de usuario. */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }
}
