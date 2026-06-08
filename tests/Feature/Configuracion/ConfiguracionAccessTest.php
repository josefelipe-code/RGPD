<?php

use App\Models\MailAccount;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

// --- Access / Permission Tests ---

test('non configuracion users cannot access configuracion routes', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('configuracion.cuentas-correo.index'))
        ->assertForbidden();
});

test('super admins can access configuracion pages', function () {
    $user = User::factory()->create();
    $user->assignRole('Super Administrador');

    $this->actingAs($user)
        ->get(route('configuracion.cuentas-correo.index'))
        ->assertOk()
        ->assertSee('Cuentas de correo');
});

test('configuracion sidebar item visible for super admins', function () {
    $user = User::factory()->create();
    $user->assignRole('Super Administrador');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSee('Configuración')
        ->assertSee('Cuentas de correo');
});

test('configuracion sidebar item not visible for regular users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertDontSee('Configuración');
});

// --- CRUD Tests ---

it('renders mail accounts configuracion page', function () {
    $user = User::factory()->create();
    $user->assignRole('Super Administrador');

    $this->actingAs($user)
        ->get(route('configuracion.cuentas-correo.index'))
        ->assertOk()
        ->assertSee('Cuentas de correo');
});

it('requires authentication', function () {
    $this->get(route('configuracion.cuentas-correo.index'))
        ->assertRedirect(route('login'));
});

it('lists only authenticated user mail accounts', function () {
    $user1 = User::factory()->create();
    $user1->assignRole('Super Administrador');
    $user2 = User::factory()->create();
    $user2->assignRole('Super Administrador');

    MailAccount::factory()->for($user1)->create(['label' => 'User 1 Account']);
    MailAccount::factory()->for($user2)->create(['label' => 'User 2 Account']);

    $this->actingAs($user1)
        ->get(route('configuracion.cuentas-correo.index'))
        ->assertSee('User 1 Account')
        ->assertDontSee('User 2 Account');
});

it('can create a new mail account', function () {
    $user = User::factory()->create();
    $user->assignRole('Super Administrador');

    Livewire::actingAs($user);

    Livewire::test('pages::configuracion.mail-accounts')
        ->set('label', 'Work Email')
        ->set('email_address', 'work@example.com')
        ->set('imap_host', 'imap.example.com')
        ->set('imap_port', 993)
        ->set('imap_encryption', 'ssl')
        ->set('imap_username', 'work@example.com')
        ->set('imap_password', 'secret-imap')
        ->set('smtp_host', 'smtp.example.com')
        ->set('smtp_port', 587)
        ->set('smtp_encryption', 'tls')
        ->set('smtp_username', 'work@example.com')
        ->set('smtp_password', 'secret-smtp')
        ->call('save')
        ->assertHasNoErrors();

    expect($user->mailAccounts()->count())->toBe(1)
        ->and($user->mailAccounts()->first()->label)->toBe('Work Email');
});

it('can edit an existing mail account without changing password', function () {
    $user = User::factory()->create();
    $user->assignRole('Super Administrador');

    $account = MailAccount::factory()->for($user)->create([
        'label' => 'Old Label',
        'imap_password' => 'old-imap-pass',
        'smtp_password' => 'old-smtp-pass',
    ]);

    Livewire::actingAs($user);

    Livewire::test('pages::configuracion.mail-accounts')
        ->call('edit', $account->id)
        ->assertSet('label', 'Old Label')
        ->assertSet('email_address', $account->email_address)
        ->assertSet('imap_password', '')
        ->assertSet('smtp_password', '')
        ->set('label', 'New Label')
        ->call('save')
        ->assertHasNoErrors();

    $account->refresh();

    expect($account->label)->toBe('New Label')
        ->and($account->imap_password)->toBe('old-imap-pass')
        ->and($account->smtp_password)->toBe('old-smtp-pass');
});

it('can toggle mail account active status', function () {
    $user = User::factory()->create();
    $user->assignRole('Super Administrador');

    $account = MailAccount::factory()->for($user)->create(['is_active' => true]);

    Livewire::actingAs($user);

    Livewire::test('pages::configuracion.mail-accounts')
        ->call('toggle', $account->id);

    expect($account->refresh()->is_active)->toBeFalse();

    Livewire::test('pages::configuracion.mail-accounts')
        ->call('toggle', $account->id);

    expect($account->refresh()->is_active)->toBeTrue();
});

