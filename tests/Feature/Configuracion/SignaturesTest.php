<?php

use App\Models\MailAccount;
use App\Models\Signature;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->user = User::factory()->create();
    $this->user->assignRole('Super Administrador');
});

// --- Access / Permission Tests ---

test('non configuracion users cannot access firmas route', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('configuracion.firmas.index'))
        ->assertForbidden();
});

test('super admins can access firmas page', function () {
    $user = User::factory()->create();
    $user->assignRole('Super Administrador');

    $this->actingAs($user)
        ->get(route('configuracion.firmas.index'))
        ->assertOk()
        ->assertSee('Firmas');
});

test('firmas requires authentication', function () {
    $this->get(route('configuracion.firmas.index'))
        ->assertRedirect(route('login'));
});

test('firmas sidebar item visible for super admins', function () {
    $user = User::factory()->create();
    $user->assignRole('Super Administrador');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSee('Firmas');
});

// --- Render Tests ---

it('renders firmas configuracion page', function () {
    $this->actingAs($this->user)
        ->get(route('configuracion.firmas.index'))
        ->assertOk()
        ->assertSee('Firmas');
});

it('shows warning when user has no mail accounts', function () {
    $this->actingAs($this->user)
        ->get(route('configuracion.firmas.index'))
        ->assertSee('Sin cuentas de correo')
        ->assertSee('Ir a Cuentas de correo');
});

it('does not show create button when user has no mail accounts', function () {
    $this->actingAs($this->user)
        ->get(route('configuracion.firmas.index'))
        ->assertDontSee('Crear firma');
});

// --- CRUD Tests ---

it('lists only authenticated user signatures', function () {
    $user1 = User::factory()->create();
    $user1->assignRole('Super Administrador');
    $user2 = User::factory()->create();
    $user2->assignRole('Super Administrador');

    $account1 = MailAccount::factory()->for($user1)->create();
    $account2 = MailAccount::factory()->for($user2)->create();

    Signature::factory()->for($account1)->create(['name' => 'User 1 Signature']);
    Signature::factory()->for($account2)->create(['name' => 'User 2 Signature']);

    $this->actingAs($user1)
        ->get(route('configuracion.firmas.index'))
        ->assertSee('User 1 Signature')
        ->assertDontSee('User 2 Signature');
});

