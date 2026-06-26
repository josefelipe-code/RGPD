<?php

use App\Enums\CaseStatus;
use App\Mail\OutboundMail;
use App\Models\Expedient;
use App\Models\MailAccount;
use App\Models\MailMessage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

uses(RefreshDatabase::class)->in(__DIR__);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->user = User::factory()->create();
    $this->user->assignRole('Super Administrador');
    $this->account = MailAccount::factory()->for($this->user)->create([
        'is_active' => true,
        'email_address' => 'test@example.com',
    ]);
});

it('opens composer modal in reply_client mode', function () {
    $expedient = Expedient::factory()->for($this->account)->create([
        'sender_email' => 'client@example.com',
    ]);
    $origin = MailMessage::factory()->for($this->account)->create([
        'case_id' => $expedient->id,
        'from_email' => 'client@example.com',
        'subject' => 'Original subject',
    ]);

    Livewire::actingAs($this->user)
        ->test('pages::bandeja.mail-composer', [
            'mode' => 'reply_client',
            'expedientId' => $expedient->id,
            'originMessageId' => $origin->id,
        ])
        ->assertSet('mode', 'reply_client')
        ->assertSet('to', 'client@example.com')
        ->assertSet('subject', 'Re: Original subject');
});

it('opens composer modal in forward_provider mode', function () {
    $expedient = Expedient::factory()->for($this->account)->create();
    $origin = MailMessage::factory()->for($this->account)->create([
        'case_id' => $expedient->id,
        'subject' => 'Original subject',
    ]);

    Livewire::actingAs($this->user)
        ->test('pages::bandeja.mail-composer', [
            'mode' => 'forward_provider',
            'expedientId' => $expedient->id,
            'originMessageId' => $origin->id,
        ])
        ->assertSet('mode', 'forward_provider')
        ->assertSet('subject', 'Fwd: Original subject');
});

it('sends reply and closes modal', function () {
    Mail::fake();

    $expedient = Expedient::factory()->for($this->account)->create([
        'sender_email' => 'client@example.com',
        'status' => CaseStatus::PendingProvider,
    ]);
    $origin = MailMessage::factory()->for($this->account)->create([
        'case_id' => $expedient->id,
        'from_email' => 'client@example.com',
        'subject' => 'Original',
    ]);

    Livewire::actingAs($this->user)
        ->test('pages::bandeja.mail-composer', [
            'mode' => 'reply_client',
            'expedientId' => $expedient->id,
            'originMessageId' => $origin->id,
        ])
        ->set('body', '<p>Reply body</p>')
        ->call('send');

    // Status transitioned
    expect($expedient->fresh()->status)->toBe(CaseStatus::PendingClient);

    // Mail was queued
    Mail::assertQueued(OutboundMail::class);
});

it('requires body to send', function () {
    $expedient = Expedient::factory()->for($this->account)->create();
    $origin = MailMessage::factory()->for($this->account)->create([
        'case_id' => $expedient->id,
    ]);

    Livewire::actingAs($this->user)
        ->test('pages::bandeja.mail-composer', [
            'mode' => 'reply_client',
            'expedientId' => $expedient->id,
            'originMessageId' => $origin->id,
        ])
        ->set('body', '')
        ->call('send')
        ->assertHasErrors(['body' => 'required']);
});

it('requires to field in forward mode', function () {
    $expedient = Expedient::factory()->for($this->account)->create();
    $origin = MailMessage::factory()->for($this->account)->create([
        'case_id' => $expedient->id,
    ]);

    Livewire::actingAs($this->user)
        ->test('pages::bandeja.mail-composer', [
            'mode' => 'forward_provider',
            'expedientId' => $expedient->id,
            'originMessageId' => $origin->id,
        ])
        ->set('to', '')
        ->set('body', '<p>Body</p>')
        ->call('send')
        ->assertHasErrors(['to' => 'required']);
});
