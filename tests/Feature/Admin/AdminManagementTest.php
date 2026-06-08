<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('super admins can manage permissions', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrador');

    $this->actingAs($admin);

    Livewire::test('pages::admin.permissions')
        ->set('name', 'reportes.ver')
        ->call('save')
        ->assertHasNoErrors();

    $permission = Permission::findByName('reportes.ver');

    expect($permission)->not->toBeNull();

    Livewire::test('pages::admin.permissions')
        ->call('edit', $permission->id)
        ->set('name', 'reportes.gestionar')
        ->call('save')
        ->assertHasNoErrors();

    expect(Permission::where('name', 'reportes.gestionar')->exists())->toBeTrue();

    $updatedPermission = Permission::findByName('reportes.gestionar');

    Livewire::test('pages::admin.permissions')
        ->call('delete', $updatedPermission->id)
        ->assertHasNoErrors();

    expect(Permission::where('name', 'reportes.gestionar')->exists())->toBeFalse();
});

test('super admins can paginate permissions', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrador');

    collect(range(1, 5))->each(function (int $number): void {
        Permission::findOrCreate('zz-permiso-'.$number, 'web');
    });

    $this->actingAs($admin);

    Livewire::test('pages::admin.permissions')
        ->call('gotoPage', 2)
        ->assertSet('paginators.page', 2);
});

test('super admins can manage roles', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrador');

    $this->actingAs($admin);

    Livewire::test('pages::admin.roles')
        ->set('name', 'Gerencia')
        ->set('selectedPermissions', ['usuarios.ver', 'usuarios.actualizar'])
        ->call('save')
        ->assertHasNoErrors();

    $role = Role::findByName('Gerencia');

    expect($role->hasPermissionTo('usuarios.ver'))->toBeTrue();
    expect($role->hasPermissionTo('usuarios.actualizar'))->toBeTrue();

    Livewire::test('pages::admin.roles')
        ->call('edit', $role->id)
        ->set('name', 'Gerencia Operativa')
        ->set('selectedPermissions', ['usuarios.ver'])
        ->call('save')
        ->assertHasNoErrors();

    $updatedRole = Role::findByName('Gerencia Operativa');

    expect($updatedRole->hasPermissionTo('usuarios.ver'))->toBeTrue();
    expect($updatedRole->hasPermissionTo('usuarios.actualizar'))->toBeFalse();

    Livewire::test('pages::admin.roles')
        ->call('delete', $updatedRole->id)
        ->assertHasNoErrors();

    expect(Role::where('name', 'Gerencia Operativa')->exists())->toBeFalse();
});

test('super admins can manage users through roles', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrador');

    Role::findOrCreate('Gerencia', 'web');

    $this->actingAs($admin);

    Livewire::test('pages::admin.users')
        ->set('name', 'Jane Manager')
        ->set('email', 'jane@example.com')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->set('selectedRoles', ['Gerencia'])
        ->call('save')
        ->assertHasNoErrors();

    $user = User::where('email', 'jane@example.com')->firstOrFail();

    expect($user->hasRole('Gerencia'))->toBeTrue();

    Livewire::test('pages::admin.users')
        ->call('edit', $user->id)
        ->set('name', 'Jane Ops')
        ->set('email', 'jane.ops@example.com')
        ->set('selectedRoles', [])
        ->call('save')
        ->assertHasNoErrors();

    $updatedUser = $user->fresh();

    expect($updatedUser->name)->toBe('Jane Ops');
    expect($updatedUser->email)->toBe('jane.ops@example.com');
    expect($updatedUser->roles)->toHaveCount(0);

    Livewire::test('pages::admin.users')
        ->call('delete', $updatedUser->id)
        ->assertHasNoErrors();

    expect(User::where('email', 'jane.ops@example.com')->exists())->toBeFalse();
});
