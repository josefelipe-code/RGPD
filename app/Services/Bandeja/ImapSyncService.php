<?php

namespace App\Services\Bandeja;

use App\Models\MailAccount;
use App\Models\MailMessage;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Webklex\PHPIMAP\Attribute;

/**
 * Backward-compatible synchronization entry point for the inbox command/UI.
 */
class ImapSyncService
{
    public function __construct(private readonly ?ImapMailboxService $mailbox = null) {}

    /**
     * Sync all unseen messages from a mail account's INBOX.
     *
     * @return Collection<int, MailMessage>
     */
    public function syncAccount(MailAccount $account, string $folder = 'INBOX'): Collection
    {
        return ($this->mailbox ?? app(ImapMailboxService::class))->syncFolder($account, $folder);
    }

    protected function resolveReceivedAt(mixed $date): CarbonInterface
    {
        if ($date instanceof Attribute) {
            return $date->toDate();
        }

        if ($date instanceof CarbonInterface) {
            return $date;
        }

        if (filled($date)) {
            return Date::parse($date);
        }

        return now();
    }
}
