<?php

use App\Enums\CaseStatus;
use App\Enums\MilestoneAction;
use App\Models\CaseMilestone;
use App\Models\Contact;
use App\Models\Expedient;
use App\Models\MailAccount;
use App\Models\MailMessage;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

// Migration tests

it('has cases table with correct columns', function () {
    expect(Schema::hasTable('cases'))->toBeTrue();

    $columns = Schema::getColumnListing('cases');

    expect($columns)->toContain(
        'id',
        'case_number',
        'sender_email',
        'sender_phone',
        'provider_id',
        'mail_account_id',
        'assigned_user_id',
        'status',
        'request_type',
        'opened_at',
        'closed_at',
        'created_at',
        'updated_at',
    );
});

it('has case_milestones table with correct columns', function () {
    expect(Schema::hasTable('case_milestones'))->toBeTrue();

    $columns = Schema::getColumnListing('case_milestones');

    expect($columns)->toContain(
        'id',
        'case_id',
        'user_id',
        'action',
        'notes',
        'created_at',
        'updated_at',
    );
});

it('case_number column is unique', function () {
    $indexes = Schema::getIndexes('cases');

    $uniqueIndex = collect($indexes)->first(fn ($index) => in_array('case_number', $index['columns']));

    expect($uniqueIndex)->not->toBeNull()
        ->and($uniqueIndex['unique'])->toBeTrue();
});

// Enum tests

test('CaseStatus enum has correct values', function () {
    expect(CaseStatus::cases())->toHaveCount(3)
        ->and(CaseStatus::PendingClient->value)->toBe('pending_client')
        ->and(CaseStatus::PendingProvider->value)->toBe('pending_provider')
        ->and(CaseStatus::Concluded->value)->toBe('concluded');
});

test('MilestoneAction enum has correct values', function () {
    expect(MilestoneAction::cases())->toHaveCount(4)
        ->and(MilestoneAction::Opened->value)->toBe('opened')
        ->and(MilestoneAction::RepliedClient->value)->toBe('replied_client')
        ->and(MilestoneAction::RepliedProvider->value)->toBe('replied_provider')
        ->and(MilestoneAction::Closed->value)->toBe('closed');
});

// Factory tests

test('expedient factory creates valid model', function () {
    $expedient = Expedient::factory()->create();

    expect($expedient)->id->not->toBeNull()
        ->and($expedient->case_number)->toStartWith('EXP-')
        ->and($expedient->status)->toBe(CaseStatus::PendingClient)
        ->and($expedient->closed_at)->toBeNull()
        ->and($expedient->opened_at)->not->toBeNull();
});

test('expedient factory concluded state', function () {
    $expedient = Expedient::factory()->concluded()->create();

    expect($expedient->status)->toBe(CaseStatus::Concluded)
        ->and($expedient->closed_at)->not->toBeNull();
});

test('expedient factory pending provider state', function () {
    $expedient = Expedient::factory()->pendingProvider()->create();

    expect($expedient->status)->toBe(CaseStatus::PendingProvider);
});

test('case milestone factory creates valid model', function () {
    $milestone = CaseMilestone::factory()->create();

    expect($milestone)->id->not->toBeNull()
        ->and($milestone->action)->toBe(MilestoneAction::Opened);
});

test('case milestone factory replied client state', function () {
    $milestone = CaseMilestone::factory()->repliedClient()->create();

    expect($milestone->action)->toBe(MilestoneAction::RepliedClient);
});

test('case milestone factory replied provider state', function () {
    $milestone = CaseMilestone::factory()->repliedProvider()->create();

    expect($milestone->action)->toBe(MilestoneAction::RepliedProvider);
});

test('case milestone factory closed state', function () {
    $milestone = CaseMilestone::factory()->closed()->create();

    expect($milestone->action)->toBe(MilestoneAction::Closed);
});

// Cast tests

test('casts expedient status as CaseStatus enum', function () {
    $expedient = Expedient::factory()->create([
        'status' => CaseStatus::PendingProvider,
    ]);

    $fresh = Expedient::find($expedient->id);

    expect($fresh->status)->toBeInstanceOf(CaseStatus::class)
        ->and($fresh->status)->toBe(CaseStatus::PendingProvider);
});

test('casts milestone action as MilestoneAction enum', function () {
    $milestone = CaseMilestone::factory()->create([
        'action' => MilestoneAction::RepliedProvider,
    ]);

    $fresh = CaseMilestone::find($milestone->id);

    expect($fresh->action)->toBeInstanceOf(MilestoneAction::class)
        ->and($fresh->action)->toBe(MilestoneAction::RepliedProvider);
});

