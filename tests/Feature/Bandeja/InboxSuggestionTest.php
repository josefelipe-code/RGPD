<?php

use App\Enums\MailMessageStatus;
use App\Models\Expedient;
use App\Models\MailAccount;
use App\Models\MailMessage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class)->in(__DIR__);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->user = User::factory()->create();
    $this->user->assignRole('Super Administrador');
    $this->account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);
});

// S7: Suggestion matched by email — shows in inbox
it('shows suggestion candidates when message email matches an expedient', function () {
    $expedient = Expedient::factory()->for($this->account)->create([
        'sender_email' => 'client@example.com',
        'case_number' => 'EXP-12345',
    ]);
    $message = MailMessage::factory()->for($this->account)->create([
        'from_email' => 'client@example.com',
    ]);

    $component = Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->set('selectedAccountId', $this->account->id)
        ->call('selectMessage', $message->id);

    // Should see the expedient case number as a suggestion
    $component->assertSee('EXP-12345');
});

// S8: User confirms association
it('associates a message to an expedient when user clicks associate', function () {
    $expedient = Expedient::factory()->for($this->account)->create([
        'sender_email' => 'client@example.com',
    ]);
    $message = MailMessage::factory()->for($this->account)->create([
        'from_email' => 'client@example.com',
        'status' => MailMessageStatus::New,
    ]);

    Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->set('selectedAccountId', $this->account->id)
        ->call('selectMessage', $message->id)
        ->call('associateMessage', $message->id, $expedient->id);

    expect($message->fresh()->case_id)->toBe($expedient->id)
        ->and($message->fresh()->status)->toBe(MailMessageStatus::Associated);
});

// S9: User discards suggestion
it('discards a message when user clicks discard', function () {
    $message = MailMessage::factory()->for($this->account)->create([
        'status' => MailMessageStatus::New,
    ]);

    Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->set('selectedAccountId', $this->account->id)
        ->call('selectMessage', $message->id)
        ->call('discard', $message->id);

    expect($message->fresh()->status)->toBe(MailMessageStatus::Discarded);
});

// S11: No matching expediente — shows create-new option
it('shows create new case option when no expedient matches', function () {
    $message = MailMessage::factory()->for($this->account)->create([
        'from_email' => 'unknown@example.com',
    ]);

    $component = Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->set('selectedAccountId', $this->account->id)
        ->call('selectMessage', $message->id);

    // Should see the "create new case" option
    $component->assertSee('Crear expediente');
});

// Authorization: cannot associate message from another user's account
it('cannot associate a message from another users account', function () {
    $otherUser = User::factory()->create();
    $otherUser->assignRole('Super Administrador');
    $otherAccount = MailAccount::factory()->for($otherUser)->create(['is_active' => true]);
    $otherExpedient = Expedient::factory()->for($otherAccount)->create();
    $message = MailMessage::factory()->for($this->account)->create([
        'from_email' => 'client@example.com',
    ]);

    Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->set('selectedAccountId', $this->account->id)
        ->call('selectMessage', $message->id)
        ->call('associateMessage', $message->id, $otherExpedient->id);

    expect($message->fresh()->case_id)->toBeNull();
});

// Authorization: cannot associate to an expedient from another user's account
it('cannot associate to an expedient from another users account', function () {
    $otherUser = User::factory()->create();
    $otherUser->assignRole('Super Administrador');
    $otherAccount = MailAccount::factory()->for($otherUser)->create(['is_active' => true]);
    $otherExpedient = Expedient::factory()->for($otherAccount)->create([
        'sender_email' => 'client@example.com',
    ]);
    $message = MailMessage::factory()->for($this->account)->create([
        'from_email' => 'client@example.com',
    ]);

    Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->set('selectedAccountId', $this->account->id)
        ->call('selectMessage', $message->id)
        ->call('associateMessage', $message->id, $otherExpedient->id);

    expect($message->fresh()->case_id)->toBeNull();
});

// Discard clears selected message
it('clears selected message after discard', function () {
    $message = MailMessage::factory()->for($this->account)->create([
        'status' => MailMessageStatus::New,
    ]);

    $component = Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->set('selectedAccountId', $this->account->id)
        ->call('selectMessage', $message->id);

    expect($component->get('selectedMessageId'))->toBe($message->id);

    $component->call('discard', $message->id);

    expect($component->get('selectedMessageId'))->toBeNull();
});

// Already associated message shows no suggestion actions
it('does not show suggestion actions for already associated message', function () {
    $expedient = Expedient::factory()->for($this->account)->create();
    $message = MailMessage::factory()->for($this->account)->associated()->create();

    $component = Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->set('selectedAccountId', $this->account->id)
        ->call('selectMessage', $message->id);

    // Should NOT see associate buttons or create new case
    $component->assertDontSee('Asociar')
        ->assertDontSee('Crear expediente');
});

// Discarded message shows no suggestion actions
it('does not show suggestion actions for discarded message', function () {
    $message = MailMessage::factory()->for($this->account)->create([
        'status' => MailMessageStatus::Discarded,
    ]);

    $component = Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->set('selectedAccountId', $this->account->id)
        ->call('selectMessage', $message->id);

    $component->assertDontSee('Asociar')
        ->assertDontSee('Crear expediente');
});
