<?php

namespace App\Models;

use Database\Factories\SignatureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'mail_account_id',
    'name',
    'body',
    'is_default',
    'is_active',
])]
class Signature extends Model
{
    /** @use HasFactory<SignatureFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the mail account that owns this signature.
     */
    public function mailAccount(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class);
    }

    /**
     * Scope a query to only active signatures.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only default signatures.
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }
}
