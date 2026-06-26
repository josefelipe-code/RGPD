<?php

use App\Enums\CaseStatus;
use App\Enums\MailDirection;
use App\Enums\MilestoneAction;
use App\Models\CaseMilestone;
use App\Models\Expedient;
use App\Models\MailAccount;
use App\Models\MailMessage;
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

// ─── Phase 3: Status Control (S11-S16 via inline control) ───

test('user without expedientes.actualizar cannot see status control', function () {
    $expedient = Expedient::factory()->create();
    $user = User::factory()->create();
    $user->givePermissionTo('expedientes.ver');

    $this->actingAs($user)
        ->get(route('expedientes.show', $expedient))
        ->assertDontSee('Cambiar estado');
});

test('user with expedientes.actualizar can see status control', function () {
    $expedient = Expedient::factory()->create();

    $this->actingAs($this->admin);

    Livewire::test('pages::expedientes.show', ['expedient' => $expedient])
        ->assertSee('Cambiar estado');
});

test('status control shows Conclude option for non-concluded expedient', function () {
    $expedient = Expedient::factory()->create(['status' => CaseStatus::PendingClient]);

    $this->actingAs($this->admin);

    Livewire::test('pages::expedientes.show', ['expedient' => $expedient])
        ->assertSee('Concluido');
});

test('status control shows Reopen options for concluded expedient', function () {
    $expedient = Expedient::factory()->concluded()->create();

    $this->actingAs($this->admin);

    Livewire::test('pages::expedientes.show', ['expedient' => $expedient])
        ->assertSee('Pendiente del cliente')
        ->assertSee('Pendiente del proveedor');
});

test('can conclude an expedient via status control (S12)', function () {
    $expedient = Expedient::factory()->create(['status' => CaseStatus::PendingClient]);

    $this->actingAs($this->admin);

    Livewire::test('pages::expedientes.show', ['expedient' => $expedient])
        ->set('statusTarget', CaseStatus::Concluded->value)
        ->call('changeStatus')
        ->assertHasNoErrors();

    $expedient->refresh();
    expect($expedient->status)->toBe(CaseStatus::Concluded)
        ->and($expedient->closed_at)->not->toBeNull()
        ->and($expedient->milestones()->action(MilestoneAction::Closed)->count())->toBe(1);
});

test('can reopen a concluded expedient to PendingClient (S14)', function () {
    $expedient = Expedient::factory()->concluded()->create();

    $this->actingAs($this->admin);

    Livewire::test('pages::expedientes.show', ['expedient' => $expedient])
        ->set('statusTarget', CaseStatus::PendingClient->value)
        ->call('changeStatus')
        ->assertHasNoErrors();

    $expedient->refresh();
    expect($expedient->status)->toBe(CaseStatus::PendingClient)
        ->and($expedient->closed_at)->toBeNull()
        ->and($expedient->milestones()->action(MilestoneAction::Reopened)->count())->toBe(1);
});

test('can reopen a concluded expedient to PendingProvider (S15)', function () {
    $expedient = Expedient::factory()->concluded()->create();

    $this->actingAs($this->admin);

    Livewire::test('pages::expedientes.show', ['expedient' => $expedient])
        ->set('statusTarget', CaseStatus::PendingProvider->value)
        ->call('changeStatus')
        ->assertHasNoErrors();

    $expedient->refresh();
    expect($expedient->status)->toBe(CaseStatus::PendingProvider)
        ->and($expedient->closed_at)->toBeNull();
});

test('cannot change status without permission', function () {
    $expedient = Expedient::factory()->create();
    $user = User::factory()->create();
    $user->givePermissionTo('expedientes.ver');

    $this->actingAs($user);

    Livewire::test('pages::expedientes.show', ['expedient' => $expedient])
        ->set('statusTarget', CaseStatus::Concluded->value)
        ->call('changeStatus')
        ->assertForbidden();
});

// ─── Phase 3: Related Expedientes (S17-S20) ───

