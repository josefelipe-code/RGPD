<?php

use App\Models\Contact;
use App\Models\MailAccount;
use App\Models\Signature;
use App\Models\Template;
use App\Models\User;
use App\Services\Bandeja\InboxOutboundMailService;
use App\Services\Bandeja\ImapMailboxService;
use Flux\Flux;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Bandeja de entrada')] class extends Component {
    use WithPagination;

    #[Url(as: 'account')]
    public ?int $selectedAccountId = null;
    public ?int $selectedMessageId = null;
    public string $statusFilter = 'all';
    public string $search = '';
    public int $perPage = 15;
    public string $selectedFolder = 'INBOX';
    public string $moveTargetFolder = '';
    /** @var array<int, array{path: string, name: string}> */
    public array $remoteFolders = [];
    public bool $foldersLoaded = false;
    public bool $folderLoadFailed = false;
    /** @var array<string, string> */
    public array $folderSyncErrors = [];
    /** @var array<int, array<string, mixed>> */
    public array $envelopes = [];
    public ?string $loadedBodyHtml = null;
    public ?string $loadedBodyText = null;
    public bool $bodyLoaded = false;
    public bool $bodyLoadFailed = false;
    public bool $composerOpen = false;
    public ?string $composerMode = null;
    public string $composerTo = '';
    public string $composerCc = '';
    public string $composerBcc = '';
    public string $composerSubject = '';
    public string $composerBody = '';
    public ?int $composerContactId = null;
    public string $composerContactSearch = '';
    public ?int $composerTemplateId = null;
    public ?int $composerSignatureId = null;

    protected ?User $currentUser = null;

    /** Livewire inicializa la cuenta, carpetas y sobres al montar la página. */
    public function mount(): void
    {
        abort_unless(Auth::user()->can('bandeja.ver'), 403);

        // Validate query-string account: must be owned by user and active
        if ($this->selectedAccountId !== null) {
            $valid = $this->getUser()->mailAccounts()
                ->where('id', $this->selectedAccountId)
                ->where('is_active', true)
                ->exists();

            if (! $valid) {
                $this->selectedAccountId = null;
            }
        }

        // Auto-select first active account if none selected
        if ($this->selectedAccountId === null) {
            $first = $this->activeAccounts()->first();
            $this->selectedAccountId = $first?->id;
        }

        $this->loadMailbox();
    }

    /** Livewire reinicia el estado y carga el buzón al cambiar de cuenta. */
    public function updatedSelectedAccountId(?int $accountId): void
    {
        $this->selectedFolder = 'INBOX';
        $this->selectedMessageId = null;
        $this->moveTargetFolder = '';
        $this->loadedBodyHtml = null;
        $this->loadedBodyText = null;
        $this->bodyLoaded = false;
        $this->bodyLoadFailed = false;
        $this->remoteFolders = [];
        $this->foldersLoaded = false;
        $this->folderLoadFailed = false;
        $this->folderSyncErrors = [];
        $this->envelopes = [];
        $this->resetPage();

        $this->loadMailbox();
    }

    /** Livewire vuelve a la primera página cuando cambia la búsqueda. */
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /** Livewire vuelve a la primera página cuando cambia el filtro de lectura. */
    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    /** Livewire reinicia la paginación al cambiar el tamaño de página. */
    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    /** Restablece el compositor cuando el usuario cierra el modal desde Flux. */
    public function updatedComposerOpen(bool $open): void
    {
        if (! $open) {
            $this->resetComposer();
        }
    }

    /** Completa el email manual con el contacto elegido en el selector. */
    public function updatedComposerContactId(?int $contactId): void
    {
        $this->selectComposerContact($contactId);
    }

    /** Livewire limpia la selección y actualiza los sobres de la carpeta elegida. */
    public function updatedSelectedFolder(string $folder): void
    {
        abort_unless(collect($this->remoteFolders)->contains('path', $folder), 403);

        $this->selectedFolder = $folder;
        $this->selectedMessageId = null;
        $this->moveTargetFolder = '';
        $this->loadedBodyHtml = null;
        $this->loadedBodyText = null;
        $this->bodyLoaded = false;
        $this->bodyLoadFailed = false;
        $this->resetPage();

        $this->refreshSelectedFolder();
    }

    /** Carga carpetas IMAP cuando lo invoca Livewire desde la página de bandeja. */
    public function loadFolders(): void
    {
        abort_unless(Auth::user()->can('bandeja.sincronizar'), 403);

        $account = $this->resolveSelectedAccount();
        abort_if($account === null, 403);

        try {
            $this->remoteFolders = app(ImapMailboxService::class)
                ->listFolders($account)
                ->map(fn (array $folder): array => [
                    'path' => $folder['path'],
                    'name' => $folder['name'],
                ])
                ->all();
            $this->foldersLoaded = true;
            $this->folderLoadFailed = false;

            if (! collect($this->remoteFolders)->contains('path', $this->selectedFolder)) {
                $this->selectedFolder = collect($this->remoteFolders)->first()['path'] ?? 'INBOX';
            }
        } catch (\Throwable) {
            $this->foldersLoaded = false;
            $this->folderLoadFailed = true;
            Flux::toast(variant: 'danger', text: __('No se pudieron cargar las carpetas IMAP.'));
        }
    }

    /** Coordina la carga inicial de carpetas y mensajes para mount o cambio de cuenta. */
    protected function loadMailbox(): void
    {
        if ($this->resolveSelectedAccount() === null || ! $this->getUser()->can('bandeja.sincronizar')) {
            return;
        }

        $this->loadFolders();

        if ($this->foldersLoaded) {
            $this->refreshSelectedFolder();
        }
    }

    /** Refresca los sobres de la carpeta seleccionada sin persistir cuerpos. */
    protected function refreshSelectedFolder(): void
    {
        $account = $this->resolveSelectedAccount();

        if ($account === null || ! $this->foldersLoaded || ! collect($this->remoteFolders)->contains('path', $this->selectedFolder)) {
            return;
        }

        try {
            $this->envelopes = app(ImapMailboxService::class)
                ->listEnvelopes($account, $this->selectedFolder)
                ->map(fn (array $envelope): array => $this->normalizeEnvelope($account->id, $this->selectedFolder, $envelope))
                ->all();
            unset($this->folderSyncErrors[$this->selectedFolder]);
        } catch (\Throwable) {
            $this->folderSyncErrors[$this->selectedFolder] = __('No se pudo cargar esta carpeta IMAP.');
            Flux::toast(variant: 'danger', text: __('No se pudo cargar la carpeta IMAP seleccionada.'));
        }
    }

    /** Devuelve el usuario autenticado que autoriza las operaciones de la bandeja. */
    protected function getUser(): User
    {
        return $this->currentUser ??= Auth::user();
    }

    /**
     * Resolve the selected account, validating ownership and active status.
     * Returns null if no valid account is selected.
     */
    /** Resuelve la cuenta seleccionada dentro de las cuentas accesibles al usuario. */
    protected function resolveSelectedAccount(): ?MailAccount
    {
        if ($this->selectedAccountId === null) {
            return null;
        }

        return $this->getUser()->mailAccounts()
            ->where('id', $this->selectedAccountId)
            ->where('is_active', true)
            ->first();
    }

    #[Computed]
    /** Computed de cuentas activas que alimenta el selector de la plantilla. */
    public function activeAccounts()
    {
        return $this->getUser()->mailAccounts()->where('is_active', true)->orderBy('label')->get();
    }

    #[Computed]
    /** Computed que entrega los contactos con email para el compositor de la cuenta seleccionada. */
    public function composerContacts()
    {
        return Contact::query()
            ->whereNotNull('email')
            ->when($this->composerContactSearch, fn ($query) => $query
                ->where(fn ($query) => $query
                    ->where('name', 'like', "%{$this->composerContactSearch}%")
                    ->orWhere('email', 'like', "%{$this->composerContactSearch}%")))
            ->orderBy('name')
            ->limit(10)
            ->get();
    }

    #[Computed]
    /** Computed que lista las plantillas activas compartidas disponibles para el equipo. */
    public function composerTemplates()
    {
        return Template::active()->orderBy('name')->get();
    }

    #[Computed]
    /** Computed que limita las firmas activas a la cuenta seleccionada del usuario. */
    public function composerSignatures()
    {
        $account = $this->resolveSelectedAccount();

        return $account === null
            ? collect()
            : $account->signatures()->active()->orderByDesc('is_default')->orderBy('name')->get();
    }

    #[Computed]
    /** Computed que filtra y pagina los sobres mostrados en la lista. */
    public function messages()
    {
        $account = $this->resolveSelectedAccount();
        if ($account === null) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $this->perPage);
        }

        $search = mb_strtolower($this->search);
        $filtered = collect($this->envelopes)
            ->filter(fn (array $envelope): bool => (int) $envelope['account_id'] === $account->id)
            ->filter(function (array $envelope) use ($search): bool {
                if ($search === '') {
                    return true;
                }

                return str_contains(mb_strtolower((string) ($envelope['from_name'] ?? '')), $search)
                    || str_contains(mb_strtolower((string) ($envelope['from_email'] ?? '')), $search)
                    || str_contains(mb_strtolower((string) ($envelope['subject'] ?? '')), $search);
            })
            ->filter(fn (array $envelope): bool => $this->statusFilter === 'all'
                || ($this->statusFilter === 'unread' && ! (bool) $envelope['is_read']))
            ->sortByDesc('received_at')
            ->map(fn (array $envelope): object => $this->envelopeView($envelope));

        $page = LengthAwarePaginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            $filtered->forPage($page, $this->perPage)->values(),
            $filtered->count(),
            $this->perPage,
            $page,
            ['path' => request()->url()],
        );
    }

    #[Computed]
    /** Computed que obtiene el sobre seleccionado por identidad IMAP. */
    public function selectedMessage(): ?object
    {
        if ($this->selectedMessageId === null) {
            return null;
        }

        $account = $this->resolveSelectedAccount();
        if ($account === null) {
            return null;
        }

        $envelope = collect($this->envelopes)->first(fn (array $envelope): bool => (int) $envelope['uid'] === $this->selectedMessageId
            && (int) $envelope['account_id'] === $account->id
            && $envelope['folder'] === $this->selectedFolder);

        if ($envelope === null) {
            return null;
        }

        return $this->envelopeView($envelope);
    }

    #[Computed]
    /** Computed que devuelve el cuerpo cargado y sanitizado del mensaje seleccionado. */
    public function selectedMessageBody(): HtmlString
    {
        $message = $this->selectedMessage;

        if ($message === null) {
            return new HtmlString('');
        }

        if (! $this->bodyLoaded && ! $this->bodyLoadFailed) {
            return new HtmlString('<p>'.e(__('Cargando contenido desde IMAP...')).'</p>');
        }

        if ($this->bodyLoadFailed) {
            return new HtmlString('<p>'.e(__('No se pudo cargar el contenido del mensaje.')).'</p>');
        }

        if (filled($this->loadedBodyHtml)) {
            return new HtmlString($this->sanitizeHtmlBody($this->loadedBodyHtml));
        }

        if (filled($this->loadedBodyText)) {
            return new HtmlString(nl2br(e($this->loadedBodyText)));
        }

        return new HtmlString('<p>'.e(__('Sin contenido.')).'</p>');
    }

    /** Selecciona el sobre para que el lector se pinte antes de iniciar la carga IMAP. */
    public function selectMessage(int $messageId): void
    {
        $account = $this->resolveSelectedAccount();
        abort_if($account === null, 403);

        $envelope = $this->findEnvelope($account, $messageId);
        abort_if($envelope === null, 404);
        $this->selectedMessageId = $messageId;
        $this->loadedBodyHtml = null;
        $this->loadedBodyText = null;
        $this->bodyLoaded = false;
        $this->bodyLoadFailed = false;
    }

    /** Carga el cuerpo remoto una vez que el lector seleccionado ya está visible. */
    public function loadSelectedMessageBody(int $accountId, string $folder, int $uid): void
    {
        $account = $this->resolveSelectedAccount();

        if ($account === null
            || $account->id !== $accountId
            || $this->selectedFolder !== $folder
            || $this->selectedMessageId !== $uid
            || $this->findEnvelope($account, $uid) === null) {
            return;
        }

        try {
            $content = app(ImapMailboxService::class)->fetchMessage($account, $folder, $uid);
            $this->loadedBodyHtml = $content['html'];
            $this->loadedBodyText = $content['text'];
            $this->bodyLoaded = true;
        } catch (\Throwable) {
            $this->loadedBodyHtml = null;
            $this->loadedBodyText = null;
            $this->bodyLoadFailed = true;
            Flux::toast(
                variant: 'danger',
                text: __('No se pudo cargar el mensaje desde IMAP.'),
            );
        }
    }

    /** Actualiza Seen después de mostrar el cuerpo, sin bloquear la selección ni su lectura. */
    public function markMessageRead(int $accountId, string $folder, int $uid): void
    {
        $account = $this->getUser()->mailAccounts()
            ->whereKey($accountId)
            ->where('is_active', true)
            ->first();

        if ($account === null) {
            return;
        }

        try {
            if (app(ImapMailboxService::class)->setRead($account, $folder, $uid) === true) {
                $this->markEnvelopeRead($accountId, $folder, $uid);
            } else {
                Flux::toast(
                    variant: 'danger',
                    text: __('No se pudo actualizar el estado de lectura en IMAP.'),
                );
            }
        } catch (\Throwable) {
            Flux::toast(
                variant: 'danger',
                text: __('No se pudo actualizar el estado de lectura en IMAP.'),
            );
        }
    }

    /** Acción `wire:click` que refresca la carpeta IMAP seleccionada. */
    public function sync(): void
    {
        abort_unless(Auth::user()->can('bandeja.sincronizar'), 403);

        $account = MailAccount::where('user_id', $this->getUser()->id)
            ->where('id', $this->selectedAccountId)
            ->where('is_active', true)
            ->firstOrFail();

        try {
            $this->refreshSelectedFolder();
            Flux::toast(
                variant: 'success',
                text: __(':count mensajes cargados desde IMAP.', ['count' => count($this->envelopes)]),
            );
        } catch (\Throwable $e) {
            Flux::toast(
                variant: 'danger',
                text: $e->getMessage(),
            );
        }
    }

    /** Acción `wire:click` que mueve el mensaje a otra carpeta IMAP. */
    public function moveMessage(int $messageId, string $targetFolder): void
    {
        abort_unless(Auth::user()->can('bandeja.sincronizar'), 403);

        $account = $this->resolveSelectedAccount();
        abort_if($account === null, 403);
        abort_unless(collect($this->remoteFolders)->contains('path', $targetFolder), 403);

        $envelope = $this->findEnvelope($account, $messageId);
        abort_if($envelope === null, 404);

        try {
            app(ImapMailboxService::class)->moveMessage(
                $account,
                $envelope['folder'],
                (int) $envelope['uid'],
                $targetFolder,
            );

            $this->removeEnvelope($messageId);

            $this->clearSelectedMessage($messageId);
            Flux::toast(variant: 'success', text: __('Mensaje movido a :folder.', ['folder' => $targetFolder]));
        } catch (\Throwable) {
            Flux::toast(variant: 'danger', text: __('No se pudo mover el mensaje en IMAP.'));
        }
    }

    /** Acción `wire:click` que mueve el mensaje seleccionado a la papelera. */
    public function deleteMessage(int $messageId): void
    {
        abort_unless(Auth::user()->can('bandeja.sincronizar'), 403);

        $account = $this->resolveSelectedAccount();
        abort_if($account === null, 403);

        $envelope = $this->findEnvelope($account, $messageId);
        abort_if($envelope === null, 404);

        try {
            app(ImapMailboxService::class)->deleteMessage(
                $account,
                $envelope['folder'],
                (int) $envelope['uid'],
            );

            $this->removeEnvelope($messageId);
            $this->clearSelectedMessage($messageId);
            Flux::toast(variant: 'success', text: __('Mensaje movido a la papelera.'));
        } catch (\Throwable) {
            Flux::toast(variant: 'danger', text: __('No se pudo mover el mensaje a la papelera.'));
        }
    }

    /** Opens the mailbox-scoped composer for the selected IMAP envelope. */
    public function openComposer(string $mode, int $messageId): void
    {
        abort_unless(Auth::user()->can('bandeja.clasificar'), 403);
        abort_unless(in_array($mode, ['reply', 'forward'], true), 422);

        $account = $this->resolveSelectedAccount();
        abort_if($account === null, 403);
        $envelope = $this->findEnvelope($account, $messageId);
        abort_if($envelope === null, 404);

        $this->selectedMessageId = $messageId;
        $this->resetComposer();
        $this->composerMode = $mode;
        $this->composerTo = $mode === 'reply' ? $envelope['from_email'] : '';
        $this->composerSubject = ($mode === 'reply' ? 'Re: ' : 'Fwd: ').($envelope['subject'] ?? '');
        $this->composerSignatureId = $account->signatures()->active()->default()->value('id');
        $this->composerOpen = true;
    }

    /** Asigna al destinatario el email de un contacto existente con dirección válida. */
    public function selectComposerContact(?int $contactId): void
    {
        if ($contactId === null) {
            return;
        }

        $contact = Contact::query()->whereNotNull('email')->findOrFail($contactId);

        $this->composerContactId = $contact->id;
        $this->composerTo = $contact->email;
        $this->resetErrorBag('composerTo');
    }

    /** Aplica una plantilla sólo mediante la acción explícita del usuario. */
    public function applyComposerTemplate(): void
    {
        if ($this->composerTemplateId === null) {
            return;
        }

        $template = Template::active()->findOrFail($this->composerTemplateId);

        $this->composerBody = $template->body ?? '';

        if (blank($this->composerSubject) && filled($template->subject)) {
            $this->composerSubject = $template->subject;
        }

        $this->resetErrorBag(['composerBody', 'composerSubject']);
    }

    /** Validates and sends a reply or forward for the selected IMAP envelope. */
    public function sendComposer(InboxOutboundMailService $outbound): void
    {
        abort_unless(Auth::user()->can('bandeja.clasificar'), 403);
        abort_unless(in_array($this->composerMode, ['reply', 'forward'], true), 422);

        $validator = Validator::make([
            'composerTo' => $this->composerTo,
            'composerCc' => $this->recipientList($this->composerCc),
            'composerBcc' => $this->recipientList($this->composerBcc),
            'composerSubject' => $this->composerSubject,
            'composerBody' => $this->composerBody,
        ], [
            'composerTo' => ['required', 'email'],
            'composerCc' => ['array'],
            'composerCc.*' => ['email'],
            'composerBcc' => ['array'],
            'composerBcc.*' => ['email'],
            'composerSubject' => ['required', 'string', 'max:255'],
            'composerBody' => ['required', 'string'],
        ]);

        $validator->after(function ($validator): void {
            foreach (['composerCc', 'composerBcc'] as $field) {
                if ($validator->errors()->has("{$field}.*")) {
                    $validator->errors()->add($field, __('Ingresá direcciones de correo válidas separadas por comas.'));
                }
            }
        });

        if ($validator->fails()) {
            $this->setErrorBag($validator->errors());

            return;
        }

        $validated = $validator->validated();
        $account = $this->resolveSelectedAccount();
        abort_if($account === null || $this->selectedMessageId === null, 403);
        $envelope = $this->findEnvelope($account, $this->selectedMessageId);
        abort_if($envelope === null, 404);
        $signature = $this->resolveComposerSignature($account);

        try {
            $outbound->send(
                account: $account,
                mode: $this->composerMode,
                recipient: $validated['composerTo'],
                cc: $validated['composerCc'],
                bcc: $validated['composerBcc'],
                subject: $validated['composerSubject'],
                body: $validated['composerBody'],
                signature: $signature?->body,
                origin: [
                    'message_id' => $envelope['message_id'],
                    'references' => $envelope['references'] ?? null,
                ],
            );
        } catch (\Throwable $e) {
            Log::withContext([
                'mail_account_id' => $account->id,
                'mode' => $this->composerMode,
                'recipient_domain' => str($validated['composerTo'])->afterLast('@')->toString(),
                'recipient_count' => 1 + count($validated['composerCc']) + count($validated['composerBcc']),
            ]);

            report($e);

            Flux::toast(variant: 'danger', text: __('No se pudo enviar el correo. Verificá la configuración SMTP e intentá nuevamente.'));

            return;
        }

        Flux::toast(variant: 'success', text: $this->composerMode === 'reply' ? __('Respuesta enviada.') : __('Reenvío enviado.'));
        $this->composerOpen = false;
        $this->resetComposer();
    }

    /** Cierra el compositor y descarta los datos transitorios no enviados. */
    public function closeComposer(): void
    {
        $this->composerOpen = false;
        $this->resetComposer();
    }

    /** Acción `wire:click` que limita la lista a todos o no leídos. */
    public function setStatusFilter(string $status): void
    {
        abort_unless(in_array($status, ['all', 'unread'], true), 422);
        $this->statusFilter = $status;
    }

    /** Limpia el lector cuando el mensaje seleccionado deja de estar disponible. */
    private function clearSelectedMessage(int $messageId): void
    {
        if ($this->selectedMessageId === $messageId) {
            $this->selectedMessageId = null;
            $this->moveTargetFolder = '';
            $this->loadedBodyHtml = null;
            $this->loadedBodyText = null;
            $this->bodyLoaded = false;
            $this->bodyLoadFailed = false;
        }
    }

    /**
     * Busca un sobre dentro de la cuenta y carpeta actualmente seleccionadas.
     *
     * @return array<string, mixed>|null
     */
    private function findEnvelope(MailAccount $account, int $uid): ?array
    {
        return collect($this->envelopes)->first(fn (array $envelope): bool => (int) $envelope['account_id'] === $account->id
            && $envelope['folder'] === $this->selectedFolder
            && (int) $envelope['uid'] === $uid);
    }

    /** Resuelve exclusivamente una firma activa de la cuenta que realizará el envío. */
    private function resolveComposerSignature(MailAccount $account): ?Signature
    {
        if ($this->composerSignatureId === null) {
            return null;
        }

        return $account->signatures()
            ->active()
            ->findOrFail($this->composerSignatureId);
    }

    /** Limpia los valores efímeros del compositor y sus errores de validación. */
    private function resetComposer(): void
    {
        $this->reset([
            'composerMode',
            'composerTo',
            'composerCc',
            'composerBcc',
            'composerSubject',
            'composerBody',
            'composerContactId',
            'composerContactSearch',
            'composerTemplateId',
            'composerSignatureId',
        ]);
        $this->resetErrorBag();
    }

    /** Separa una lista de destinatarios introducida con comas y elimina valores vacíos. */
    private function recipientList(string $recipients): array
    {
        return array_values(array_filter(array_map(
            fn (string $recipient): string => trim($recipient),
            explode(',', $recipients),
        )));
    }

    /** Actualiza en memoria el estado de lectura tras confirmarlo en IMAP. */
    private function markEnvelopeRead(int $accountId, string $folder, int $uid): void
    {
        foreach ($this->envelopes as $index => $envelope) {
            if ((int) $envelope['account_id'] === $accountId
                && (int) $envelope['uid'] === $uid
                && $envelope['folder'] === $folder) {
                $this->envelopes[$index]['is_read'] = true;
            }
        }
    }

    /** Retira de la lista el sobre movido o eliminado en IMAP. */
    private function removeEnvelope(int $uid): void
    {
        $this->envelopes = array_values(array_filter(
            $this->envelopes,
            fn (array $envelope): bool => ! ((int) $envelope['uid'] === $uid && $envelope['folder'] === $this->selectedFolder),
        ));
    }

    /**
     * Completa el sobre remoto con la identidad de cuenta y carpeta seleccionadas.
     *
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    private function normalizeEnvelope(int $accountId, string $folder, array $envelope): array
    {
        return [
            'account_id' => $accountId,
            'folder' => $folder,
            'uid' => (int) ($envelope['uid'] ?? 0),
            'message_id' => $envelope['message_id'] ?? null,
            'references' => $envelope['references'] ?? null,
            'subject' => $envelope['subject'] ?? null,
            'from_email' => $envelope['from_email'] ?? 'unknown@example.com',
            'from_name' => $envelope['from_name'] ?? null,
            'received_at' => $envelope['received_at'] ?? now()->toIso8601String(),
            'is_read' => (bool) ($envelope['is_read'] ?? false),
        ];
    }

    /**
     * Adapta un sobre IMAP transitorio al contrato de las vistas de correo.
     * No copia cuerpos, adjuntos ni datos persistidos del mensaje.
     *
     * @param  array<string, mixed>  $envelope
     */
    private function envelopeView(array $envelope): object
    {
        return (object) [
            'id' => (int) $envelope['uid'],
            'imap_uid' => (string) $envelope['uid'],
            'folder' => $envelope['folder'],
            'message_id' => $envelope['message_id'],
            'subject' => $envelope['subject'],
            'from_email' => $envelope['from_email'],
            'from_name' => $envelope['from_name'],
            'received_at' => Date::parse($envelope['received_at']),
            'is_read' => $envelope['is_read'],
            'status' => null,
            'body_text' => null,
            'body_html' => null,
            'case_id' => null,
            'case' => null,
        ];
    }

    /** Elimina etiquetas y atributos no permitidos antes de mostrar HTML remoto. */
    private function sanitizeHtmlBody(string $html): string
    {
        $sanitized = preg_replace('/<(script|style|iframe|object|embed|form)[^>]*>.*?<\/\1>/is', '', $html) ?? '';
        $sanitized = strip_tags($sanitized, '<p><br><div><span><strong><b><em><i><u><ul><ol><li><blockquote><h1><h2><h3><h4><h5><h6><table><thead><tbody><tr><th><td><hr>');

        return preg_replace('/<([a-z0-9]+)(?:\s[^>]*)?\x3E/i', '<$1>', $sanitized) ?? '';
    }
}; ?>

