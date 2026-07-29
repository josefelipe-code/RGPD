<?php

use App\Mail\ImapOutboundMail;
use App\Models\Contact;
use App\Models\MailAccount;
use App\Models\MailMessage;
use App\Models\Signature;
use App\Models\Template;
use App\Models\User;
use App\Services\Bandeja\ImapMailboxService;
use App\Services\Bandeja\InboxOutboundMailService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

uses(RefreshDatabase::class)->in(__DIR__);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->user = User::factory()->create();
    $this->user->assignRole('Super Administrador');

    $mailbox = Mockery::mock(ImapMailboxService::class);
    $mailbox->shouldReceive('listFolders')->byDefault()->andReturn(collect([
        ['path' => 'INBOX', 'name' => 'INBOX'],
        ['path' => 'Archive', 'name' => 'Archive'],
        ['path' => 'Trash', 'name' => 'Trash'],
    ]));
    $mailbox->shouldReceive('listEnvelopes')->byDefault()->andReturn(collect());
    $this->instance(ImapMailboxService::class, $mailbox);
});

function inboxEnvelope(int $uid, string $subject, bool $isRead = false, string $folder = 'INBOX'): array
{
    return [
        'uid' => $uid,
        'message_id' => "<{$uid}@imap.example>",
        'subject' => $subject,
        'from_email' => "sender{$uid}@example.com",
        'from_name' => "Sender {$uid}",
        'received_at' => now()->subMinutes($uid),
        'is_read' => $isRead,
        'folder' => $folder,
    ];
}

it('requires authentication to access the inbox', function () {
    $this->get(route('bandeja.inbox'))
        ->assertRedirect(route('login'));
});

it('requires the inbox view permission', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('bandeja.inbox'))
        ->assertForbidden();
});

it('loads transient IMAP envelopes without querying or persisting MailMessage', function () {
    $account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);
    $mailbox = Mockery::mock(ImapMailboxService::class);
    $mailbox->shouldReceive('listFolders')->once()->andReturn(collect([
        ['path' => 'INBOX', 'name' => 'INBOX'],
    ]));
    $mailbox->shouldReceive('listEnvelopes')->once()
        ->with(Mockery::on(fn (MailAccount $selected): bool => $selected->is($account)), 'INBOX')
        ->andReturn(collect([inboxEnvelope(77, 'IMAP only envelope')]));
    $this->instance(ImapMailboxService::class, $mailbox);

    $component = Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox');

    expect($component->get('messages')->first()->subject)->toBe('IMAP only envelope')
        ->and(MailMessage::query()->where('mail_account_id', $account->id)->count())->toBe(0);
});

it('filters envelopes by IMAP read state only', function () {
    $account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);
    $mailbox = Mockery::mock(ImapMailboxService::class);
    $mailbox->shouldReceive('listFolders')->andReturn(collect([
        ['path' => 'INBOX', 'name' => 'INBOX'],
    ]));
    $mailbox->shouldReceive('listEnvelopes')->andReturn(collect([
        inboxEnvelope(1, 'Unread', false),
        inboxEnvelope(2, 'Read', true),
    ]));
    $this->instance(ImapMailboxService::class, $mailbox);

    $component = Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox');

    expect($component->get('messages')->total())->toBe(2);

    $component->call('setStatusFilter', 'unread');

    expect($component->get('messages')->total())->toBe(1)
        ->and($component->get('messages')->first()->subject)->toBe('Unread');
});

