<?php

use App\Models\Category;
use App\Models\Contact;
use App\Models\User;
use Database\Seeders\ContactCategoriesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class)->in(__DIR__);

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, ContactCategoriesSeeder::class]);

    $this->user = User::factory()->create();
    $this->user->assignRole('Super Administrador');
});

// Access tests

test('non authenticated users cannot access categories routes', function () {
    $this->get(route('contactos.categories.index'))
        ->assertRedirect(route('login'));
});

test('users without permission cannot access categories page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('contactos.categories.index'))
        ->assertForbidden();
});

test('super admins can access categories page', function () {
    $this->actingAs($this->user)
        ->get(route('contactos.categories.index'))
        ->assertOk()
        ->assertSee('Categorías');
});

// Render tests

test('categories page shows page heading with correct content', function () {
    $this->actingAs($this->user)
        ->get(route('contactos.categories.index'))
        ->assertSee('Categorías')
        ->assertSee('Clasificá tus contactos con categorías');
});

test('categories page renders toolbar with search and create button', function () {
    $this->actingAs($this->user)
        ->get(route('contactos.categories.index'))
        ->assertSee('Crear categoría')
        ->assertSee('Buscar por nombre o descripción');
});

test('categories page renders modal form', function () {
    $this->actingAs($this->user)
        ->get(route('contactos.categories.index'))
        ->assertSee('category-form');
});

test('categories page renders table with actions column', function () {
    $this->actingAs($this->user)
        ->get(route('contactos.categories.index'))
        ->assertSee('Acciones')
        ->assertSee('Editar')
        ->assertSee('Eliminar');
});

// CRUD tests via Livewire

test('can create a category', function () {
    $this->actingAs($this->user);

    Livewire::test('pages::contactos.categories')
        ->set('name', 'Clientes')
        ->set('slug', 'clientes')
        ->set('description', 'Clientes del sistema')
        ->set('color', '#FF5733')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('categories', [
        'name' => 'Clientes',
        'slug' => 'clientes',
    ]);
});

test('can update a category', function () {
    $category = Category::factory()->create();

    $this->actingAs($this->user);

    Livewire::test('pages::contactos.categories')
        ->call('edit', $category->id)
        ->assertSet('name', $category->name)
        ->assertSet('slug', $category->slug)
        ->set('name', 'Proveedores Actualizados')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('categories', [
        'id' => $category->id,
        'name' => 'Proveedores Actualizados',
    ]);
});

test('can delete a category without contacts', function () {
    $category = Category::factory()->create();

    $this->actingAs($this->user);

    Livewire::test('pages::contactos.categories')
        ->call('delete', $category->id)
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('categories', ['id' => $category->id]);
});

test('cannot delete a category with assigned contacts', function () {
    $category = Category::factory()->create();
    $contact = Contact::factory()->create();
    $category->contacts()->attach($contact);

    $this->actingAs($this->user);

    Livewire::test('pages::contactos.categories')
        ->call('delete', $category->id)
        ->assertHasErrors(['general']);

    $this->assertDatabaseHas('categories', ['id' => $category->id]);
});

test('can search categories by name', function () {
    Category::factory()->create(['name' => 'Proveedores']);
    Category::factory()->create(['name' => 'Clientes']);

    $this->actingAs($this->user);

    Livewire::test('pages::contactos.categories')
        ->set('search', 'Provee')
        ->assertSee('Proveedores')
        ->assertDontSee('Clientes');
});

test('category slug must be unique', function () {
    // Create a category with a specific slug
    Category::factory()->create(['slug' => 'unique-slug-test']);

    $this->actingAs($this->user);

    Livewire::test('pages::contactos.categories')
        ->set('name', 'Nuevo')
        ->set('slug', 'unique-slug-test')
        ->call('save')
        ->assertHasErrors(['slug']);
});

test('can update category without changing slug uniqueness check', function () {
    $category = Category::factory()->create(['slug' => 'mi-categoria']);

    $this->actingAs($this->user);

    Livewire::test('pages::contactos.categories')
        ->call('edit', $category->id)
        ->set('name', 'Mi Categoría Actualizada')
        ->set('slug', 'mi-categoria')
        ->call('save')
        ->assertHasNoErrors();
});

// Permission tests

test('user without crear permission cannot create categories', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('categorias.ver');

    $this->actingAs($user);

    Livewire::test('pages::contactos.categories')
        ->call('create')
        ->assertStatus(403);
});

test('user without actualizar permission cannot edit categories', function () {
    $category = Category::factory()->create();
    $user = User::factory()->create();
    $user->givePermissionTo(['categorias.ver', 'categorias.crear']);

    $this->actingAs($user);

    Livewire::test('pages::contactos.categories')
        ->call('edit', $category->id)
        ->assertStatus(403);
});

test('user without eliminar permission cannot delete categories', function () {
    $category = Category::factory()->create();
    $user = User::factory()->create();
    $user->givePermissionTo(['categorias.ver', 'categorias.crear']);

    $this->actingAs($user);

    Livewire::test('pages::contactos.categories')
        ->call('delete', $category->id)
        ->assertStatus(403);
});
