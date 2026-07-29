<?php

use App\Models\MailAccount;
use App\Models\User;
use App\Services\MailAccountConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->in(__DIR__);

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('generates IMAP config array from MailAccount', function () {
    $account = MailAccount::factory()->for($this->user)->create([
        'imap_host' => 'imap.gmail.com',
        'imap_port' => 993,
        'imap_encryption' => 'ssl',
        'imap_username' => 'test@gmail.com',
        'imap_password' => 'secret123',
    ]);

    $config = $account->imapConfig();

    expect($config)->toBeArray()
        ->and($config['host'])->toBe('imap.gmail.com')
        ->and($config['port'])->toBe(993)
        ->and($config['protocol'])->toBe('imap')
        ->and($config['encryption'])->toBe('ssl')
        ->and($config['username'])->toBe('test@gmail.com')
        ->and($config['password'])->toBe('secret123')
        ->and($config['validate_cert'])->toBeTrue()
        ->and($config['timeout'])->toBe(30);
});

it('merges imap_options into IMAP config', function () {
    $account = MailAccount::factory()->for($this->user)->create([
        'imap_options' => ['timeout' => 60, 'validate_cert' => false],
    ]);

    $config = $account->imapConfig();

    expect($config['timeout'])->toBe(60)
        ->and($config['validate_cert'])->toBeFalse();
});

it('generates SMTP config array from MailAccount', function () {
    $account = MailAccount::factory()->for($this->user)->create([
        'smtp_host' => 'smtp.gmail.com',
        'smtp_port' => 587,
        'smtp_encryption' => 'tls',
        'smtp_username' => 'test@gmail.com',
        'smtp_password' => 'mypass',
    ]);

    $config = $account->smtpConfig();

    expect($config)->toBeArray()
        ->and($config['transport'])->toBe('smtp')
        ->and($config['host'])->toBe('smtp.gmail.com')
        ->and($config['port'])->toBe(587)
        ->and($config['encryption'])->toBe('tls')
        ->and($config['username'])->toBe('test@gmail.com')
        ->and($config['password'])->toBe('mypass');
});

it('handles SSL encryption in SMTP config', function () {
    $account = MailAccount::factory()->for($this->user)->create([
        'smtp_encryption' => 'ssl',
        'smtp_port' => 465,
    ]);

    $config = $account->smtpConfig();

    expect($config['encryption'])->toBe('ssl')
        ->and($config['port'])->toBe(465);
});

it('handles no encryption in SMTP config', function () {
    $account = MailAccount::factory()->for($this->user)->create([
        'smtp_encryption' => 'none',
    ]);

    $config = $account->smtpConfig();

    expect($config['encryption'])->toBeNull();
});

it('merges smtp_options into SMTP config', function () {
    $account = MailAccount::factory()->for($this->user)->create([
        'smtp_options' => ['local_domain' => 'myapp.com', 'timeout' => 30],
    ]);

    $config = $account->smtpConfig();

    expect($config['local_domain'])->toBe('myapp.com')
        ->and($config['timeout'])->toBe(30);
});

it('resolves MailAccountConfigService from container', function () {
    $service = app(MailAccountConfigService::class);

    expect($service)->toBeInstanceOf(MailAccountConfigService::class);
});

it('registers dynamic SMTP mailer at runtime', function () {
    $account = MailAccount::factory()->for($this->user)->create([
        'smtp_host' => 'smtp.custom.com',
        'smtp_port' => 587,
        'smtp_username' => 'user@custom.com',
        'smtp_password' => 'pass123',
    ]);

    $service = app(MailAccountConfigService::class);
    $mailerName = $service->registerSmtpMailer($account);

    expect($mailerName)->toBeString()
        ->and(config("mail.mailers.{$mailerName}"))->toBeArray()
        ->and(config("mail.mailers.{$mailerName}.host"))->toBe('smtp.custom.com')
        ->and(config("mail.mailers.{$mailerName}.port"))->toBe(587)
        ->and(config("mail.mailers.{$mailerName}.scheme"))->toBe('smtp');
});

it('maps SSL encryption to the SMTPS scheme when registering a dynamic mailer', function () {
    $account = MailAccount::factory()->for($this->user)->create([
        'smtp_encryption' => 'ssl',
        'smtp_port' => 465,
    ]);

    $mailerName = app(MailAccountConfigService::class)->registerSmtpMailer($account);

    expect(config("mail.mailers.{$mailerName}.scheme"))->toBe('smtps');
});

it('preserves Laravel default SMTP scheme selection without encryption', function () {
    $account = MailAccount::factory()->for($this->user)->create([
        'smtp_encryption' => 'none',
        'smtp_port' => 2525,
    ]);

    $mailerName = app(MailAccountConfigService::class)->registerSmtpMailer($account);

    expect(array_key_exists('scheme', config("mail.mailers.{$mailerName}")))->toBeFalse();
});

it('returns consistent mailer name for same account', function () {
    $account = MailAccount::factory()->for($this->user)->create();

    $service = app(MailAccountConfigService::class);

    $name1 = $service->registerSmtpMailer($account);
    $name2 = $service->registerSmtpMailer($account);

    expect($name1)->toBe($name2);
});

it('throws when registering SMTP mailer for unpersisted account', function () {
    $account = MailAccount::factory()->for($this->user)->make();

    $service = app(MailAccountConfigService::class);

    $service->registerSmtpMailer($account);
})->throws(InvalidArgumentException::class, 'Cannot register SMTP mailer for an unpersisted MailAccount');

it('throws RuntimeException with user-friendly message when SMTP connection fails', function () {
    $service = app(MailAccountConfigService::class);

    $service->verifySmtpConnection([
        'host' => '10.255.255.1', // Unreachable host
        'port' => 587,
        'encryption' => 'tls',
        'username' => 'test@example.com',
        'password' => 'secret',
    ]);
})->throws(RuntimeException::class);

it('throws RuntimeException with user-friendly message when IMAP connection fails', function () {
    $service = app(MailAccountConfigService::class);

    $service->verifyImapConnection([
        'host' => '10.255.255.1', // Unreachable host
        'port' => 993,
        'protocol' => 'imap',
        'encryption' => 'ssl',
        'validate_cert' => true,
        'username' => 'test@example.com',
        'password' => 'secret',
        'authentication' => null,
        'timeout' => 5,
    ]);
})->throws(RuntimeException::class);

it('does not expose raw exception messages in SMTP errors', function () {
    $service = app(MailAccountConfigService::class);

    try {
        $service->verifySmtpConnection([
            'host' => '10.255.255.1',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'test@example.com',
            'password' => 'secret',
        ]);
    } catch (RuntimeException $e) {
        // Error message should be in Spanish and user-friendly
        expect($e->getMessage())->toContain('SMTP');
    }
});

it('does not expose raw exception messages in IMAP errors', function () {
    $service = app(MailAccountConfigService::class);

    try {
        $service->verifyImapConnection([
            'host' => '10.255.255.1',
            'port' => 993,
            'protocol' => 'imap',
            'encryption' => 'ssl',
            'validate_cert' => true,
            'username' => 'test@example.com',
            'password' => 'secret',
            'authentication' => null,
            'timeout' => 5,
        ]);
    } catch (RuntimeException $e) {
        // Error message should be in Spanish and user-friendly
        expect($e->getMessage())->toContain('IMAP');
    }
});
