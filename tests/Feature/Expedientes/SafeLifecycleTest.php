<?php

use App\Enums\CaseStatus;
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
    $this->admin = User::factory()->create();
    $this->admin->assignRole('Super Administrador');
    $this->account = MailAccount::factory()->for($this->admin)->create();
});

test('creation always starts pending client without a status input', function () {
    $assignee = User::factory()->create();

    Livewire::actingAs($this->admin)
        ->test('pages::expedientes.index')
        ->call('create')
        ->set('caseNumber', 'EXP-SAFE-CREATE')
        ->set('mailAccountId', $this->account->id)
        ->set('assignedUserId', $assignee->id)
        ->set('stateDeadline', now()->addWeek()->format('Y-m-d\\TH:i'))
        ->call('save')
        ->assertHasNoErrors();

    $expedient = Expedient::query()->where('case_number', 'EXP-SAFE-CREATE')->firstOrFail();
    expect($expedient->fresh()->status)->toBe(CaseStatus::PendingClient)
        ->and($expedient->fresh()->state_deadline)->not->toBeNull();
});

test('mass creation cannot choose an arbitrary lifecycle status', function () {
    $attributes = Expedient::factory()->for($this->account)->make([
        'status' => CaseStatus::Concluded,
    ])->getAttributes();

    $expedient = Expedient::query()->create($attributes);

    expect($expedient->fresh()->status)->toBe(CaseStatus::PendingClient);
});

test('only an account owner may invoke expediente outbound actions', function () {
    $expedient = Expedient::factory()->for($this->account)->create();
    $message = MailMessage::factory()->for($this->account)->create(['case_id' => $expedient->id]);
    $otherUser = User::factory()->create();
    $otherUser->givePermissionTo(['expedientes.ver', 'expedientes.actualizar']);

    Livewire::actingAs($otherUser)
        ->test('pages::expedientes.show', ['expedient' => $expedient])
        ->call('openComposer', 'reply_client', $message->id)
        ->assertForbidden();
});

test('show page does not expose outbound actions for concluded expedients', function () {
    $expedient = Expedient::factory()->for($this->account)->concluded()->create();
    MailMessage::factory()->for($this->account)->create(['case_id' => $expedient->id]);

    $this->actingAs($this->admin)
        ->get(route('expedientes.show', $expedient))
        ->assertDontSee('Responder')
        ->assertDontSee('Reenviar')
        ->assertDontSee('Cambiar estado');
});

test('show page offers only a phone request reply until the client phone is validated', function () {
    $expedient = Expedient::factory()->for($this->account)->create();
    MailMessage::factory()->for($this->account)->create(['case_id' => $expedient->id]);

    $this->actingAs($this->admin)
        ->get(route('expedientes.show', $expedient))
        ->assertSee('Solicitar teléfono')
        ->assertDontSee('Reenviar');
});

test('show page offers client reply and provider forwarding after the phone is validated', function () {
    $expedient = Expedient::factory()->for($this->account)->create();
    $expedient->validatePhone($this->admin);
    MailMessage::factory()->for($this->account)->create(['case_id' => $expedient->id]);

    $this->actingAs($this->admin)
        ->get(route('expedientes.show', $expedient))
        ->assertSee('Responder')
        ->assertSee('Reenviar');
});
