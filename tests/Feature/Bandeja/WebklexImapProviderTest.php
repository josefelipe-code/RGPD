<?php

use App\Models\MailAccount;
use App\Services\Bandeja\WebklexImapProvider;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Exceptions\ImapServerErrorException;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Query\WhereQuery;
use Webklex\PHPIMAP\Support\MessageCollection;

uses(RefreshDatabase::class)->in(__DIR__);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->account = MailAccount::factory()->create();
});

it('uses an explicit all-message headers-only query for envelope sync', function () {
    $query = Mockery::mock(WhereQuery::class);
    $query->shouldReceive('whereAll')->once()->andReturnSelf();
    $query->shouldReceive('setFetchBody')->once()->with(false)->andReturnSelf();
    $query->shouldReceive('setFetchFlags')->once()->with(true)->andReturnSelf();
    $query->shouldReceive('get')->once()->andReturn(new MessageCollection([]));

    $folder = Mockery::mock(Folder::class);
    $folder->shouldReceive('query')->once()->andReturn($query);

    $client = Mockery::mock(Client::class);
    $client->shouldReceive('getFolderByPath')->once()->with('INBOX', false, true)->andReturn($folder);

    $provider = new class($client) extends WebklexImapProvider
    {
        public function __construct(private readonly Client $client) {}

        protected function withClient(MailAccount $account, callable $callback): mixed
        {
            return $callback($this->client);
        }
    };

    expect($provider->listEnvelopes($this->account, 'INBOX'))->toBeEmpty();
});

it('converts an IMAP server error into the application runtime exception path', function () {
    $query = Mockery::mock(WhereQuery::class);
    $query->shouldReceive('whereAll')->once()->andReturnSelf();
    $query->shouldReceive('setFetchBody')->once()->with(false)->andReturnSelf();
    $query->shouldReceive('setFetchFlags')->once()->with(true)->andReturnSelf();
    $query->shouldReceive('get')->once()->andThrow(new ImapServerErrorException('BAD Error in IMAP command UID SEARCH'));

    $folder = Mockery::mock(Folder::class);
    $folder->shouldReceive('query')->once()->andReturn($query);

    $client = Mockery::mock(Client::class);
    $client->shouldReceive('getFolderByPath')->once()->andReturn($folder);

    $provider = new class($client) extends WebklexImapProvider
    {
        public function __construct(private readonly Client $client) {}

        protected function withClient(MailAccount $account, callable $callback): mixed
        {
            return $callback($this->client);
        }
    };

    expect(fn () => $provider->listEnvelopes($this->account, 'INBOX'))
        ->toThrow(RuntimeException::class, 'No se pudo sincronizar la carpeta IMAP.');
});

it('resolves an already existing folder to its canonical remote path', function () {
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('createFolder')->once()->with('Cases/Review', false)
        ->andThrow(new ImapServerErrorException('NO [ALREADYEXISTS] Mailbox already exists'));
    $folder = Mockery::mock(Folder::class);
    $folder->path = 'Cases/Review';
    $client->shouldReceive('getFolderByPath')->once()->with('Cases/Review', false, true)
        ->andReturn($folder);

    $provider = new class($client) extends WebklexImapProvider
    {
        public function __construct(private readonly Client $client) {}

        protected function withClient(MailAccount $account, callable $callback): mixed
        {
            return $callback($this->client);
        }
    };

    expect($provider->createFolder($this->account, 'Cases/Review'))->toBe('Cases/Review');
});

it('propagates unrelated IMAP folder creation errors', function () {
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('createFolder')->once()->with('Cases/Review', false)
        ->andThrow(new ImapServerErrorException('NO [CANNOT] Permission denied'));
    $client->shouldNotReceive('getFolderByPath');

    $provider = new class($client) extends WebklexImapProvider
    {
        public function __construct(private readonly Client $client) {}

        protected function withClient(MailAccount $account, callable $callback): mixed
        {
            return $callback($this->client);
        }
    };

    expect(fn () => $provider->createFolder($this->account, 'Cases/Review'))
        ->toThrow(ImapServerErrorException::class, '[CANNOT]');
});
