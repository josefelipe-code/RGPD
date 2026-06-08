<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('non admin users cannot access admin routes', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.users.index'))
        ->assertForbidden();
});

test('super admins can access admin pages without multiple root elements error', function () {
    $user = User::factory()->create();
    $user->assignRole('Super Administrador');

    $this->actingAs($user)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertSee('Usuarios');

    $this->get(route('admin.roles.index'))
        ->assertOk()
        ->assertSee('Roles');

    $this->get(route('admin.permissions.index'))
        ->assertOk()
        ->assertSee('Permisos');
});

test('admin pages show page heading with correct content', function () {
    $user = User::factory()->create();
    $user->assignRole('Super Administrador');

    $this->actingAs($user)
        ->get(route('admin.users.index'))
        ->assertSee('Usuarios')
        ->assertSee('Creá usuarios, asignales roles');

    $this->get(route('admin.roles.index'))
        ->assertSee('Roles')
        ->assertSee('Agrupá permisos en roles');

    $this->get(route('admin.permissions.index'))
        ->assertSee('Permisos')
        ->assertSee('Definí las capacidades de bajo nivel');
});

test('admin pages do not render internal navlist sidebar', function () {
    $user = User::factory()->create();
    $user->assignRole('Super Administrador');

    $this->actingAs($user)
        ->get(route('admin.users.index'))
        ->assertSee('Usuarios')
        ->assertDontSee('aria-label="Administración"');

    $this->get(route('admin.roles.index'))
        ->assertDontSee('aria-label="Administración"');

    $this->get(route('admin.permissions.index'))
        ->assertDontSee('aria-label="Administración"');
});

test('admin sidebar items have icons for super admins', function () {
    $user = User::factory()->create();
    $user->assignRole('Super Administrador');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSee('Usuarios')
        ->assertSee('Roles')
        ->assertSee('Permisos');
});

test('admin users page renders toolbar with search and create button', function () {
    $user = User::factory()->create();
    $user->assignRole('Super Administrador');

    $this->actingAs($user)
        ->get(route('admin.users.index'))
        ->assertSee('Crear usuario')
        ->assertSee('Buscar por nombre o email');
});

test('admin roles page renders toolbar with search and create button', function () {
    $user = User::factory()->create();
    $user->assignRole('Super Administrador');

    $this->actingAs($user)
        ->get(route('admin.roles.index'))
        ->assertSee('Crear rol')
        ->assertSee('Buscar por nombre');
});

test('admin permissions page renders toolbar with search and create button', function () {
    $user = User::factory()->create();
    $user->assignRole('Super Administrador');

    $this->actingAs($user)
        ->get(route('admin.permissions.index'))
        ->assertSee('Crear permiso')
        ->assertSee('Buscar por nombre');
});

test('admin pages render table with actions column', function () {
    $user = User::factory()->create();
    $user->assignRole('Super Administrador');

    $this->actingAs($user)
        ->get(route('admin.users.index'))
        ->assertSee('Acciones')
        ->assertSee('Editar')
        ->assertSee('Eliminar');

    $this->get(route('admin.roles.index'))
        ->assertSee('Acciones');

    $this->get(route('admin.permissions.index'))
        ->assertSee('Acciones');
});

test('admin pages render modal forms for create and edit', function () {
    $user = User::factory()->create();
    $user->assignRole('Super Administrador');

    $this->actingAs($user)
        ->get(route('admin.users.index'))
        ->assertSee('user-form');

    $this->get(route('admin.roles.index'))
        ->assertSee('role-form');

    $this->get(route('admin.permissions.index'))
        ->assertSee('permission-form');
});
