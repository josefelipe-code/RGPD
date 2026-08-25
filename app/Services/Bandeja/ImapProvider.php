<?php

namespace App\Services\Bandeja;

use App\Models\MailAccount;
use Illuminate\Support\Collection;

interface ImapProvider
{
    /**
     * @return Collection<int, array{path: string, name: string, delimiter: string}>
     */
    /** Obtiene las carpetas remotas que consume la bandeja Livewire. */
    public function listFolders(MailAccount $account): Collection;

    /** Creates a remote folder and returns its validated path. */
    public function createFolder(MailAccount $account, string $path): string;

    /**
     * @return Collection<int, array<string, mixed>>
     */
    /** Obtiene únicamente los metadatos de mensajes de una carpeta IMAP. */
    public function listEnvelopes(MailAccount $account, string $folder): Collection;

    /**
     * @return array{html: string, text: string, headers: array<string, mixed>, is_read: bool}
     */
    /** Recupera cuerpo y cabeceras de un mensaje seleccionado en la bandeja. */
    public function fetchMessage(MailAccount $account, string $folder, int $uid): array;

    /** Actualiza la marca de lectura de un mensaje desde la bandeja. */
    public function setRead(MailAccount $account, string $folder, int $uid, bool $read): bool;

    /**
     * @return array{folder: string, uid: int|string|null}
     */
    /** Mueve un mensaje a otra carpeta solicitado por la bandeja Livewire. */
    public function moveMessage(MailAccount $account, string $folder, int $uid, string $targetFolder): array;

    /**
     * Mueve un mensaje a la papelera. El proveedor debe rechazar la operación si no
     * Trash folder is available instead of permanently deleting the message.
     *
     * @return array{folder: string, uid: int|string|null}
     */
    /** Mueve un mensaje a la papelera solicitado por la bandeja Livewire. */
    public function deleteMessage(MailAccount $account, string $folder, int $uid): array;
}
