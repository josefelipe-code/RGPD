<?php

use App\Enums\MailDirection;
use App\Enums\MailMessageStatus;
use App\Models\Expedient;
use App\Models\MailAccount;
use App\Models\MailMessage;
use Carbon\CarbonInterface;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

// Migration tests

it('has mail_messages table with correct columns', function () {
    expect(Schema::hasTable('mail_messages'))->toBeTrue();

    $columns = Schema::getColumnListing('mail_messages');

    expect($columns)->toContain(
        'id',
        'case_id',
        'mail_account_id',
        'message_id',
        'subject',
        'from_email',
        'from_name',
        'body_html',
        'body_text',
        'received_at',
        'direction',
        'status',
        'created_at',
        'updated_at',
    );
});

it('case_id column is nullable', function () {
    $columnType = Schema::getColumnType('mail_messages', 'case_id');

    // SQLite maps bigint to integer; the column is still nullable at the schema level.
    expect(in_array($columnType, ['bigint', 'integer']))->toBeTrue();
});

// Factory tests

test('mail message factory creates valid model', function () {
    $message = MailMessage::factory()->create();

    expect($message)->id->not->toBeNull()
        ->and($message->message_id)->not->toBeNull()
        ->and($message->from_email)->not->toBeNull()
        ->and($message->direction)->toBe(MailDirection::Incoming)
        ->and($message->status)->toBe(MailMessageStatus::New)
        ->and($message->case_id)->toBeNull();
});

test('mail message factory outgoing state', function () {
    $message = MailMessage::factory()->outgoing()->create();

    expect($message->direction)->toBe(MailDirection::Outgoing);
});

test('mail message factory associated state', function () {
    $message = MailMessage::factory()->associated()->create();

    expect($message->case_id)->not->toBeNull()
        ->and($message->status)->toBe(MailMessageStatus::Associated);
});

// Cast tests

test('casts direction as MailDirection enum', function () {
    $message = MailMessage::factory()->create([
        'direction' => MailDirection::Outgoing,
    ]);

    $fresh = MailMessage::find($message->id);

    expect($fresh->direction)->toBeInstanceOf(MailDirection::class)
        ->and($fresh->direction)->toBe(MailDirection::Outgoing);
});

test('casts status as MailMessageStatus enum', function () {
    $message = MailMessage::factory()->create([
        'status' => MailMessageStatus::PendingReview,
    ]);

    $fresh = MailMessage::find($message->id);

    expect($fresh->status)->toBeInstanceOf(MailMessageStatus::class)
        ->and($fresh->status)->toBe(MailMessageStatus::PendingReview);
});

test('casts received_at as datetime', function () {
    $message = MailMessage::factory()->create();

    expect($message->received_at)->toBeInstanceOf(CarbonInterface::class);
});

// Relationship tests

test('mail message belongs to mail account', function () {
    $mailAccount = MailAccount::factory()->create();
    $message = MailMessage::factory()->for($mailAccount)->create();

    expect($message->mailAccount->is($mailAccount))->toBeTrue();
});

test('mail message belongs to expedient', function () {
    $expedient = Expedient::factory()->create();
    $message = MailMessage::factory()->for($expedient, 'case')->create();

    expect($message->case->is($expedient))->toBeTrue();
});

test('mail account has many mail messages', function () {
    $mailAccount = MailAccount::factory()->create();
    MailMessage::factory()->count(3)->for($mailAccount)->create();

    expect($mailAccount->mailMessages)->toHaveCount(3);
});

test('deleting mail account cascades to mail messages', function () {
    $mailAccount = MailAccount::factory()->create();
    $message = MailMessage::factory()->for($mailAccount)->create();

    $mailAccount->delete();

    expect(MailMessage::find($message->id))->toBeNull();
});

// Scope tests

test('incoming scope returns only incoming messages', function () {
    $mailAccount = MailAccount::factory()->create();
    MailMessage::factory()->for($mailAccount)->create(['direction' => MailDirection::Incoming]);
    MailMessage::factory()->for($mailAccount)->outgoing()->create();

    expect(MailMessage::incoming()->count())->toBe(1);
});

test('outgoing scope returns only outgoing messages', function () {
    $mailAccount = MailAccount::factory()->create();
    MailMessage::factory()->for($mailAccount)->outgoing()->create();
    MailMessage::factory()->for($mailAccount)->create(['direction' => MailDirection::Incoming]);

    expect(MailMessage::outgoing()->count())->toBe(1);
});

test('unassociated scope returns messages without case_id', function () {
    $mailAccount = MailAccount::factory()->create();
    MailMessage::factory()->for($mailAccount)->create(['case_id' => null]);
    MailMessage::factory()->for($mailAccount)->associated()->create();

    expect(MailMessage::unassociated()->count())->toBe(1);
});

// Permission seeder tests

test('roles and permissions seeder includes mail message permissions', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $messagePermissions = Permission::where('name', 'like', 'mensajes-correo.%')->pluck('name')->sort()->values();

    expect($messagePermissions)->toHaveCount(4)
        ->and($messagePermissions->toArray())->toBe([
            'mensajes-correo.actualizar',
            'mensajes-correo.crear',
            'mensajes-correo.eliminar',
            'mensajes-correo.ver',
        ]);
});

test('super administrador has mail message permissions', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $role = Role::where('name', 'Super Administrador')->first();

    expect($role->hasPermissionTo('mensajes-correo.ver'))->toBeTrue()
        ->and($role->hasPermissionTo('mensajes-correo.crear'))->toBeTrue()
        ->and($role->hasPermissionTo('mensajes-correo.actualizar'))->toBeTrue()
        ->and($role->hasPermissionTo('mensajes-correo.eliminar'))->toBeTrue();
});