it('can create a new signature', function () {
    $account = MailAccount::factory()->for($this->user)->create();

    Livewire::actingAs($this->user);

    Livewire::test('pages::configuracion.signatures')
        ->call('create')
        ->assertSet('selectedMailAccountId', (string) $account->id)
        ->set('name', 'Firma Profesional')
        ->set('body', 'Saludos cordiales')
        ->set('isDefault', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($this->user->mailAccounts()->first()->signatures()->count())->toBe(1)
        ->and($this->user->mailAccounts()->first()->signatures()->first()->name)->toBe('Firma Profesional');
});

it('prefills the first mail account when opening the create signature form', function () {
    $firstAccount = MailAccount::factory()->for($this->user)->create(['label' => 'Primera']);
    MailAccount::factory()->for($this->user)->create(['label' => 'Segunda']);

    Livewire::actingAs($this->user);

    Livewire::test('pages::configuracion.signatures')
        ->call('create')
        ->assertSet('selectedMailAccountId', (string) $firstAccount->id);
});

it('can edit an existing signature', function () {
    $account = MailAccount::factory()->for($this->user)->create();
    $signature = Signature::factory()->for($account)->create([
        'name' => 'Old Name',
        'body' => 'Old body',
    ]);

    Livewire::actingAs($this->user);

    Livewire::test('pages::configuracion.signatures')
        ->call('edit', $signature->id)
        ->assertSet('name', 'Old Name')
        ->assertSet('body', 'Old body')
        ->set('name', 'New Name')
        ->set('body', 'New body')
        ->call('save')
        ->assertHasNoErrors();

    $signature->refresh();

    expect($signature->name)->toBe('New Name')
        ->and($signature->body)->toBe('New body');
});

it('can toggle signature active status', function () {
    $account = MailAccount::factory()->for($this->user)->create();
    $signature = Signature::factory()->for($account)->create(['is_active' => true]);

    Livewire::actingAs($this->user);

    Livewire::test('pages::configuracion.signatures')
        ->call('toggle', $signature->id);

    expect($signature->refresh()->is_active)->toBeFalse();

    Livewire::test('pages::configuracion.signatures')
        ->call('toggle', $signature->id);

    expect($signature->refresh()->is_active)->toBeTrue();
});

it('can delete a signature', function () {
    $account = MailAccount::factory()->for($this->user)->create();
    $signature = Signature::factory()->for($account)->create();

    Livewire::actingAs($this->user);

    Livewire::test('pages::configuracion.signatures')
        ->call('delete', $signature->id)
        ->assertHasNoErrors();

    expect(Signature::find($signature->id))->toBeNull();
});

// --- Default Consistency Tests ---

it('ensures only one default signature per mail account on create', function () {
    $account = MailAccount::factory()->for($this->user)->create();

    Signature::factory()->for($account)->default()->create(['name' => 'Existing Default']);

    Livewire::actingAs($this->user);

    Livewire::test('pages::configuracion.signatures')
        ->call('create')
        ->set('selectedMailAccountId', $account->id)
        ->set('name', 'New Default')
        ->set('body', 'New body')
        ->set('isDefault', true)
        ->call('save')
        ->assertHasNoErrors();

    $defaults = $account->signatures()->where('is_default', true)->count();

    expect($defaults)->toBe(1)
        ->and($account->signatures()->where('name', 'New Default')->first()->is_default)->toBeTrue()
        ->and($account->signatures()->where('name', 'Existing Default')->first()->is_default)->toBeFalse();
});

it('ensures only one default signature per mail account on edit', function () {
    $account = MailAccount::factory()->for($this->user)->create();

    $existingDefault = Signature::factory()->for($account)->default()->create(['name' => 'Existing Default']);
    $nonDefault = Signature::factory()->for($account)->create(['name' => 'Non Default', 'is_default' => false]);

    Livewire::actingAs($this->user);

    Livewire::test('pages::configuracion.signatures')
        ->call('edit', $nonDefault->id)
        ->set('isDefault', true)
        ->call('save')
        ->assertHasNoErrors();

    $existingDefault->refresh();
    $nonDefault->refresh();

    expect($existingDefault->is_default)->toBeFalse()
        ->and($nonDefault->is_default)->toBeTrue();
});

it('can toggle default status from list', function () {
    $account = MailAccount::factory()->for($this->user)->create();
    $signature = Signature::factory()->for($account)->create(['is_default' => false]);

    Livewire::actingAs($this->user);

    Livewire::test('pages::configuracion.signatures')
        ->call('toggleDefault', $signature->id);

    expect($signature->refresh()->is_default)->toBeTrue();
});

it('unsets other defaults when toggling default on', function () {
    $account = MailAccount::factory()->for($this->user)->create();

    $existingDefault = Signature::factory()->for($account)->default()->create(['name' => 'Existing Default']);
    $nonDefault = Signature::factory()->for($account)->create(['name' => 'Non Default', 'is_default' => false]);

    Livewire::actingAs($this->user);

    Livewire::test('pages::configuracion.signatures')
        ->call('toggleDefault', $nonDefault->id);

    $existingDefault->refresh();
    $nonDefault->refresh();

    expect($existingDefault->is_default)->toBeFalse()
        ->and($nonDefault->is_default)->toBeTrue();
});

// --- Isolation Tests ---

it('cannot edit another user signature', function () {
    $user1 = User::factory()->create();
    $user1->assignRole('Super Administrador');
    $user2 = User::factory()->create();
    $user2->assignRole('Super Administrador');

    $account = MailAccount::factory()->for($user2)->create();
    $signature = Signature::factory()->for($account)->create(['name' => 'Original Name']);

    Livewire::actingAs($user1);

    Livewire::test('pages::configuracion.signatures')
        ->call('edit', $signature->id);

    expect($signature->refresh()->name)->toBe('Original Name');
})->throws(ModelNotFoundException::class);

it('cannot delete another user signature', function () {
    $user1 = User::factory()->create();
    $user1->assignRole('Super Administrador');
    $user2 = User::factory()->create();
    $user2->assignRole('Super Administrador');

    $account = MailAccount::factory()->for($user2)->create();
    $signature = Signature::factory()->for($account)->create();

    Livewire::actingAs($user1);

    Livewire::test('pages::configuracion.signatures')
        ->call('delete', $signature->id);

    expect(Signature::find($signature->id))->not->toBeNull();
})->throws(ModelNotFoundException::class);

it('cannot toggle another user signature', function () {
    $user1 = User::factory()->create();
    $user1->assignRole('Super Administrador');
    $user2 = User::factory()->create();
    $user2->assignRole('Super Administrador');

    $account = MailAccount::factory()->for($user2)->create();
    $signature = Signature::factory()->for($account)->create(['is_active' => true]);

    Livewire::actingAs($user1);

    Livewire::test('pages::configuracion.signatures')
        ->call('toggle', $signature->id);

    expect($signature->refresh()->is_active)->toBeTrue();
})->throws(ModelNotFoundException::class);

it('cannot create signature for another user mail account', function () {
    $user1 = User::factory()->create();
    $user1->assignRole('Super Administrador');
    $user2 = User::factory()->create();
    $user2->assignRole('Super Administrador');

    $account = MailAccount::factory()->for($user2)->create();

    Livewire::actingAs($user1);

    Livewire::test('pages::configuracion.signatures')
        ->call('create')
        ->set('selectedMailAccountId', $account->id)
        ->set('name', 'Bad Signature')
        ->set('body', 'Body')
        ->set('isDefault', false)
        ->call('save')
        ->assertHasErrors(['selectedMailAccountId']);
});

// --- Search & Pagination Tests ---

it('can search signatures by name or body', function () {
    $account = MailAccount::factory()->for($this->user)->create();

    Signature::factory()->for($account)->create(['name' => 'Firma Trabajo', 'body' => 'Saludos del trabajo']);
    Signature::factory()->for($account)->create(['name' => 'Firma Personal', 'body' => 'Saludos personales']);

    Livewire::actingAs($this->user);

    Livewire::test('pages::configuracion.signatures')
        ->set('search', 'Trabajo')
        ->assertSee('Firma Trabajo')
        ->assertDontSee('Firma Personal');
});

it('can paginate signatures', function () {
    $account = MailAccount::factory()->for($this->user)->create();

    collect(range(1, 21))->each(function (int $number) use ($account): void {
        Signature::factory()->for($account)->create([
            'name' => 'Firma '.$number,
            'created_at' => now()->subSeconds(21 - $number),
        ]);
    });

    Livewire::actingAs($this->user);

    Livewire::test('pages::configuracion.signatures')
        ->set('perPage', 20)
        ->call('gotoPage', 2)
        ->assertSet('paginators.page', 2);
});

// --- Validation Tests ---

it('validates required fields on create', function () {
    $account = MailAccount::factory()->for($this->user)->create();

    Livewire::actingAs($this->user);

    Livewire::test('pages::configuracion.signatures')
        ->call('create')
        ->set('selectedMailAccountId', '')
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['selectedMailAccountId', 'name']);
});

