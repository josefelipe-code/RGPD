<?php

namespace App\Console\Commands;

use App\Mail\ExpedientDeadlineReminder;
use App\Models\CaseDeadlineReminder;
use App\Models\Expedient;
use Carbon\CarbonInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Mail;

#[Signature('expedientes:send-deadline-reminders {--case-id= : Send only one expedient} {--dry-run : Report reminders without queuing email}')]
#[Description('Queue due Expedientes deadline reminder emails')]
class SendExpedientDeadlineReminders extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $query = Expedient::query()
            ->with('mailAccount')
            ->where(fn ($cases) => $cases->whereDoesntHave('state', fn ($states) => $states->where('is_final', true))
                ->orWhereNull('expedient_state_id'))
            ->whereNotNull('state_deadline')
            ->whereHas('mailAccount', fn ($mailAccounts) => $mailAccounts
                ->where('is_active', true)
                ->whereNotNull('deadline_notification_email')
                ->where('deadline_notification_email', '!=', ''));

        if ($this->option('case-id')) {
            $query->whereKey($this->option('case-id'));
        }

        $queued = 0;
        $now = now();

        $query->orderBy('id')->each(function (Expedient $expedient) use (&$queued, $now): void {
            $reminder = $this->dueReminder($expedient, $now);

            if ($reminder === null) {
                return;
            }

            if ($this->option('dry-run')) {
                $this->line("Would queue {$reminder['alert_type']} reminder for {$expedient->case_number}.");

                return;
            }

            try {
                $record = CaseDeadlineReminder::query()->firstOrCreate([
                    'case_id' => $expedient->id,
                    'case_status' => $expedient->status->value,
                    'deadline' => $expedient->state_deadline,
                    'alert_type' => $reminder['alert_type'],
                    'reminder_date' => $reminder['reminder_date'],
                ]);
            } catch (QueryException) {
                return;
            }

            if (! $record->wasRecentlyCreated) {
                return;
            }

            Mail::to($expedient->mailAccount->deadline_notification_email)->queue(
                new ExpedientDeadlineReminder($expedient, $reminder['alert_type']),
            );
            $queued++;
        });

        $this->info("{$queued} deadline reminder(s) queued.");

        return self::SUCCESS;
    }

    /**
     * @return array{alert_type: 'five_days'|'twenty_four_hours'|'overdue', reminder_date: string}|null
     */
    private function dueReminder(Expedient $expedient, CarbonInterface $now): ?array
    {
        $deadline = $expedient->state_deadline;

        if ($deadline === null) {
            return null;
        }

        if ($now->greaterThan($deadline)) {
            return ['alert_type' => 'overdue', 'reminder_date' => $now->toDateString()];
        }

        if ($now->greaterThanOrEqualTo($deadline->copy()->subDay())) {
            return ['alert_type' => 'twenty_four_hours', 'reminder_date' => $deadline->toDateString()];
        }

        if ($now->greaterThanOrEqualTo($deadline->copy()->subDays(5))) {
            return ['alert_type' => 'five_days', 'reminder_date' => $deadline->toDateString()];
        }

        return null;
    }
}
