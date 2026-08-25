<?php

use App\Models\CaseMilestone;
use App\Models\MailAccount;
use App\Models\MailMessage;
use App\Models\Template;
use App\Services\Bandeja\ImapMailboxService;
use App\Services\Bandeja\ImapProvider;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

// ── Task 1.1: mail_messages outbound/threading columns ──

it('has outbound threading columns on mail_messages table', function () {
    expect(Schema::hasColumn('mail_messages', 'to_email'))->toBeTrue()
        ->and(Schema::hasColumn('mail_messages', 'cc'))->toBeTrue()
        ->and(Schema::hasColumn('mail_messages', 'bcc'))->toBeTrue()
        ->and(Schema::hasColumn('mail_messages', 'sent_at'))->toBeTrue()
        ->and(Schema::hasColumn('mail_messages', 'in_reply_to'))->toBeTrue()
        ->and(Schema::hasColumn('mail_messages', 'references'))->toBeTrue()
        ->and(Schema::hasColumn('mail_messages', 'folder'))->toBeTrue()
        ->and(Schema::hasColumn('mail_messages', 'thread_id'))->toBeTrue()
        ->and(Schema::hasColumn('mail_messages', 'sender_phone'))->toBeTrue();
});

it('mail_messages columns are nullable as designed', function () {
    $columns = Schema::getColumns('mail_messages');
    $nullableColumns = collect($columns)
        ->filter(fn ($col) => in_array($col['name'], ['to_email', 'cc', 'bcc', 'sent_at', 'in_reply_to', 'references', 'folder', 'thread_id', 'sender_phone']))
        ->pluck('name');

    expect($nullableColumns->count())->toBe(9);
});

it('has index on mail_messages for mail_account_id and in_reply_to', function () {
    $indexes = Schema::getIndexes('mail_messages');
    $hasIndex = collect($indexes)->contains(fn ($idx) => isset($idx['columns']) &&
        in_array('mail_account_id', $idx['columns']) &&
        in_array('in_reply_to', $idx['columns'])
    );

    expect($hasIndex)->toBeTrue();
});

// ── Task 1.2: case_milestones.mail_message_id FK ──

it('has mail_message_id column on case_milestones table', function () {
    expect(Schema::hasColumn('case_milestones', 'mail_message_id'))->toBeTrue();
});

it('case_milestones mail_message_id is nullable', function () {
    $columns = Schema::getColumns('case_milestones');
    $mailMessageCol = collect($columns)->firstWhere('name', 'mail_message_id');

    expect($mailMessageCol)->not->toBeNull()
        ->and($mailMessageCol['nullable'])->toBeTrue();
});

// ── Task 1.3: templates.purpose column ──

it('has purpose column on templates table', function () {
    expect(Schema::hasColumn('templates', 'purpose'))->toBeTrue();
});

it('templates purpose column is nullable', function () {
    $columns = Schema::getColumns('templates');
    $purposeCol = collect($columns)->firstWhere('name', 'purpose');

    expect($purposeCol)->not->toBeNull()
        ->and($purposeCol['nullable'])->toBeTrue();
});

it('templates has index on purpose column', function () {
    $indexes = Schema::getIndexes('templates');
    $hasIndex = collect($indexes)->contains(fn ($idx) => isset($idx['columns']) && in_array('purpose', $idx['columns'])
    );

    expect($hasIndex)->toBeTrue();
});

it('seeds missing_phone template when migration runs', function () {
    $template = Template::where('purpose', 'missing_phone')->first();

    expect($template)->not->toBeNull();
});

// ── Task 1.4: MailMessage model fillable, casts, relations ──

it('mail_message model has new fillable fields', function () {
    $model = new MailMessage;
    $fillable = $model->getFillable();

    expect($fillable)->toContain('to_email', 'cc', 'bcc', 'sent_at', 'in_reply_to', 'references', 'folder', 'thread_id', 'sender_phone');
});

it('mail_message casts cc and bcc as array', function () {
    $model = new MailMessage;
    $casts = $model->getCasts();

    expect($casts['cc'])->toBe('array')
        ->and($casts['bcc'])->toBe('array')
        ->and($casts['references'])->toBe('array');
});

it('mail_message casts sent_at as datetime', function () {
    $model = new MailMessage;
    $casts = $model->getCasts();

    expect($casts['sent_at'])->toBe('datetime');
});

