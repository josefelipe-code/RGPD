<?php

use App\Enums\CaseStatus;
use App\Enums\MailDirection;
use App\Enums\MailMessageStatus;
use App\Enums\MilestoneAction;
use App\Mail\OutboundMail;
use App\Models\Contact;
use App\Models\Expedient;
use App\Models\MailAccount;
use App\Models\MailMessage;
use App\Models\Signature;
use App\Models\Template;
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

function createComposerOrigin(Expedient $expedient, array $attributes = []): MailMessage
{
    return MailMessage::factory()->for($expedient->mailAccount)->create(array_merge([
        'case_id' => $expedient->id,
        'from_email' => 'client@example.com',
        'subject' => 'Original subject',
        'folder' => 'INBOX',
        'imap_uid' => '42',
    ], $attributes));
}

it('opens the shared composer in reply mode and acquires the source reservation', function () {
    $expedient = Expedient::factory()->for($this->account)->create([
        'sender_email' => 'client@example.com',
    ]);
    $origin = createComposerOrigin($expedient);

    $component = Livewire::actingAs($this->user)
        ->test('pages::bandeja.mail-composer', [
            'accountId' => $this->account->id,
            'folder' => 'INBOX',
            'imapUid' => 42,
            'mode' => 'reply',
            'expedientId' => $expedient->id,
            'originMessageId' => $origin->id,
        ]);

    $component
        ->assertSet('open', true)
        ->assertSet('mode', 'reply')
        ->assertSet('to', 'client@example.com')
        ->assertSet('subject', 'Re: Original subject')
        ->assertSee('Tiempo restante');
});

it('preselects the phone request template until the client phone is validated', function () {
    Template::query()->where('purpose', 'missing_phone')->delete();
    $template = Template::factory()->create([
        'purpose' => 'missing_phone',
        'body' => 'Please provide your phone number.',
    ]);
    $expedient = Expedient::factory()->for($this->account)->create([
        'sender_email' => 'client@example.com',
    ]);
    $origin = createComposerOrigin($expedient);

    Livewire::actingAs($this->user)
        ->test('pages::bandeja.mail-composer', [
            'accountId' => $this->account->id,
            'folder' => 'INBOX',
            'imapUid' => 42,
            'mode' => 'reply',
            'expedientId' => $expedient->id,
            'originMessageId' => $origin->id,
        ])
        ->assertSet('templateId', $template->id)
        ->assertSet('body', 'Please provide your phone number.');
});

it('opens the shared composer in forward mode after phone validation', function () {
    $expedient = Expedient::factory()->for($this->account)->create();
    $expedient->validatePhone($this->user);
    $origin = createComposerOrigin($expedient);

    Livewire::actingAs($this->user)
        ->test('pages::bandeja.mail-composer', [
            'accountId' => $this->account->id,
            'folder' => 'INBOX',
            'imapUid' => 42,
            'mode' => 'forward',
            'expedientId' => $expedient->id,
            'originMessageId' => $origin->id,
        ])
        ->assertSet('mode', 'forward')
        ->assertSet('to', '')
        ->assertSet('subject', 'Fwd: Original subject');
});

it('sends an expedient reply synchronously and dispatches the refresh event', function () {
    Mail::fake();
    $expedient = Expedient::factory()->for($this->account)->create([
        'sender_email' => 'client@example.com',
        'status' => CaseStatus::PendingClient,
    ]);
    $origin = createComposerOrigin($expedient, [
        'message_id' => '<origin@example.com>',
        'references' => ['<root@example.com>'],
    ]);

    Livewire::actingAs($this->user)
        ->test('pages::bandeja.mail-composer', [
            'accountId' => $this->account->id,
            'folder' => 'INBOX',
            'imapUid' => 42,
            'mode' => 'reply',
            'expedientId' => $expedient->id,
            'originMessageId' => $origin->id,
        ])
        ->set('body', '<p>Reply body</p>')
        ->call('send')
        ->assertSet('open', false)
        ->assertDispatched('outbound-mail-sent');

    $outgoing = $expedient->fresh()->mailMessages()->where('direction', MailDirection::Outgoing)->sole();

    expect($expedient->fresh()->status)->toBe(CaseStatus::PendingClient)
        ->and($outgoing->status)->toBe(MailMessageStatus::Associated)
        ->and($outgoing->body_html)->toBe('<p>Reply body</p>')
        ->and($expedient->fresh()->milestones()->action(MilestoneAction::RepliedClient)->sole()->mail_message_id)->toBe($outgoing->id);

    Mail::assertSent(OutboundMail::class, function (OutboundMail $mail) use ($outgoing): bool {
        return $mail->messageId === $outgoing->message_id
            && $mail->inReplyTo === '<origin@example.com>'
            && $mail->references === ['<root@example.com>', '<origin@example.com>'];
    });
});

