<?php

use App\Enums\CaseStatus;
use App\Enums\MailDirection;
use App\Enums\MilestoneAction;
use App\Mail\OutboundMail;
use App\Models\Expedient;
use App\Models\MailAccount;
use App\Models\MailMessage;
use App\Models\User;
use App\Services\Bandeja\OutboundMailContext;
use App\Services\Bandeja\OutboundMailService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class)->in(__DIR__);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->owner = User::factory()->create();
    $this->owner->assignRole('Super Administrador');
    $this->account = MailAccount::factory()->for($this->owner)->create(['is_active' => true]);
    $this->service = app(OutboundMailService::class);
});

function expedientOutboundContext(
    MailAccount $account,
    User $actor,
    Expedient $expedient,
    MailMessage $origin,
    string $mode,
    string $recipient = 'provider@example.com',
): OutboundMailContext {
    return OutboundMailContext::fromExpedient(
        account: $account,
        actor: $actor,
        mode: $mode,
        folder: $origin->folder,
        imapUid: (int) $origin->imap_uid,
        expedient: $expedient,
        origin: $origin,
        recipient: $recipient,
        subject: $mode === 'reply' ? 'Re: Subject' : 'Fwd: Subject',
        body: 'Body',
    );
}

test('provider outreach sends only after phone validation and records the transition', function () {
    Mail::fake();
    $expedient = Expedient::factory()->for($this->account)->create(['status' => CaseStatus::PendingClient]);
    $origin = MailMessage::factory()->for($this->account)->create([
        'case_id' => $expedient->id,
        'folder' => 'INBOX',
        'imap_uid' => '42',
    ]);
    $context = expedientOutboundContext($this->account, $this->owner, $expedient, $origin, 'forward');

    expect(fn () => $this->service->send($context))->toThrow(LogicException::class);
    Mail::assertNothingSent();

    $expedient->validatePhone($this->owner);
    $prepared = $this->service->prepare($context);
    $outgoing = $this->service->send($prepared['context']);

    expect($outgoing->direction)->toBe(MailDirection::Outgoing)
        ->and($expedient->fresh()->status)->toBe(CaseStatus::PendingProvider)
        ->and($expedient->milestones()->action(MilestoneAction::RepliedProvider)->first()->mail_message_id)->toBe($outgoing->id);

    Mail::assertSent(OutboundMail::class);
});

test('outbound service rejects concluded expedients before sending', function () {
    Mail::fake();
    $expedient = Expedient::factory()->for($this->account)->concluded()->create();
    $origin = MailMessage::factory()->for($this->account)->create([
        'case_id' => $expedient->id,
        'folder' => 'INBOX',
        'imap_uid' => '42',
    ]);

    expect(fn () => $this->service->send(
        expedientOutboundContext($this->account, $this->owner, $expedient, $origin, 'reply', 'client@example.com'),
    ))->toThrow(LogicException::class);

    Mail::assertNothingSent();
});

test('outbound service requires access to the expedient mail account', function () {
    $expedient = Expedient::factory()->for($this->account)->create();
    $origin = MailMessage::factory()->for($this->account)->create([
        'case_id' => $expedient->id,
        'folder' => 'INBOX',
        'imap_uid' => '42',
    ]);
    $otherUser = User::factory()->create();
    $context = expedientOutboundContext($this->account, $otherUser, $expedient, $origin, 'reply', 'client@example.com');

    expect(fn () => $this->service->send($context))->toThrow(AuthorizationException::class);
});

test('outbound service acquires and revalidates the active source reservation', function () {
    Mail::fake();
    $expedient = Expedient::factory()->for($this->account)->create();
    $origin = MailMessage::factory()->for($this->account)->create([
        'case_id' => $expedient->id,
        'folder' => 'INBOX',
        'imap_uid' => '42',
    ]);
    $context = expedientOutboundContext($this->account, $this->owner, $expedient, $origin, 'reply', 'client@example.com');

    $prepared = $this->service->prepare($context);
    $outgoing = $this->service->send($prepared['context']);

    expect($outgoing->case_id)->toBe($expedient->id)
        ->and($outgoing->status->value)->toBe('associated')
        ->and($this->account->fresh()->mailMessages()->whereKey($outgoing->id)->exists())->toBeTrue();
});
