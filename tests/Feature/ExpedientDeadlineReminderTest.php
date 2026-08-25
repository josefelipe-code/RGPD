<?php

use App\Enums\CaseStatus;
use App\Mail\ExpedientDeadlineReminder;
use App\Models\CaseDeadlineReminder;
use App\Models\Expedient;
use App\Models\MailAccount;
use App\Models\MailMessage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->operator = User::factory()->create();
    $this->account = MailAccount::factory()->for($this->operator)->create([
        'deadline_notification_email' => 'alerts@example.com',
    ]);
});

test('queues each deadline alert once and queues overdue alerts daily', function () {
    Carbon::setTestNow('2026-07-30 09:00:00');
    Mail::fake();
    $expedient = Expedient::factory()->for($this->account)->create([
        'state_deadline' => now()->addDays(5),
    ]);

    $this->artisan('expedientes:send-deadline-reminders', ['--case-id' => $expedient->id])->assertExitCode(0);
    $this->artisan('expedientes:send-deadline-reminders', ['--case-id' => $expedient->id])->assertExitCode(0);
    Mail::assertQueued(ExpedientDeadlineReminder::class, 1);

    Carbon::setTestNow(now()->addDays(4));
    $this->artisan('expedientes:send-deadline-reminders', ['--case-id' => $expedient->id])->assertExitCode(0);

    Carbon::setTestNow(now()->addDay()->addMinute());
    $this->artisan('expedientes:send-deadline-reminders', ['--case-id' => $expedient->id])->assertExitCode(0);
    $this->artisan('expedientes:send-deadline-reminders', ['--case-id' => $expedient->id])->assertExitCode(0);

    Carbon::setTestNow(now()->addDay());
    $this->artisan('expedientes:send-deadline-reminders', ['--case-id' => $expedient->id])->assertExitCode(0);

    expect(CaseDeadlineReminder::query()->count())->toBe(4);
    Mail::assertQueued(ExpedientDeadlineReminder::class, 4);
    Carbon::setTestNow();
});

test('state transitions and deadline replacements start a fresh reminder sequence', function () {
    Carbon::setTestNow('2026-07-30 09:00:00');
    Mail::fake();
    $expedient = Expedient::factory()->for($this->account)->create([
        'state_deadline' => now()->addDays(5),
    ]);

    $this->artisan('expedientes:send-deadline-reminders', ['--case-id' => $expedient->id])->assertExitCode(0);
    $expedient->updateDeadline($this->operator, now()->addDays(5)->subMinute());
    $this->artisan('expedientes:send-deadline-reminders', ['--case-id' => $expedient->id])->assertExitCode(0);

    $outgoing = MailMessage::factory()->for($this->account)->create(['case_id' => $expedient->id]);
    $expedient->validatePhone($this->operator);
    $expedient->forwardProvider($outgoing, $this->operator, now()->addDays(5)->subMinutes(2));
    $this->artisan('expedientes:send-deadline-reminders', ['--case-id' => $expedient->id])->assertExitCode(0);

    expect(CaseDeadlineReminder::query()->count())->toBe(3);
    Mail::assertQueued(ExpedientDeadlineReminder::class, 3);
    Carbon::setTestNow();
});

test('skips blank recipients and concluded expedients', function () {
    Carbon::setTestNow('2026-07-30 09:00:00');
    Mail::fake();
    $blankRecipient = MailAccount::factory()->for($this->operator)->create(['deadline_notification_email' => '']);
    $blank = Expedient::factory()->for($blankRecipient)->create(['state_deadline' => now()->addDays(5)]);
    $concluded = Expedient::factory()->for($this->account)->concluded()->create(['state_deadline' => null]);

    $this->artisan('expedientes:send-deadline-reminders', ['--case-id' => $blank->id])->assertExitCode(0);
    $this->artisan('expedientes:send-deadline-reminders', ['--case-id' => $concluded->id])->assertExitCode(0);

    expect(CaseDeadlineReminder::query()->count())->toBe(0);
    Mail::assertNothingQueued();
    Carbon::setTestNow();
});

test('supports dry runs and registers the scheduled command', function () {
    Carbon::setTestNow('2026-07-30 09:00:00');
    Mail::fake();
    $expedient = Expedient::factory()->for($this->account)->create(['state_deadline' => now()->addDays(5)]);

    $this->artisan('expedientes:send-deadline-reminders', ['--case-id' => $expedient->id, '--dry-run' => true])
        ->expectsOutput("Would queue five_days reminder for {$expedient->case_number}.")
        ->assertExitCode(0);

    expect(CaseDeadlineReminder::query()->count())->toBe(0)
        ->and(collect(Schedule::events())->contains(fn ($event) => str_contains($event->command, 'expedientes:send-deadline-reminders')))->toBeTrue();
    Mail::assertNothingQueued();
    Carbon::setTestNow();
});

test('lifecycle deadline updates are traceable and conclusions clear active deadlines', function () {
    $expedient = Expedient::factory()->for($this->account)->pendingProvider()->create(['state_deadline' => now()->addDay()]);

    $expedient->updateDeadline($this->operator, now()->addDays(2));
    $expedient->confirmProvider($this->operator);

    expect($expedient->fresh()->status)->toBe(CaseStatus::Concluded)
        ->and($expedient->fresh()->state_deadline)->toBeNull()
        ->and($expedient->milestones()->where('action', 'deadline_updated')->exists())->toBeTrue();
});

test('only the attended account owner can update an expedient deadline', function () {
    $expedient = Expedient::factory()->for($this->account)->create();
    $otherUser = User::factory()->create();
    $otherUser->givePermissionTo(['expedientes.ver', 'expedientes.actualizar']);

    Livewire::actingAs($otherUser)
        ->test('pages::expedientes.show', ['expedient' => $expedient])
        ->set('stateDeadline', now()->addWeek()->format('Y-m-d\\TH:i'))
        ->call('saveDeadline')
        ->assertForbidden();
});
