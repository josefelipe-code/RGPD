<?php

namespace App\Services\Bandeja;

use App\Models\MailAccount;
use Illuminate\Support\Collection;

interface ImapProvider
{
    /**
     * @return Collection<int, array{path: string, name: string, delimiter: string}>
     */
    public function listFolders(MailAccount $account): Collection;

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function listEnvelopes(MailAccount $account, string $folder): Collection;

    /**
     * @return array{html: string, text: string, headers: array<string, mixed>, is_read: bool}
     */
    public function fetchMessage(MailAccount $account, string $folder, int $uid): array;

    public function setRead(MailAccount $account, string $folder, int $uid, bool $read): bool;

    /**
     * @return array{folder: string, uid: int|string|null}
     */
    public function moveMessage(MailAccount $account, string $folder, int $uid, string $targetFolder): array;

    /**
     * Move a message to Trash. Providers must reject the operation when no
     * Trash folder is available instead of permanently deleting the message.
     *
     * @return array{folder: string, uid: int|string|null}
     */
    public function deleteMessage(MailAccount $account, string $folder, int $uid): array;
}