test('casts opened_at and closed_at as datetime', function () {
    $expedient = Expedient::factory()->concluded()->create();

    expect($expedient->opened_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($expedient->closed_at)->toBeInstanceOf(CarbonInterface::class);
});

// Relationship tests

test('expedient belongs to mail account', function () {
    $mailAccount = MailAccount::factory()->create();
    $expedient = Expedient::factory()->for($mailAccount)->create();

    expect($expedient->mailAccount->is($mailAccount))->toBeTrue();
});

test('expedient belongs to assigned user', function () {
    $user = User::factory()->create();
    $expedient = Expedient::factory()->for($user, 'assignedUser')->create();

    expect($expedient->assignedUser->is($user))->toBeTrue();
});

test('expedient belongs to provider contact', function () {
    $provider = Contact::factory()->create();
    $expedient = Expedient::factory()->for($provider, 'provider')->create();

    expect($expedient->provider->is($provider))->toBeTrue();
});

test('expedient has many milestones', function () {
    $expedient = Expedient::factory()->create();
    CaseMilestone::factory()->count(3)->for($expedient, 'case')->create();

    expect($expedient->milestones)->toHaveCount(3);
});

test('expedient has many mail messages', function () {
    $expedient = Expedient::factory()->create();
    MailMessage::factory()->count(2)->for($expedient, 'case')->create();

    expect($expedient->mailMessages)->toHaveCount(2);
});

test('mail account has many expedients', function () {
    $mailAccount = MailAccount::factory()->create();
    Expedient::factory()->count(3)->for($mailAccount)->create();

    expect($mailAccount->cases)->toHaveCount(3);
});

test('contact has many expedients as provider', function () {
    $provider = Contact::factory()->create();
    Expedient::factory()->count(2)->for($provider, 'provider')->create();

    expect($provider->cases)->toHaveCount(2);
});

test('user has many assigned expedients', function () {
    $user = User::factory()->create();
    Expedient::factory()->count(3)->for($user, 'assignedUser')->create();

    expect($user->assignedCases)->toHaveCount(3);
});

test('user has many milestones', function () {
    $user = User::factory()->create();
    CaseMilestone::factory()->count(4)->for($user)->create();

    expect($user->milestones)->toHaveCount(4);
});

test('milestone belongs to expedient', function () {
    $expedient = Expedient::factory()->create();
    $milestone = CaseMilestone::factory()->for($expedient, 'case')->create();

    expect($milestone->case->is($expedient))->toBeTrue();
});

test('milestone belongs to user', function () {
    $user = User::factory()->create();
    $milestone = CaseMilestone::factory()->for($user)->create();

    expect($milestone->user->is($user))->toBeTrue();
});

test('mail message belongs to expedient', function () {
    $expedient = Expedient::factory()->create();
    $message = MailMessage::factory()->for($expedient, 'case')->create();

    expect($message->case->is($expedient))->toBeTrue();
});

// Cascade delete tests

test('deleting expedient cascades to milestones', function () {
    $expedient = Expedient::factory()->create();
    CaseMilestone::factory()->count(3)->for($expedient, 'case')->create();

    $expedient->delete();

    expect(CaseMilestone::count())->toBe(0);
});

test('deleting expedient sets mail message case_id to null', function () {
    $expedient = Expedient::factory()->create();
    $message = MailMessage::factory()->for($expedient, 'case')->create();

    $expedient->delete();

    $message->refresh();
    expect($message->case_id)->toBeNull();
});

// Scope tests

test('open scope returns only non-concluded expedients', function () {
    Expedient::factory()->count(2)->create();
    Expedient::factory()->concluded()->create();

    expect(Expedient::open()->count())->toBe(2);
});

test('concluded scope returns only concluded expedients', function () {
    Expedient::factory()->count(2)->create();
    Expedient::factory()->concluded()->count(3)->create();

    expect(Expedient::concluded()->count())->toBe(3);
});

test('assigned to scope returns expedients for specific user', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    Expedient::factory()->count(3)->for($user1, 'assignedUser')->create();
    Expedient::factory()->count(2)->for($user2, 'assignedUser')->create();

    expect(Expedient::assignedTo($user1)->count())->toBe(3);
});

test('action scope filters milestones by action', function () {
    $expedient = Expedient::factory()->create();
    CaseMilestone::factory()->for($expedient, 'case')->repliedClient()->count(2)->create();
    CaseMilestone::factory()->for($expedient, 'case')->repliedProvider()->count(3)->create();

    expect(CaseMilestone::action(MilestoneAction::RepliedClient)->count())->toBe(2);
});

// Foreign key tests

it('mail_messages case_id has foreign key to cases', function () {
    $foreignKeys = Schema::getForeignKeys('mail_messages');

    $caseFk = collect($foreignKeys)->first(fn ($fk) => in_array('case_id', $fk['columns']));

    expect($caseFk)->not->toBeNull()
        ->and($caseFk['foreign_table'])->toBe('cases');
});

// Permission seeder tests

test('roles and permissions seeder includes expedient permissions', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $casePermissions = Permission::where('name', 'like', 'expedientes.%')->pluck('name')->sort()->values();

    expect($casePermissions)->toHaveCount(4)
        ->and($casePermissions->toArray())->toBe([
            'expedientes.actualizar',
            'expedientes.crear',
            'expedientes.eliminar',
            'expedientes.ver',
        ]);
});

test('roles and permissions seeder includes milestone permissions', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $milestonePermissions = Permission::where('name', 'like', 'hitos.%')->pluck('name')->sort()->values();

    expect($milestonePermissions)->toHaveCount(4)
        ->and($milestonePermissions->toArray())->toBe([
            'hitos.actualizar',
            'hitos.crear',
            'hitos.eliminar',
            'hitos.ver',
        ]);
});

test('super administrador has expedient and milestone permissions', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $role = Role::where('name', 'Super Administrador')->first();

    expect($role->hasPermissionTo('expedientes.ver'))->toBeTrue()
        ->and($role->hasPermissionTo('expedientes.crear'))->toBeTrue()
        ->and($role->hasPermissionTo('hitos.ver'))->toBeTrue()
        ->and($role->hasPermissionTo('hitos.crear'))->toBeTrue();
});