it('keeps an unread selected reader and actions after deferred IMAP marking', function () {
    $account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);
    $localMessage = MailMessage::factory()->for($account)->create([
        'imap_uid' => '42',
        'folder' => 'INBOX',
        'is_read' => false,
        'body_text' => null,
        'body_html' => null,
    ]);
    $mailbox = Mockery::mock(ImapMailboxService::class);
    $mailbox->shouldReceive('listFolders')->andReturn(collect([
        ['path' => 'INBOX', 'name' => 'INBOX'],
    ]));
    $mailbox->shouldReceive('listEnvelopes')->andReturn(collect([inboxEnvelope(42, 'Remote body')]));
    $mailbox->shouldReceive('fetchMessage')->once()->with(Mockery::on(fn (MailAccount $selected): bool => $selected->is($account)), 'INBOX', 42)->andReturn([
        'html' => '<p>Loaded from IMAP</p>',
        'text' => 'Loaded from IMAP',
        'headers' => [],
        'is_read' => false,
    ]);
    $mailbox->shouldReceive('setRead')->once()->with(Mockery::on(fn (MailAccount $selected): bool => $selected->is($account)), 'INBOX', 42)->andReturnTrue();
    $this->instance(ImapMailboxService::class, $mailbox);

    $component = Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->call('setStatusFilter', 'unread')
        ->call('selectMessage', 42);

    expect($component->get('selectedMessage')->subject)->toBe('Remote body')
        ->and($component->get('bodyLoaded'))->toBeFalse()
        ->and($component->get('envelopes')[0]['is_read'])->toBeFalse()
        ->and($component->html())->toContain('wire:init="loadSelectedMessageBody')
        ->and($component->html())->toContain('class="flex-1 min-h-0"');

    $component->call('loadSelectedMessageBody', $account->id, 'INBOX', 42);

    expect($component->get('selectedMessageBody')->toHtml())->toContain('Loaded from IMAP')
        ->and($component->get('bodyLoaded'))->toBeTrue()
        ->and($component->get('envelopes')[0]['is_read'])->toBeFalse();

    $component->call('markMessageRead', $account->id, 'INBOX', 42);

    expect($component->get('envelopes')[0]['is_read'])->toBeTrue()
        ->and($component->get('messages')->total())->toBe(0)
        ->and($component->get('selectedMessage')->subject)->toBe('Remote body')
        ->and($component->html())->toContain('Responder')
        ->and($component->html())->toContain('Reenviar')
        ->and($localMessage->fresh()->is_read)->toBeFalse()
        ->and($localMessage->fresh()->body_text)->toBeNull();
});

it('handles deferred body failures and rejects a body fetch outside the selected account', function () {
    $account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);
    $otherAccount = MailAccount::factory()->create(['is_active' => true]);
    $mailbox = Mockery::mock(ImapMailboxService::class);
    $mailbox->shouldReceive('listFolders')->andReturn(collect([['path' => 'INBOX', 'name' => 'INBOX']]));
    $mailbox->shouldReceive('listEnvelopes')->andReturn(collect([inboxEnvelope(42, 'Remote body')]));
    $mailbox->shouldReceive('fetchMessage')->once()->with(Mockery::on(fn (MailAccount $selected): bool => $selected->is($account)), 'INBOX', 42)->andThrow(new RuntimeException('IMAP unavailable'));
    $this->instance(ImapMailboxService::class, $mailbox);

    $component = Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->call('selectMessage', 42)
        ->call('loadSelectedMessageBody', $otherAccount->id, 'INBOX', 42)
        ->assertSet('bodyLoaded', false)
        ->assertSet('bodyLoadFailed', false)
        ->call('loadSelectedMessageBody', $account->id, 'INBOX', 42)
        ->assertSet('bodyLoaded', false)
        ->assertSet('bodyLoadFailed', true);
});

it('uses account, folder, and UID for move and trash operations', function () {
    $account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);
    $localMessage = MailMessage::factory()->for($account)->create([
        'imap_uid' => '42',
        'folder' => 'INBOX',
    ]);
    $mailbox = Mockery::mock(ImapMailboxService::class);
    $mailbox->shouldReceive('listFolders')->andReturn(collect([
        ['path' => 'INBOX', 'name' => 'INBOX'],
        ['path' => 'Archive', 'name' => 'Archive'],
        ['path' => 'Trash', 'name' => 'Trash'],
    ]));
    $mailbox->shouldReceive('listEnvelopes')->andReturn(collect([inboxEnvelope(42, 'Remote operation')]));
    $mailbox->shouldReceive('moveMessage')->once()->with(Mockery::on(fn (MailAccount $selected): bool => $selected->is($account)), 'INBOX', 42, 'Archive')->andReturn([
        'folder' => 'Archive',
        'uid' => 9,
    ]);
    $mailbox->shouldReceive('deleteMessage')->once()->with(Mockery::on(fn (MailAccount $selected): bool => $selected->is($account)), 'INBOX', 42)->andReturn([
        'folder' => 'Trash',
        'uid' => 10,
    ]);
    $this->instance(ImapMailboxService::class, $mailbox);

    $component = Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->set('remoteFolders', [
            ['path' => 'INBOX', 'name' => 'INBOX'],
            ['path' => 'Archive', 'name' => 'Archive'],
            ['path' => 'Trash', 'name' => 'Trash'],
        ])
        ->call('moveMessage', 42, 'Archive');

    expect($component->get('envelopes'))->toBeEmpty()
        ->and($localMessage->fresh()->folder)->toBe('INBOX');

    $deleteComponent = Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->set('envelopes', [array_merge(inboxEnvelope(42, 'Remote operation'), ['account_id' => $account->id, 'received_at' => now()->toIso8601String()])])
        ->call('deleteMessage', 42);

    expect($deleteComponent->get('envelopes'))->toBeEmpty()
        ->and($localMessage->fresh()->folder)->toBe('INBOX');
});

