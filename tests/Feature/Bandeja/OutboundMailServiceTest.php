<?php

use App\Enums\CaseStatus;
use App\Enums\MailDirection;
use App\Enums\MailMessageStatus;
use App\Enums\MilestoneAction;
use App\Mail\OutboundMail;
use App\Models\Expedient;
use App\Models\MailAccount;
use App\Models\MailMessage;
use App\Models\User;
use App\Services\Bandeja\OutboundMailContext;
use App\Services\Bandeja\OutboundMailService;
use Carbon\CarbonInterface;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
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
    $this->service = app(OutboundMailService::class);
});

function createServiceOrigin(Expedient $expedient, array $attributes = []): MailMessage
{
    return MailMessage::factory()->for($expedient->mailAccount)->create(array_merge([
        'case_id' => $expedient->id,
        'from_email' => 'client@example.com',
        'from_name' => 'Client',
        'subject' => 'Original subject',
        'message_id' => '<origin@example.com>',
        'references' => ['<root@example.com>'],
        'folder' => 'INBOX',
        'imap_uid' => '42',
    ], $attributes));
}

function createServiceContext(
    MailAccount $account,
    User $actor,
    Expedient $expedient,
    MailMessage $origin,
    string $mode = 'reply',
    string $recipient = 'client@example.com',
    ?CarbonInterface $deadline = null,
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
        subject: $mode === 'reply' ? 'Re: Original subject' : 'Fwd: Original subject',
        body: '<p>Outbound body</p>',
        deadline: $deadline,
    );
}

test('prepares an expedient reply with defaults and a five-minute reservation', function () {
    $expedient = Expedient::factory()->for($this->account)->create([
        'sender_email' => 'client@example.com',
    ]);
    $origin = createServiceOrigin($expedient);
    $context = OutboundMailContext::fromExpedient(
        account: $this->account,
        actor: $this->user,
        mode: 'reply',
        folder: 'INBOX',
        imapUid: 42,
        expedient: $expedient,
        origin: $origin,
        deadline: $expedient->state_deadline,
    );

    $prepared = $this->service->prepare($context);

    expect($prepared['context']->recipient)->toBe('client@example.com')
        ->and($prepared['context']->subject)->toBe('Re: Original subject')
        ->and($prepared['reservation']->user_id)->toBe($this->user->id)
        ->and($prepared['reservation']->expires_at->isFuture())->toBeTrue();
});

test('sends a reply synchronously, persists threading, and records its milestone', function () {
    Mail::fake();
    $expedient = Expedient::factory()->for($this->account)->create([
        'status' => CaseStatus::PendingClient,
        'sender_email' => 'client@example.com',
    ]);
    $origin = createServiceOrigin($expedient);
    $context = createServiceContext(
        $this->account,
        $this->user,
        $expedient,
        $origin,
        deadline: $expedient->state_deadline,
    );
    $prepared = $this->service->prepare($context);

    $outgoing = $this->service->send($prepared['context']);

    expect($outgoing->direction)->toBe(MailDirection::Outgoing)
        ->and($outgoing->status)->toBe(MailMessageStatus::Associated)
        ->and($outgoing->case_id)->toBe($expedient->id)
        ->and($outgoing->to_email)->toBe('client@example.com')
        ->and($outgoing->body_html)->toBe('<p>Outbound body</p>')
        ->and($outgoing->body_text)->toBe('Outbound body')
        ->and($outgoing->in_reply_to)->toBe('<origin@example.com>')
        ->and($outgoing->references)->toBe(['<root@example.com>', '<origin@example.com>'])
        ->and($outgoing->message_id)->toMatch('/^[^<>]+@[^<>]+$/')
        ->and($expedient->milestones()->action(MilestoneAction::RepliedClient)->sole()->mail_message_id)->toBe($outgoing->id);

    Mail::assertSent(OutboundMail::class, function (OutboundMail $mail) use ($outgoing): bool {
        return $mail->messageId === $outgoing->message_id
            && $mail->inReplyTo === '<origin@example.com>'
            && $mail->references === ['<root@example.com>', '<origin@example.com>'];
    });
});

test('forwards to the provider only after phone validation and applies the deadline', function () {
    Mail::fake();
    $expedient = Expedient::factory()->for($this->account)->create([
        'status' => CaseStatus::PendingClient,
    ]);
    $origin = createServiceOrigin($expedient);
    $deadline = now()->addDays(3);
    $context = createServiceContext(
        $this->account,
        $this->user,
        $expedient,
        $origin,
        mode: 'forward',
        recipient: 'provider@example.com',
        deadline: $deadline,
    );

    expect(fn () => $this->service->prepare($context))->toThrow(LogicException::class);
    Mail::assertNothingSent();

    $expedient->validatePhone($this->user);
    $prepared = $this->service->prepare($context);
    $outgoing = $this->service->send($prepared['context']);

    expect($outgoing->to_email)->toBe('provider@example.com')
        ->and($outgoing->references)->toBe([])
        ->and($outgoing->in_reply_to)->toBeNull()
        ->and($expedient->fresh()->status)->toBe(CaseStatus::PendingProvider)
        ->and($expedient->fresh()->state_deadline->format('Y-m-d H:i:s'))->toBe($deadline->format('Y-m-d H:i:s'))
        ->and($expedient->milestones()->action(MilestoneAction::RepliedProvider)->sole()->mail_message_id)->toBe($outgoing->id);

    Mail::assertSent(OutboundMail::class, fn (OutboundMail $mail): bool => $mail->references === [] && $mail->inReplyTo === null);
});