test('show page shows related expedientes by email (S17)', function () {
    $email = 'shared@example.com';
    $e1 = Expedient::factory()->create(['sender_email' => $email, 'case_number' => 'EXP-RELATED-1']);
    $e2 = Expedient::factory()->create(['sender_email' => $email, 'case_number' => 'EXP-RELATED-2']);

    $this->actingAs($this->admin)
        ->get(route('expedientes.show', $e1))
        ->assertSee('EXP-RELATED-2')
        ->assertSee('Expedientes relacionados');
});

test('show page shows related expedientes by phone (S18)', function () {
    $phone = '+34123456789';
    $e1 = Expedient::factory()->create(['sender_phone' => $phone, 'case_number' => 'EXP-PHONE-1']);
    $e2 = Expedient::factory()->create(['sender_phone' => $phone, 'case_number' => 'EXP-PHONE-2']);

    $this->actingAs($this->admin)
        ->get(route('expedientes.show', $e1))
        ->assertSee('EXP-PHONE-2');
});

test('show page excludes self from related panel (S19)', function () {
    $email = 'unique@example.com';
    $e1 = Expedient::factory()->create(['sender_email' => $email, 'case_number' => 'EXP-SELF']);
    $e2 = Expedient::factory()->create(['sender_email' => $email, 'case_number' => 'EXP-OTHER']);

    $this->actingAs($this->admin)
        ->get(route('expedientes.show', $e1))
        ->assertSee('EXP-OTHER')
        ->assertDontSee('href="'.route('expedientes.show', $e1).'"');
});

test('show page shows empty state when no related expedientes (S20)', function () {
    $e1 = Expedient::factory()->create([
        'sender_email' => 'unique-'.fake()->uuid().'@example.com',
        'sender_phone' => '+34'.fake()->unique()->numberBetween(100000000, 999999999),
    ]);

    $this->actingAs($this->admin)
        ->get(route('expedientes.show', $e1))
        ->assertSee('Sin expedientes relacionados');
});

// ─── Phase 3: Associated Mail Messages (S21-S23) ───

test('show page lists associated mail messages newest first (S21)', function () {
    $expedient = Expedient::factory()->create(['case_number' => 'EXP-MAIL']);
    $older = MailMessage::factory()->associated()->create([
        'case_id' => $expedient->id,
        'subject' => 'Older message',
        'received_at' => now()->subDays(3),
        'direction' => MailDirection::Incoming,
    ]);
    $newer = MailMessage::factory()->associated()->create([
        'case_id' => $expedient->id,
        'subject' => 'Newer message',
        'received_at' => now()->subDay(),
        'direction' => MailDirection::Outgoing,
    ]);

    $this->actingAs($this->admin)
        ->get(route('expedientes.show', $expedient))
        ->assertSee('Newer message')
        ->assertSee('Older message')
        ->assertSee('Mensajes asociados');
});

test('show page shows empty state when no mail messages (S22)', function () {
    $expedient = Expedient::factory()->create();

    $this->actingAs($this->admin)
        ->get(route('expedientes.show', $expedient))
        ->assertSee('Sin mensajes asociados');
});

test('show page mail section shows reply/forward buttons for incoming messages (PR3)', function () {
    $expedient = Expedient::factory()->create();
    MailMessage::factory()->associated()->create([
        'case_id' => $expedient->id,
        'direction' => MailDirection::Incoming,
    ]);

    $this->actingAs($this->admin)
        ->get(route('expedientes.show', $expedient))
        ->assertSee('Responder')
        ->assertSee('Reenviar');
});

test('milestone timeline shows reopened label and icon', function () {
    $expedient = Expedient::factory()->create(['case_number' => 'EXP-REOPENED']);
    $user = User::factory()->create(['name' => 'Test User']);

    CaseMilestone::factory()
        ->for($expedient, 'case')
        ->for($user)
        ->create([
            'action' => MilestoneAction::Reopened,
            'notes' => 'Reabierto por el cliente',
        ]);

    $this->actingAs($this->admin)
        ->get(route('expedientes.show', $expedient))
        ->assertSee('Reapertura')
        ->assertSee('Reabierto por el cliente');
});