it('renders one sync control beside a responsive folder selector', function () {
    $account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);

    $html = Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->html();

    expect(substr_count($html, 'wire:click="sync"'))->toBe(1)
        ->and(substr_count($html, 'wire:click="loadFolders"'))->toBe(0)
        ->and($html)->toContain('min-w-0')
        ->and($html)->toContain('sm:grid-cols-[minmax(0,1fr)_minmax(0,auto)_5.5rem]');
});

it('bounds the inbox panes and keeps their overflow surfaces independent', function () {
    $account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);
    $mailbox = Mockery::mock(ImapMailboxService::class);
    $mailbox->shouldReceive('listFolders')->andReturn(collect([['path' => 'INBOX', 'name' => 'INBOX']]));
    $mailbox->shouldReceive('listEnvelopes')->andReturn(collect([inboxEnvelope(42, 'Scrollable message')]));
    $this->instance(ImapMailboxService::class, $mailbox);

    $html = Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->call('selectMessage', 42)
        ->html();

    expect($html)->toContain('h-[calc(100dvh-10.5rem)]')
        ->and($html)->toContain('grid-rows-2')
        ->and($html)->toContain('lg:grid-rows-1')
        ->and(substr_count($html, 'overflow-y-auto'))->toBeGreaterThanOrEqual(2)
        ->and($html)->toContain('shrink-0');
});

it('does not render or expose legacy expediente and discard actions', function () {
    $account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);

    $component = Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox');

    $html = $component->html();

    expect($html)->not->toContain('associateMessage')
        ->and($html)->not->toContain('createExpedientFromMessage')
        ->and($html)->not->toContain('suggestNewCase')
        ->and($html)->not->toContain('wire:click="discard');
});

it('does not load an account belonging to another user', function () {
    $otherUser = User::factory()->create();
    $otherUser->assignRole('Super Administrador');
    $otherAccount = MailAccount::factory()->for($otherUser)->create(['is_active' => true]);
    $mailbox = Mockery::mock(ImapMailboxService::class);
    $mailbox->shouldNotReceive('listFolders');
    $mailbox->shouldNotReceive('listEnvelopes');
    $this->instance(ImapMailboxService::class, $mailbox);

    Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->set('selectedAccountId', $otherAccount->id)
        ->assertSet('remoteFolders', []);
});

