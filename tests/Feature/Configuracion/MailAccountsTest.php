<?php

use App\Models\MailAccount;
use App\Models\User;
use App\Services\MailAccountConfigService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class)->in(__DIR__);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->user = User::factory()->create();
    $this->user->assignRole('Super Administrador');
});

it('does not save mail account when SMTP verification fails', function () {
    $this->actingAs($this->user);

    // Mock the service to throw on SMTP verification
    $mock = Mockery::mock(MailAccountConfigService::class);
    $mock->shouldReceive('verifySmtpConnection')
        ->andThrow(new RuntimeException('No se pudo conectar al servidor SMTP. Verificá host y puerto.'));
    $mock->shouldReceive('registerSmtpMailer')->andReturn('mail_account_1');

    $this->instance(MailAccountConfigService::class, $mock);

    Livewire::test('pages::configuracion.mail-accounts')
        ->set('label', 'Test Account')
        ->set('email_address', 'test@example.com')
        ->set('imap_host', 'imap.example.com')
        ->set('imap_port', 993)
        ->set('imap_encryption', 'ssl')
        ->set('imap_username', 'test@example.com')
        ->set('imap_password', 'secret')
        ->set('smtp_host', 'smtp.example.com')
        ->set('smtp_port', 587)
        ->set('smtp_encryption', 'tls')
        ->set('smtp_username', 'test@example.com')
        ->set('smtp_password', 'secret')
        ->call('save')
        ->assertHasErrors(['smtp_connection']);

    expect(MailAccount::count())->toBe(0);
});

it('does not save mail account when IMAP verification fails', function () {
    $this->actingAs($this->user);

    // Mock the service — SMTP passes, IMAP fails
    $mock = Mockery::mock(MailAccountConfigService::class);
    $mock->shouldReceive('verifySmtpConnection')->andReturn(null);
    $mock->shouldReceive('verifyImapConnection')
        ->andThrow(new RuntimeException('Autenticación IMAP fallida. Verificá usuario y contraseña.'));
    $mock->shouldReceive('registerSmtpMailer')->andReturn('mail_account_1');

    $this->instance(MailAccountConfigService::class, $mock);

    Livewire::test('pages::configuracion.mail-accounts')
        ->set('label', 'Test Account')
        ->set('email_address', 'test@example.com')
        ->set('imap_host', 'imap.example.com')
        ->set('imap_port', 993)
        ->set('imap_encryption', 'ssl')
        ->set('imap_username', 'test@example.com')
        ->set('imap_password', 'wrong-password')
        ->set('smtp_host', 'smtp.example.com')
        ->set('smtp_port', 587)
        ->set('smtp_encryption', 'tls')
        ->set('smtp_username', 'test@example.com')
        ->set('smtp_password', 'secret')
        ->call('save')
        ->assertHasErrors(['imap_connection']);

    expect(MailAccount::count())->toBe(0);
});

it('does not reach IMAP verification when SMTP fails first', function () {
    $this->actingAs($this->user);

    $mock = Mockery::mock(MailAccountConfigService::class);
    $mock->shouldReceive('verifySmtpConnection')
        ->andThrow(new RuntimeException('SMTP connection failed'));
    // verifyImapConnection should NEVER be called
    $mock->shouldNotReceive('verifyImapConnection');
    $mock->shouldReceive('registerSmtpMailer')->andReturn('mail_account_1');

    $this->instance(MailAccountConfigService::class, $mock);

    Livewire::test('pages::configuracion.mail-accounts')
        ->set('label', 'Test Account')
        ->set('email_address', 'test@example.com')
        ->set('imap_host', 'imap.example.com')
        ->set('imap_port', 993)
        ->set('imap_encryption', 'ssl')
        ->set('imap_username', 'test@example.com')
        ->set('imap_password', 'secret')
        ->set('smtp_host', 'smtp.example.com')
        ->set('smtp_port', 587)
        ->set('smtp_encryption', 'tls')
        ->set('smtp_username', 'test@example.com')
        ->set('smtp_password', 'secret')
        ->call('save')
        ->assertHasErrors(['smtp_connection']);

    expect(MailAccount::count())->toBe(0);
});
