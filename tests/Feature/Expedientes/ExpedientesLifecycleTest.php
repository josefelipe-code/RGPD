<?php

use App\Enums\CaseStatus;
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
    $this->account = MailAccount::factory()->for($this->user)->create();
});

test('phone validation is recorded before provider outreach', function () {
    $expedient = Expedient::factory()->for($this->account)->create(['status' => CaseStatus::PendingClient]);
    $outgoing = MailMessage::factory()->for($this->account)->outgoing()->create(['case_id' => $expedient->id]);

    expect(fn () => $expedient->forwardProvider($outgoing, $this->user, now()->addWeek()))->toThrow(LogicException::class);

    $validated = $expedient->validatePhone($this->user);
    $forwarded = $expedient->forwardProvider($outgoing, $this->user, now()->addWeek());

    expect($expedient->fresh()->status)->toBe(CaseStatus::PendingProvider)
        ->and($expedient->fresh()->phone_validated_at)->not->toBeNull()
        ->and($validated->action)->toBe(MilestoneAction::PhoneValidated)
        ->and($forwarded->action)->toBe(MilestoneAction::RepliedProvider);
});

test('only the two explicit provider-pending paths conclude an expedient', function () {
    $providerConfirmed = Expedient::factory()->for($this->account)->pendingProvider()->create();
    $fingerprintSent = Expedient::factory()->for($this->account)->pendingProvider()->create();

    $confirmedMilestone = $providerConfirmed->confirmProvider($this->user);
    $fingerprintMilestone = $fingerprintSent->markClientFingerprintSent($this->user);

    expect($providerConfirmed->fresh()->status)->toBe(CaseStatus::Concluded)
        ->and($providerConfirmed->fresh()->closed_at)->not->toBeNull()
        ->and($confirmedMilestone->action)->toBe(MilestoneAction::ProviderConfirmed)
        ->and($fingerprintSent->fresh()->status)->toBe(CaseStatus::Concluded)
        ->and($fingerprintMilestone->action)->toBe(MilestoneAction::ClientFingerprintSent);
});

test('forbids lifecycle actions on concluded expedients', function () {
    $expedient = Expedient::factory()->for($this->account)->concluded()->create();
    $outgoing = MailMessage::factory()->for($this->account)->outgoing()->create(['case_id' => $expedient->id]);

    expect(fn () => $expedient->validatePhone($this->user))->toThrow(LogicException::class)
        ->and(fn () => $expedient->replyClient($outgoing, $this->user))->toThrow(LogicException::class)
        ->and(fn () => $expedient->forwardProvider($outgoing, $this->user, now()->addWeek()))->toThrow(LogicException::class)
        ->and(fn () => $expedient->confirmProvider($this->user))->toThrow(LogicException::class)
        ->and(fn () => $expedient->markClientFingerprintSent($this->user))->toThrow(LogicException::class);
});

test('does not allow the provider-pending transition without provider outreach', function () {
    $expedient = Expedient::factory()->for($this->account)->create(['status' => CaseStatus::PendingClient]);

    $expedient->validatePhone($this->user);

    expect($expedient->fresh()->status)->toBe(CaseStatus::PendingClient)
        ->and($expedient->milestones()->action(MilestoneAction::RepliedProvider)->count())->toBe(0);
});