test('sends inbox mail from a transient origin without associating it to an expedient', function () {
    Mail::fake();
    $context = OutboundMailContext::fromInbox(
        account: $this->account,
        actor: $this->user,
        mode: 'reply',
        folder: 'INBOX',
        imapUid: 77,
        origin: [
            'message_id' => '<inbox-origin@example.com>',
            'references' => '<inbox-root@example.com>',
            'subject' => 'Inbox subject',
            'from_email' => 'sender@example.com',
        ],
        recipient: 'sender@example.com',
        subject: 'Re: Inbox subject',
        body: 'Inbox reply',
    );
    $prepared = $this->service->prepare($context);
    $outgoing = $this->service->send($prepared['context']);

    expect($outgoing->case_id)->toBeNull()
        ->and($outgoing->status)->toBe(MailMessageStatus::New)
        ->and($outgoing->body_html)->toBeNull()
        ->and($outgoing->body_text)->toBeNull()
        ->and($outgoing->in_reply_to)->toBe('<inbox-origin@example.com>')
        ->and($outgoing->references)->toBe(['<inbox-root@example.com>', '<inbox-origin@example.com>'])
        ->and(config("mail.mailers.mail_account_{$this->account->id}.host"))->toBe($this->account->smtp_host);

    Mail::assertSent(OutboundMail::class);
});

test('does not renew an expired reservation during send', function () {
    Mail::fake();
    $expedient = Expedient::factory()->for($this->account)->create();
    $origin = createServiceOrigin($expedient);
    $context = createServiceContext(
        $this->account,
        $this->user,
        $expedient,
        $origin,
        deadline: $expedient->state_deadline,
    );
    $prepared = $this->service->prepare($context);
    $prepared['reservation']->forceFill(['expires_at' => now()->subMinute()])->save();

    expect(fn () => $this->service->send($prepared['context']))->toThrow(AuthorizationException::class);
    Mail::assertNothingSent();
    expect(MailMessage::query()->where('direction', MailDirection::Outgoing)->count())->toBe(0);
});

test('does not send when another operator holds the source reservation', function () {
    Mail::fake();
    $otherUser = User::factory()->create();
    $otherUser->assignRole('Super Administrador');
    $this->account->operators()->attach($otherUser);
    $expedient = Expedient::factory()->for($this->account)->create();
    $origin = createServiceOrigin($expedient);
    $this->service->prepare(createServiceContext(
        $this->account,
        $otherUser,
        $expedient,
        $origin,
        deadline: $expedient->state_deadline,
    ));

    expect(fn () => $this->service->prepare(createServiceContext(
        $this->account,
        $this->user,
        $expedient,
        $origin,
        deadline: $expedient->state_deadline,
    )))->toThrow(AuthorizationException::class);

    Mail::assertNothingSent();
});

test('rolls back persistence and lifecycle changes when SMTP fails', function () {
    $mailer = Mockery::mock(Mailer::class);
    $mailer->shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP connection failed'));
    Mail::shouldReceive('mailer')->once()->andReturn($mailer);

    $expedient = Expedient::factory()->for($this->account)->create([
        'status' => CaseStatus::PendingClient,
    ]);
    $origin = createServiceOrigin($expedient);
    $context = createServiceContext(
        $this->account,
        $this->user,
        $expedient,
        $origin,
        deadline: $expedient->state_deadline,
    );
    $prepared = $this->service->prepare($context);

    expect(fn () => $this->service->send($prepared['context']))->toThrow(RuntimeException::class);

    expect($expedient->fresh()->status)->toBe(CaseStatus::PendingClient)
        ->and($expedient->milestones()->action(MilestoneAction::RepliedClient)->count())->toBe(0)
        ->and(MailMessage::query()->where('direction', MailDirection::Outgoing)->count())->toBe(0);
});

test('rejects an origin that is not associated with the supplied expedient', function () {
    $expedient = Expedient::factory()->for($this->account)->create();
    $otherExpedient = Expedient::factory()->for($this->account)->create();
    $origin = createServiceOrigin($otherExpedient);
    $context = createServiceContext($this->account, $this->user, $expedient, $origin);

    expect(fn () => $this->service->prepare($context))->toThrow(LogicException::class);
});
