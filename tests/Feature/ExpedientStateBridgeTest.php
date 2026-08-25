<?php

use App\Models\Expedient;
use App\Models\MailAccount;
use App\Models\User;
use App\Services\Bandeja\ImapExpedientBridgeService;
use App\Services\Bandeja\ImapMailboxService;
use App\Services\Expedientes\ExpedientStateService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function bridgeEnvelope(int $uid = 42): array
{
    return [
        'folder' => 'INBOX',
        'uid' => $uid,
        'uid_validity' => '77',
        'message_id' => '<message@example.test>',
        'in_reply_to' => null,
        'references' => '<root@example.test>',
        'subject' => 'Data request',
        'from_email' => 'client@example.test',
        'from_name' => 'Client',
        'received_at' => now(),
    ];
}

test('new accounts receive the three configurable legacy states with one final state', function () {
    $account = MailAccount::factory()->create();

    expect($account->expedientStates()->count())->toBe(3)
        ->and($account->expedientStates()->where('is_final', true)->sole()->key)->toBe('concluded');
});

test('a confirmed IMAP envelope can be associated with multiple account-scoped expedients without persisting a body', function () {
    $owner = User::factory()->create();
    $account = MailAccount::factory()->for($owner)->create();
    $first = Expedient::factory()->for($account)->create();
    $second = Expedient::factory()->for($account)->create();
    $bridge = app(ImapExpedientBridgeService::class);

    $reference = $bridge->associate($account, $first, $owner, bridgeEnvelope());
    $bridge->associate($account, $second, $owner, bridgeEnvelope());

    expect($reference->fresh()->expedients)->toHaveCount(2)
        ->and($reference->getAttributes())->not->toHaveKey('body_html')
        ->and($reference->getAttributes())->not->toHaveKey('body_text');
});

test('bridge candidates are ranked and never auto-associated', function () {
    $account = MailAccount::factory()->create();
    $match = Expedient::factory()->for($account)->create(['sender_email' => 'client@example.test']);

    $candidates = app(ImapExpedientBridgeService::class)->candidates($account, bridgeEnvelope());

    expect($candidates)->toHaveCount(1)
        ->and($candidates->first()['expedient']->is($match))->toBeTrue()
        ->and($candidates->first()['confidence'])->toBe('high')
        ->and($match->imapMessageReferences)->toBeEmpty();
});

test('state service permits a non-owner with expedient access to configure folders and enforces one final state per account', function () {
    $owner = User::factory()->create();
    $account = MailAccount::factory()->for($owner)->create();
    $manager = User::factory()->create();
    $manager->givePermissionTo('expedientes.ver');
    $mailbox = Mockery::mock(ImapMailboxService::class);
    $mailbox->shouldReceive('listFolders')->andReturn(collect([['path' => 'Cases', 'name' => 'Cases']]));
    $service = new ExpedientStateService($mailbox);

    $state = $service->save($account, $manager, null, ['name' => 'Review', 'key' => 'review', 'imap_folder' => 'Cases', 'is_final' => false]);

    expect($state->imap_folder)->toBe('Cases');
    expect(fn () => $service->save($account, $manager, null, ['name' => 'Other final', 'key' => 'other-final', 'is_final' => true]))->toThrow(ValidationException::class);
    expect(fn () => $service->save($account, User::factory()->create(), null, ['name' => 'No', 'key' => 'no', 'is_final' => false]))->toThrow(AuthorizationException::class);
});

test('state service persists the canonical path after an existing remote folder is resolved', function () {
    $owner = User::factory()->create();
    $owner->givePermissionTo('expedientes.ver');
    $account = MailAccount::factory()->for($owner)->create();
    $mailbox = Mockery::mock(ImapMailboxService::class);
    $mailbox->shouldReceive('createFolder')->once()->with($account, 'Cases/Review')->andReturn('Cases/Review');
    $mailbox->shouldReceive('listFolders')->once()->with($account)->andReturn(collect([['path' => 'Cases/Review', 'name' => 'Review']]));

    $state = (new ExpedientStateService($mailbox))->save($account, $owner, null, [
        'name' => 'Review',
        'key' => 'review',
        'is_final' => false,
    ], 'Cases/Review');

    expect($state->imap_folder)->toBe('Cases/Review');
});

test('state service propagates unrelated IMAP folder creation errors', function () {
    $owner = User::factory()->create();
    $owner->givePermissionTo('expedientes.ver');
    $account = MailAccount::factory()->for($owner)->create();
    $mailbox = Mockery::mock(ImapMailboxService::class);
    $mailbox->shouldReceive('createFolder')->once()->andThrow(new RuntimeException('IMAP unavailable'));

    expect(fn () => (new ExpedientStateService($mailbox))->save($account, $owner, null, [
        'name' => 'Review',
        'key' => 'review',
        'is_final' => false,
    ], 'Cases/Review'))->toThrow(RuntimeException::class, 'IMAP unavailable');
});

test('state configuration access does not permit transitions for a non-owned account', function () {
    $owner = User::factory()->create();
    $account = MailAccount::factory()->for($owner)->create();
    $manager = User::factory()->create();
    $manager->givePermissionTo('expedientes.ver');
    $expedient = Expedient::factory()->for($account)->create();
    $target = $account->expedientStates()->where('key', 'pending_provider')->sole();

    expect(fn () => (new ExpedientStateService(Mockery::mock(ImapMailboxService::class)))->transition($expedient, $target, $manager))
        ->toThrow(AuthorizationException::class);
});

test('state transition moves associated IMAP evidence first and records the new remote UID', function () {
    $owner = User::factory()->create();
    $account = MailAccount::factory()->for($owner)->create();
    $expedient = Expedient::factory()->for($account)->create();
    $target = $account->expedientStates()->create(['name' => 'Review', 'key' => 'review', 'imap_folder' => 'Cases']);
    $reference = app(ImapExpedientBridgeService::class)->associate($account, $expedient, $owner, bridgeEnvelope());
    $mailbox = Mockery::mock(ImapMailboxService::class);
    $mailbox->shouldReceive('moveMessage')->once()->with(Mockery::on(fn (MailAccount $selected): bool => $selected->is($account)), 'INBOX', 42, 'Cases')->andReturn(['folder' => 'Cases', 'uid' => 99]);

    (new ExpedientStateService($mailbox))->transition($expedient, $target, $owner, now()->addDay());

    expect($reference->fresh()->folder)->toBe('Cases')
        ->and($reference->fresh()->imap_uid)->toBe('99')
        ->and($expedient->fresh()->expedient_state_id)->toBe($target->id);
});

test('failed remote moves leave a reconciliation record and do not change the expedient state', function () {
    $owner = User::factory()->create();
    $account = MailAccount::factory()->for($owner)->create();
    $expedient = Expedient::factory()->for($account)->create();
    $target = $account->expedientStates()->create(['name' => 'Review', 'key' => 'review', 'imap_folder' => 'Cases']);
    $reference = app(ImapExpedientBridgeService::class)->associate($account, $expedient, $owner, bridgeEnvelope());
    $mailbox = Mockery::mock(ImapMailboxService::class);
    $mailbox->shouldReceive('moveMessage')->once()->andThrow(new RuntimeException('IMAP unavailable'));

    expect(fn () => (new ExpedientStateService($mailbox))->transition($expedient, $target, $owner))->toThrow(RuntimeException::class);
    expect($reference->fresh()->reconciliation_status)->toBe('failed')
        ->and($expedient->fresh()->expedient_state_id)->not->toBe($target->id);
});
