<?php

use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\IMAP;

test('imap config file exists and is loadable', function () {
    $config = config('imap');

    expect($config)->toBeArray()
        ->and($config)->toHaveKey('default')
        ->and($config)->toHaveKey('accounts')
        ->and($config)->toHaveKey('options')
        ->and($config)->toHaveKey('decoding');
});

test('default imap account has required connection keys', function () {
    $account = config('imap.accounts.default');

    expect($account)->toHaveKeys([
        'host',
        'port',
        'protocol',
        'encryption',
        'validate_cert',
        'username',
        'password',
        'authentication',
        'timeout',
    ]);
});

test('imap default account uses env variables for secrets', function () {
    // Verify that config values resolve from env() — not hardcoded
    expect(config('imap.accounts.default.host'))->toBe(env('IMAP_HOST', 'localhost'))
        ->and(config('imap.accounts.default.port'))->toBe((int) env('IMAP_PORT', 993))
        ->and(config('imap.accounts.default.username'))->toBe(env('IMAP_USERNAME', 'root@example.com'))
        ->and(config('imap.accounts.default.password'))->toBe(env('IMAP_PASSWORD', ''));
});

test('imap options have sensible defaults', function () {
    $options = config('imap.options');

    expect($options['fetch'])->toBe(IMAP::FT_PEEK)
        ->and($options['sequence'])->toBe(IMAP::ST_UID)
        ->and($options['fetch_body'])->toBeTrue()
        ->and($options['soft_fail'])->toBeFalse()
        ->and($options['debug'])->toBeFalse();
});

test('services config references imap default account', function () {
    expect(config('services.imap'))->toBeArray()
        ->and(config('services.imap.default_account'))->toBe(env('IMAP_DEFAULT_ACCOUNT', 'default'));
});

test('imap facade is resolvable', function () {
    $client = Webklex\IMAP\Facades\Client::account('default');

    expect($client)->toBeInstanceOf(Client::class);
});