it('can delete a mail account', function () {
    $user = User::factory()->create();
    $user->assignRole('Super Administrador');

    $account = MailAccount::factory()->for($user)->create();

    Livewire::actingAs($user);

    Livewire::test('pages::configuracion.mail-accounts')
        ->call('delete', $account->id)
        ->assertHasNoErrors();

    expect(MailAccount::find($account->id))->toBeNull();
});

it('can paginate mail accounts', function () {
    $user = User::factory()->create();
    $user->assignRole('Super Administrador');

    collect(range(1, 21))->each(function (int $number) use ($user): void {
        MailAccount::factory()->for($user)->create([
            'label' => 'Cuenta '.$number,
            'email_address' => 'cuenta'.$number.'@example.com',
            'created_at' => now()->subSeconds(21 - $number),
        ]);
    });

    Livewire::actingAs($user);

    Livewire::test('pages::configuracion.mail-accounts')
        ->set('perPage', 20)
        ->call('gotoPage', 2)
        ->assertSet('paginators.page', 2);
});

it('cannot edit another user mail account', function () {
    $user1 = User::factory()->create();
    $user1->assignRole('Super Administrador');
    $user2 = User::factory()->create();
    $user2->assignRole('Super Administrador');

    $account = MailAccount::factory()->for($user2)->create(['label' => 'Original Label']);

    Livewire::actingAs($user1);

    Livewire::test('pages::configuracion.mail-accounts')
        ->call('edit', $account->id);

    expect($account->refresh()->label)->toBe('Original Label');
})->throws(ModelNotFoundException::class);

it('cannot delete another user mail account', function () {
    $user1 = User::factory()->create();
    $user1->assignRole('Super Administrador');
    $user2 = User::factory()->create();
    $user2->assignRole('Super Administrador');

    $account = MailAccount::factory()->for($user2)->create();

    Livewire::actingAs($user1);

    Livewire::test('pages::configuracion.mail-accounts')
        ->call('delete', $account->id);

    expect(MailAccount::find($account->id))->not->toBeNull();
})->throws(ModelNotFoundException::class);

it('cannot toggle another user mail account', function () {
    $user1 = User::factory()->create();
    $user1->assignRole('Super Administrador');
    $user2 = User::factory()->create();
    $user2->assignRole('Super Administrador');

    $account = MailAccount::factory()->for($user2)->create(['is_active' => true]);

    Livewire::actingAs($user1);

    Livewire::test('pages::configuracion.mail-accounts')
        ->call('toggle', $account->id);

    expect($account->refresh()->is_active)->toBeTrue();
})->throws(ModelNotFoundException::class);

it('validates required fields on create', function () {
    $user = User::factory()->create();
    $user->assignRole('Super Administrador');

    Livewire::actingAs($user);

    Livewire::test('pages::configuracion.mail-accounts')
        ->set('label', '')
        ->set('email_address', 'invalid-email')
        ->set('imap_host', '')
        ->set('imap_username', '')
        ->set('imap_password', '')
        ->set('smtp_host', '')
        ->set('smtp_username', '')
        ->set('smtp_password', '')
        ->call('save')
        ->assertHasErrors(['label', 'email_address', 'imap_host', 'imap_username', 'smtp_host', 'smtp_username']);
});

it('can search mail accounts by label or email', function () {
    $user = User::factory()->create();
    $user->assignRole('Super Administrador');

    MailAccount::factory()->for($user)->create(['label' => 'Work Email', 'email_address' => 'work@example.com']);
    MailAccount::factory()->for($user)->create(['label' => 'Personal Email', 'email_address' => 'personal@example.com']);

    Livewire::actingAs($user);

    Livewire::test('pages::configuracion.mail-accounts')
        ->set('search', 'Work')
        ->assertSee('Work Email')
        ->assertDontSee('Personal Email');
});

// --- Permission Granularity Tests ---

test('configuracion index redirects to cuentas-correo', function () {
    $user = User::factory()->create();
    $user->assignRole('Super Administrador');

    $this->actingAs($user)
        ->get(route('configuracion.index'))
        ->assertRedirect(route('configuracion.cuentas-correo.index'));
});

test('configuracion page renders toolbar with search and create button', function () {
    $user = User::factory()->create();
    $user->assignRole('Super Administrador');

    $this->actingAs($user)
        ->get(route('configuracion.cuentas-correo.index'))
        ->assertSee('Crear cuenta')
        ->assertSee('Buscar por nombre o email');
});

test('configuracion page renders modal form', function () {
    $user = User::factory()->create();
    $user->assignRole('Super Administrador');

    $this->actingAs($user)
        ->get(route('configuracion.cuentas-correo.index'))
        ->assertSee('mail-account-form');
});
