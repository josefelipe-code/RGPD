<?php

use App\Models\Expedient;
use App\Models\SharedIncident;
use App\Models\User;
use Livewire\Livewire;

test('the active application layout renders the shared incident bell', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('shared-incidents-bell', false);
});

test('open incidents are visible to every authenticated user with their shared count and contextual navigation', function () {
    $expedient = Expedient::factory()->create(['case_number' => 'EXP-INCIDENT']);
    $incident = SharedIncident::factory()->for($expedient, 'expedient')->create();
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();

    $this->actingAs($firstUser);
    Livewire::test('incidents.notification-bell')
        ->assertSee('1')
        ->assertSee($incident->title)
        ->assertSee(route('expedientes.show', $expedient));

    $this->actingAs($secondUser);
    Livewire::test('incidents.notification-bell')
        ->assertSee('1')
        ->assertSee($incident->title);
});

test('claiming an incident is atomic and prevents a second user from taking it', function () {
    $incident = SharedIncident::factory()->create();
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();

    $claimed = SharedIncident::claim($incident->id, $firstUser);
    $secondClaim = SharedIncident::claim($incident->id, $secondUser);

    expect($claimed)->not->toBeNull()
        ->and($secondClaim)->toBeNull()
        ->and($incident->fresh()->status)->toBe(SharedIncident::StatusClaimed)
        ->and($incident->fresh()->claimed_by_user_id)->toBe($firstUser->id)
        ->and($incident->fresh()->claimed_at)->not->toBeNull();
});

test('an authenticated user can take an incident from the notification bell', function () {
    $user = User::factory()->create();
    $incident = SharedIncident::factory()->create();

    $this->actingAs($user);
    Livewire::test('incidents.notification-bell')
        ->assertSee('Tomar incidencia')
        ->call('claim', $incident->id)
        ->assertDontSee($incident->title);

    expect($incident->fresh()->claimed_by_user_id)->toBe($user->id);
});
