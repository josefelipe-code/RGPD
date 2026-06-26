<?php

use App\Models\Expedient;
use App\Models\MailAccount;
use App\Models\MailMessage;
use App\Models\User;
use App\Services\Bandeja\InboundSuggestionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->user = User::factory()->create();
    $this->user->assignRole('Super Administrador');
    $this->account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);
    $this->service = new InboundSuggestionService;
});

// S7: Suggestion matched by email
it('suggests expedientes matching the message from_email', function () {
    $expedient = Expedient::factory()->for($this->account)->create([
        'sender_email' => 'client@example.com',
    ]);
    $message = MailMessage::factory()->for($this->account)->create([
        'from_email' => 'client@example.com',
    ]);

    $suggestions = $this->service->suggest($message);

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions->first()['expedient']->id)->toBe($expedient->id)
        ->and($suggestions->first()['confidence'])->toBe('high')
        ->and($suggestions->first()['reason'])->toBe('email match');
});

// S10: Multiple candidate expedientes
it('returns multiple candidates when both email and phone match different expedientes', function () {
    $emailMatch = Expedient::factory()->for($this->account)->create([
        'sender_email' => 'client@example.com',
        'sender_phone' => '+34111111111',
    ]);
    $phoneMatch = Expedient::factory()->for($this->account)->create([
        'sender_email' => 'other@example.com',
        'sender_phone' => '+34612345678',
    ]);
    $message = MailMessage::factory()->for($this->account)->create([
        'from_email' => 'client@example.com',
        'sender_phone' => '+34612345678',
    ]);

    $suggestions = $this->service->suggest($message);

    expect($suggestions)->toHaveCount(2);
});

// S11: No matching expediente
it('returns empty collection when no expedient matches', function () {
    Expedient::factory()->for($this->account)->create([
        'sender_email' => 'other@example.com',
        'sender_phone' => '+34999999999',
    ]);
    $message = MailMessage::factory()->for($this->account)->create([
        'from_email' => 'client@example.com',
        'sender_phone' => '+34612345678',
    ]);

    $suggestions = $this->service->suggest($message);

    expect($suggestions)->toHaveCount(0);
});

// Phone-alone never sufficient (D7)
it('does not suggest by phone alone when message has no from_email match', function () {
    // Create expedient with matching phone but different email
    Expedient::factory()->for($this->account)->create([
        'sender_email' => 'other@example.com',
        'sender_phone' => '+34612345678',
    ]);
    // Message has phone but from_email does NOT match any expedient
    $message = MailMessage::factory()->for($this->account)->create([
        'from_email' => 'unknown@example.com',
        'sender_phone' => '+34612345678',
    ]);

    $suggestions = $this->service->suggest($message);

    // Phone alone should not produce suggestions
    expect($suggestions)->toHaveCount(0);
});

// Email match should work even without phone
it('suggests by email match even when message has no phone', function () {
    Expedient::factory()->for($this->account)->create([
        'sender_email' => 'client@example.com',
        'sender_phone' => null,
    ]);
    $message = MailMessage::factory()->for($this->account)->create([
        'from_email' => 'client@example.com',
        'sender_phone' => null,
    ]);

    $suggestions = $this->service->suggest($message);

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions->first()['confidence'])->toBe('high')
        ->and($suggestions->first()['reason'])->toBe('email match');
});

// Scoped to mail account
it('only suggests expedientes from the same mail account', function () {
    $otherAccount = MailAccount::factory()->for($this->user)->create(['is_active' => true]);
    // Expedient on different account with matching email
    Expedient::factory()->for($otherAccount)->create([
        'sender_email' => 'client@example.com',
    ]);
    $message = MailMessage::factory()->for($this->account)->create([
        'from_email' => 'client@example.com',
    ]);

    $suggestions = $this->service->suggest($message);

    expect($suggestions)->toHaveCount(0);
});

// Excludes soft-deleted expedientes
it('does not suggest soft-deleted expedientes', function () {
    $expedient = Expedient::factory()->for($this->account)->create([
        'sender_email' => 'client@example.com',
    ]);
    $expedient->delete();

    $message = MailMessage::factory()->for($this->account)->create([
        'from_email' => 'client@example.com',
    ]);

    $suggestions = $this->service->suggest($message);

    expect($suggestions)->toHaveCount(0);
});

// Excludes concluded expedientes
it('does not suggest concluded expedientes', function () {
    Expedient::factory()->for($this->account)->concluded()->create([
        'sender_email' => 'client@example.com',
    ]);

    $message = MailMessage::factory()->for($this->account)->create([
        'from_email' => 'client@example.com',
    ]);

    $suggestions = $this->service->suggest($message);

    expect($suggestions)->toHaveCount(0);
});

// Limits results
it('limits suggestions to a reasonable number', function () {
    for ($i = 0; $i < 10; $i++) {
        Expedient::factory()->for($this->account)->create([
            'sender_email' => 'client@example.com',
        ]);
    }

    $message = MailMessage::factory()->for($this->account)->create([
        'from_email' => 'client@example.com',
    ]);

    $suggestions = $this->service->suggest($message);

    expect($suggestions->count())->toBeLessThanOrEqual(5);
});
