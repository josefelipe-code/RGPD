<?php

use App\Mail\OutboundMail;
use App\Models\MailAccount;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

it('can be instantiated with the operational outbound parameters', function () {
    $mailable = new OutboundMail(
        account: $this->account,
        recipientEmail: 'recipient@example.com',
        mailSubject: 'Test Subject',
        mailBody: '<p>Body</p>',
        messageId: '<message@example.com>',
    );

    expect($mailable->recipientEmail)->toBe('recipient@example.com')
        ->and($mailable->mailSubject)->toBe('Test Subject')
        ->and($mailable->mailBody)->toBe('<p>Body</p>')
        ->and($mailable->messageId)->toBe('<message@example.com>')
        ->and($mailable->signature)->toBeNull();
});

it('accepts cc, bcc, threading headers, and a signature', function () {
    $mailable = new OutboundMail(
        account: $this->account,
        recipientEmail: 'recipient@example.com',
        mailSubject: 'Test Subject',
        mailBody: '<p>Body</p>',
        messageId: '<message@example.com>',
        ccAddresses: ['cc@example.com'],
        bccAddresses: ['bcc@example.com'],
        inReplyTo: '<origin@example.com>',
        references: ['<root@example.com>', '<origin@example.com>'],
        signature: '<p>Signature</p>',
    );

    $headers = $mailable->headers();

    expect($mailable->ccAddresses)->toBe(['cc@example.com'])
        ->and($mailable->bccAddresses)->toBe(['bcc@example.com'])
        ->and($headers->messageId)->toBe('<message@example.com>')
        ->and($headers->references)->toBe(['<root@example.com>', '<origin@example.com>'])
        ->and($headers->text)->toBe(['In-Reply-To' => '<origin@example.com>'])
        ->and($mailable->content()->with)->toHaveKey('signature', '<p>Signature</p>');
});

it('does not implement ShouldQueue because outbound delivery is synchronous', function () {
    $mailable = new OutboundMail(
        account: $this->account,
        recipientEmail: 'recipient@example.com',
        mailSubject: 'Test',
        mailBody: '<p>Body</p>',
        messageId: '<message@example.com>',
    );

    expect($mailable)->not->toBeInstanceOf(ShouldQueue::class);
});

it('uses the outbound html and text views', function () {
    $mailable = new OutboundMail(
        account: $this->account,
        recipientEmail: 'recipient@example.com',
        mailSubject: 'Test',
        mailBody: '<p>Hello</p>',
        messageId: '<message@example.com>',
    );

    $content = $mailable->content();

    expect($content->html)->toBe('mail.outbound')
        ->and($content->text)->toBe('mail.outbound-text')
        ->and($content->with)->toHaveKey('body', '<p>Hello</p>');
});
