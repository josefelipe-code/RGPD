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

test('non authenticated users cannot access contacts routes', function () {
    $this->get(route('contactos.contacts.index'))
        ->assertRedirect(route('login'));
});

test('users without permission cannot access contacts page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('contactos.contacts.index'))
        ->assertForbidden();
});

test('super admins can access contacts page', function () {
    $this->actingAs($this->user)
        ->get(route('contactos.contacts.index'))
        ->assertOk()
        ->assertSee('Contactos');
});

// Render tests

test('contacts page shows page heading with correct content', function () {
    $this->actingAs($this->user)
        ->get(route('contactos.contacts.index'))
        ->assertSee('Contactos')
        ->assertSee('Gestioná tu agenda de contactos');
});

test('contacts page renders toolbar with search and create button', function () {
    $this->actingAs($this->user)
        ->get(route('contactos.contacts.index'))
        ->assertSee('Crear contacto')
        ->assertSee('Buscar por nombre, email, teléfono o empresa');
});

test('contacts page renders modal form', function () {
    $this->actingAs($this->user)
        ->get(route('contactos.contacts.index'))
        ->assertSee('contact-form');
});

test('contacts page renders table with actions column', function () {
    Contact::factory()->create(['name' => 'Test Contact']);

    $this->actingAs($this->user)
        ->get(route('contactos.contacts.index'))
        ->assertSee('Acciones')
        ->assertSee('Editar')
        ->assertSee('Eliminar');
});

// CRUD tests via Livewire

test('can create a contact', function () {
    $this->actingAs($this->user);

    Livewire::test('pages::contactos.contacts')
        ->set('name', 'Juan Pérez')
        ->set('email', 'juan@example.com')
        ->set('phone', '+54 11 1234-5678')
        ->set('company', 'Empresa SRL')
        ->set('notes', 'Contacto principal')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('contacts', [
        'name' => 'Juan Pérez',
        'email' => 'juan@example.com',
    ]);
});

test('can update a contact', function () {
    $contact = Contact::factory()->create();

    $this->actingAs($this->user);

    Livewire::test('pages::contactos.contacts')
        ->call('edit', $contact->id)
        ->assertSet('name', $contact->name)
        ->assertSet('email', $contact->email)
        ->set('name', 'Juan Pérez Actualizado')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('contacts', [
        'id' => $contact->id,
        'name' => 'Juan Pérez Actualizado',
    ]);
});

test('can delete a contact', function () {
    $contact = Contact::factory()->create();

    $this->actingAs($this->user);

    Livewire::test('pages::contactos.contacts')
        ->call('delete', $contact->id)
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('contacts', ['id' => $contact->id]);
});

test('can search contacts by name', function () {
    Contact::factory()->create(['name' => 'Juan Pérez']);
    Contact::factory()->create(['name' => 'María García']);

    $this->actingAs($this->user);

    Livewire::test('pages::contactos.contacts')
        ->set('search', 'Juan')
        ->assertSee('Juan Pérez')
        ->assertDontSee('María García');
});

test('can assign categories to contact', function () {
    $category = Category::first();

    $this->actingAs($this->user);

    Livewire::test('pages::contactos.contacts')
        ->set('name', 'Contacto con categoría')
        ->set('email', 'test@example.com')
        ->set('selectedCategories', [$category->id])
        ->call('save')
        ->assertHasNoErrors();

    $contact = Contact::where('email', 'test@example.com')->first();

    expect($contact->categories->pluck('id')->all())->toContain($category->id);
});

test('can assign multiple categories to contact', function () {
    $categories = Category::limit(3)->pluck('id')->all();

    $this->actingAs($this->user);

    Livewire::test('pages::contactos.contacts')
        ->set('name', 'Contacto multi-categoría')
        ->set('email', 'multi@example.com')
        ->set('selectedCategories', $categories)
        ->call('save')
        ->assertHasNoErrors();

    $contact = Contact::where('email', 'multi@example.com')->first();

    expect($contact->categories()->count())->toBe(count($categories));
});

test('can update contact categories', function () {
    $contact = Contact::factory()->create();
    $category1 = Category::skip(0)->first();
    $category2 = Category::skip(1)->first();

    $this->actingAs($this->user);

    Livewire::test('pages::contactos.contacts')
        ->call('edit', $contact->id)
        ->set('selectedCategories', [$category1->id, $category2->id])
        ->call('save')
        ->assertHasNoErrors();

    $contact->refresh();

    expect($contact->categories()->count())->toBe(2);
});

// Permission tests

test('user without crear permission cannot create contacts', function () {
    $user = User::factory()->create();
    // Has ver but not crear
    $user->givePermissionTo('contactos.ver');

    $this->actingAs($user);

    Livewire::test('pages::contactos.contacts')
        ->call('create')
        ->assertStatus(403);
});

test('user without actualizar permission cannot edit contacts', function () {
    $contact = Contact::factory()->create();
    $user = User::factory()->create();
    $user->givePermissionTo(['contactos.ver', 'contactos.crear']);

    $this->actingAs($user);

    Livewire::test('pages::contactos.contacts')
        ->call('edit', $contact->id)
        ->assertStatus(403);
});

test('user without eliminar permission cannot delete contacts', function () {
    $contact = Contact::factory()->create();
    $user = User::factory()->create();
    $user->givePermissionTo(['contactos.ver', 'contactos.crear']);

    $this->actingAs($user);

    Livewire::test('pages::contactos.contacts')
        ->call('delete', $contact->id)
        ->assertStatus(403);
});
