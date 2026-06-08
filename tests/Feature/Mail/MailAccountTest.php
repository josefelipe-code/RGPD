<?php

use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class)->in(__DIR__);

beforeEach(function () {
    // Ensure we have a clean slate for each test
});

it('has mail_accounts table with correct columns', function () {
    expect(Schema::hasTable('mail_accounts'))->toBeTrue();

    $columns = Schema::getColumnListing('mail_accounts');

    expect($columns)->toContain(
        'id',
        'user_id',
        'label',
        'email_address',
        'imap_host',
        'imap_port',
        'imap_encryption',
        'imap_username',
        'imap_password',
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'smtp_username',
        'smtp_password',
        'is_active',
        'created_at',
        'updated_at',
    );
});

it('has foreign key on user_id referencing users table', function () {
    $foreignKeys = Schema::getForeignKeys('mail_accounts');

    $userFk = collect($foreignKeys)->first(fn ($fk) => in_array('user_id', $fk['columns']));

    expect($userFk)->not->toBeNull()
        ->and($userFk['foreign_table'])->toBe('users')
        ->and($userFk['foreign_columns'])->toBe(['id']);
});

it('has unique index on email_address per user', function () {
    $indexes = Schema::getIndexes('mail_accounts');

    $uniqueEmailIndex = collect($indexes)->first(fn ($idx) => $idx['unique'] && in_array('email_address', $idx['columns']));

    expect($uniqueEmailIndex)->not->toBeNull();
});

it('casts imap_password and smtp_password as encrypted', function () {
    $account = MailAccount::factory()->create([
        'imap_password' => 'secret-imap-pass',
        'smtp_password' => 'secret-smtp-pass',
    ]);

    // Reload from DB to ensure casting is applied
    $fresh = MailAccount::find($account->id);

    expect($fresh->imap_password)->toBe('secret-imap-pass')
        ->and($fresh->smtp_password)->toBe('secret-smtp-pass');
});

it('stores encrypted passwords as cipher text in database', function () {
    $account = MailAccount::factory()->create([
        'imap_password' => 'my-secret',
    ]);

    $raw = DB::table('mail_accounts')->find($account->id);

    // Laravel's encrypted cast stores as base64-encoded JSON with iv, value, mac
    $decoded = json_decode(base64_decode($raw->imap_password), true);

    expect($decoded)->toHaveKeys(['iv', 'value', 'mac']);
});

it('casts imap_options and smtp_options as encrypted array', function () {
    $account = MailAccount::factory()->create([
        'imap_options' => ['validate_cert' => false, 'timeout' => 60],
        'smtp_options' => ['local_domain' => 'example.com'],
    ]);

    $fresh = MailAccount::find($account->id);

    expect($fresh->imap_options)->toBe(['validate_cert' => false, 'timeout' => 60])
        ->and($fresh->smtp_options)->toBe(['local_domain' => 'example.com']);
});

it('belongs to a user', function () {
    $user = User::factory()->create();
    $account = MailAccount::factory()->for($user)->create();

    expect($account->user)->toBeInstanceOf(User::class)
        ->and($account->user->id)->toBe($user->id);
});

it('has active scope', function () {
    $user = User::factory()->create();
    MailAccount::factory()->for($user)->create(['is_active' => true]);
    MailAccount::factory()->for($user)->create(['is_active' => false]);

    expect(MailAccount::active()->count())->toBe(1);
});

it('normalizes starttls encryption to tls', function () {
    $account = MailAccount::factory()->for(User::factory()->create())->create([
        'smtp_encryption' => 'starttls',
        'imap_encryption' => 'starttls',
    ]);

    expect($account->smtp_encryption)->toBe('tls')
        ->and($account->imap_encryption)->toBe('tls');
});

it('normalizes none and empty encryption to null', function () {
    $user = User::factory()->create();

    $noneAccount = MailAccount::factory()->for($user)->create([
        'smtp_encryption' => 'none',
    ]);

    $emptyAccount = MailAccount::factory()->for($user)->create([
        'smtp_encryption' => '',
    ]);

    expect($noneAccount->smtp_encryption)->toBeNull()
        ->and($emptyAccount->smtp_encryption)->toBeNull();
});

it('rejects invalid encryption values', function () {
    $account = MailAccount::factory()->for(User::factory())->make([
        'smtp_encryption' => 'invalid-encryption',
    ]);

    $account->smtp_encryption;
})->throws(InvalidArgumentException::class, 'Invalid encryption value');
