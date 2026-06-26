<?php

use App\Enums\CaseStatus;
use App\Enums\MilestoneAction;
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

// Create tests

test('user without crear permission cannot create expedients', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('expedientes.ver');

    $this->actingAs($user);

    Livewire::test('pages::expedientes.index')
        ->call('create')
        ->assertForbidden();
});

test('user with crear permission can open create form', function () {
    $user = User::factory()->create();
    $user->givePermissionTo(['expedientes.ver', 'expedientes.crear']);

    $this->actingAs($user);

    Livewire::test('pages::expedientes.index')
        ->call('create')
        ->assertSet('editingExpedientId', null)
        ->assertSet('caseNumber', '')
        ->assertSet('status', CaseStatus::PendingClient->value);
});

test('can create a new expedient', function () {
    $assignee = User::factory()->create();

    $this->actingAs($this->admin);

    Livewire::test('pages::expedientes.index')
        ->call('create')
        ->set('caseNumber', 'EXP-99999')
        ->set('senderEmail', 'test@example.com')
        ->set('senderPhone', '+5491112345678')
        ->set('mailAccountId', $this->mailAccount->id)
        ->set('assignedUserId', $assignee->id)
        ->set('status', CaseStatus::PendingClient->value)
        ->set('requestType', 'consulta')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('cases', [
        'case_number' => 'EXP-99999',
        'sender_email' => 'test@example.com',
        'request_type' => 'consulta',
    ]);
});

test('case number must be unique', function () {
    Expedient::factory()->create(['case_number' => 'EXP-UNIQUE']);

    $assignee = User::factory()->create();

    $this->actingAs($this->admin);

    Livewire::test('pages::expedientes.index')
        ->call('create')
        ->set('caseNumber', 'EXP-UNIQUE')
        ->set('senderEmail', 'test@example.com')
        ->set('mailAccountId', $this->mailAccount->id)
        ->set('assignedUserId', $assignee->id)
        ->set('status', CaseStatus::PendingClient->value)
        ->call('save')
        ->assertHasErrors(['caseNumber' => 'unique']);
});

test('case number is required', function () {
    $assignee = User::factory()->create();

    $this->actingAs($this->admin);

    Livewire::test('pages::expedientes.index')
        ->call('create')
        ->set('caseNumber', '')
        ->set('mailAccountId', $this->mailAccount->id)
        ->set('assignedUserId', $assignee->id)
        ->set('status', CaseStatus::PendingClient->value)
        ->call('save')
        ->assertHasErrors(['caseNumber' => 'required']);
});

// Edit tests

test('user without actualizar permission cannot edit expedients', function () {
    $expedient = Expedient::factory()->create();
    $user = User::factory()->create();
    $user->givePermissionTo(['expedientes.ver', 'expedientes.crear']);

    $this->actingAs($user);

    Livewire::test('pages::expedientes.index')
        ->call('edit', $expedient->id)
        ->assertForbidden();
});

test('can edit an existing expedient', function () {
    $expedient = Expedient::factory()->create([
        'case_number' => 'EXP-EDIT',
        'sender_email' => 'original@example.com',
        'mail_account_id' => $this->mailAccount->id,
    ]);

    $this->actingAs($this->admin);

    Livewire::test('pages::expedientes.index')
        ->call('edit', $expedient->id)
        ->assertSet('editingExpedientId', $expedient->id)
        ->assertSet('caseNumber', 'EXP-EDIT')
        ->assertSet('senderEmail', 'original@example.com')
        ->assertSet('status', $expedient->status->value);
});

test('can update an expedient', function () {
    $expedient = Expedient::factory()->create([
        'case_number' => 'EXP-UPDATE',
        'mail_account_id' => $this->mailAccount->id,
    ]);

    $newAssignee = User::factory()->create();

    $this->actingAs($this->admin);

    Livewire::test('pages::expedientes.index')
        ->call('edit', $expedient->id)
        ->set('caseNumber', 'EXP-UPDATED')
        ->set('senderEmail', 'updated@example.com')
        ->set('mailAccountId', $this->mailAccount->id)
        ->set('assignedUserId', $newAssignee->id)
        ->set('status', CaseStatus::PendingProvider->value)
        ->set('requestType', 'reclamo')
        ->call('save')
        ->assertHasNoErrors();

    $expedient->refresh();
    expect($expedient->case_number)->toBe('EXP-UPDATED')
        ->and($expedient->sender_email)->toBe('updated@example.com')
        ->and($expedient->request_type)->toBe('reclamo')
        ->and($expedient->status->value)->toBe(CaseStatus::PendingProvider->value);
});

