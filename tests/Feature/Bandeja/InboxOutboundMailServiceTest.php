<?php

use App\Enums\MailDirection;
use App\Mail\ImapOutboundMail;
use App\Models\MailAccount;
use App\Models\MailMessage;
use App\Models\User;
use App\Services\Bandeja\InboxOutboundMailService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;

uses(RefreshDatabase::class)->in(__DIR__);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->user = User::factory()->create();
    $this->account = MailAccount::factory()->for($this->user)->create([
        'email_address' => 'inbox@example.com',
    ]);
    $this->service = app(InboxOutboundMailService::class);
});

it('sends a reply with a generated message identity and threading headers', function () {
    Mail::fake();

    $outgoing = $this->service->send(
        account: $this->account,
        mode: 'reply',
        recipient: 'sender@example.com',
        cc: ['cc@example.com'],
        bcc: ['bcc@example.com'],
        subject: 'Re: Consulta',
        body: 'Respuesta',
        signature: '<p>Saludos</p>',
        origin: [
            'message_id' => '<origin@example.com>',
            'references' => '<root@example.com>',
        ],
    );

    expect($outgoing->direction)->toBe(MailDirection::Outgoing)
        ->and($outgoing->message_id)->toMatch('/^[^<>]+@[^<>]+$/')
        ->and($outgoing->in_reply_to)->toBe('<origin@example.com>')
        ->and($outgoing->references)->toBe(['<root@example.com>', '<origin@example.com>'])
        ->and($outgoing->body_html)->toBeNull()
        ->and($outgoing->body_text)->toBeNull()
        ->and($outgoing->cc)->toBe(['cc@example.com'])
        ->and($outgoing->bcc)->toBe(['bcc@example.com'])
        ->and(config("mail.mailers.mail_account_{$this->account->id}.host"))->toBe($this->account->smtp_host);

    Mail::assertSent(ImapOutboundMail::class, function (ImapOutboundMail $mail) use ($outgoing): bool {
        $headers = $mail->headers();
        $mail->assertSeeInHtml('Saludos');
        $mail->assertSeeInText('Saludos');
        $email = new Email;

        foreach ($mail->callbacks as $callback) {
            $callback($email);
        }

        return $headers->messageId === $outgoing->message_id
            && $headers->references === ['<root@example.com>', '<origin@example.com>']
            && $headers->text === ['In-Reply-To' => '<origin@example.com>']
            && $email->getHeaders()->get('Message-Id')?->getBodyAsString() === "<{$outgoing->message_id}>"
            && $mail->signature === '<p>Saludos</p>'
            && $mail->ccRecipients === ['cc@example.com']
            && $mail->bccRecipients === ['bcc@example.com'];
    });
});

it('does not persist an outgoing record when SMTP fails', function () {
    $mailer = Mockery::mock(Mailer::class);
    $mailer->shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP failed'));
    Mail::shouldReceive('mailer')->once()->with("mail_account_{$this->account->id}")->andReturn($mailer);

    expect(fn () => $this->service->send(
        account: $this->account,
        mode: 'forward',
        recipient: 'provider@example.com',
        cc: [],
        bcc: [],
        subject: 'Fwd: Consulta',
        body: 'Reenvío',
        origin: ['message_id' => '<origin@example.com>'],
    ))->toThrow(RuntimeException::class);

    expect(MailMessage::query()->where('mail_account_id', $this->account->id)->count())->toBe(0);
});
