<?php

use App\Enums\MailMessageStatus;
use App\Models\MailAccount;
use App\Models\MailMessage;
use App\Models\User;
use App\Services\Bandeja\ImapSyncService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Livewire;

uses(RefreshDatabase::class)->in(__DIR__);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->user = User::factory()->create();
    $this->user->assignRole('Super Administrador');
});

// -- Access / Permission tests

it('requires authentication to access inbox', function () {
    $this->get(route('bandeja.inbox'))
        ->assertRedirect(route('login'));
});

it('forbids access without bandeja.ver permission', function () {
    $user = User::factory()->create();
    // No role assigned — no bandeja.ver permission

    $this->actingAs($user)
        ->get(route('bandeja.inbox'))
        ->assertForbidden();
});

it('allows access with bandeja.ver permission', function () {
    $this->actingAs($this->user)
        ->get(route('bandeja.inbox'))
        ->assertOk();
});

// -- Render tests

it('shows active accounts for selection', function () {
    $account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);
    MailAccount::factory()->for($this->user)->create(['is_active' => false]);

    $this->actingAs($this->user)
        ->get(route('bandeja.inbox'))
        ->assertSee($account->label);
});

it('shows messages for selected account', function () {
    $account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);
    $message = MailMessage::factory()->for($account)->create([
        'subject' => 'Test Subject',
        'from_email' => 'test@example.com',
        'from_name' => 'Test User',
    ]);

    Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->assertSee('Test Subject')
        ->assertSee('Test User');
});

it('shows empty state when no account selected', function () {
    // Create user with no mail accounts
    $this->actingAs($this->user)
        ->get(route('bandeja.inbox'))
        ->assertSee('Seleccioná una cuenta');
});

// -- Sync tests (mocked)

it('syncs messages from IMAP account', function () {
    $account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);

    $syncedMessage = MailMessage::factory()->make([
        'mail_account_id' => $account->id,
        'subject' => 'Synced Message',
    ]);

    $mock = Mockery::mock(ImapSyncService::class);
    $mock->shouldReceive('syncAccount')
        ->with($account)
        ->andReturn(Collection::make([$syncedMessage]));

    $this->instance(ImapSyncService::class, $mock);

    Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->set('selectedAccountId', $account->id)
        ->call('sync')
        ->assertSet('selectedAccountId', $account->id);
});

it('shows error toast when sync fails', function () {
    $account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);

    $mock = Mockery::mock(ImapSyncService::class);
    $mock->shouldReceive('syncAccount')
        ->with($account)
        ->andThrow(new RuntimeException('Error de conexión IMAP'));

    $this->instance(ImapSyncService::class, $mock);

    Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->set('selectedAccountId', $account->id)
        ->call('sync');
});

// -- Discard / Status change tests

it('discards a message and changes status', function () {
    $account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);
    $message = MailMessage::factory()->for($account)->create(['status' => MailMessageStatus::New]);

    Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->set('selectedAccountId', $account->id)
        ->call('discard', $message->id);

    expect($message->fresh()->status)->toBe(MailMessageStatus::Discarded);
});

it('suggests new case by setting pending_review status', function () {
    $account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);
    $message = MailMessage::factory()->for($account)->create(['status' => MailMessageStatus::New]);

    Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->set('selectedAccountId', $account->id)
        ->call('suggestNewCase', $message->id);

    expect($message->fresh()->status)->toBe(MailMessageStatus::PendingReview);
});

it('filters messages by status', function () {
    $account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);
    MailMessage::factory()->for($account)->count(3)->create(['status' => MailMessageStatus::New]);
    MailMessage::factory()->for($account)->count(2)->create(['status' => MailMessageStatus::Discarded]);

    $component = Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->set('selectedAccountId', $account->id);

    // All messages visible by default
    expect($component->get('messages')->count())->toBe(5);

    // Filter by new
    $component->call('setStatusFilter', 'new');
    expect($component->get('messages')->count())->toBe(3);

    // Filter by discarded
    $component->call('setStatusFilter', 'discarded');
    expect($component->get('messages')->count())->toBe(2);
});

it('selects a message and shows it in reading pane', function () {
    $account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);
    $message = MailMessage::factory()->for($account)->create([
        'subject' => 'Important Message',
        'body_html' => '<p>Hello world</p>',
    ]);

    $component = Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->set('selectedAccountId', $account->id)
        ->call('selectMessage', $message->id);

    expect($component->get('selectedMessageId'))->toBe($message->id);
    expect($component->get('selectedMessage')->subject)->toBe('Important Message');
});

it('shows status counts for the selected account', function () {
    $account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);
    MailMessage::factory()->for($account)->count(3)->create(['status' => MailMessageStatus::New]);
    MailMessage::factory()->for($account)->count(1)->create(['status' => MailMessageStatus::PendingReview]);

    $component = Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->set('selectedAccountId', $account->id);

    $counts = $component->get('statusCounts');
    expect($counts['new'])->toBe(3);
    expect($counts['pending_review'])->toBe(1);
});

// -- Authorization / Multi-tenant isolation tests

it('cannot read messages from another users account', function () {
    $otherUser = User::factory()->create();
    $otherUser->assignRole('Super Administrador');
    $otherAccount = MailAccount::factory()->for($otherUser)->create(['is_active' => true]);
    $otherMessage = MailMessage::factory()->for($otherAccount)->create(['subject' => 'Secret Message']);

    Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->set('selectedAccountId', $otherAccount->id)
        ->call('selectMessage', $otherMessage->id)
        ->assertSet('selectedMessage', null);
});

