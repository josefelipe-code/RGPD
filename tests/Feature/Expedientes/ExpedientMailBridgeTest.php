<?php

use App\Enums\CaseStatus;
use App\Enums\MilestoneAction;
use App\Models\Expedient;
use App\Models\MailAccount;
use App\Models\MailMessage;
use App\Models\User;
use App\Services\Bandeja\MailBridgeService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class)->in(__DIR__);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->owner = User::factory()->create();
    $this->account = MailAccount::factory()->for($this->owner)->create(['is_active' => true]);
    $this->service = app(MailBridgeService::class);
});

test('provider outreach sends only after phone validation and records the transition', function () {
    Mail::fake();
    $expedient = Expedient::factory()->for($this->account)->create(['status' => CaseStatus::PendingClient]);
    $origin = MailMessage::factory()->for($this->account)->create(['case_id' => $expedient->id]);

    expect(fn () => $this->service->send($this->account, 'forward_provider', $origin, $expedient, $this->owner, [
        'to' => 'provider@example.com', 'body' => 'Body', 'subject' => 'Subject',
    ]))->toThrow(LogicException::class);
    Mail::assertNothingQueued();

    $expedient->validatePhone($this->owner);
    $outgoing = $this->service->send($this->account, 'forward_provider', $origin, $expedient, $this->owner, [
        'to' => 'provider@example.com', 'body' => 'Body', 'subject' => 'Subject',
    ]);

    expect($expedient->fresh()->status)->toBe(CaseStatus::PendingProvider)
        ->and($expedient->milestones()->action(MilestoneAction::RepliedProvider)->first()->mail_message_id)->toBe($outgoing->id);
});

test('mail bridge rejects concluded expedients before sending', function () {
    Mail::fake();
    $expedient = Expedient::factory()->for($this->account)->concluded()->create();
    $origin = MailMessage::factory()->for($this->account)->create(['case_id' => $expedient->id]);

    expect(fn () => $this->service->send($this->account, 'reply_client', $origin, $expedient, $this->owner, [
        'body' => 'Body', 'subject' => 'Subject',
    ]))->toThrow(LogicException::class);
    Mail::assertNothingQueued();
});

test('mail bridge requires ownership of the expedient mail account', function () {
    $expedient = Expedient::factory()->for($this->account)->create();
    $origin = MailMessage::factory()->for($this->account)->create(['case_id' => $expedient->id]);

    expect(fn () => $this->service->send($this->account, 'reply_client', $origin, $expedient, User::factory()->create(), [
        'body' => 'Body', 'subject' => 'Subject',
    ]))->toThrow(AuthorizationException::class);
});
