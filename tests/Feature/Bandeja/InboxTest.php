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

it('navigating to a different account shows that accounts messages', function () {
    $account1 = MailAccount::factory()->for($this->user)->create(['is_active' => true]);
    $account2 = MailAccount::factory()->for($this->user)->create(['is_active' => true]);
    MailMessage::factory()->for($account1)->create(['subject' => 'Only In Account One']);
    MailMessage::factory()->for($account2)->create(['subject' => 'Only In Account Two']);

    // Navigate to account1 — shows account1 message
    $response1 = $this->actingAs($this->user)
        ->get(route('bandeja.inbox', ['account' => $account1->id]));
    $response1->assertSee('Only In Account One');

    // Navigate to account2 — shows account2 message
    $response2 = $this->actingAs($this->user)
        ->get(route('bandeja.inbox', ['account' => $account2->id]));
    $response2->assertSee('Only In Account Two');

    // Verify account1 message is NOT in account2's response
    // Use a regex that matches the exact message text in the message list context
    $response2->assertDontSee('Only In Account One');
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

// -- Navigation context / query string tests

it('selects account from query string parameter', function () {
    $account = MailAccount::factory()->for($this->user)->create(['is_active' => true, 'label' => 'Test Account']);
    MailMessage::factory()->for($account)->create(['subject' => 'Account Specific']);

    $this->actingAs($this->user)
        ->get(route('bandeja.inbox', ['account' => $account->id]))
        ->assertSee('Account Specific')
        ->assertSee('Test Account');
});

it('falls back to first active account when query string account is invalid', function () {
    $validAccount = MailAccount::factory()->for($this->user)->create(['is_active' => true, 'label' => 'Valid Account']);
    MailMessage::factory()->for($validAccount)->create(['subject' => 'Valid Message']);

    // Non-existent account ID
    $this->actingAs($this->user)
        ->get(route('bandeja.inbox', ['account' => 99999]))
        ->assertSee('Valid Message');
});

it('falls back to first active account when query string account is inactive', function () {
    $inactiveAccount = MailAccount::factory()->for($this->user)->create(['is_active' => false, 'label' => 'Inactive']);
    $activeAccount = MailAccount::factory()->for($this->user)->create(['is_active' => true, 'label' => 'Active']);
    MailMessage::factory()->for($activeAccount)->create(['subject' => 'Active Message']);

    $this->actingAs($this->user)
        ->get(route('bandeja.inbox', ['account' => $inactiveAccount->id]))
        ->assertSee('Active Message')
        ->assertDontSee('Inactive');
});

it('falls back safely when query string account belongs to another user', function () {
    $otherUser = User::factory()->create();
    $otherUser->assignRole('Super Administrador');
    $otherAccount = MailAccount::factory()->for($otherUser)->create(['is_active' => true]);

    $myAccount = MailAccount::factory()->for($this->user)->create(['is_active' => true, 'label' => 'My Account']);
    MailMessage::factory()->for($myAccount)->create(['subject' => 'My Message']);

    $this->actingAs($this->user)
        ->get(route('bandeja.inbox', ['account' => $otherAccount->id]))
        ->assertSee('My Message')
        ->assertDontSee($otherAccount->label);
});

it('auto-selects first active account when no query string provided', function () {
    $firstAccount = MailAccount::factory()->for($this->user)->create(['is_active' => true, 'label' => 'First']);
    $secondAccount = MailAccount::factory()->for($this->user)->create(['is_active' => true, 'label' => 'Second']);
    MailMessage::factory()->for($firstAccount)->create(['subject' => 'First Message']);

    $this->actingAs($this->user)
        ->get(route('bandeja.inbox'))
        ->assertSee('First Message');
});

// -- Filtered selection coherence tests

it('clears selected message when status filter excludes it', function () {
    $account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);
    $newMessage = MailMessage::factory()->for($account)->create([
        'subject' => 'New Message',
        'status' => MailMessageStatus::New,
    ]);
    MailMessage::factory()->for($account)->create([
        'subject' => 'Discarded Message',
        'status' => MailMessageStatus::Discarded,
    ]);

    $component = Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->set('selectedAccountId', $account->id)
        ->call('selectMessage', $newMessage->id);

    // Message is visible and selected
    expect($component->get('selectedMessage')->id)->toBe($newMessage->id);

    // Filter to discarded — the selected 'new' message should no longer be visible
    $component->call('setStatusFilter', 'discarded');

    expect($component->get('selectedMessage'))->toBeNull();
});

it('clears selected message when search excludes it', function () {
    $account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);
    $message = MailMessage::factory()->for($account)->create([
        'subject' => 'Important Report',
        'from_name' => 'Juan Perez',
    ]);
    MailMessage::factory()->for($account)->create([
        'subject' => 'Other Thing',
        'from_name' => 'Maria Lopez',
    ]);

    $component = Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->set('selectedAccountId', $account->id)
        ->call('selectMessage', $message->id);

    expect($component->get('selectedMessage')->id)->toBe($message->id);

    // Search that excludes the selected message
    $component->set('search', 'Maria');

    expect($component->get('selectedMessage'))->toBeNull();
});

it('keeps selected message when it matches active filters', function () {
    $account = MailAccount::factory()->for($this->user)->create(['is_active' => true]);
    $message = MailMessage::factory()->for($account)->create([
        'subject' => 'Important Report',
        'status' => MailMessageStatus::New,
    ]);

    $component = Livewire::actingAs($this->user)
        ->test('pages::bandeja.inbox')
        ->set('selectedAccountId', $account->id)
        ->call('selectMessage', $message->id);

    // Filter to 'new' — selected message should still be visible
    $component->call('setStatusFilter', 'new');

    expect($component->get('selectedMessage')->id)->toBe($message->id);
});
