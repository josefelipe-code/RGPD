<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('non authenticated users cannot access expedientes routes', function () {
    $this->get(route('expedientes.index'))
        ->assertRedirect(route('login'));
});

test('users without expedientes.ver cannot access expedientes page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('expedientes.index'))
        ->assertForbidden();
});

test('super admins can access expedientes page', function () {
    $user = User::factory()->create();
    $user->assignRole('Super Administrador');

    $this->actingAs($user)
        ->get(route('expedientes.index'))
        ->assertOk()
        ->assertSee('Expedientes');
});

test('expedientes sidebar item visible for super admins', function () {
    $user = User::factory()->create();
    $user->assignRole('Super Administrador');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSee('Expedientes');
});

test('expedientes sidebar item not visible for regular users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertDontSee('Expedientes');
});
