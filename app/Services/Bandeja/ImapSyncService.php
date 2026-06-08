<?php

namespace App\Services\Bandeja;

use App\Enums\MailDirection;
use App\Enums\MailMessageStatus;
use App\Models\MailAccount;
use App\Models\MailMessage;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Webklex\PHPIMAP\Attribute;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Message;

/**
 * Synchronizes IMAP messages from active mail accounts into mail_messages.
 *
 * Uses webklex/php-imap with MailAccount::imapConfig() to connect,
 * fetch INBOX messages, and upsert them by message_id.
 */
class ImapSyncService
{
    /**
     * Sync all unseen messages from a mail account's INBOX.
     *
     * @return Collection<int, MailMessage> The synced/created messages
     *
     * @throws \RuntimeException on connection/auth failure
     */
    public function syncAccount(MailAccount $account): Collection
    {
        $config = $account->imapConfig();

        try {
            $manager = new ClientManager([]);

            $client = $manager->make([
                'host' => $config['host'],
                'port' => $config['port'],
                'protocol' => $config['protocol'] ?? 'imap',
                'encryption' => $config['encryption'] ?? 'ssl',
                'validate_cert' => $config['validate_cert'] ?? true,
                'username' => $config['username'],
                'password' => $config['password'],
                'authentication' => $config['authentication'] ?? null,
                'timeout' => $config['timeout'] ?? 30,
            ]);

            $client->connect();
            $folder = $client->getFolder('INBOX') ?? $client->getFolderByName('INBOX');

            if ($folder === null) {
                $client->disconnect();

                throw new \RuntimeException(__('No se encontró la carpeta INBOX en la cuenta :label', ['label' => $account->label]));
            }

            // Fetch all messages (unseen + seen) — we upsert by message_id so duplicates are safe
            $messages = $folder->messages()->all()->get();

            $synced = collect();

            /** @var Message $message */
            foreach ($messages as $message) {
                $synced->push($this->upsertMessage($account, $message));
            }

            $client->disconnect();

            return $synced;
        } catch (ConnectionFailedException|AuthFailedException $e) {
            throw new \RuntimeException(
                __('Error de conexión IMAP para :label: :error', [
                    'label' => $account->label,
                    'error' => $e->getMessage(),
                ]),
                0,
                $e,
            );
        }
    }

    /**
     * Upsert a single IMAP message into mail_messages.
     */
    protected function upsertMessage(MailAccount $account, Message $message): MailMessage
    {
        $messageId = $message->getMessageId() ?? $message->getUid()?->toString() ?? 'unknown-'.uniqid();

        $subject = $message->getSubject() ?? '';
        $from = $message->getFrom();
        $fromEmail = $from?->first()?->mail ?? 'unknown@example.com';
        $fromName = $from?->first()?->personal ?? null;

        $bodyHtml = $message->getHTMLBody() ?? '';
        $bodyText = $message->getTextBody() ?? '';

        $date = $this->resolveReceivedAt($message->getDate());

        return MailMessage::updateOrCreate(
            [
                'mail_account_id' => $account->id,
                'message_id' => (string) $messageId,
            ],
            [
                'subject' => $subject,
                'from_email' => $fromEmail,
                'from_name' => $fromName,
                'body_html' => $bodyHtml,
                'body_text' => $bodyText,
                'received_at' => $date,
                'direction' => MailDirection::Incoming,
                'status' => MailMessageStatus::New,
            ],
        );
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
