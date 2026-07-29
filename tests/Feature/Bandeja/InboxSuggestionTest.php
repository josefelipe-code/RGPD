<?php

use App\Models\MailAccount;
use App\Models\User;
use App\Services\Bandeja\ImapMailboxService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class)->in(__DIR__);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->user = User::factory()->create();
    $this->user->assignRole('Super Administrador');
    $this->account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);

    $mailbox = Mockery::mock(ImapMailboxService::class);
    $mailbox->shouldReceive('listFolders')->byDefault()->andReturn(collect([
        ['path' => 'INBOX', 'name' => 'INBOX'],
    ]));
    $mailbox->shouldReceive('listEnvelopes')->byDefault()->andReturn(collect());
    $this->instance(ImapMailboxService::class, $mailbox);
});

it('does not offer expediente suggestions or creation from an IMAP envelope', function () {
    $component = Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox');

    expect($component->html())
        ->not->toContain('Expedientes sugeridos')
        ->not->toContain('Crear expediente')
        ->not->toContain('Descartar')
        ->not->toContain('Asociar');
});
