<?php

use App\Models\MailAccount;
use App\Models\Signature;
use App\Models\Template;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

// Template factory tests

test('template factory creates valid model', function () {
    $template = Template::factory()->create();

    expect($template)->id->not->toBeNull()
        ->and($template->name)->not->toBeNull()
        ->and($template->subject)->not->toBeNull()
        ->and($template->body)->not->toBeNull()
        ->and($template->is_active)->toBeTrue();
});

// Signature factory tests

test('signature factory creates valid model', function () {
    $signature = Signature::factory()->create();

    expect($signature)->id->not->toBeNull()
        ->and($signature->name)->not->toBeNull()
        ->and($signature->body)->not->toBeNull()
        ->and($signature->is_default)->toBeFalse()
        ->and($signature->is_active)->toBeTrue();
});

test('signature factory default state sets is_default', function () {
    $signature = Signature::factory()->default()->create();

    expect($signature->is_default)->toBeTrue();
});

// Relationship tests

test('template has no user or mail account ownership', function () {
    $template = Template::factory()->create();

    expect($template)->not->toHaveProperty('user_id')
        ->and($template)->not->toHaveProperty('mail_account_id');
});

test('signature belongs to mail account', function () {
    $mailAccount = MailAccount::factory()->create();
    $signature = Signature::factory()->for($mailAccount)->create();

    expect($signature->mailAccount->is($mailAccount))->toBeTrue();
});

test('mail account has many signatures', function () {
    $mailAccount = MailAccount::factory()->create();
    Signature::factory()->count(2)->for($mailAccount)->create();

    expect($mailAccount->signatures)->toHaveCount(2);
});

// Scope tests

test('template active scope returns only active templates', function () {
    Template::factory()->create(['is_active' => true]);
    Template::factory()->create(['is_active' => false]);

    expect(Template::active()->count())->toBe(1);
});

test('signature active scope returns only active signatures', function () {
    $mailAccount = MailAccount::factory()->create();
    Signature::factory()->for($mailAccount)->create(['is_active' => true]);
    Signature::factory()->for($mailAccount)->create(['is_active' => false]);

    expect(Signature::active()->count())->toBe(1);
});

test('signature default scope returns only default signatures', function () {
    $mailAccount = MailAccount::factory()->create();
    Signature::factory()->for($mailAccount)->create(['is_default' => true]);
    Signature::factory()->for($mailAccount)->create(['is_default' => false]);

    expect(Signature::default()->count())->toBe(1);
});

// Cascade delete tests

test('deleting mail account cascades to signatures', function () {
    $mailAccount = MailAccount::factory()->create();
    $signature = Signature::factory()->for($mailAccount)->create();

    $mailAccount->delete();

    expect(Signature::find($signature->id))->toBeNull();
});

// Templates are global - no cascade from user or mail account

test('templates are not deleted when user is deleted', function () {
    $user = User::factory()->create();
    $template = Template::factory()->create();

    $templateId = $template->id;
    $user->delete();

    expect(Template::find($templateId))->not->toBeNull();
});

test('templates are not deleted when mail account is deleted', function () {
    $mailAccount = MailAccount::factory()->create();
    $template = Template::factory()->create();

    $templateId = $template->id;
    $mailAccount->delete();

    expect(Template::find($templateId))->not->toBeNull();
});

// Permission seeder tests

test('roles and permissions seeder includes template permissions', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $templatePermissions = Permission::where('name', 'like', 'plantillas.%')->pluck('name')->sort()->values();

    expect($templatePermissions)->toHaveCount(4)
        ->and($templatePermissions->toArray())->toBe([
            'plantillas.actualizar',
            'plantillas.crear',
            'plantillas.eliminar',
            'plantillas.ver',
        ]);
});

test('roles and permissions seeder includes signature permissions', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $signaturePermissions = Permission::where('name', 'like', 'firmas.%')->pluck('name')->sort()->values();

    expect($signaturePermissions)->toHaveCount(4)
        ->and($signaturePermissions->toArray())->toBe([
            'firmas.actualizar',
            'firmas.crear',
            'firmas.eliminar',
            'firmas.ver',
        ]);
});

test('super administrador has template and signature permissions', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $role = Role::where('name', 'Super Administrador')->first();

    expect($role->hasPermissionTo('plantillas.ver'))->toBeTrue()
        ->and($role->hasPermissionTo('plantillas.crear'))->toBeTrue()
        ->and($role->hasPermissionTo('firmas.ver'))->toBeTrue()
        ->and($role->hasPermissionTo('firmas.crear'))->toBeTrue();
});
