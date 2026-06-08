<?php

use App\Models\User;
use Database\Seeders\ContactCategoriesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, ContactCategoriesSeeder::class]);
});

test('non authenticated users cannot access contactos routes', function () {
    $this->get(route('contactos.contacts.index'))
        ->assertRedirect(route('login'));

    $this->get(route('contactos.categories.index'))
        ->assertRedirect(route('login'));
});

test('users without contactos.ver cannot access contacts page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('contactos.contacts.index'))
        ->assertForbidden();
});

test('users without categorias.ver cannot access categories page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('contactos.categories.index'))
        ->assertForbidden();
});

test('super admins can access contacts and categories pages', function () {
    $user = User::factory()->create();
    $user->assignRole('Super Administrador');

    $this->actingAs($user)
        ->get(route('contactos.contacts.index'))
        ->assertOk()
        ->assertSee('Contactos');

    $this->get(route('contactos.categories.index'))
        ->assertOk()
        ->assertSee('Categorías');
});

test('contactos sidebar items visible for super admins', function () {
    $user = User::factory()->create();
    $user->assignRole('Super Administrador');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSee('Contactos')
        ->assertSee('Categorías');
});
