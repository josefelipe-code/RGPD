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

it('can be instantiated with required parameters', function () {
    $mailable = new OutboundMail(
        account: $this->account,
        recipientEmail: 'recipient@example.com',
        mailSubject: 'Test Subject',
        mailBody: '<p>Body</p>',
    );

    expect($mailable->recipientEmail)->toBe('recipient@example.com')
        ->and($mailable->mailSubject)->toBe('Test Subject')
        ->and($mailable->mailBody)->toBe('<p>Body</p>')
        ->and($mailable->mailSignature)->toBeNull();
});

it('accepts cc and bcc arrays', function () {
    $mailable = new OutboundMail(
        account: $this->account,
        recipientEmail: 'recipient@example.com',
        mailSubject: 'Test Subject',
        mailBody: '<p>Body</p>',
        ccAddresses: ['cc@example.com'],
        bccAddresses: ['bcc@example.com'],
    );

    expect($mailable->ccAddresses)->toBe(['cc@example.com'])
        ->and($mailable->bccAddresses)->toBe(['bcc@example.com']);
});

it('generates Message-ID header via headers method', function () {
    $mailable = new OutboundMail(
        account: $this->account,
        recipientEmail: 'recipient@example.com',
        mailSubject: 'Test Subject',
        mailBody: '<p>Body</p>',
    );

    $headers = $mailable->headers();

    expect($headers->messageId)->toContain('@');
});

it('implements ShouldQueue', function () {
    $mailable = new OutboundMail(
        account: $this->account,
        recipientEmail: 'recipient@example.com',
        mailSubject: 'Test',
        mailBody: '<p>Body</p>',
    );

    expect($mailable)->toBeInstanceOf(ShouldQueue::class);
});

it('passes body and signature to view via content with', function () {
    $mailable = new OutboundMail(
        account: $this->account,
        recipientEmail: 'recipient@example.com',
        mailSubject: 'Test',
        mailBody: '<p>Hello</p>',
        mailSignature: '<p>Signature</p>',
    );

    $content = $mailable->content();

    expect($content->with)->toHaveKey('body', '<p>Hello</p>')
        ->and($content->with)->toHaveKey('signature', '<p>Signature</p>');
});
