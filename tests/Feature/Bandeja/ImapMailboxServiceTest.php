<?php

use App\Models\Expedient;
use App\Models\MailAccount;
use App\Models\MailMessage;
use App\Services\Bandeja\ImapMailboxService;
use App\Services\Bandeja\ImapProvider;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class)->in(__DIR__);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->account = MailAccount::factory()->create();
});

it('lists folders through the IMAP provider', function () {
    $provider = Mockery::mock(ImapProvider::class);
    $provider->shouldReceive('listFolders')
        ->once()
        ->with($this->account)
        ->andReturn(new Collection([
            ['path' => 'INBOX', 'name' => 'INBOX', 'delimiter' => '/'],
        ]));

    $folders = (new ImapMailboxService($provider))->listFolders($this->account);

    expect($folders)->toHaveCount(1)
        ->and($folders->first()['path'])->toBe('INBOX');
});

it('persists only envelope metadata when syncing an IMAP folder', function () {
    $provider = Mockery::mock(ImapProvider::class);
    $provider->shouldReceive('listEnvelopes')
        ->once()
        ->with($this->account, 'INBOX')
        ->andReturn(new Collection([
            [
                'uid' => 42,
                'message_id' => '<message-42@example.com>',
                'subject' => 'Data request',
                'from_email' => 'client@example.com',
                'from_name' => 'Client',
                'received_at' => '2026-07-22 10:00:00',
                'in_reply_to' => null,
                'references' => null,
                'is_read' => false,
            ],
        ]));

    $messages = (new ImapMailboxService($provider))->syncFolder($this->account, 'INBOX');
    $message = $messages->first();

    expect($message->imap_uid)->toBe('42')
        ->and($message->folder)->toBe('INBOX')
        ->and($message->message_id)->toBe('<message-42@example.com>')
        ->and($message->body_html)->toBeNull()
        ->and($message->body_text)->toBeNull()
        ->and($message->sender_phone)->toBeNull()
        ->and($message->is_read)->toBeFalse();
});

it('retrieves one message on demand through the IMAP provider', function () {
    $provider = Mockery::mock(ImapProvider::class);
    $provider->shouldReceive('fetchMessage')
        ->once()
        ->with($this->account, 'INBOX', 42)
        ->andReturn([
            'html' => '<p>Hello</p>',
            'text' => 'Hello',
            'headers' => ['message_id' => '<message-42@example.com>'],
            'is_read' => true,
        ]);

    $content = (new ImapMailboxService($provider))->fetchMessage($this->account, 'INBOX', 42);

    expect($content['html'])->toBe('<p>Hello</p>')
        ->and($content['headers']['message_id'])->toBe('<message-42@example.com>');
});

it('keeps same Message-ID messages separate across folders', function () {
    $provider = Mockery::mock(ImapProvider::class);
    $provider->shouldReceive('listEnvelopes')
        ->twice()
        ->andReturnUsing(function (MailAccount $account, string $folder): Collection {
            return new Collection([
                [
                    'uid' => $folder === 'INBOX' ? 42 : 7,
                    'message_id' => '<same-message@example.com>',
                    'subject' => $folder,
                    'from_email' => 'client@example.com',
                    'received_at' => '2026-07-22 10:00:00',
                    'is_read' => false,
                ],
            ]);
        });

    $service = new ImapMailboxService($provider);
    $inboxMessage = $service->syncFolder($this->account, 'INBOX')->first();
    $sentMessage = $service->syncFolder($this->account, 'Sent')->first();

    expect($inboxMessage->id)->not->toBe($sentMessage->id)
        ->and(MailMessage::query()->where('mail_account_id', $this->account->id)->count())->toBe(2)
        ->and($inboxMessage->folder)->toBe('INBOX')
        ->and($sentMessage->folder)->toBe('Sent');
});

it('preserves an existing association when updating an IMAP reference', function () {
    $expedient = Expedient::factory()->for($this->account)->create();
    $message = MailMessage::factory()->for($this->account)->create([
        'folder' => 'INBOX',
        'imap_uid' => '42',
        'message_id' => '<same-message@example.com>',
        'case_id' => $expedient->id,
    ]);

    $provider = Mockery::mock(ImapProvider::class);
    $provider->shouldReceive('listEnvelopes')->once()->andReturn(new Collection([
        [
            'uid' => 42,
            'message_id' => '<same-message@example.com>',
            'subject' => 'Updated subject',
            'from_email' => 'client@example.com',
            'received_at' => '2026-07-22 10:00:00',
            'is_read' => true,
        ],
    ]));

    $updated = (new ImapMailboxService($provider))->syncFolder($this->account, 'INBOX')->first();

    expect($updated->id)->toBe($message->id)
        ->and($updated->case_id)->toBe($expedient->id)
        ->and($updated->subject)->toBe('Updated subject');
});

it('moves a message through the provider without changing local associations', function () {
    $expedient = Expedient::factory()->for($this->account)->create();
    $message = MailMessage::factory()->for($this->account)->create([
        'folder' => 'INBOX',
        'imap_uid' => '42',
        'case_id' => $expedient->id,
    ]);
    $provider = Mockery::mock(ImapProvider::class);
    $provider->shouldReceive('moveMessage')->once()->with($this->account, 'INBOX', 42, 'Archive')->andReturn([
        'folder' => 'Archive',
        'uid' => 9,
    ]);

    $reference = (new ImapMailboxService($provider))->moveMessage($this->account, 'INBOX', 42, 'Archive');
    $message->update(['folder' => $reference['folder'], 'imap_uid' => $reference['uid']]);

    expect($message->fresh()->folder)->toBe('Archive')
        ->and($message->fresh()->imap_uid)->toBe('9')
        ->and($message->fresh()->case_id)->toBe($expedient->id);
});

it('does not provide a delete fallback when the provider has no trash', function () {
    $provider = Mockery::mock(ImapProvider::class);
    $provider->shouldReceive('deleteMessage')->once()->andThrow(new RuntimeException('No Trash'));

    expect(fn () => (new ImapMailboxService($provider))->deleteMessage($this->account, 'INBOX', 42))
        ->toThrow(RuntimeException::class, 'No Trash');
});
