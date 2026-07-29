<?php

namespace App\Services\Bandeja;

use App\Enums\MailDirection;
use App\Enums\MailMessageStatus;
use App\Models\MailAccount;
use App\Models\MailMessage;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

class ImapMailboxService
{
    /** Recibe el proveedor IMAP que ejecuta las operaciones remotas. */
    public function __construct(private readonly ImapProvider $provider) {}

    /** Delega al proveedor IMAP las carpetas que muestra la bandeja. */
    public function listFolders(MailAccount $account): Collection
    {
        return $this->provider->listFolders($account);
    }

    /** Delega la lectura de sobres sin descargar cuerpos de mensajes. */
    public function listEnvelopes(MailAccount $account, string $folder): Collection
    {
        return $this->provider->listEnvelopes($account, $folder);
    }

    /**
     * Persiste los sobres de una carpeta para el comando y los consumidores heredados.
     *
     * @return Collection<int, MailMessage>
     */
    public function syncFolder(MailAccount $account, string $folder = 'INBOX'): Collection
    {
        return $this->listEnvelopes($account, $folder)
            ->map(fn (array $envelope): MailMessage => $this->persistEnvelope($account, $folder, $envelope));
    }

    /**
     * Recupera el contenido completo que solicita el lector de la bandeja.
     *
     * @return array{html: string, text: string, headers: array<string, mixed>, is_read: bool}
     */
    public function fetchMessage(MailAccount $account, string $folder, int $uid): array
    {
        return $this->provider->fetchMessage($account, $folder, $uid);
    }

    /** Marca un mensaje como leído o no leído a petición de la bandeja. */
    public function setRead(MailAccount $account, string $folder, int $uid, bool $read = true): bool
    {
        return $this->provider->setRead($account, $folder, $uid, $read);
    }

    /**
     * Mueve un mensaje mediante el proveedor usado por la bandeja Livewire.
     *
     * @return array{folder: string, uid: int|string|null}
     */
    public function moveMessage(MailAccount $account, string $folder, int $uid, string $targetFolder): array
    {
        return $this->provider->moveMessage($account, $folder, $uid, $targetFolder);
    }

    /**
     * Envía un mensaje a la papelera mediante el proveedor IMAP.
     *
     * @return array{folder: string, uid: int|string|null}
     */
    public function deleteMessage(MailAccount $account, string $folder, int $uid): array
    {
        return $this->provider->deleteMessage($account, $folder, $uid);
    }

    /**
     * Convierte un sobre remoto en un MailMessage para la sincronización heredada.
     *
     * @param  array<string, mixed>  $envelope
     */
    protected function persistEnvelope(MailAccount $account, string $folder, array $envelope): MailMessage
    {
        $uid = (string) $envelope['uid'];
        $message = MailMessage::query()
            ->where('mail_account_id', $account->id)
            ->where('folder', $folder)
            ->where('imap_uid', $uid)
            ->first();

        $attributes = [
            'message_id' => $envelope['message_id'] ?? "{$folder}:{$uid}",
            'subject' => $envelope['subject'] ?? null,
            'from_email' => $envelope['from_email'] ?? 'unknown@example.com',
            'from_name' => $envelope['from_name'] ?? null,
            'received_at' => $this->resolveDate($envelope['received_at'] ?? null),
            'direction' => MailDirection::Incoming,
            'in_reply_to' => $envelope['in_reply_to'] ?? null,
            'references' => $this->references($envelope['references'] ?? null),
            'folder' => $folder,
            'imap_uid' => $uid,
            'is_read' => (bool) ($envelope['is_read'] ?? false),
        ];

        if ($message === null) {
            return MailMessage::create($attributes + [
                'mail_account_id' => $account->id,
                'status' => MailMessageStatus::New,
            ]);
        }

        $message->update($attributes);

        return $message->refresh();
    }

    /** Normaliza fechas del proveedor y usa la fecha actual cuando faltan. */
    protected function resolveDate(mixed $date): CarbonInterface
    {
        if ($date instanceof CarbonInterface) {
            return $date;
        }

        return filled($date) ? Date::parse($date) : now();
    }

    /**
     * Divide la cabecera References en identificadores utilizables para threading.
     *
     * @return array<int, string>|null
     */
    protected function references(mixed $references): ?array
    {
        if (blank($references)) {
            return null;
        }

        return array_values(array_filter(preg_split('/\s+/', (string) $references) ?: []));
    }
}
