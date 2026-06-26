<?php

use App\Enums\CaseStatus;
use App\Models\Expedient;
use App\Models\MailAccount;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class)->in(__DIR__);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->user = User::factory()->create();
    $this->user->assignRole('Super Administrador');

    $this->mailAccount1 = MailAccount::factory()->create(['label' => 'Account A', 'is_active' => true]);
    $this->mailAccount2 = MailAccount::factory()->create(['label' => 'Account B', 'is_active' => true]);
});

// Access tests

test('non authenticated users cannot access expedientes routes', function () {
    $this->get(route('expedientes.index'))
        ->assertRedirect(route('login'));
});

test('users without permission cannot access expedientes page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('expedientes.index'))
        ->assertForbidden();
});

test('super admins can access expedientes page', function () {
    $this->actingAs($this->user)
        ->get(route('expedientes.index'))
        ->assertOk()
        ->assertSee('Expedientes');
});

// Render tests

test('expedientes page shows page heading with correct content', function () {
    $this->actingAs($this->user)
        ->get(route('expedientes.index'))
        ->assertSee('Expedientes')
        ->assertSee('Gestioná los expedientes');
});

test('expedientes page renders toolbar with search', function () {
    $this->actingAs($this->user)
        ->get(route('expedientes.index'))
        ->assertSee('Buscar por número, email o tipo');
});

test('expedientes page renders table with actions column', function () {
    $this->actingAs($this->user)
        ->get(route('expedientes.index'))
        ->assertSee('Acciones');
});

test('expedientes page shows empty state when no records', function () {
    $this->actingAs($this->user)
        ->get(route('expedientes.index'))
        ->assertSee('No hay expedientes disponibles');
});

test('expedientes page lists existing expedients', function () {
    Expedient::factory()->create(['case_number' => 'EXP-12345']);

    $this->actingAs($this->user)
        ->get(route('expedientes.index'))
        ->assertSee('EXP-12345');
});

// Permission tests

test('user without crear permission cannot see create button', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('expedientes.ver');

    $this->actingAs($user)
        ->get(route('expedientes.index'))
        ->assertDontSee('wire:click="create"');
});

test('user with crear permission can see create button', function () {
    $user = User::factory()->create();
    $user->givePermissionTo(['expedientes.ver', 'expedientes.crear']);

    $this->actingAs($user)
        ->get(route('expedientes.index'))
        ->assertSee('Crear expediente');
});

// Livewire component tests

test('can search expedients by case number', function () {
    Expedient::factory()->create(['case_number' => 'EXP-11111']);
    Expedient::factory()->create(['case_number' => 'EXP-22222']);

    $this->actingAs($this->user);

    Livewire::test('pages::expedientes.index')
        ->set('search', '11111')
        ->assertSee('EXP-11111')
        ->assertDontSee('EXP-22222');
});

test('can filter expedients by status', function () {
    Expedient::factory()->create(['case_number' => 'EXP-PENDING']);
    Expedient::factory()->concluded()->create(['case_number' => 'EXP-CONCLUDED']);

    $this->actingAs($this->user);

    Livewire::test('pages::expedientes.index')
        ->set('statusFilter', 'concluded')
        ->assertSee('EXP-CONCLUDED')
        ->assertDontSee('EXP-PENDING');
});

// ─── PR2: Soft-delete exclusion from index (S1) ───

test('soft-deleted expedient is not visible on index (S1)', function () {
    $active = Expedient::factory()->create(['case_number' => 'EXP-ACTIVE']);
    $trashed = Expedient::factory()->create(['case_number' => 'EXP-TRASHED']);
    $trashed->delete();

    $this->actingAs($this->user);

    Livewire::test('pages::expedientes.index')
        ->assertSee('EXP-ACTIVE')
        ->assertDontSee('EXP-TRASHED');
});

// ─── PR2: Delete action with authorization (S5, S6, S7) ───

test('user without eliminar permission does not see delete button (S6)', function () {
    $expedient = Expedient::factory()->create();
    $user = User::factory()->create();
    $user->givePermissionTo(['expedientes.ver', 'expedientes.crear']);

    $this->actingAs($user);

    Livewire::test('pages::expedientes.index')
        ->assertDontSee('confirmDelete('.$expedient->id.')');
});

test('user with eliminar permission sees delete button (S5)', function () {
    $expedient = Expedient::factory()->create();
    $user = User::factory()->create();
    $user->givePermissionTo(['expedientes.ver', 'expedientes.eliminar']);

    $this->actingAs($user);

    Livewire::test('pages::expedientes.index')
        ->assertSee('confirmDelete('.$expedient->id.')');
});

