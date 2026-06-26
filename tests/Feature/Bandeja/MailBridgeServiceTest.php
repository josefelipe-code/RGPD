<?php

use App\Enums\CaseStatus;
use App\Enums\MailDirection;
use App\Enums\MilestoneAction;
use App\Mail\OutboundMail;
use App\Models\Expedient;
use App\Models\MailAccount;
use App\Models\MailMessage;
use App\Models\User;
use App\Services\Bandeja\MailBridgeService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class)->in(__DIR__);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->user = User::factory()->create();
    $this->user->assignRole('Super Administrador');
    $this->account = MailAccount::factory()->for($this->user)->create([
        'is_active' => true,
        'email_address' => 'test@example.com',
    ]);
    $this->service = app(MailBridgeService::class);
});

// S12: Reply to client — outgoing recorded, status transition, milestone with mail_message_id
it('sends reply to client and transitions expedient to pending_client', function () {
    Mail::fake();

    $expedient = Expedient::factory()->for($this->account)->create([
        'status' => CaseStatus::PendingProvider,
        'sender_email' => 'client@example.com',
    ]);
    $origin = MailMessage::factory()->for($this->account)->create([
        'case_id' => $expedient->id,
        'from_email' => 'client@example.com',
        'subject' => 'Original subject',
    ]);

    $result = $this->service->send(
        account: $this->account,
        mode: 'reply_client',
        origin: $origin,
        expedient: $expedient,
        actor: $this->user,
        payload: [
            'body' => '<p>Reply body</p>',
            'subject' => 'Re: Original subject',
        ],
    );

    // Outgoing mail_message created
    expect($result)->toBeInstanceOf(MailMessage::class)
        ->and($result->direction)->toBe(MailDirection::Outgoing)
        ->and($result->case_id)->toBe($expedient->id)
        ->and($result->to_email)->toBe('client@example.com')
        ->and($result->message_id)->not->toBeNull();

    // Expedient transitioned
    expect($expedient->fresh()->status)->toBe(CaseStatus::PendingClient);

    // Milestone created with mail_message_id link
    $milestone = $expedient->milestones()->action(MilestoneAction::RepliedClient)->first();
    expect($milestone)->not->toBeNull()
        ->and($milestone->mail_message_id)->toBe($result->id);

    // Mail was queued
    Mail::assertQueued(OutboundMail::class);
});

// S13: Forward to provider — outgoing recorded, status transition, milestone
it('sends forward to provider and transitions expedient to pending_provider', function () {
    Mail::fake();

    $expedient = Expedient::factory()->for($this->account)->create([
        'status' => CaseStatus::PendingClient,
        'sender_email' => 'client@example.com',
    ]);
    $origin = MailMessage::factory()->for($this->account)->create([
        'case_id' => $expedient->id,
        'from_email' => 'client@example.com',
        'subject' => 'Original subject',
    ]);

    $result = $this->service->send(
        account: $this->account,
        mode: 'forward_provider',
        origin: $origin,
        expedient: $expedient,
        actor: $this->user,
        payload: [
            'to' => 'provider@example.com',
            'body' => '<p>Forward body</p>',
            'subject' => 'Fwd: Original subject',
            'bcc' => ['bcc@example.com'],
        ],
    );

    expect($result->direction)->toBe(MailDirection::Outgoing)
        ->and($result->to_email)->toBe('provider@example.com')
        ->and($expedient->fresh()->status)->toBe(CaseStatus::PendingProvider);

    $milestone = $expedient->milestones()->action(MilestoneAction::RepliedProvider)->first();
    expect($milestone->mail_message_id)->toBe($result->id);
});

// S14: BCC on provider forward
it('includes bcc recipients on provider forward', function () {
    Mail::fake();

    $expedient = Expedient::factory()->for($this->account)->create([
        'sender_email' => 'client@example.com',
    ]);
    $origin = MailMessage::factory()->for($this->account)->create([
        'case_id' => $expedient->id,
        'from_email' => 'client@example.com',
    ]);

    $this->service->send(
        account: $this->account,
        mode: 'forward_provider',
        origin: $origin,
        expedient: $expedient,
        actor: $this->user,
        payload: [
            'to' => 'provider@example.com',
            'body' => '<p>Body</p>',
            'subject' => 'Fwd',
            'bcc' => ['audit@example.com'],
        ],
    );

    Mail::assertQueued(OutboundMail::class, function (OutboundMail $mail) {
        return in_array('audit@example.com', $mail->bccAddresses);
    });
});

// S16/S19: Failed send does not transition — rollback
it('rolls back on SMTP failure without status change or milestone', function () {
    // Use a partial mock that throws on send()
    $mock = Mockery::mock(Mailer::class);
    $mock->shouldReceive('send')->andThrow(new RuntimeException('SMTP connection failed'));

    Mail::shouldReceive('mailer')->andReturn($mock);

    $expedient = Expedient::factory()->for($this->account)->create([
        'status' => CaseStatus::PendingClient,
        'sender_email' => 'client@example.com',
    ]);
    $origin = MailMessage::factory()->for($this->account)->create([
        'case_id' => $expedient->id,
        'from_email' => 'client@example.com',
    ]);

    $initialStatus = $expedient->status;

    expect(fn () => $this->service->send(
        account: $this->account,
        mode: 'reply_client',
        origin: $origin,
        expedient: $expedient,
        actor: $this->user,
        payload: [
            'body' => '<p>Body</p>',
            'subject' => 'Re: Test',
        ],
    ))->toThrow(RuntimeException::class);

    // Status unchanged
    expect($expedient->fresh()->status)->toBe($initialStatus);

    // No new milestones
    $repliedCount = $expedient->milestones()->action(MilestoneAction::RepliedClient)->count();
    expect($repliedCount)->toBe(0);

    // No outgoing mail_message created
    $outgoingCount = MailMessage::where('case_id', $expedient->id)
        ->where('direction', MailDirection::Outgoing)
        ->count();
    expect($outgoingCount)->toBe(0);
});
