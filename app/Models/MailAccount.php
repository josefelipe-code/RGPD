<?php

namespace App\Models;

use App\Casts\EncryptionCast;
use Database\Factories\MailAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'label',
    'email_address',
    'deadline_notification_email',
    'imap_host',
    'imap_port',
    'imap_encryption',
    'imap_username',
    'imap_password',
    'imap_options',
    'smtp_host',
    'smtp_port',
    'smtp_encryption',
    'smtp_username',
    'smtp_password',
    'smtp_options',
    'is_active',
])]
class MailAccount extends Model
{
    /** @use HasFactory<MailAccountFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::created(function (MailAccount $account): void {
            $account->expedientStates()->createMany([
                ['name' => 'Pending client', 'key' => 'pending_client'],
                ['name' => 'Pending provider', 'key' => 'pending_provider'],
                ['name' => 'Concluded', 'key' => 'concluded', 'is_final' => true],
            ]);
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'imap_port' => 'integer',
            'smtp_port' => 'integer',
            'is_active' => 'boolean',
            'deadline_notification_email' => 'string',
            'imap_password' => 'encrypted',
            'smtp_password' => 'encrypted',
            'imap_options' => 'encrypted:array',
            'smtp_options' => 'encrypted:array',
            'imap_encryption' => EncryptionCast::class,
            'smtp_encryption' => EncryptionCast::class,
        ];
    }

    /**
     * Get the user that owns this mail account.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the signatures for this mail account.
     */
    public function signatures(): HasMany
    {
        return $this->hasMany(Signature::class);
    }

    /**
     * Get the mail messages for this mail account.
     */
    public function mailMessages(): HasMany
    {
        return $this->hasMany(MailMessage::class);
    }

    /**
     * Get the cases for this mail account.
     */
    public function cases(): HasMany
    {
        return $this->hasMany(Expedient::class);
    }

    public function expedientStates(): HasMany
    {
        return $this->hasMany(ExpedientState::class);
    }

    public function imapMessageReferences(): HasMany
    {
        return $this->hasMany(ImapMessageReference::class);
    }

    /**
     * Scope a query to only active accounts.
     */
    /** Limita la consulta a cuentas habilitadas para operar. */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get IMAP configuration array for use with webklex/laravel-imap.
     *
     * @return array<string, mixed>
     */
    /** Construye la configuración IMAP consumida por los servicios de bandeja. */
    public function imapConfig(): array
    {
        return array_merge([
            'host' => $this->imap_host,
            'port' => $this->imap_port,
            'protocol' => 'imap',
            'encryption' => $this->imap_encryption,
            'validate_cert' => true,
            'username' => $this->imap_username,
            'password' => $this->imap_password,
            'authentication' => null,
            'proxy' => [
                'socket' => null,
                'request_fulluri' => false,
                'username' => null,
                'password' => null,
            ],
            'timeout' => 30,
            'extensions' => [],
        ], $this->imap_options ?? []);
    }

    /**
     * Get SMTP mailer configuration array for Laravel's mail config.
     *
     * @return array<string, mixed>
     */
    /** Construye la configuración SMTP consumida por MailAccountConfigService. */
    public function smtpConfig(): array
    {
        return array_merge([
            'transport' => 'smtp',
            'host' => $this->smtp_host,
            'port' => $this->smtp_port,
            'encryption' => $this->smtp_encryption,
            'username' => $this->smtp_username,
            'password' => $this->smtp_password,
            'timeout' => null,
            'local_domain' => null,
        ], $this->smtp_options ?? []);
    }
}