it('sends a reply from the selected account without persisting the IMAP body', function () {
    Mail::fake();
    $account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);
    $signature = Signature::factory()->for($account)->default()->create(['body' => '<p>Saludos desde la firma</p>']);
    $mailbox = Mockery::mock(ImapMailboxService::class);
    $mailbox->shouldReceive('listFolders')->andReturn(collect([
        ['path' => 'INBOX', 'name' => 'INBOX'],
    ]));
    $mailbox->shouldReceive('listEnvelopes')->andReturn(collect([
        array_merge(inboxEnvelope(42, 'Consulta'), [
            'message_id' => '<origin@example.com>',
            'references' => '<root@example.com>',
        ]),
    ]));
    $this->instance(ImapMailboxService::class, $mailbox);

    Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->call('openComposer', 'reply', 42)
        ->assertSet('composerOpen', true)
        ->assertSet('composerSignatureId', $signature->id)
        ->set('composerBody', 'Respuesta desde la bandeja')
        ->call('sendComposer');

    Mail::assertSent(ImapOutboundMail::class, function (ImapOutboundMail $mail) use ($account): bool {
        return $mail->account->is($account)
            && $mail->recipientEmail === 'sender42@example.com'
            && $mail->inReplyTo === '<origin@example.com>'
            && $mail->signature === '<p>Saludos desde la firma</p>';
    });

    $record = MailMessage::query()->where('mail_account_id', $account->id)->sole();
    expect($record->body_html)->toBeNull()
        ->and($record->body_text)->toBeNull()
        ->and($record->in_reply_to)->toBe('<origin@example.com>')
        ->and($record->message_id)->toMatch('/^[^<>]+@[^<>]+$/');
});

it('reports outbound failures with safe composer context', function () {
    Exceptions::fake();
    $account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);
    $mailbox = Mockery::mock(ImapMailboxService::class);
    $mailbox->shouldReceive('listFolders')->andReturn(collect([['path' => 'INBOX', 'name' => 'INBOX']]));
    $mailbox->shouldReceive('listEnvelopes')->andReturn(collect([inboxEnvelope(42, 'Consulta')]));
    $this->instance(ImapMailboxService::class, $mailbox);

    $exception = new RuntimeException('SMTP delivery failed');
    $outbound = Mockery::mock(InboxOutboundMailService::class);
    $outbound->shouldReceive('send')->once()->andThrow($exception);
    $this->instance(InboxOutboundMailService::class, $outbound);

    Log::shouldReceive('withContext')->once()->with([
        'mail_account_id' => $account->id,
        'mode' => 'reply',
        'recipient_domain' => 'example.com',
        'recipient_count' => 4,
    ]);

    Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->call('openComposer', 'reply', 42)
        ->set('composerCc', 'copy.one@example.com, copy.two@example.com')
        ->set('composerBcc', 'copy.three@example.com')
        ->set('composerBody', 'Sensitive outgoing body')
        ->call('sendComposer');

    Exceptions::assertReported(fn (RuntimeException $reported): bool => $reported === $exception);
});

it('opens the bound composer with reply and forward defaults', function () {
    $account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);
    $mailbox = Mockery::mock(ImapMailboxService::class);
    $mailbox->shouldReceive('listFolders')->andReturn(collect([['path' => 'INBOX', 'name' => 'INBOX']]));
    $mailbox->shouldReceive('listEnvelopes')->andReturn(collect([inboxEnvelope(42, 'Consulta')]));
    $this->instance(ImapMailboxService::class, $mailbox);

    $component = Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox');

    $html = $component->html();

    expect($html)->toContain('data-flux-modal')
        ->and($html)->toContain('wire:model.self="composerOpen"')
        ->and($html)->toContain('w-[min(96vw,72rem)]')
        ->and($html)->toContain('style="resize: both; "')
        ->and($html)->toContain('min-h-48');

    $component
        ->call('openComposer', 'reply', 42)
        ->assertSet('composerOpen', true)
        ->assertSet('composerMode', 'reply')
        ->assertSet('composerTo', 'sender42@example.com')
        ->assertSet('composerSubject', 'Re: Consulta');

    $component->call('openComposer', 'forward', 42)
        ->assertSet('composerOpen', true)
        ->assertSet('composerMode', 'forward')
        ->assertSet('composerTo', '')
        ->assertSet('composerSubject', 'Fwd: Consulta')
        ->call('closeComposer')
        ->assertSet('composerOpen', false)
        ->assertSet('composerMode', null);
});