it('validates mail account belongs to user', function () {
    $otherUser = User::factory()->create();
    $otherAccount = MailAccount::factory()->for($otherUser)->create();

    Livewire::actingAs($this->user);

    Livewire::test('pages::configuracion.signatures')
        ->call('create')
        ->set('selectedMailAccountId', $otherAccount->id)
        ->set('name', 'Test')
        ->set('body', 'Body')
        ->set('isDefault', false)
        ->call('save')
        ->assertHasErrors(['selectedMailAccountId']);
});

// --- Bug Fix: Select mail account ---

it('can create a signature when selecting a mail account from the select', function () {
    $account = MailAccount::factory()->for($this->user)->create();

    Livewire::actingAs($this->user);

    Livewire::test('pages::configuracion.signatures')
        ->call('create')
        ->assertSet('selectedMailAccountId', (string) $account->id)
        ->set('selectedMailAccountId', $account->id)
        ->set('name', 'Firma con cuenta seleccionada')
        ->set('body', 'Saludos cordiales')
        ->set('isDefault', false)
        ->call('save')
        ->assertHasNoErrors(['selectedMailAccountId']);

    expect($this->user->mailAccounts()->first()->signatures()->count())->toBe(1);
});

it('shows validation error when mail account is not selected on save', function () {
    MailAccount::factory()->for($this->user)->create();

    Livewire::actingAs($this->user);

    Livewire::test('pages::configuracion.signatures')
        ->call('create')
        ->set('selectedMailAccountId', null)
        ->set('name', 'Firma sin cuenta')
        ->set('body', 'Body')
        ->set('isDefault', false)
        ->call('save')
        ->assertHasErrors(['selectedMailAccountId']);
});

