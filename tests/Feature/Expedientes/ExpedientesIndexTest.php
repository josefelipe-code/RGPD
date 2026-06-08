<?php

use App\Models\Expedient;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class)->in(__DIR__);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->user = User::factory()->create();
    $this->user->assignRole('Super Administrador');
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
