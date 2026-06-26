<?php

use App\Enums\CaseStatus;
use App\Enums\MailDirection;
use App\Enums\MilestoneAction;
use App\Models\Expedient;
use App\Models\MailAccount;
use App\Models\MailMessage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->in(__DIR__);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->user = User::factory()->create();
    $this->user->assignRole('Super Administrador');
    $this->account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);
});

// S17: Reply transitions to pending_client
it('transitions expedient to pending_client when replyClient is called', function () {
    $expedient = Expedient::factory()->for($this->account)->create([
        'status' => CaseStatus::PendingProvider,
    ]);
    $outgoing = MailMessage::factory()->for($this->account)->outgoing()->create([
        'case_id' => $expedient->id,
        'direction' => MailDirection::Outgoing,
    ]);

    $milestone = $expedient->replyClient($outgoing, $this->user);

    expect($expedient->fresh()->status)->toBe(CaseStatus::PendingClient)
        ->and($milestone->action)->toBe(MilestoneAction::RepliedClient)
        ->and($milestone->user_id)->toBe($this->user->id)
        ->and($milestone->mail_message_id)->toBe($outgoing->id);
});

// S18: Forward transitions to pending_provider
it('transitions expedient to pending_provider when forwardProvider is called', function () {
    $expedient = Expedient::factory()->for($this->account)->create([
        'status' => CaseStatus::PendingClient,
    ]);
    $outgoing = MailMessage::factory()->for($this->account)->outgoing()->create([
        'case_id' => $expedient->id,
        'direction' => MailDirection::Outgoing,
    ]);

    $milestone = $expedient->forwardProvider($outgoing, $this->user);

    expect($expedient->fresh()->status)->toBe(CaseStatus::PendingProvider)
        ->and($milestone->action)->toBe(MilestoneAction::RepliedProvider)
        ->and($milestone->user_id)->toBe($this->user->id)
        ->and($milestone->mail_message_id)->toBe($outgoing->id);
});

// S25: Milestone links outbound message
it('creates milestone with mail_message_id link', function () {
    $expedient = Expedient::factory()->for($this->account)->create();
    $outgoing = MailMessage::factory()->for($this->account)->outgoing()->create([
        'case_id' => $expedient->id,
    ]);

    $milestone = $expedient->replyClient($outgoing, $this->user);

    expect($milestone->mail_message_id)->toBe($outgoing->id)
        ->and($milestone->case_id)->toBe($expedient->id);
});

// S26: Manual milestones have no link (existing behavior, regression guard)
it('does not set mail_message_id on manual milestones', function () {
    $expedient = Expedient::factory()->for($this->account)->create();

    $milestone = $expedient->milestones()->create([
        'user_id' => $this->user->id,
        'action' => MilestoneAction::RepliedClient,
        'notes' => 'Manual milestone',
    ]);

    expect($milestone->mail_message_id)->toBeNull();
});
