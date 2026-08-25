<?php

namespace App\Services\Bandeja;

use App\Models\MailAccount;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Webklex\PHPIMAP\Attribute;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\ImapServerErrorException;
use Webklex\PHPIMAP\Message;

class WebklexImapProvider implements ImapProvider
{
    /** Lista las carpetas remotas que solicita la bandeja Livewire. */
    public function listFolders(MailAccount $account): Collection
    {
        return $this->withClient($account, function ($client): Collection {
            return collect($client->getFolders(false))->map(fn ($folder): array => [
                'path' => $folder->path,
                'name' => $folder->name,
                'delimiter' => $folder->delimiter,
            ])->values();
        });
    }

    public function createFolder(MailAccount $account, string $path): string
    {
        return $this->withClient($account, function ($client) use ($path): string {
            try {
                $folder = $client->createFolder($path, false);
            } catch (ImapServerErrorException $exception) {
                if (! str_contains($exception->getMessage(), '[ALREADYEXISTS]')) {
                    throw $exception;
                }

                $folder = $client->getFolderByPath($path, false, true);

                if ($folder === null) {
                    throw $exception;
                }
            }

            return $folder->path;
        });
    }

    /** Lee sobres sin cuerpo para mostrar una carpeta de forma eficiente. */
    public function listEnvelopes(MailAccount $account, string $folder): Collection
    {
        try {
            return $this->withClient($account, function ($client) use ($folder): Collection {
                $imapFolder = $client->getFolderByPath($folder, false, true);

                if ($imapFolder === null) {
                    return collect();
                }

                return collect($imapFolder->query()
                    ->whereAll()
                    ->setFetchBody(false)
                    ->setFetchFlags(true)
                    ->get())
                    ->map(fn (Message $message): array => $this->envelope($message, $folder))
                    ->values();
            });
        } catch (\Exception $e) {
            throw new \RuntimeException(__('No se pudo sincronizar la carpeta IMAP.'), 0, $e);
        }
    }

    /** Obtiene cuerpo, cabeceras y estado del mensaje leído por la bandeja. */
    public function fetchMessage(MailAccount $account, string $folder, int $uid): array
    {
        return $this->withClient($account, function ($client) use ($folder, $uid): array {
            $imapFolder = $client->getFolderByPath($folder, false, true);

            if ($imapFolder === null) {
                throw new \RuntimeException(__('No se encontró la carpeta IMAP :folder.', ['folder' => $folder]));
            }

            /** @var Message|null $message */
            $message = $imapFolder->messages()
                ->whereUid($uid)
                ->setFetchBody(true)
                ->setFetchFlags(true)
                ->get()
                ->first();

            if ($message === null) {
                throw new \RuntimeException(__('No se encontró el mensaje IMAP :uid.', ['uid' => $uid]));
            }

            return [
                'html' => $message->getHTMLBody(),
                'text' => $message->getTextBody(),
                'headers' => $this->headers($message),
                'is_read' => $message->hasFlag('Seen'),
            ];
        });
    }

    /** Cambia la marca Seen del mensaje solicitada por la bandeja. */
    public function setRead(MailAccount $account, string $folder, int $uid, bool $read): bool
    {
        return $this->withClient($account, function ($client) use ($folder, $uid, $read): bool {
            $imapFolder = $client->getFolderByPath($folder, false, true);

            if ($imapFolder === null) {
                return false;
            }

            /** @var Message|null $message */
            $message = $imapFolder->messages()->whereUid($uid)->setFetchBody(false)->get()->first();

            if ($message === null) {
                return false;
            }

            return $read ? $message->setFlag('Seen') : $message->unsetFlag('Seen');
        });
    }

    /** Mueve un mensaje a la carpeta destino elegida en la bandeja. */
    public function moveMessage(MailAccount $account, string $folder, int $uid, string $targetFolder): array
    {
        return $this->withClient($account, function ($client) use ($folder, $uid, $targetFolder): array {
            $message = $this->findMessage($client, $folder, $uid);
            $moved = $message->move($targetFolder);

            if ($moved === null) {
                throw new \RuntimeException(__('No se pudo mover el mensaje a :folder.', ['folder' => $targetFolder]));
            }

            return [
                'folder' => $targetFolder,
                'uid' => $moved->getUid(),
            ];
        });
    }

