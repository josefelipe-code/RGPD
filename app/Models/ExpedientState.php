<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['mail_account_id', 'name', 'key', 'imap_folder', 'deadline_days', 'is_final'])]
class ExpedientState extends Model
{
    protected function casts(): array
    {
        return ['deadline_days' => 'integer', 'is_final' => 'boolean'];
    }

    public function mailAccount(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class);
    }

    public function expedients(): HasMany
    {
        return $this->hasMany(Expedient::class);
    }
}