it('keeps edited text until the user explicitly applies a selected template', function () {
    $account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);
    $template = Template::factory()->create([
        'name' => 'Seguimiento',
        'subject' => 'Seguimiento de consulta',
        'body' => 'Cuerpo de plantilla',
    ]);
    $mailbox = Mockery::mock(ImapMailboxService::class);
    $mailbox->shouldReceive('listFolders')->andReturn(collect([['path' => 'INBOX', 'name' => 'INBOX']]));
    $mailbox->shouldReceive('listEnvelopes')->andReturn(collect([inboxEnvelope(42, 'Consulta')]));
    $this->instance(ImapMailboxService::class, $mailbox);

    Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->call('openComposer', 'reply', 42)
        ->set('composerBody', 'Texto editado')
        ->set('composerTemplateId', $template->id)
        ->assertSet('composerBody', 'Texto editado')
        ->call('applyComposerTemplate')
        ->assertSet('composerBody', 'Cuerpo de plantilla')
        ->assertSet('composerSubject', 'Re: Consulta');
});

it('uses a selected contact or a valid manual recipient for forwards', function () {
    Mail::fake();
    $account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);
    $contact = Contact::factory()->create(['name' => 'Ana Perez', 'email' => 'ana@example.com']);
    $mailbox = Mockery::mock(ImapMailboxService::class);
    $mailbox->shouldReceive('listFolders')->andReturn(collect([['path' => 'INBOX', 'name' => 'INBOX']]));
    $mailbox->shouldReceive('listEnvelopes')->andReturn(collect([inboxEnvelope(42, 'Consulta')]));
    $this->instance(ImapMailboxService::class, $mailbox);

    $component = Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->call('openComposer', 'forward', 42)
        ->set('composerContactSearch', 'Ana');

    expect($component->get('composerContacts')->pluck('id')->all())->toContain($contact->id);

    $component
        ->call('selectComposerContact', $contact->id)
        ->assertSet('composerTo', 'ana@example.com')
        ->set('composerBody', 'Reenvío para contacto')
        ->call('sendComposer');

    Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->call('openComposer', 'forward', 42)
        ->set('composerTo', 'manual@example.com')
        ->set('composerBody', 'Reenvío manual')
        ->call('sendComposer');

    Mail::assertSent(ImapOutboundMail::class, fn (ImapOutboundMail $mail): bool => $mail->recipientEmail === 'ana@example.com');
    Mail::assertSent(ImapOutboundMail::class, fn (ImapOutboundMail $mail): bool => $mail->recipientEmail === 'manual@example.com');
});

it('sends and persists comma-separated CC and BCC recipients', function () {
    Mail::fake();
    $account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);
    $mailbox = Mockery::mock(ImapMailboxService::class);
    $mailbox->shouldReceive('listFolders')->andReturn(collect([['path' => 'INBOX', 'name' => 'INBOX']]));
    $mailbox->shouldReceive('listEnvelopes')->andReturn(collect([inboxEnvelope(42, 'Consulta')]));
    $this->instance(ImapMailboxService::class, $mailbox);

    Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->call('openComposer', 'reply', 42)
        ->set('composerCc', 'cc.one@example.com, cc.two@example.com')
        ->set('composerBcc', 'bcc@example.com')
        ->set('composerBody', 'Respuesta con copias')
        ->call('sendComposer');

    Mail::assertSent(ImapOutboundMail::class, function (ImapOutboundMail $mail): bool {
        return $mail->ccRecipients === ['cc.one@example.com', 'cc.two@example.com']
            && $mail->bccRecipients === ['bcc@example.com'];
    });

    $record = MailMessage::query()->where('mail_account_id', $account->id)->sole();
    expect($record->cc)->toBe(['cc.one@example.com', 'cc.two@example.com'])
        ->and($record->bcc)->toBe(['bcc@example.com']);
});

it('validates the mailbox composer before sending', function () {
    $account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);
    $mailbox = Mockery::mock(ImapMailboxService::class);
    $mailbox->shouldReceive('listFolders')->andReturn(collect([
        ['path' => 'INBOX', 'name' => 'INBOX'],
    ]));
    $mailbox->shouldReceive('listEnvelopes')->andReturn(collect([inboxEnvelope(42, 'Consulta')]));
    $this->instance(ImapMailboxService::class, $mailbox);

    Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->call('openComposer', 'forward', 42)
        ->set('composerTo', '')
        ->set('composerCc', 'not-an-email')
        ->set('composerBcc', 'also-not-an-email')
        ->set('composerBody', '')
        ->call('sendComposer')
        ->assertHasErrors([
            'composerTo',
            'composerCc',
            'composerBcc',
            'composerBody',
        ]);
});
