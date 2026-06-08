<?php

namespace App\Console\Commands;

use App\Models\MailAccount;
use App\Services\Bandeja\ImapSyncService;
use Illuminate\Console\Command;

class SyncMailMessages extends Command
{
    protected $signature = 'bandeja:sync {--account-id= : Sync only a specific mail account}';

    protected $description = 'Synchronize incoming IMAP messages into mail_messages';

    public function handle(ImapSyncService $syncService): int
    {
        $query = MailAccount::query()->where('is_active', true);

        if ($this->option('account-id')) {
            $query->where('id', $this->option('account-id'));
        }

        $accounts = $query->get();

        if ($accounts->isEmpty()) {
            $this->warn(__('No active mail accounts found to sync.'));

            return Command::FAILURE;
        }

        $totalSynced = 0;

        foreach ($accounts as $account) {
            $this->info(__('Syncing account: :label (:email)', [
                'label' => $account->label,
                'email' => $account->email_address,
            ]));

            try {
                $messages = $syncService->syncAccount($account);
                $totalSynced += $messages->count();
                $this->line(__('- :count messages synced', ['count' => $messages->count()]));
            } catch (\RuntimeException $e) {
                $this->error(__('- Error: :message', ['message' => $e->getMessage()]));
            }
        }

        $this->info(__('Total messages synced: :total', ['total' => $totalSynced]));

        return Command::SUCCESS;
    }
}