test('can cancel form and reset state', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::expedientes.index')
        ->call('create')
        ->set('caseNumber', 'EXP-DRAFT')
        ->call('cancel')
        ->assertSet('editingExpedientId', null)
        ->assertSet('caseNumber', '');
});

test('created expedient appears in the list', function () {
    $assignee = User::factory()->create();

    $this->actingAs($this->admin);

    Livewire::test('pages::expedientes.index')
        ->call('create')
        ->set('caseNumber', 'EXP-LISTED')
        ->set('mailAccountId', $this->mailAccount->id)
        ->set('assignedUserId', $assignee->id)
        ->set('status', CaseStatus::PendingClient->value)
        ->call('save')
        ->assertSee('EXP-LISTED');
});

// ─── PR2: Lifecycle integration on create (S11, S16) ───

test('creating expedient stamps opened_at and creates Opened milestone (S11)', function () {
    $assignee = User::factory()->create();

    $this->actingAs($this->admin);

    Livewire::test('pages::expedientes.index')
        ->call('create')
        ->set('caseNumber', 'EXP-LIFECYCLE')
        ->set('senderEmail', 'lifecycle@example.com')
        ->set('mailAccountId', $this->mailAccount->id)
        ->set('assignedUserId', $assignee->id)
        ->set('status', CaseStatus::PendingClient->value)
        ->call('save')
        ->assertHasNoErrors();

    $expedient = Expedient::where('case_number', 'EXP-LIFECYCLE')->first();

    expect($expedient->opened_at)->not->toBeNull()
        ->and($expedient->milestones()->action(MilestoneAction::Opened)->count())->toBe(1);
});

test('creating expedient with any status stamps opened_at (S16)', function () {
    $assignee = User::factory()->create();

    $this->actingAs($this->admin);

    Livewire::test('pages::expedientes.index')
        ->call('create')
        ->set('caseNumber', 'EXP-CONCLUDED-CREATE')
        ->set('mailAccountId', $this->mailAccount->id)
        ->set('assignedUserId', $assignee->id)
        ->set('status', CaseStatus::Concluded->value)
        ->call('save')
        ->assertHasNoErrors();

    $expedient = Expedient::where('case_number', 'EXP-CONCLUDED-CREATE')->first();

    expect($expedient->opened_at)->not->toBeNull()
        ->and($expedient->status)->toBe(CaseStatus::Concluded);
});

test('editing expedient with status change calls transitionTo (S12)', function () {
    $expedient = Expedient::factory()->create([
        'case_number' => 'EXP-TRANSITION',
        'status' => CaseStatus::PendingClient,
        'mail_account_id' => $this->mailAccount->id,
    ]);

    $this->actingAs($this->admin);

    Livewire::test('pages::expedientes.index')
        ->call('edit', $expedient->id)
        ->set('caseNumber', 'EXP-TRANSITION')
        ->set('mailAccountId', $this->mailAccount->id)
        ->set('assignedUserId', $expedient->assigned_user_id)
        ->set('status', CaseStatus::Concluded->value)
        ->call('save')
        ->assertHasNoErrors();

    $expedient->refresh();

    expect($expedient->status)->toBe(CaseStatus::Concluded)
        ->and($expedient->closed_at)->not->toBeNull()
        ->and($expedient->milestones()->action(MilestoneAction::Closed)->count())->toBe(1);
});

test('editing expedient without status change does not create milestone (S13)', function () {
    $expedient = Expedient::factory()->create([
        'case_number' => 'EXP-NOTRANSITION',
        'status' => CaseStatus::PendingClient,
        'mail_account_id' => $this->mailAccount->id,
    ]);

    $this->actingAs($this->admin);

    Livewire::test('pages::expedientes.index')
        ->call('edit', $expedient->id)
        ->set('caseNumber', 'EXP-NOTRANSITION-UPDATED')
        ->set('mailAccountId', $this->mailAccount->id)
        ->set('assignedUserId', $expedient->assigned_user_id)
        ->set('status', CaseStatus::PendingClient->value)
        ->call('save')
        ->assertHasNoErrors();

    $expedient->refresh();

    expect($expedient->case_number)->toBe('EXP-NOTRANSITION-UPDATED')
        ->and($expedient->closed_at)->toBeNull()
        ->and($expedient->milestones()->count())->toBe(0);
});