    /** Mueve el mensaje a la carpeta Trash detectada para la cuenta. */
    public function deleteMessage(MailAccount $account, string $folder, int $uid): array
    {
        return $this->withClient($account, function ($client) use ($folder, $uid): array {
            $trashFolder = $this->findTrashFolder($client);

            if ($trashFolder === null) {
                throw new \RuntimeException(__('La cuenta no tiene una carpeta Trash disponible.'));
            }

            $message = $this->findMessage($client, $folder, $uid);
            $moved = $message->move($trashFolder);

            if ($moved === null) {
                throw new \RuntimeException(__('No se pudo mover el mensaje a la papelera.'));
            }

            return [
                'folder' => $trashFolder,
                'uid' => $moved->getUid(),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    /** Convierte un mensaje Webklex en el sobre que consumen los servicios IMAP. */
    protected function envelope(Message $message, string $folder): array
    {
        $from = $message->getFrom()?->first();
        $headers = $this->headers($message);

        return [
            'uid' => (int) $message->getUid(),
            'message_id' => $this->stringValue($message->getMessageId()),
            'subject' => $this->stringValue($message->getSubject()),
            'from_email' => $from?->mail ?? 'unknown@example.com',
            'from_name' => $from?->personal,
            'received_at' => $this->dateValue($message->getDate()),
            'in_reply_to' => $headers['in_reply_to'] ?? null,
            'references' => $headers['references'] ?? null,
            'is_read' => $message->hasFlag('Seen'),
            'folder' => $folder,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /** Extrae cabeceras de threading con valores compatibles con la aplicación. */
    protected function headers(Message $message): array
    {
        $headers = $message->getHeaders();

        return [
            'message_id' => $this->stringValue($message->getMessageId()),
            'in_reply_to' => $this->stringValue($headers->get('in_reply_to')),
            'references' => $this->stringValue($headers->get('references')),
        ];
    }

    /** Convierte atributos Webklex o escalares en texto nullable. */
    protected function stringValue(mixed $value): ?string
    {
        if ($value instanceof Attribute) {
            return $value->toString();
        }

        return filled($value) ? (string) $value : null;
    }

    /** Convierte una fecha Webklex a Carbon sin inventar valores faltantes. */
    protected function dateValue(mixed $value): ?CarbonInterface
    {
        if ($value instanceof Attribute) {
            return $value->toDate();
        }

        return $value instanceof CarbonInterface ? $value : null;
    }

    /** Abre un cliente IMAP, ejecuta la operación y garantiza su desconexión. */
    protected function withClient(MailAccount $account, callable $callback): mixed
    {
        $config = $account->imapConfig();
        $client = (new ClientManager([]))->make([
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

        try {
            return $callback($client);
        } finally {
            $client->disconnect();
        }
    }

    /** Resuelve un mensaje por carpeta y UID para moverlo o eliminarlo. */
    protected function findMessage(mixed $client, string $folder, int $uid): Message
    {
        $imapFolder = $client->getFolderByPath($folder, false, true);

        if ($imapFolder === null) {
            throw new \RuntimeException(__('No se encontró la carpeta IMAP :folder.', ['folder' => $folder]));
        }

        $message = $imapFolder->messages()->whereUid($uid)->setFetchBody(false)->get()->first();

        if ($message === null) {
            throw new \RuntimeException(__('No se encontró el mensaje IMAP :uid.', ['uid' => $uid]));
        }

        return $message;
    }

    /** Encuentra la ruta de papelera compatible con distintos nombres de servidor. */
    protected function findTrashFolder(mixed $client): ?string
    {
        return collect($client->getFolders(false))
            ->first(fn ($folder): bool => $this->isTrashFolder($folder->path, $folder->name))
            ?->path;
    }

    /** Determina si una carpeta remota representa la papelera. */
    protected function isTrashFolder(string $path, string $name): bool
    {
        $value = mb_strtolower($path.' '.$name);

        return str_contains($value, 'trash')
            || str_contains($value, 'deleted items')
            || str_contains($value, 'papelera')
            || str_contains($value, 'eliminados');
    }
}