it('mail_message has to relation for in_reply_to target', function () {
    $parent = MailMessage::factory()->create(['message_id' => 'parent-msg-id']);
    $child = MailMessage::factory()->create(['in_reply_to' => 'parent-msg-id']);

    expect($child->to)->not->toBeNull()
        ->and($child->to->message_id)->toBe('parent-msg-id');
});

// ── Task 1.5: CaseMilestone model mail_message_id ──

it('case_milestone model has mail_message_id fillable', function () {
    $model = new CaseMilestone;
    $fillable = $model->getFillable();

    expect($fillable)->toContain('mail_message_id');
});

it('case_milestone has mailMessage relation', function () {
    $mailMessage = MailMessage::factory()->create();
    $milestone = CaseMilestone::factory()->create(['mail_message_id' => $mailMessage->id]);

    expect($milestone->mailMessage)->not->toBeNull()
        ->and($milestone->mailMessage->id)->toBe($mailMessage->id);
});

// ── Task 1.6: Template model purpose ──

it('template model has purpose fillable', function () {
    $model = new Template;
    $fillable = $model->getFillable();

    expect($fillable)->toContain('purpose');
});

it('template has forPurpose scope', function () {
    // The migration already seeds a missing_phone template, so delete it first
    Template::where('purpose', 'missing_phone')->delete();

    Template::factory()->create(['purpose' => 'missing_phone', 'name' => 'Missing Phone']);
    Template::factory()->create(['purpose' => 'welcome', 'name' => 'Welcome']);
    Template::factory()->create(['purpose' => null, 'name' => 'No Purpose']);

    $results = Template::forPurpose('missing_phone')->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->name)->toBe('Missing Phone');
});

// ── Task 1.8-1.9: Factory states ──

it('mail_message factory has outgoing state with full fields', function () {
    $message = MailMessage::factory()
        ->outgoing()
        ->create([
            'to_email' => 'client@example.com',
            'cc' => ['cc@example.com'],
            'bcc' => ['bcc@example.com'],
            'sent_at' => now(),
        ]);

    expect($message->direction->value)->toBe('outgoing')
        ->and($message->to_email)->toBe('client@example.com')
        ->and($message->cc)->toBe(['cc@example.com'])
        ->and($message->bcc)->toBe(['bcc@example.com'])
        ->and($message->sent_at)->not->toBeNull();
});

it('mail_message factory has withPhone state', function () {
    $message = MailMessage::factory()->withPhone()->create();

    expect($message->sender_phone)->toBe('+34612345678');
});

it('mail_message factory has withInReplyTo state', function () {
    $parent = MailMessage::factory()->create(['message_id' => 'original-msg']);
    $reply = MailMessage::factory()->withInReplyTo($parent)->create();

    expect($reply->in_reply_to)->toBe('original-msg');
});

it('case_milestone factory has withMailMessage state', function () {
    $mailMessage = MailMessage::factory()->create();
    $milestone = CaseMilestone::factory()->withMailMessage($mailMessage)->create();

    expect($milestone->mail_message_id)->toBe($mailMessage->id);
});

// ── Task 1.7: IMAP envelope threading capture ──

it('captures in_reply_to and references from an IMAP envelope', function () {
    $account = MailAccount::factory()->create();
    $provider = Mockery::mock(ImapProvider::class);
    $provider->shouldReceive('listEnvelopes')->once()->with($account, 'INBOX')->andReturn(new Collection([
        [
            'uid' => 1,
            'in_reply_to' => '<original@example.com>',
            'references' => '<ref1@example.com> <ref2@example.com>',
        ],
    ]));

    $message = (new ImapMailboxService($provider))->syncFolder($account)->first();

    expect($message->in_reply_to)->toBe('<original@example.com>')
        ->and($message->references)->toBe(['<ref1@example.com>', '<ref2@example.com>']);
});

it('returns null for missing threading fields in an IMAP envelope', function () {
    $account = MailAccount::factory()->create();
    $provider = Mockery::mock(ImapProvider::class);
    $provider->shouldReceive('listEnvelopes')->once()->with($account, 'INBOX')->andReturn(new Collection([
        ['uid' => 1],
    ]));

    $message = (new ImapMailboxService($provider))->syncFolder($account)->first();

    expect($message->in_reply_to)->toBeNull()
        ->and($message->references)->toBeNull();
});
