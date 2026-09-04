<?php

use App\Models\ImapMessageOperationReservation;
use App\Models\MailAccount;
use App\Models\User;
use App\Services\Bandeja\ImapMessageOperationReservationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows an account owner to authorize another operator without changing technical ownership', function () {
    $owner = User::factory()->create();
    $operator = User::factory()->create();
    $account = MailAccount::factory()->for($owner)->create();

    $account->operators()->attach($operator);

    expect($account->fresh()->user_id)->toBe($owner->id)
        ->and($account->fresh()->isAccessibleBy($operator))->toBeTrue()
        ->and($operator->accessibleMailAccounts()->pluck('id')->all())->toContain($account->id);
});

it('acquires a five-minute reservation without renewing it for the same operator', function () {
    $owner = User::factory()->create();
    $account = MailAccount::factory()->for($owner)->create();
    $service = app(ImapMessageOperationReservationService::class);

    $reservation = $service->acquire($account, $owner, 'INBOX', 42);
    $sameReservation = $service->acquire($account, $owner, 'INBOX', 42);

    expect($sameReservation->id)->toBe($reservation->id)
        ->and($sameReservation->expires_at->equalTo($reservation->expires_at))->toBeTrue()
        ->and($reservation->created_at->diffInSeconds($reservation->expires_at))->toBe(300.0);
});

it('rejects a competing operator and identifies the active operator', function () {
    $owner = User::factory()->create(['name' => 'Owner Operator']);
    $competitor = User::factory()->create();
    $account = MailAccount::factory()->for($owner)->create();
    $account->operators()->attach($competitor);
    $service = app(ImapMessageOperationReservationService::class);

    $service->acquire($account, $owner, 'INBOX', 42);

    expect(fn () => $service->acquire($account, $competitor, 'INBOX', 42))
        ->toThrow(AuthorizationException::class, 'Owner Operator');
});

it('permits a new reservation only after the prior reservation expires', function () {
    $owner = User::factory()->create();
    $operator = User::factory()->create();
    $account = MailAccount::factory()->for($owner)->create();
    $account->operators()->attach($operator);
    $reservation = ImapMessageOperationReservation::query()->create([
        'mail_account_id' => $account->id,
        'folder' => 'INBOX',
        'imap_uid' => 42,
        'user_id' => $owner->id,
        'expires_at' => now()->subSecond(),
    ]);

    $replacement = app(ImapMessageOperationReservationService::class)->acquire($account, $operator, 'INBOX', 42);

    expect($replacement->id)->toBe($reservation->id)
        ->and($replacement->user_id)->toBe($operator->id)
        ->and($replacement->isActive())->toBeTrue();
});