it('cannot discard a message from another users account', function () {
    $otherUser = User::factory()->create();
    $otherUser->assignRole('Super Administrador');
    $otherAccount = MailAccount::factory()->for($otherUser)->create(['is_active' => true]);
    $otherMessage = MailMessage::factory()->for($otherAccount)->create(['status' => MailMessageStatus::New]);

    Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->set('selectedAccountId', $otherAccount->id)
        ->call('discard', $otherMessage->id);

    expect($otherMessage->fresh()->status)->toBe(MailMessageStatus::New);
});

it('cannot suggest new case for a message from another users account', function () {
    $otherUser = User::factory()->create();
    $otherUser->assignRole('Super Administrador');
    $otherAccount = MailAccount::factory()->for($otherUser)->create(['is_active' => true]);
    $otherMessage = MailMessage::factory()->for($otherAccount)->create(['status' => MailMessageStatus::New]);

    Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->set('selectedAccountId', $otherAccount->id)
        ->call('suggestNewCase', $otherMessage->id);

    expect($otherMessage->fresh()->status)->toBe(MailMessageStatus::New);
});

it('cannot view status counts from another users account', function () {
    $otherUser = User::factory()->create();
    $otherUser->assignRole('Super Administrador');
    $otherAccount = MailAccount::factory()->for($otherUser)->create(['is_active' => true]);
    MailMessage::factory()->for($otherAccount)->count(5)->create(['status' => MailMessageStatus::New]);

    $component = Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->set('selectedAccountId', $otherAccount->id);

    expect($component->get('statusCounts'))->toBe([]);
});

it('cannot select message without a valid selected account', function () {
    $account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);
    $message = MailMessage::factory()->for($account)->create();

    Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->set('selectedAccountId', null)
        ->call('selectMessage', $message->id)
        ->assertStatus(403);
});

it('cannot discard message without a valid selected account', function () {
    $account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);
    $message = MailMessage::factory()->for($account)->create(['status' => MailMessageStatus::New]);

    Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->set('selectedAccountId', null)
        ->call('discard', $message->id)
        ->assertStatus(403);
});

it('cannot use an inactive account for actions', function () {
    $account = MailAccount::factory()->for($this->user)->create(['is_active' => false]);
    $message = MailMessage::factory()->for($account)->create(['status' => MailMessageStatus::New]);

    Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->set('selectedAccountId', $account->id)
        ->call('discard', $message->id)
        ->assertStatus(403);

    expect($message->fresh()->status)->toBe(MailMessageStatus::New);
});

// -- Search / Pagination tests

it('filters messages by search query on sender name', function () {
    $account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);
    MailMessage::factory()->for($account)->create(['from_name' => 'Juan Perez', 'subject' => 'Test']);
    MailMessage::factory()->for($account)->create(['from_name' => 'Maria Lopez', 'subject' => 'Other']);

    $component = Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->set('selectedAccountId', $account->id);

    expect($component->get('messages')->total())->toBe(2);

    $component->set('search', 'Juan');
    expect($component->get('messages')->total())->toBe(1);
    expect($component->get('messages')->first()->from_name)->toBe('Juan Perez');
});

it('filters messages by search query on subject', function () {
    $account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);
    MailMessage::factory()->for($account)->create(['subject' => 'Urgent matter']);
    MailMessage::factory()->for($account)->create(['subject' => 'Regular update']);

    $component = Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->set('selectedAccountId', $account->id);

    $component->set('search', 'Urgent');
    expect($component->get('messages')->total())->toBe(1);
});

it('filters messages by search query on body text', function () {
    $account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);
    MailMessage::factory()->for($account)->create(['body_text' => 'Please review the attached document']);
    MailMessage::factory()->for($account)->create(['body_text' => 'Just a quick hello']);

    $component = Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->set('selectedAccountId', $account->id);

    $component->set('search', 'document');
    expect($component->get('messages')->total())->toBe(1);
});

it('respects perPage setting', function () {
    $account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);
    MailMessage::factory()->for($account)->count(20)->create();

    $component = Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->set('selectedAccountId', $account->id);

    // Default perPage is 15
    expect($component->get('messages')->perPage())->toBe(15);
    expect($component->get('messages')->count())->toBe(15);

    $component->set('perPage', 10);
    expect($component->get('messages')->perPage())->toBe(10);
    expect($component->get('messages')->count())->toBe(10);
});

it('returns paginator from messages', function () {
    $account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);
    MailMessage::factory()->for($account)->count(3)->create();

    $component = Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->set('selectedAccountId', $account->id);

    expect($component->get('messages'))->toBeInstanceOf(LengthAwarePaginator::class);
});

it('resets search when switching accounts', function () {
    $account1 = MailAccount::factory()->for($this->user)->create(['is_active' => true]);
    $account2 = MailAccount::factory()->for($this->user)->create(['is_active' => true]);
    MailMessage::factory()->for($account1)->create(['from_name' => 'Searchable Name']);

    $component = Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->set('selectedAccountId', $account1->id)
        ->set('search', 'Searchable');

    expect($component->get('search'))->toBe('Searchable');

    $component->call('selectAccount', $account2->id);
    expect($component->get('search'))->toBe('');
});

it('shows no results message when search has no matches', function () {
    $account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);
    MailMessage::factory()->for($account)->create(['subject' => 'Hello']);

    Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->set('selectedAccountId', $account->id)
        ->set('search', 'nonexistent-xyz')
        ->assertSee('No se encontraron resultados');
});
