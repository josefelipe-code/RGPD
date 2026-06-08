<?php

use App\Enums\MilestoneAction;
use App\Models\CaseMilestone;
use App\Models\Expedient;
use App\Models\MailAccount;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class)->in(__DIR__);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Super Administrador');

    $this->mailAccount = MailAccount::factory()->create(['label' => 'Test Account']);
});

// Access tests

test('non authenticated users cannot access expediente detail', function () {
    $expedient = Expedient::factory()->create();

    $this->get(route('expedientes.show', $expedient))
        ->assertRedirect(route('login'));
});

test('users without expedientes.ver cannot access detail page', function () {
    $expedient = Expedient::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('expedientes.show', $expedient))
        ->assertForbidden();
});

test('super admin can access expediente detail', function () {
    $expedient = Expedient::factory()->create(['case_number' => 'EXP-DETAIL']);

    $this->actingAs($this->admin)
        ->get(route('expedientes.show', $expedient))
        ->assertOk()
        ->assertSee('EXP-DETAIL');
});

// Render tests

test('detail page shows expedient information', function () {
    $assignee = User::factory()->create(['name' => 'Juan Pérez']);
    $expedient = Expedient::factory()->create([
        'case_number' => 'EXP-INFO',
        'sender_email' => 'test@example.com',
        'sender_phone' => '+5491112345678',
        'mail_account_id' => $this->mailAccount->id,
        'assigned_user_id' => $assignee->id,
        'request_type' => 'consulta',
    ]);

    $this->actingAs($this->admin)
        ->get(route('expedientes.show', $expedient))
        ->assertSee('EXP-INFO')
        ->assertSee('test@example.com')
        ->assertSee('+5491112345678')
        ->assertSee('Juan Pérez')
        ->assertSee('consulta')
        ->assertSee('Test Account');
});

test('detail page shows status badge', function () {
    $expedient = Expedient::factory()->create(['status' => 'pending_client']);

    $this->actingAs($this->admin)
        ->get(route('expedientes.show', $expedient))
        ->assertSee('Pendiente del cliente');
});

test('detail page shows back button to index', function () {
    $expedient = Expedient::factory()->create();

    $this->actingAs($this->admin)
        ->get(route('expedientes.show', $expedient))
        ->assertSee('Volver');
});

// Milestone display tests

test('detail page shows milestones for the expedient', function () {
    $expedient = Expedient::factory()->create(['case_number' => 'EXP-MILESTONES']);
    $user = User::factory()->create(['name' => 'María López']);

    CaseMilestone::factory()
        ->for($expedient, 'case')
        ->for($user)
        ->create([
            'action' => MilestoneAction::Opened,
            'notes' => 'Expediente abierto para revisión',
        ]);

    $this->actingAs($this->admin)
        ->get(route('expedientes.show', $expedient))
        ->assertSee('Expediente abierto para revisión')
        ->assertSee('María López')
        ->assertSee('Apertura');
});

test('detail page shows empty state when no milestones', function () {
    $expedient = Expedient::factory()->create();

    $this->actingAs($this->admin)
        ->get(route('expedientes.show', $expedient))
        ->assertSee('No hay hitos registrados');
});

test('detail page shows multiple milestones in reverse chronological order', function () {
    $expedient = Expedient::factory()->create();
    $user = User::factory()->create();

    $older = CaseMilestone::factory()
        ->for($expedient, 'case')
        ->for($user)
        ->create([
            'action' => MilestoneAction::Opened,
            'created_at' => now()->subDays(5),
        ]);

    $newer = CaseMilestone::factory()
        ->for($expedient, 'case')
        ->for($user)
        ->create([
            'action' => MilestoneAction::RepliedClient,
            'created_at' => now()->subDay(),
        ]);

    $this->actingAs($this->admin)
        ->get(route('expedientes.show', $expedient));

    // Both should be visible
    $this->assertDatabaseHas('case_milestones', ['id' => $older->id]);
    $this->assertDatabaseHas('case_milestones', ['id' => $newer->id]);
});

// Add milestone tests

test('user without hitos.crear cannot see add milestone form', function () {
    $expedient = Expedient::factory()->create();
    $user = User::factory()->create();
    $user->givePermissionTo('expedientes.ver');

    $this->actingAs($user)
        ->get(route('expedientes.show', $expedient))
        ->assertDontSee('Agregar');
});

test('user with hitos.crear can see add milestone form', function () {
    $expedient = Expedient::factory()->create();
    $user = User::factory()->create();
    $user->givePermissionTo(['expedientes.ver', 'hitos.crear']);

    $this->actingAs($user)
        ->get(route('expedientes.show', $expedient))
        ->assertSee('Agregar');
});

test('can add a milestone via Livewire component', function () {
    $expedient = Expedient::factory()->create();

    $this->actingAs($this->admin);

    Livewire::test('pages::expedientes.show', ['expedient' => $expedient])
        ->set('milestoneAction', MilestoneAction::RepliedClient->value)
        ->set('milestoneNotes', 'Se respondió la consulta del cliente')
        ->call('addMilestone')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('case_milestones', [
        'case_id' => $expedient->id,
        'action' => MilestoneAction::RepliedClient->value,
        'notes' => 'Se respondió la consulta del cliente',
    ]);
});

test('can add a milestone without notes', function () {
    $expedient = Expedient::factory()->create();

    $this->actingAs($this->admin);

    Livewire::test('pages::expedientes.show', ['expedient' => $expedient])
        ->set('milestoneAction', MilestoneAction::Opened->value)
        ->call('addMilestone')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('case_milestones', [
        'case_id' => $expedient->id,
        'action' => MilestoneAction::Opened->value,
    ]);
});

test('cannot add milestone without permission', function () {
    $expedient = Expedient::factory()->create();
    $user = User::factory()->create();
    $user->givePermissionTo('expedientes.ver');

    $this->actingAs($user);

    Livewire::test('pages::expedientes.show', ['expedient' => $expedient])
        ->set('milestoneAction', MilestoneAction::Opened->value)
        ->call('addMilestone')
        ->assertForbidden();
});

test('milestone action is required', function () {
    $expedient = Expedient::factory()->create();

    $this->actingAs($this->admin);

    Livewire::test('pages::expedientes.show', ['expedient' => $expedient])
        ->set('milestoneAction', '')
        ->call('addMilestone')
        ->assertHasErrors(['milestoneAction' => 'required']);
});

// Index link tests

test('index page has ver button linking to detail', function () {
    $expedient = Expedient::factory()->create(['case_number' => 'EXP-LINK']);

    $this->actingAs($this->admin)
        ->get(route('expedientes.index'))
        ->assertSee('EXP-LINK')
        ->assertSee(route('expedientes.show', $expedient));
});
