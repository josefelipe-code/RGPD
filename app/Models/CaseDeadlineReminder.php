<?php

namespace App\Models;

use Database\Factories\CaseDeadlineReminderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'case_id',
    'case_status',
    'deadline',
    'alert_type',
    'reminder_date',
])]
class CaseDeadlineReminder extends Model
{
    /** @use HasFactory<CaseDeadlineReminderFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deadline' => 'datetime',
            'reminder_date' => 'date',
        ];
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(Expedient::class);
    }
}