it('uses the inbox context without requiring a case or persisting the transient body', function () {
    Mail::fake();

    $component = Livewire::actingAs($this->user)
        ->test('pages::bandeja.mail-composer', [
            'accountId' => $this->account->id,
            'folder' => 'INBOX',
            'imapUid' => 77,
            'mode' => 'reply',
            'originData' => [
                'message_id' => '<origin@example.com>',
                'references' => '<root@example.com>',
                'subject' => 'Inbox subject',
                'from_email' => 'sender@example.com',
            ],
        ])
        ->assertSet('to', 'sender@example.com')
        ->assertSet('subject', 'Re: Inbox subject')
        ->set('body', 'Inbox reply')
        ->call('send')
        ->assertSet('open', false);

    $outgoing = MailMessage::query()->where('mail_account_id', $this->account->id)->sole();

    expect($outgoing->case_id)->toBeNull()
        ->and($outgoing->status)->toBe(MailMessageStatus::New)
        ->and($outgoing->body_html)->toBeNull();

    $component->assertDispatched('outbound-mail-sent');

    Mail::assertSent(OutboundMail::class);
});

it('keeps edited text until a template is explicitly applied', function () {
    $template = Template::factory()->create([
        'name' => 'Follow-up',
        'subject' => 'Follow-up subject',
        'body' => 'Template body',
    ]);
    $expedient = Expedient::factory()->for($this->account)->create();
    $origin = createComposerOrigin($expedient);

    Livewire::actingAs($this->user)
        ->test('pages::bandeja.mail-composer', [
            'accountId' => $this->account->id,
            'folder' => 'INBOX',
            'imapUid' => 42,
            'mode' => 'reply',
            'expedientId' => $expedient->id,
            'originMessageId' => $origin->id,
        ])
        ->set('body', 'Edited body')
        ->set('templateId', $template->id)
        ->assertSet('body', 'Edited body')
        ->call('applyTemplate')
        ->assertSet('body', 'Template body')
        ->assertSet('subject', 'Re: Original subject');
});

it('supports contacts, signatures, and comma-separated copy recipients', function () {
    Mail::fake();
    $contact = Contact::factory()->create(['name' => 'Ana Perez', 'email' => 'ana@example.com']);
    $signature = Signature::factory()->for($this->account)->default()->create(['body' => '<p>Regards</p>']);
    $expedient = Expedient::factory()->for($this->account)->create();
    $expedient->validatePhone($this->user);
    $origin = createComposerOrigin($expedient);

    Livewire::actingAs($this->user)
        ->test('pages::bandeja.mail-composer', [
            'accountId' => $this->account->id,
            'folder' => 'INBOX',
            'imapUid' => 42,
            'mode' => 'forward',
            'expedientId' => $expedient->id,
            'originMessageId' => $origin->id,
        ])
        ->set('contactSearch', 'Ana')
        ->assertSee('ana@example.com')
        ->set('contactId', $contact->id)
        ->assertSet('to', 'ana@example.com')
        ->assertSet('signatureId', $signature->id)
        ->set('cc', 'cc.one@example.com, cc.two@example.com')
        ->set('bcc', 'bcc@example.com')
        ->set('body', 'Forward body')
        ->call('send');

    Mail::assertSent(OutboundMail::class, function (OutboundMail $mail): bool {
        return $mail->recipientEmail === 'ana@example.com'
            && $mail->ccAddresses === ['cc.one@example.com', 'cc.two@example.com']
            && $mail->bccAddresses === ['bcc@example.com']
            && $mail->signature === '<p>Regards</p>';
    });
});

it('validates the composer form before calling the backend', function () {
    $expedient = Expedient::factory()->for($this->account)->create();
    $origin = createComposerOrigin($expedient);

    Livewire::actingAs($this->user)
        ->test('pages::bandeja.mail-composer', [
            'accountId' => $this->account->id,
            'folder' => 'INBOX',
            'imapUid' => 42,
            'mode' => 'forward',
            'expedientId' => $expedient->id,
            'originMessageId' => $origin->id,
        ])
        ->set('to', '')
        ->set('cc', 'not-an-email')
        ->set('bcc', 'also-not-an-email')
        ->set('body', '')
        ->call('send')
        ->assertHasErrors(['to', 'cc', 'bcc', 'body']);
});

it('requires a recipient for forwards', function () {
    $expedient = Expedient::factory()->for($this->account)->create();
    $expedient->validatePhone($this->user);
    $origin = createComposerOrigin($expedient);

    Livewire::actingAs($this->user)
        ->test('pages::bandeja.mail-composer', [
            'accountId' => $this->account->id,
            'folder' => 'INBOX',
            'imapUid' => 42,
            'mode' => 'forward',
            'expedientId' => $expedient->id,
            'originMessageId' => $origin->id,
        ])
        ->set('to', '')
        ->set('body', 'Body')
        ->call('send')
        ->assertHasErrors(['to']);
});