test('authorized user can soft-delete an expedient (S5)', function () {
    $expedient = Expedient::factory()->create(['case_number' => 'EXP-DELETE']);
    $user = User::factory()->create();
    $user->givePermissionTo(['expedientes.ver', 'expedientes.eliminar']);

    $this->actingAs($user);

    Livewire::test('pages::expedientes.index')
        ->call('delete', $expedient->id)
        ->assertHasNoErrors();

    $this->assertSoftDeleted('cases', ['id' => $expedient->id]);
});

test('unauthorized user cannot delete even if they call the method (S3)', function () {
    $expedient = Expedient::factory()->create();
    $user = User::factory()->create();
    $user->givePermissionTo('expedientes.ver');

    $this->actingAs($user);

    Livewire::test('pages::expedientes.index')
        ->call('delete', $expedient->id)
        ->assertForbidden();

    $this->assertNotSoftDeleted('cases', ['id' => $expedient->id]);
});

// ─── PR2: Mail account filter on index (S8, S9, S10) ───

test('account filter renders with all enabled accounts (S8)', function () {
    $this->actingAs($this->user);

    Livewire::test('pages::expedientes.index')
        ->assertSee('Account A')
        ->assertSee('Account B');
});

test('selecting account filter scopes expedients list (S8)', function () {
    Expedient::factory()->count(3)->create([
        'case_number' => fn () => 'EXP-A-'.fake()->unique()->numberBetween(100, 999),
        'mail_account_id' => $this->mailAccount1->id,
    ]);
    Expedient::factory()->count(2)->create([
        'case_number' => fn () => 'EXP-B-'.fake()->unique()->numberBetween(100, 999),
        'mail_account_id' => $this->mailAccount2->id,
    ]);

    $this->actingAs($this->user);

    Livewire::test('pages::expedientes.index')
        ->set('mailAccountIdFilter', $this->mailAccount1->id)
        ->assertSee('EXP-A-')
        ->assertDontSee('EXP-B-');
});

test('selecting account filter scopes status counts (S8)', function () {
    Expedient::factory()->create([
        'mail_account_id' => $this->mailAccount1->id,
        'status' => CaseStatus::PendingClient,
    ]);
    Expedient::factory()->create([
        'mail_account_id' => $this->mailAccount2->id,
        'status' => CaseStatus::Concluded,
    ]);

    $this->actingAs($this->user);

    $component = Livewire::test('pages::expedientes.index');

    // Before filter: both statuses visible
    $component->assertSee('Pendiente del cliente');

    // After filter: only account 1's status
    $component->set('mailAccountIdFilter', $this->mailAccount1->id)
        ->assertSee('Pendiente del cliente');
});

test('clearing account filter restores full list (S10)', function () {
    Expedient::factory()->create([
        'case_number' => 'EXP-ACCOUNT1',
        'mail_account_id' => $this->mailAccount1->id,
    ]);
    Expedient::factory()->create([
        'case_number' => 'EXP-ACCOUNT2',
        'mail_account_id' => $this->mailAccount2->id,
    ]);

    $this->actingAs($this->user);

    Livewire::test('pages::expedientes.index')
        ->set('mailAccountIdFilter', $this->mailAccount1->id)
        ->assertSee('EXP-ACCOUNT1')
        ->assertDontSee('EXP-ACCOUNT2')
        ->set('mailAccountIdFilter', 0)
        ->assertSee('EXP-ACCOUNT1')
        ->assertSee('EXP-ACCOUNT2');
});

test('account filter persists with search (S9)', function () {
    Expedient::factory()->create([
        'case_number' => 'EXP-SEARCH-A',
        'mail_account_id' => $this->mailAccount1->id,
    ]);
    Expedient::factory()->create([
        'case_number' => 'EXP-OTHER-A',
        'mail_account_id' => $this->mailAccount1->id,
    ]);
    Expedient::factory()->create([
        'case_number' => 'EXP-SEARCH-B',
        'mail_account_id' => $this->mailAccount2->id,
    ]);

    $this->actingAs($this->user);

    Livewire::test('pages::expedientes.index')
        ->set('mailAccountIdFilter', $this->mailAccount1->id)
        ->set('search', 'SEARCH')
        ->assertSee('EXP-SEARCH-A')
        ->assertDontSee('EXP-OTHER-A')
        ->assertDontSee('EXP-SEARCH-B');
});
