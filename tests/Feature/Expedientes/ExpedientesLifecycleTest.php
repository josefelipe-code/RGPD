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
    $this->mailAccount = MailAccount::factory()->create();
});

// ─── Task 1.2: MilestoneAction::Reopened enum ───

test('MilestoneAction has Reopened case', function () {
    expect(MilestoneAction::Reopened->value)->toBe('reopened');
});

// ─── Task 1.3: SoftDeletes on Expedient ───

test('expedients use soft deletes', function () {
    $expedient = Expedient::factory()->create();
    $expedient->delete();

    expect(Expedient::find($expedient->id))->toBeNull()
        ->and(Expedient::withTrashed()->find($expedient->id))->not->toBeNull();
});

test('soft-deleted expedients are excluded from default queries (S1)', function () {
    $active = Expedient::factory()->create();
    $trashed = Expedient::factory()->create();
    $trashed->delete();

    $all = Expedient::all();

    expect($all)->toHaveCount(1)
        ->and($all->first()->id)->toBe($active->id);
});

test('mail messages survive soft delete of expedient (S2)', function () {
    $expedient = Expedient::factory()->create();
    MailMessage::factory()->count(2)->create(['case_id' => $expedient->id]);

    $expedient->delete();

    $messages = MailMessage::where('case_id', $expedient->id)->get();
    expect($messages)->toHaveCount(2);
});

// ─── Task 1.4: Domain lifecycle methods ───

test('open stamps opened_at and creates Opened milestone (S11)', function () {
    $expedient = Expedient::factory()->create(['opened_at' => null]);

    $expedient->open($this->user);

    expect($expedient->opened_at)->not->toBeNull()
        ->and($expedient->milestones()->action(MilestoneAction::Opened)->count())->toBe(1);
});

test('open does not duplicate opened_at if already set', function () {
    $originalOpenedAt = now()->subDays(5);
    $expedient = Expedient::factory()->create(['opened_at' => $originalOpenedAt]);

    $expedient->open($this->user);

    expect($expedient->opened_at->timestamp)->toBe($originalOpenedAt->timestamp);
});

test('close stamps closed_at and creates Closed milestone (S12)', function () {
    $expedient = Expedient::factory()->create(['status' => CaseStatus::PendingClient, 'closed_at' => null]);

    $milestone = $expedient->close($this->user);

    $expedient->refresh();
    expect($expedient->closed_at)->not->toBeNull()
        ->and($expedient->status)->toBe(CaseStatus::Concluded)
        ->and($milestone->action)->toBe(MilestoneAction::Closed);
});

test('status change without conclusion skips close (S13)', function () {
    $expedient = Expedient::factory()->create(['status' => CaseStatus::PendingClient, 'closed_at' => null]);

    $milestone = $expedient->transitionTo(CaseStatus::PendingProvider, $this->user);

    $expedient->refresh();
    expect($expedient->closed_at)->toBeNull()
        ->and($expedient->status)->toBe(CaseStatus::PendingProvider)
        ->and($milestone)->toBeNull();
});

test('reopen clears closed_at and creates Reopened milestone (S14)', function () {
    $expedient = Expedient::factory()->concluded()->create();

    $milestone = $expedient->reopen(CaseStatus::PendingClient, $this->user);

    $expedient->refresh();
    expect($expedient->closed_at)->toBeNull()
        ->and($expedient->status)->toBe(CaseStatus::PendingClient)
        ->and($milestone->action)->toBe(MilestoneAction::Reopened);
});

test('reopen to PendingProvider (S15)', function () {
    $expedient = Expedient::factory()->concluded()->create();

    $milestone = $expedient->reopen(CaseStatus::PendingProvider, $this->user);

    $expedient->refresh();
    expect($expedient->status)->toBe(CaseStatus::PendingProvider)
        ->and($expedient->closed_at)->toBeNull()
        ->and($milestone->action)->toBe(MilestoneAction::Reopened);
});

test('transitionTo delegates to close when status is Concluded', function () {
    $expedient = Expedient::factory()->create(['status' => CaseStatus::PendingClient]);

    $milestone = $expedient->transitionTo(CaseStatus::Concluded, $this->user);

    expect($milestone->action)->toBe(MilestoneAction::Closed);
});

test('transitionTo delegates to reopen when current status is Concluded and target is not', function () {
    $expedient = Expedient::factory()->concluded()->create();

    $milestone = $expedient->transitionTo(CaseStatus::PendingClient, $this->user);

    expect($milestone->action)->toBe(MilestoneAction::Reopened);
});

test('create always stamps opened_at regardless of status (S16)', function () {
    $expedient = Expedient::factory()->create(['status' => CaseStatus::PendingProvider]);

    expect($expedient->opened_at)->not->toBeNull();
});

// ─── Task 1.5: Scopes ───

test('forMailAccount scope filters by account', function () {
    $account1 = MailAccount::factory()->create();
    $account2 = MailAccount::factory()->create();

    Expedient::factory()->count(3)->create(['mail_account_id' => $account1->id]);
    Expedient::factory()->count(2)->create(['mail_account_id' => $account2->id]);

    expect(Expedient::forMailAccount($account1->id)->count())->toBe(3)
        ->and(Expedient::forMailAccount($account2->id)->count())->toBe(2);
});

test('relatedByEmail finds expedients with same email excluding self', function () {
    $email = 'shared@example.com';

    $e1 = Expedient::factory()->create(['sender_email' => $email]);
    $e2 = Expedient::factory()->create(['sender_email' => $email]);
    Expedient::factory()->create(['sender_email' => 'different@example.com']);

    $related = Expedient::relatedByEmail($email)->get();

    expect($related)->toHaveCount(2)
        ->and($related->pluck('id')->contains($e1->id))->toBeTrue()
        ->and($related->pluck('id')->contains($e2->id))->toBeTrue();
});

test('relatedByPhone finds expedients with same phone excluding self', function () {
    $phone = '+34123456789';

    $e1 = Expedient::factory()->create(['sender_phone' => $phone]);
    $e2 = Expedient::factory()->create(['sender_phone' => $phone]);
    Expedient::factory()->create(['sender_phone' => '+34987654321']);

    $related = Expedient::relatedByPhone($phone)->get();

    expect($related)->toHaveCount(2);
});

test('relatedTo combines email and phone, excludes self and trashed, caps at limit', function () {
    $email = 'test@example.com';
    $phone = '+34111111111';

    $self = Expedient::factory()->create(['sender_email' => $email, 'sender_phone' => $phone]);

    // 3 related by email
    Expedient::factory()->count(3)->create(['sender_email' => $email, 'sender_phone' => null]);
    // 2 related by phone
    Expedient::factory()->count(2)->create(['sender_email' => null, 'sender_phone' => $phone]);
    // 1 trashed (should be excluded)
    $trashed = Expedient::factory()->create(['sender_email' => $email]);
    $trashed->delete();

    $related = Expedient::relatedTo($email, $phone, 5, $self->id)->get();

    expect($related)->toHaveCount(5)
        ->and($related->pluck('id')->contains($self->id))->toBeFalse()
        ->and($related->pluck('id')->contains($trashed->id))->toBeFalse();
});
