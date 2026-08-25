<?php

use App\Models\Expedient;
use App\Models\MailAccount;
use App\Models\User;
use App\Services\Bandeja\ImapMailboxService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->user = User::factory()->create();
    $this->user->assignRole('Super Administrador');

    $mailbox = Mockery::mock(ImapMailboxService::class);
    $mailbox->shouldReceive('listFolders')->zeroOrMoreTimes()->andReturn(collect([
        ['path' => 'Cases', 'name' => 'Cases'],
        ['path' => 'Cases/Review', 'name' => 'Review'],
    ]));
    $this->app->instance(ImapMailboxService::class, $mailbox);
});

test('authorized users can access the expedient states page and sidebar entry', function () {
    $this->actingAs($this->user)
        ->get(route('expedientes.states.index'))
        ->assertOk()
        ->assertSee('Estados de expedientes');

    $this->get(route('dashboard'))
        ->assertSee('Estados');
});

test('users without expedient access cannot manage states', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('expedientes.states.index'))
        ->assertForbidden();
});

test('users with expedient access can create states without the generic create permission', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('expedientes.ver');

    Livewire::actingAs($user)
        ->test('pages::expedientes.states')
        ->call('create')
        ->assertHasNoErrors();
});

test('a non-owner with expedient access can manage state folder mappings for active accounts', function () {
    $manager = User::factory()->create();
    $manager->givePermissionTo('expedientes.ver');
    $account = MailAccount::factory()->create();
    $state = $account->expedientStates()->create(['name' => 'Review', 'key' => 'review', 'imap_folder' => 'Cases']);

    Livewire::actingAs($manager);

    Livewire::test('pages::expedientes.states')
        ->call('create')
        ->set('selectedMailAccountId', (string) $account->id)
        ->set('name', 'Waiting')
        ->set('key', 'waiting')
        ->set('imapFolder', 'Cases')
        ->call('save')
        ->assertHasNoErrors()
        ->call('edit', $state->id)
        ->set('name', 'Reviewed')
        ->set('imapFolder', 'Cases/Review')
        ->call('save')
        ->assertHasNoErrors();

    expect($account->expedientStates()->where('key', 'waiting')->exists())->toBeTrue()
        ->and($state->fresh()->name)->toBe('Reviewed')
        ->and($state->fresh()->imap_folder)->toBe('Cases/Review');
});

test('state form persists a folder resolved from an already existing remote mailbox', function () {
    $account = MailAccount::factory()->for($this->user)->create();
    $mailbox = app(ImapMailboxService::class);
    $mailbox->shouldReceive('createFolder')->once()->with(Mockery::on(fn (MailAccount $selected): bool => $selected->is($account)), 'Cases/Review')->andReturn('Cases/Review');

    Livewire::actingAs($this->user);

    Livewire::test('pages::expedientes.states')
        ->call('create')
        ->set('selectedMailAccountId', (string) $account->id)
        ->set('name', 'Review')
        ->set('key', 'review')
        ->set('newImapFolder', 'Cases/Review')
        ->call('save')
        ->assertHasNoErrors();

    expect($account->expedientStates()->where('key', 'review')->sole()->imap_folder)->toBe('Cases/Review');
});

test('only one final state may be created for each account', function () {
    $account = MailAccount::factory()->for($this->user)->create();

    Livewire::actingAs($this->user);

    Livewire::test('pages::expedientes.states')
        ->call('create')
        ->set('selectedMailAccountId', (string) $account->id)
        ->set('name', 'Another final state')
        ->set('key', 'another-final')
        ->set('isFinal', true)
        ->call('save')
        ->assertHasErrors(['is_final']);
});

test('final and in-use states cannot be deleted', function () {
    $account = MailAccount::factory()->for($this->user)->create();
    $final = $account->expedientStates()->where('is_final', true)->sole();
    $inUse = $account->expedientStates()->create(['name' => 'Review', 'key' => 'review']);
    $deletable = $account->expedientStates()->create(['name' => 'Draft', 'key' => 'draft']);
    Expedient::factory()->for($account)->create(['expedient_state_id' => $inUse->id]);

    Livewire::actingAs($this->user);

    Livewire::test('pages::expedientes.states')
        ->call('delete', $final->id)
        ->assertHasErrors(['state']);
    Livewire::test('pages::expedientes.states')
        ->call('delete', $inUse->id)
        ->assertHasErrors(['state']);
    Livewire::test('pages::expedientes.states')
        ->call('delete', $deletable->id)
        ->assertHasNoErrors();

    expect($final->fresh())->not->toBeNull()
        ->and($inUse->fresh())->not->toBeNull()
        ->and($deletable->fresh())->toBeNull();
});
