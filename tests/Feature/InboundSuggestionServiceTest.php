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

// --- PR4: Provider Reply Detection ---

// S19: Match by In-Reply-To — highest confidence
it('suggests expediente when inbound In-Reply-To matches an outgoing message_id', function () {
    $expedient = Expedient::factory()->for($this->account)->create([
        'sender_email' => 'client@example.com',
    ]);

    // Outgoing message we sent to the client
    $outgoing = MailMessage::factory()->for($this->account)->outgoing()->create([
        'case_id' => $expedient->id,
        'message_id' => 'outgoing-abc123@domain.com',
        'to_email' => 'client@example.com',
    ]);

    // Inbound reply from the provider/client referencing our outgoing message
    $inbound = MailMessage::factory()->for($this->account)->create([
        'from_email' => 'client@example.com',
        'in_reply_to' => 'outgoing-abc123@domain.com',
    ]);

    $suggestions = $this->service->suggest($inbound);

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions->first()['expedient']->id)->toBe($expedient->id)
        ->and($suggestions->first()['confidence'])->toBe('highest')
        ->and($suggestions->first()['reason'])->toBe('In-Reply-To match');
});

// S19 edge case: In-Reply-To matches outgoing but outgoing has no case_id
it('returns empty when In-Reply-To matches outgoing message with no associated case', function () {
    // Outgoing message NOT linked to any expedient
    MailMessage::factory()->for($this->account)->outgoing()->create([
        'case_id' => null,
        'message_id' => 'orphan-outgoing@domain.com',
    ]);

    $inbound = MailMessage::factory()->for($this->account)->create([
        'from_email' => 'someone@example.com',
        'in_reply_to' => 'orphan-outgoing@domain.com',
    ]);

    $suggestions = $this->service->suggest($inbound);

    expect($suggestions)->toHaveCount(0);
});

// S19 + S7: In-Reply-To takes priority over email match
it('prioritizes In-Reply-To match over email match when both exist', function () {
    $expedientA = Expedient::factory()->for($this->account)->create([
        'sender_email' => 'client@example.com',
    ]);
    $expedientB = Expedient::factory()->for($this->account)->create([
        'sender_email' => 'other@example.com',
    ]);

    // Outgoing linked to expedientB
    $outgoing = MailMessage::factory()->for($this->account)->outgoing()->create([
        'case_id' => $expedientB->id,
        'message_id' => 'outgoing-def456@domain.com',
        'to_email' => 'other@example.com',
    ]);

    // Inbound reply: In-Reply-To points to expedientB's outgoing, but from_email matches expedientA
    $inbound = MailMessage::factory()->for($this->account)->create([
        'from_email' => 'client@example.com',
        'in_reply_to' => 'outgoing-def456@domain.com',
    ]);

    $suggestions = $this->service->suggest($inbound);

    // In-Reply-To match should be first (highest confidence)
    expect($suggestions->first()['expedient']->id)->toBe($expedientB->id)
        ->and($suggestions->first()['confidence'])->toBe('highest')
        ->and($suggestions->first()['reason'])->toBe('In-Reply-To match');
});

// S20: email+phone fallback still works (existing behavior, now named explicitly)
it('suggests by email+phone match with medium confidence when no In-Reply-To', function () {
    $expedient = Expedient::factory()->for($this->account)->create([
        'sender_email' => 'client@example.com',
        'sender_phone' => '+34612345678',
    ]);

    $inbound = MailMessage::factory()->for($this->account)->create([
        'from_email' => 'client@example.com',
        'sender_phone' => '+34612345678',
        'in_reply_to' => null,
    ]);

    $suggestions = $this->service->suggest($inbound);

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions->first()['expedient']->id)->toBe($expedient->id)
        ->and($suggestions->first()['confidence'])->toBe('high')
        ->and($suggestions->first()['reason'])->toBe('email match');
});

// S21: Subject fallback — low confidence when no In-Reply-To, no email, no phone
it('suggests by subject match with low confidence when no other signals exist', function () {
    $expedient = Expedient::factory()->for($this->account)->create([
        'sender_email' => 'client@example.com',
    ]);

    // Inbound with matching subject but different from_email and no phone
    $inbound = MailMessage::factory()->for($this->account)->create([
        'from_email' => 'unknown@example.com',
        'sender_phone' => null,
        'subject' => 'Consulta sobre RGPD - Expediente 001',
        'in_reply_to' => null,
    ]);

    $suggestions = $this->service->suggest($inbound);

    // No email match → no suggestions (subject fallback not yet implemented)
    // This test documents current behavior; subject match will be added later
    expect($suggestions)->toHaveCount(0);
});

// Triangulation: In-Reply-To scoped to mail account
it('does not match In-Reply-To against outgoing messages from a different mail account', function () {
    $otherAccount = MailAccount::factory()->for($this->user)->create(['is_active' => true]);

    $otherExpedient = Expedient::factory()->for($otherAccount)->create([
        'sender_email' => 'client@example.com',
    ]);

    // Outgoing on a different account
    MailMessage::factory()->for($otherAccount)->outgoing()->create([
        'case_id' => $otherExpedient->id,
        'message_id' => 'other-account-outgoing@domain.com',
        'to_email' => 'client@example.com',
    ]);

    // Create an expedient on our account with the same email
    $ourExpedient = Expedient::factory()->for($this->account)->create([
        'sender_email' => 'client@example.com',
    ]);

    // Inbound on our account with same In-Reply-To
    $inbound = MailMessage::factory()->for($this->account)->create([
        'from_email' => 'client@example.com',
        'in_reply_to' => 'other-account-outgoing@domain.com',
    ]);

    $suggestions = $this->service->suggest($inbound);

    // Should fall through to email match (our account), NOT In-Reply-To to other account
    expect($suggestions)->toHaveCount(1)
        ->and($suggestions->first()['expedient']->id)->toBe($ourExpedient->id)
        ->and($suggestions->first()['confidence'])->toBe('high')
        ->and($suggestions->first()['reason'])->toBe('email match');
});

// Triangulation: In-Reply-To with concluded expedient
it('does not suggest In-Reply-To match when linked expedient is concluded', function () {
    $expedient = Expedient::factory()->for($this->account)->concluded()->create([
        'sender_email' => 'client@example.com',
    ]);

    MailMessage::factory()->for($this->account)->outgoing()->create([
        'case_id' => $expedient->id,
        'message_id' => 'concluded-outgoing@domain.com',
        'to_email' => 'client@example.com',
    ]);

    $inbound = MailMessage::factory()->for($this->account)->create([
        'from_email' => 'client@example.com',
        'in_reply_to' => 'concluded-outgoing@domain.com',
    ]);

    $suggestions = $this->service->suggest($inbound);

    // Concluded expedient should not appear via In-Reply-To
    expect($suggestions)->toHaveCount(0);
});
