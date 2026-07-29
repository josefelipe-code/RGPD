<?php

namespace App\Services\Bandeja;

use App\Models\MailAccount;
use App\Models\MailMessage;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Webklex\PHPIMAP\Attribute;

/**
 * Punto de entrada de sincronización compatible con el comando y la bandeja.
 */
class ImapSyncService
{
    /** Permite inyectar el servicio usado por el comando y la compatibilidad heredada. */
    public function __construct(private readonly ?ImapMailboxService $mailbox = null) {}

    /**
     * Sincroniza los mensajes de una carpeta de la cuenta de correo.
     *
     * @return Collection<int, MailMessage>
     */
    /**
     * Mantiene la entrada de sincronización usada por el comando y código legado.
     */
    public function syncAccount(MailAccount $account, string $folder = 'INBOX'): Collection
    {
        return ($this->mailbox ?? app(ImapMailboxService::class))->syncFolder($account, $folder);
    }

    /** Normaliza fechas del proveedor Webklex para registros sincronizados. */
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
