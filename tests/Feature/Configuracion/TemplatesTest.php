<?php

use App\Models\Template;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->user = User::factory()->create();
    $this->user->assignRole('Super Administrador');
});

// --- Access / Permission Tests ---

test('non configuracion users cannot access plantillas route', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('configuracion.plantillas.index'))
        ->assertForbidden();
});

test('super admins can access plantillas page', function () {
    $user = User::factory()->create();
    $user->assignRole('Super Administrador');

    $this->actingAs($user)
        ->get(route('configuracion.plantillas.index'))
        ->assertOk()
        ->assertSee('Plantillas');
});

test('plantillas requires authentication', function () {
    $this->get(route('configuracion.plantillas.index'))
        ->assertRedirect(route('login'));
});

test('plantillas sidebar item visible for super admins', function () {
    $user = User::factory()->create();
    $user->assignRole('Super Administrador');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSee('Plantillas');
});

// --- Render Tests ---

it('renders plantillas configuracion page', function () {
    $this->actingAs($this->user)
        ->get(route('configuracion.plantillas.index'))
        ->assertOk()
        ->assertSee('Plantillas');
});

it('shows create button even when no templates exist', function () {
    $this->actingAs($this->user)
        ->get(route('configuracion.plantillas.index'))
        ->assertSee('Crear plantilla');
});

// --- CRUD Tests ---

it('lists all global templates regardless of creator', function () {
    $user1 = User::factory()->create();
    $user1->assignRole('Super Administrador');
    $user2 = User::factory()->create();
    $user2->assignRole('Super Administrador');

    Template::factory()->create(['name' => 'Template A']);
    Template::factory()->create(['name' => 'Template B']);

    // Both users see all templates
    $this->actingAs($user1)
        ->get(route('configuracion.plantillas.index'))
        ->assertSee('Template A')
        ->assertSee('Template B');

    $this->actingAs($user2)
        ->get(route('configuracion.plantillas.index'))
        ->assertSee('Template A')
        ->assertSee('Template B');
});

it('can create a new global template', function () {
    Livewire::actingAs($this->user);

    Livewire::test('pages::configuracion.templates')
        ->call('create')
        ->set('name', 'Respuesta Inicial')
        ->set('subject', 'Re: Su consulta')
        ->set('body', 'Estimado cliente, gracias por contactarnos...')
        ->set('isActive', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(Template::count())->toBe(1)
        ->and(Template::first()->name)->toBe('Respuesta Inicial');
});

it('can edit an existing template', function () {
    $template = Template::factory()->create([
        'name' => 'Old Name',
        'subject' => 'Old Subject',
        'body' => 'Old body',
    ]);

    Livewire::actingAs($this->user);

    Livewire::test('pages::configuracion.templates')
        ->call('edit', $template->id)
        ->assertSet('name', 'Old Name')
        ->assertSet('subject', 'Old Subject')
        ->assertSet('body', 'Old body')
        ->set('name', 'New Name')
        ->set('subject', 'New Subject')
        ->set('body', 'New body')
        ->call('save')
        ->assertHasNoErrors();

    $template->refresh();

    expect($template->name)->toBe('New Name')
        ->and($template->subject)->toBe('New Subject')
        ->and($template->body)->toBe('New body');
});

it('can toggle template active status', function () {
    $template = Template::factory()->create(['is_active' => true]);

    Livewire::actingAs($this->user);

    Livewire::test('pages::configuracion.templates')
        ->call('toggle', $template->id);

    expect($template->refresh()->is_active)->toBeFalse();

    Livewire::test('pages::configuracion.templates')
        ->call('toggle', $template->id);

    expect($template->refresh()->is_active)->toBeTrue();
});

it('can delete a template', function () {
    $template = Template::factory()->create();

    Livewire::actingAs($this->user);

    Livewire::test('pages::configuracion.templates')
        ->call('delete', $template->id)
        ->assertHasNoErrors();

    expect(Template::find($template->id))->toBeNull();
});

// --- Shared Access Tests ---

it('user can edit template created by another user', function () {
    $user1 = User::factory()->create();
    $user1->assignRole('Super Administrador');
    $user2 = User::factory()->create();
    $user2->assignRole('Super Administrador');

    $template = Template::factory()->create(['name' => 'Original Name']);

    Livewire::actingAs($user1);

    Livewire::test('pages::configuracion.templates')
        ->call('edit', $template->id)
        ->assertSet('name', 'Original Name')
        ->set('name', 'Edited by other user')
        ->call('save')
        ->assertHasNoErrors();

    expect($template->refresh()->name)->toBe('Edited by other user');
});

it('user can delete template created by another user', function () {
    $user1 = User::factory()->create();
    $user1->assignRole('Super Administrador');
    $user2 = User::factory()->create();
    $user2->assignRole('Super Administrador');

    $template = Template::factory()->create();

    Livewire::actingAs($user1);

    Livewire::test('pages::configuracion.templates')
        ->call('delete', $template->id)
        ->assertHasNoErrors();

    expect(Template::find($template->id))->toBeNull();
});

it('user can toggle template created by another user', function () {
    $user1 = User::factory()->create();
    $user1->assignRole('Super Administrador');
    $user2 = User::factory()->create();
    $user2->assignRole('Super Administrador');

    $template = Template::factory()->create(['is_active' => true]);

    Livewire::actingAs($user1);

    Livewire::test('pages::configuracion.templates')
        ->call('toggle', $template->id);

    expect($template->refresh()->is_active)->toBeFalse();
});

// --- Search & Pagination Tests ---

it('can search templates by name, subject or body', function () {
    Template::factory()->create(['name' => 'Plantilla Trabajo', 'subject' => 'Consulta laboral']);
    Template::factory()->create(['name' => 'Plantilla Personal', 'subject' => 'Asunto personal']);

    Livewire::actingAs($this->user);

    Livewire::test('pages::configuracion.templates')
        ->set('search', 'Trabajo')
        ->assertSee('Plantilla Trabajo')
        ->assertDontSee('Plantilla Personal');
});

it('can search templates by body content', function () {
    Template::factory()->create(['name' => 'Template A', 'body' => 'Contenido exclusivo aqui']);
    Template::factory()->create(['name' => 'Template B', 'body' => 'Otro contenido diferente']);

    Livewire::actingAs($this->user);

    Livewire::test('pages::configuracion.templates')
        ->set('search', 'exclusivo')
        ->assertSee('Template A')
        ->assertDontSee('Template B');
});

it('can paginate templates', function () {
    collect(range(1, 21))->each(function (int $number): void {
        Template::factory()->create([
            'name' => 'Plantilla '.$number,
            'created_at' => now()->subSeconds(21 - $number),
        ]);
    });

    Livewire::actingAs($this->user);

    Livewire::test('pages::configuracion.templates')
        ->set('perPage', 20)
        ->call('gotoPage', 2)
        ->assertSet('paginators.page', 2);
});

// --- Validation Tests ---

it('validates required fields on create', function () {
    Livewire::actingAs($this->user);

    Livewire::test('pages::configuracion.templates')
        ->call('create')
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name']);
});

// --- Templates are global, not account-bound ---

it('templates have no mail_account_id or user_id columns', function () {
    $template = Template::factory()->create();

    expect($template)->not->toHaveProperty('mail_account_id')
        ->and($template)->not->toHaveProperty('user_id');
});