<x-mail.inbox-layout>
    {{-- Header: page heading --}}
    <x-slot:header>
        <x-page-heading
            :heading="__('Bandeja de entrada')"
            :subheading="__('Revisá, clasificá y sincronizá mensajes de tus cuentas de correo.')"
        />

    </x-slot:header>

    {{-- Filters: IMAP read-state buttons --}}
    <x-slot:filters>
        <div class="flex items-center gap-2 flex-wrap">
            <flux:button
                wire:click="setStatusFilter('all')"
                variant="{{ $statusFilter === 'all' ? 'primary' : 'ghost' }}"
                size="xs"
            >
                {{ __('Todos') }}
            </flux:button>
            <flux:button
                wire:click="setStatusFilter('unread')"
                variant="{{ $statusFilter === 'unread' ? 'primary' : 'ghost' }}"
                size="xs"
            >
                {{ __('No leídos') }}
            </flux:button>
        </div>
    </x-slot:filters>

    {{-- Toolbar: search + perPage --}}
    <x-slot:toolbar>
        <div class="grid min-w-0 grid-cols-1 items-center gap-2 sm:grid-cols-[minmax(0,1fr)_minmax(0,auto)_5.5rem]">
            <div class="min-w-0">
                <flux:input
                    wire:model.live="search"
                    icon="magnifying-glass"
                    :placeholder="__('Buscar por remitente, asunto o contenido...')"
                    class="w-full"
                />
            </div>

            <div class="flex min-w-0 flex-wrap items-center gap-1 sm:flex-nowrap">
                @can('bandeja.sincronizar')
                    @if ($remoteFolders !== [])
                        <flux:select wire:model.live="selectedFolder" size="sm" :aria-label="__('Carpeta IMAP')" class="min-w-0 w-full sm:w-40">
                            @foreach ($remoteFolders as $folder)
                                <flux:select.option value="{{ $folder['path'] }}">{{ $folder['name'] }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:button
                            wire:click="sync"
                            wire:target="sync"
                            size="sm"
                            icon="arrow-path"
                            :aria-label="__('Sincronizar carpeta IMAP seleccionada')"
                        />
                    @endif
                @endcan
            </div>

            <div class="w-full min-w-0 sm:w-[5.5rem]">
                <flux:select
                    wire:model.live="perPage"
                    size="sm"
                    :aria-label="__('Resultados por página')"
                    class="w-full"
                >
                    <flux:select.option value="10">10</flux:select.option>
                    <flux:select.option value="15">15</flux:select.option>
                    <flux:select.option value="25">25</flux:select.option>
                    <flux:select.option value="50">50</flux:select.option>
                </flux:select>
            </div>
        </div>
    </x-slot:toolbar>

    {{-- Message list --}}
    <x-slot:messageList>
        <x-mail.message-list
            :messages="$this->messages"
            :selectedMessageId="$selectedMessageId"
            selectAction="selectMessage"
            :showStatus="false"
            :emptyMessage="$selectedAccountId === null
                ? __('Seleccioná una cuenta para ver mensajes.')
                : ($folderLoadFailed
                    ? __('No se pudieron cargar las carpetas remotas. Podés reintentar desde el botón de recarga.')
                    : (isset($folderSyncErrors[$selectedFolder])
                        ? $folderSyncErrors[$selectedFolder]
                        : ($foldersLoaded && $remoteFolders === []
                            ? __('La cuenta no informó carpetas remotas.')
                            : ($search ? __('No se encontraron resultados.') : __('Esta carpeta no contiene mensajes.')))))"
        />
    </x-slot:messageList>

    {{-- Reader pane --}}
    <x-slot:reader>
        @if ($this->selectedMessage)
            <div
                class="flex-1 min-h-0"
                wire:key="reader-{{ $selectedAccountId }}-{{ $selectedFolder }}-{{ $selectedMessageId }}"
                @if (! $bodyLoaded && ! $bodyLoadFailed)
                    wire:init="loadSelectedMessageBody({{ $selectedAccountId }}, {{ \Illuminate\Support\Js::from($selectedFolder) }}, {{ $selectedMessageId }})"
                @elseif ($bodyLoaded && ! $this->selectedMessage->is_read)
                    wire:init="markMessageRead({{ $selectedAccountId }}, {{ \Illuminate\Support\Js::from($selectedFolder) }}, {{ $selectedMessageId }})"
                @endif
            >
                <x-mail.reader
                    :message="$this->selectedMessage"
                    :body="$this->selectedMessageBody"
                >
                    <x-slot:actions>
                        @can('bandeja.sincronizar')
                            @if ($this->selectedMessage && $this->selectedMessage->imap_uid && $this->selectedMessage->folder)
                                <flux:select wire:model.live="moveTargetFolder" size="sm" :aria-label="__('Mover mensaje a')">
                                    @foreach ($remoteFolders as $folder)
                                        @if ($folder['path'] !== $this->selectedMessage->folder)
                                            <flux:select.option value="{{ $folder['path'] }}">{{ $folder['name'] }}</flux:select.option>
                                        @endif
                                    @endforeach
                                </flux:select>
                                <flux:button
                                    wire:click="moveMessage({{ $this->selectedMessage->id }}, '{{ $moveTargetFolder }}')"
                                    variant="ghost"
                                    size="sm"
                                    icon="folder-arrow-down"
                                >
                                    {{ __('Mover') }}
                                </flux:button>
                                <flux:button
                                    wire:click="deleteMessage({{ $this->selectedMessage->id }})"
                                    variant="danger"
                                    size="sm"
                                    icon="trash"
                                >
                                    {{ __('Papelera') }}
                                </flux:button>
                                @can('bandeja.clasificar')
                                    <flux:button wire:click="openComposer('reply', {{ $this->selectedMessage->id }})" variant="ghost" size="sm" icon="arrow-uturn-left">
                                        {{ __('Responder') }}
                                    </flux:button>
                                    <flux:button wire:click="openComposer('forward', {{ $this->selectedMessage->id }})" variant="ghost" size="sm" icon="arrow-right">
                                        {{ __('Reenviar') }}
                                    </flux:button>
                                @endcan
                            @endif
                        @endcan
                    </x-slot:actions>
                </x-mail.reader>
            </div>
        @else
            <x-mail.reader :message="null" />
        @endif
    </x-slot:reader>

    <flux:modal wire:model.self="composerOpen" class="h-[min(90vh,56rem)] w-[min(96vw,72rem)] min-w-[20rem] max-w-[96vw] resize overflow-auto">
        <form wire:submit="sendComposer" class="flex h-full min-h-[30rem] flex-col gap-4">
            <flux:heading size="lg">{{ $composerMode === 'reply' ? __('Responder') : __('Reenviar') }}</flux:heading>
            <flux:field>
                <flux:label>{{ __('Contacto') }}</flux:label>
                <flux:input wire:model.live.debounce.300ms="composerContactSearch" :placeholder="__('Buscar por nombre o email...')" />
                <flux:select wire:model.live="composerContactId">
                    <flux:select.option value="">{{ __('Ingresar email manualmente') }}</flux:select.option>
                    @foreach ($this->composerContacts as $contact)
                        <flux:select.option wire:key="composer-contact-{{ $contact->id }}" value="{{ $contact->id }}">{{ $contact->name }} ({{ $contact->email }})</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>
            <flux:field>
                <flux:label>{{ __('Para') }}</flux:label>
                <flux:input wire:model="composerTo" type="email" :placeholder="__('correo@ejemplo.com')" />
                <flux:error name="composerTo" />
            </flux:field>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:field>
                    <flux:label>{{ __('CC') }}</flux:label>
                    <flux:input wire:model="composerCc" type="text" :placeholder="__('correo@ejemplo.com, otro@ejemplo.com')" />
                    <flux:error name="composerCc" />
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('CCO') }}</flux:label>
                    <flux:input wire:model="composerBcc" type="text" :placeholder="__('correo@ejemplo.com, otro@ejemplo.com')" />
                    <flux:error name="composerBcc" />
                </flux:field>
            </div>
            <flux:field>
                <flux:label>{{ __('Plantilla') }}</flux:label>
                <div class="flex gap-2">
                    <flux:select wire:model="composerTemplateId" class="min-w-0 flex-1">
                        <flux:select.option value="">{{ __('Sin plantilla') }}</flux:select.option>
                        @foreach ($this->composerTemplates as $template)
                            <flux:select.option wire:key="composer-template-{{ $template->id }}" value="{{ $template->id }}">{{ $template->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:button type="button" wire:click="applyComposerTemplate" wire:target="applyComposerTemplate" :disabled="$composerTemplateId === null" variant="ghost">
                        {{ __('Aplicar') }}
                    </flux:button>
                </div>
                <flux:text size="sm" variant="subtle">{{ __('Aplicar reemplaza el cuerpo sólo cuando lo confirmás.') }}</flux:text>
            </flux:field>
            <flux:field>
                <flux:label>{{ __('Asunto') }}</flux:label>
                <flux:input wire:model="composerSubject" />
                <flux:error name="composerSubject" />
            </flux:field>
            <flux:field>
                <flux:label>{{ __('Mensaje') }}</flux:label>
                <flux:textarea wire:model="composerBody" rows="12" resize="both" class="min-h-48 flex-1" />
                <flux:error name="composerBody" />
            </flux:field>
            <flux:field>
                <flux:label>{{ __('Firma') }}</flux:label>
                <flux:select wire:model="composerSignatureId">
                    <flux:select.option value="">{{ __('Sin firma') }}</flux:select.option>
                    @foreach ($this->composerSignatures as $signature)
                        <flux:select.option wire:key="composer-signature-{{ $signature->id }}" value="{{ $signature->id }}">{{ $signature->name }}{{ $signature->is_default ? ' ('.__('predeterminada').')' : '' }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>
            <div class="mt-auto flex justify-end gap-2">
                <flux:button type="button" wire:click="closeComposer" variant="ghost">{{ __('Cancelar') }}</flux:button>
                <flux:button type="submit" wire:loading.attr="disabled" wire:target="sendComposer" variant="primary" icon="paper-airplane">
                    <span wire:loading.remove wire:target="sendComposer">{{ __('Enviar') }}</span>
                    <span wire:loading wire:target="sendComposer">{{ __('Enviando...') }}</span>
                </flux:button>
            </div>
        </form>
    </flux:modal>
</x-mail.inbox-layout>
