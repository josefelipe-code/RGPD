<?php

use App\Models\MailAccount;
use App\Models\User;
use App\Services\Bandeja\ImapSyncService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class)->in(__DIR__);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->user = User::factory()->create();
});

it('syncs active accounts via artisan command', function () {
    $account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);

    $mock = Mockery::mock(ImapSyncService::class);
    $mock->shouldReceive('syncAccount')
        ->with($account)
        ->andReturn(Collection::make([]));

    $this->instance(ImapSyncService::class, $mock);

    $this->artisan('bandeja:sync')
        ->assertExitCode(0)
        ->expectsOutput(__('Syncing account: :label (:email)', [
            'label' => $account->label,
            'email' => $account->email_address,
        ]));
});

it('skips inactive accounts', function () {
    MailAccount::factory()->for($this->user)->create(['is_active' => false]);

    $this->artisan('bandeja:sync')
        ->assertExitCode(1)
        ->expectsOutput(__('No active mail accounts found to sync.'));
});

it('syncs specific account when account-id option is provided', function () {
    $account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);
    MailAccount::factory()->for($this->user)->create(['is_active' => true]);

    $mock = Mockery::mock(ImapSyncService::class);
    $mock->shouldReceive('syncAccount')
        ->with($account)
        ->andReturn(Collection::make([]));

    $this->instance(ImapSyncService::class, $mock);

    $this->artisan('bandeja:sync', ['--account-id' => $account->id])
        ->assertExitCode(0);
});

it('handles sync errors gracefully', function () {
    $account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);

    $mock = Mockery::mock(ImapSyncService::class);
    $mock->shouldReceive('syncAccount')
        ->with($account)
        ->andThrow(new \RuntimeException('Connection failed'));

    $this->instance(ImapSyncService::class, $mock);

    $this->artisan('bandeja:sync')
        ->assertExitCode(0);
});