// --- HTML Body Support ---

it('persists HTML body content without stripping tags', function () {
    $account = MailAccount::factory()->for($this->user)->create();
    $htmlBody = '<p>Saludos cordiales,</p><p><strong>Juan Pérez</strong><br><em>Director Legal</em></p><hr><p style="color: #666;">Este mensaje es confidencial.</p>';

    Livewire::actingAs($this->user);

    Livewire::test('pages::configuracion.signatures')
        ->call('create')
        ->set('selectedMailAccountId', $account->id)
        ->set('name', 'Firma HTML')
        ->set('body', $htmlBody)
        ->set('isDefault', false)
        ->call('save')
        ->assertHasNoErrors();

    $signature = $this->user->mailAccounts()->first()->signatures()->first();

    expect($signature->body)->toBe($htmlBody);
});

it('renders HTML body preview safely stripping dangerous tags', function () {
    $account = MailAccount::factory()->for($this->user)->create();
    $bodyWithScript = '<p>Hola</p><script>alert("xss")</script><strong>Negrita</strong>';

    $signature = Signature::factory()->for($account)->create([
        'name' => 'Firma con script',
        'body' => $bodyWithScript,
    ]);

    Livewire::actingAs($this->user);

    Livewire::test('pages::configuracion.signatures')
        ->call('edit', $signature->id)
        ->assertSet('body', $bodyWithScript);

    // Toggle preview to trigger rendering and verify script tags are stripped in output
    $component = Livewire::test('pages::configuracion.signatures')
        ->call('edit', $signature->id)
        ->call('togglePreview');

    $component->assertSee('Hola')
        ->assertSee('Negrita')
        ->assertDontSee('alert("xss")')
        ->assertDontSee('<script>');
});

it('can toggle HTML preview visibility', function () {
    $account = MailAccount::factory()->for($this->user)->create();
    $signature = Signature::factory()->for($account)->create([
        'body' => '<p>Test HTML</p>',
    ]);

    Livewire::actingAs($this->user);

    Livewire::test('pages::configuracion.signatures')
        ->call('edit', $signature->id)
        ->assertSet('showPreview', false)
        ->call('togglePreview')
        ->assertSet('showPreview', true)
        ->call('togglePreview')
        ->assertSet('showPreview', false);
});

it('renders HTML body in table preview with allowed tags', function () {
    $account = MailAccount::factory()->for($this->user)->create();
    $htmlBody = '<p>Saludos,</p><strong>Nombre</strong><br><em>Cargo</em>';

    Signature::factory()->for($account)->create([
        'name' => 'Firma HTML Table',
        'body' => $htmlBody,
    ]);

    $this->actingAs($this->user)
        ->get(route('configuracion.firmas.index'))
        ->assertSee('Saludos,')
        ->assertSee('Nombre')
        ->assertSee('Cargo');
});

it('resets preview toggle when closing form', function () {
    $account = MailAccount::factory()->for($this->user)->create();

    Livewire::actingAs($this->user);

    Livewire::test('pages::configuracion.signatures')
        ->call('create')
        ->call('togglePreview')
        ->assertSet('showPreview', true)
        ->call('cancel')
        ->assertSet('showPreview', false);
});
