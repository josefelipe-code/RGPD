<?php

use App\Enums\MailDirection;
use App\Enums\MailMessageStatus;
use App\Models\Expedient;
use App\Models\MailAccount;
use App\Models\MailMessage;
use App\Models\User;
use App\Services\Bandeja\ImapExpedientBridgeService;
use App\Services\Bandeja\ImapMailboxService;
use App\Services\Bandeja\ImapMessageOperationReservationService;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Bandeja de entrada')] class extends Component
{
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

    public ?int $composerAccountId = null;

    public ?string $composerFolder = null;

    public ?int $composerImapUid = null;

    /** @var array<string, mixed> */
    public array $composerOriginData = [];

    public bool $associationOpen = false;

    public array $associationCandidateIds = [];

    public ?int $associationMessageId = null;

    public bool $createExpedientOpen = false;

    public ?int $createExpedientMessageId = null;

    public string $createExpedientNumber = '';

    public string $createExpedientEmail = '';

    public string $createExpedientPhone = '';

    public string $createExpedientType = '';

    public ?string $operationReservationExpiresAt = null;

    public ?string $operationReservationOperatorName = null;

    protected ?User $currentUser = null;

    /** Livewire inicializa la cuenta, carpetas y sobres al montar la página. */
    public function mount(): void
    {
        abort_unless(Auth::user()->can('bandeja.ver'), 403);

        // Validate query-string account: must be owned by user and active
        if ($this->selectedAccountId !== null) {
            $valid = $this->getUser()->accessibleMailAccounts()
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

    #[On('outbound-mail-sent')]
    /** Refreshes the inbox after the shared composer completes an outbound send. */
    public function refreshAfterOutboundMail(): void
    {
        $this->refreshSelectedFolder();
        $this->closeComposer();
    }

    #[On('outbound-mail-composer-closed')]
    /** Clears the launcher state after the shared composer closes. */
    public function clearOutboundComposer(): void
    {
        $this->closeComposer();
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
        } catch (Throwable) {
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
        } catch (Throwable) {
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

        return $this->getUser()->accessibleMailAccounts()
            ->where('id', $this->selectedAccountId)
            ->where('is_active', true)
            ->first();
    }

    #[Computed]
    /** Computed de cuentas activas que alimenta el selector de la plantilla. */
    public function activeAccounts()
    {
        return $this->getUser()->accessibleMailAccounts()->where('is_active', true)->orderBy('label')->get();
    }

    #[Computed]
    /** Computed que filtra y pagina los sobres mostrados en la lista. */
    public function messages()
    {
        $account = $this->resolveSelectedAccount();
        if ($account === null) {
            return new LengthAwarePaginator([], 0, $this->perPage);
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
        } catch (Throwable) {
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
        $account = $this->getUser()->accessibleMailAccounts()
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
        } catch (Throwable) {
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

        $account = $this->getUser()->accessibleMailAccounts()
            ->where('id', $this->selectedAccountId)
            ->where('is_active', true)
            ->firstOrFail();

        try {
            $this->refreshSelectedFolder();
            Flux::toast(
                variant: 'success',
                text: __(':count mensajes cargados desde IMAP.', ['count' => count($this->envelopes)]),
            );
        } catch (Throwable $e) {
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
        if (blank($targetFolder)) {
            $this->addError('moveTargetFolder', __('Seleccioná una carpeta destino.'));

            return;
        }

        $permittedFolders = $account->expedientStates()->whereNotNull('imap_folder')->pluck('imap_folder');
        abort_unless($permittedFolders->contains($targetFolder), 403);

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
        } catch (Throwable) {
            Flux::toast(variant: 'danger', text: __('No se pudo mover el mensaje en IMAP.'));
        }
    }

    #[Computed]
    public function configuredMoveFolders()
    {
        $account = $this->resolveSelectedAccount();

        return $account === null ? collect() : $account->expedientStates()
            ->whereNotNull('imap_folder')
            ->where('imap_folder', '!=', $this->selectedFolder)
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function associationCandidates()
    {
        return Expedient::query()->whereKey($this->associationCandidateIds)->orderBy('case_number')->get();
    }

    public function openAssociation(int $messageId, ImapExpedientBridgeService $bridge, ImapMessageOperationReservationService $reservationService): void
    {
        abort_unless(Auth::user()->can('bandeja.clasificar'), 403);
        $account = $this->resolveSelectedAccount();
        abort_if($account === null, 403);
        $envelope = $this->findEnvelope($account, $messageId);
        abort_if($envelope === null || $envelope['folder'] !== 'INBOX', 404);
        if (! $this->acquireOperationReservation($reservationService, $account, $envelope)) {
            return;
        }

        $this->associationMessageId = $messageId;
        $this->associationCandidateIds = $bridge->candidates($account, $envelope)->pluck('expedient.id')->all();
        $this->associationOpen = true;
    }

    public function openCreateExpedient(int $messageId, ImapMessageOperationReservationService $reservationService): void
    {
        abort_unless(Auth::user()->can('expedientes.crear'), 403);
        $account = $this->resolveSelectedAccount();
        abort_if($account === null, 403);
        $envelope = $this->findEnvelope($account, $messageId);
        abort_if($envelope === null || $envelope['folder'] !== 'INBOX', 404);
        if (! $this->acquireOperationReservation($reservationService, $account, $envelope)) {
            return;
        }

        $this->createExpedientMessageId = $messageId;
        $this->createExpedientNumber = 'EXP-'.now()->format('YmdHis').'-'.$messageId;
        $this->createExpedientEmail = $envelope['from_email'] ?? '';
        $this->createExpedientPhone = '';
        $this->createExpedientType = $envelope['subject'] ?? '';
        $this->createExpedientOpen = true;
    }

    public function saveCreatedExpedient(ImapExpedientBridgeService $bridge, ImapMessageOperationReservationService $reservationService): void
    {
        abort_unless(Auth::user()->can('expedientes.crear'), 403);
        $account = $this->resolveSelectedAccount();
        abort_if($account === null || $this->createExpedientMessageId === null, 403);
        $envelope = $this->findEnvelope($account, $this->createExpedientMessageId);
        abort_if($envelope === null || $envelope['folder'] !== 'INBOX', 404);
        $reservationService->assertHeldBy($account, $this->getUser(), $envelope['folder'], (int) $envelope['uid']);

        $validator = Validator::make([
            'createExpedientNumber' => $this->createExpedientNumber,
            'createExpedientEmail' => $this->createExpedientEmail,
            'createExpedientPhone' => $this->createExpedientPhone,
            'createExpedientType' => $this->createExpedientType,
        ], [
            'createExpedientNumber' => ['required', 'string', 'max:50', 'unique:cases,case_number'],
            'createExpedientEmail' => ['nullable', 'email', 'max:255'],
            'createExpedientPhone' => ['nullable', 'string', 'max:50'],
            'createExpedientType' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            $this->setErrorBag($validator->errors());

            return;
        }

        $validated = $validator->validated();

        $expedient = Expedient::create([
            'case_number' => $validated['createExpedientNumber'],
            'sender_email' => $validated['createExpedientEmail'] ?: null,
            'sender_phone' => $validated['createExpedientPhone'] ?: null,
            'request_type' => $validated['createExpedientType'] ?: null,
            'mail_account_id' => $account->id,
            'assigned_user_id' => $this->getUser()->id,
        ]);
        $expedient->open($this->getUser());
        $bridge->associate($account, $expedient, $this->getUser(), $envelope);
        MailMessage::create([
            'case_id' => $expedient->id,
            'mail_account_id' => $account->id,
            'message_id' => $envelope['message_id'],
            'imap_uid' => (string) $envelope['uid'],
            'folder' => $envelope['folder'],
            'subject' => $envelope['subject'],
            'from_email' => $envelope['from_email'],
            'from_name' => $envelope['from_name'],
            'received_at' => $envelope['received_at'],
            'direction' => MailDirection::Incoming,
            'status' => MailMessageStatus::Associated,
            'in_reply_to' => $envelope['in_reply_to'] ?? null,
            'references' => is_array($envelope['references'] ?? null)
                ? $envelope['references']
                : preg_split('/\s+/', (string) ($envelope['references'] ?? ''), -1, PREG_SPLIT_NO_EMPTY),
        ]);

        $this->createExpedientOpen = false;

        $this->redirect(route('expedientes.show', $expedient), navigate: true);
    }

    public function confirmAssociation(array $expedientIds, ImapExpedientBridgeService $bridge): void
    {
        abort_unless(Auth::user()->can('bandeja.clasificar'), 403);
        $account = $this->resolveSelectedAccount();
        abort_if($account === null || $this->associationMessageId === null, 403);
        $envelope = $this->findEnvelope($account, $this->associationMessageId);
        abort_if($envelope === null || $envelope['folder'] !== 'INBOX', 404);

        foreach (Expedient::query()->where('mail_account_id', $account->id)->whereKey($expedientIds)->get() as $expedient) {
            $bridge->associate($account, $expedient, $this->getUser(), $envelope);
        }

        $this->associationOpen = false;
        Flux::toast(variant: 'success', text: __('Asociaciones confirmadas.'));
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
        } catch (Throwable) {
            Flux::toast(variant: 'danger', text: __('No se pudo mover el mensaje a la papelera.'));
        }
    }

    /** Opens the shared composer for the selected transient IMAP envelope. */
    public function openComposer(string $mode, int $messageId): void
    {
        abort_unless(Auth::user()->can('bandeja.clasificar'), 403);
        abort_unless(in_array($mode, ['reply', 'forward'], true), 422);

        $account = $this->resolveSelectedAccount();
        abort_if($account === null, 403);
        $envelope = $this->findEnvelope($account, $messageId);
        abort_if($envelope === null, 404);

        $this->selectedMessageId = $messageId;
        $this->composerAccountId = $account->id;
        $this->composerFolder = $envelope['folder'];
        $this->composerImapUid = (int) $envelope['uid'];
        $this->composerMode = $mode;
        $this->composerOriginData = [
            'message_id' => $envelope['message_id'] ?? null,
            'references' => $envelope['references'] ?? null,
            'subject' => $envelope['subject'] ?? null,
            'from_email' => $envelope['from_email'] ?? null,
            'from_name' => $envelope['from_name'] ?? null,
        ];
        $this->composerOpen = true;
    }

    /** Clears the shared composer launcher state. */
    public function closeComposer(): void
    {
        $this->composerOpen = false;
        $this->reset([
            'composerMode',
            'composerAccountId',
            'composerFolder',
            'composerImapUid',
            'composerOriginData',
        ]);
        $this->resetErrorBag();
    }

    /** Discards all transient forms after their fixed reservation expires. */
    public function expireOperationForm(): void
    {
        $this->closeComposer();
        $this->associationOpen = false;
        $this->createExpedientOpen = false;
        $this->reset([
            'associationCandidateIds',
            'associationMessageId',
            'createExpedientMessageId',
            'createExpedientNumber',
            'createExpedientEmail',
            'createExpedientPhone',
            'createExpedientType',
            'operationReservationExpiresAt',
            'operationReservationOperatorName',
        ]);
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

    /**
     * Acquire the fixed reservation used by the currently opened message operation.
     *
     * @param  array<string, mixed>  $envelope
     */
    private function acquireOperationReservation(ImapMessageOperationReservationService $reservationService, MailAccount $account, array $envelope): bool
    {
        try {
            $reservation = $reservationService->acquire($account, $this->getUser(), $envelope['folder'], (int) $envelope['uid']);
        } catch (AuthorizationException $exception) {
            Flux::toast(variant: 'danger', text: __($exception->getMessage()));

            return false;
        }

        $this->operationReservationExpiresAt = $reservation->expires_at->toIso8601String();
        $this->operationReservationOperatorName = $reservation->operator()->value('name') ?? $this->getUser()->name;

        return true;
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
                                    <flux:select.option value="">{{ __('Seleccionar estado...') }}</flux:select.option>
                                    @foreach ($this->configuredMoveFolders as $state)
                                        <flux:select.option value="{{ $state->imap_folder }}">{{ $state->name }}</flux:select.option>
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
                                    @if ($this->selectedMessage->folder === 'INBOX')
                                        <flux:button wire:click="openCreateExpedient({{ $this->selectedMessage->id }})" variant="ghost" size="sm" icon="plus">
                                            {{ __('Crear expediente') }}
                                        </flux:button>
                                        <flux:button wire:click="openAssociation({{ $this->selectedMessage->id }})" variant="ghost" size="sm" icon="link">
                                            {{ __('Asociar expediente') }}
                                        </flux:button>
                                    @endif
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

    @if ($composerOpen && $composerAccountId !== null && $composerFolder !== null && $composerImapUid !== null && $composerMode !== null)
        @livewire('pages::bandeja.mail-composer', [
            'accountId' => $composerAccountId,
            'folder' => $composerFolder,
            'imapUid' => $composerImapUid,
            'mode' => $composerMode,
            'originData' => $composerOriginData,
        ], key('outbound-mail-composer-'.$composerAccountId.'-'.$composerFolder.'-'.$composerImapUid.'-'.$composerMode))
    @endif

    <flux:modal wire:model="associationOpen" class="w-full md:w-[34rem]">
        <div
            x-data="{ expiresAt: Date.parse(@js($operationReservationExpiresAt)), remaining: 0, expired: false, interval: null, update() { this.remaining = Math.max(0, Math.ceil((this.expiresAt - Date.now()) / 1000)); if (this.remaining === 0 && ! this.expired) { this.expired = true; clearInterval(this.interval); $wire.expireOperationForm(); } }, init() { this.update(); this.interval = setInterval(() => this.update(), 1000); }, destroy() { clearInterval(this.interval); } }"
        >
            <flux:heading size="lg">{{ __('Asociar expediente') }}</flux:heading>
            <flux:text variant="subtle" size="sm">{{ __('Gestionado por') }} {{ $operationReservationOperatorName }} · {{ __('Tiempo restante') }}: <span x-text="remaining"></span>s</flux:text>
            <flux:text variant="subtle" class="mb-4">{{ __('Seleccioná explícitamente uno o más expedientes sugeridos.') }}</flux:text>
            <div class="space-y-2">
                @foreach ($this->associationCandidates as $candidate)
                    <flux:checkbox wire:model="associationCandidateIds" value="{{ $candidate->id }}" label="{{ $candidate->case_number }}" />
                @endforeach
            </div>
            <div class="mt-4 flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('associationOpen', false)">{{ __('Cancelar') }}</flux:button>
                <flux:button variant="primary" x-bind:disabled="expired" wire:click="confirmAssociation({{ \Illuminate\Support\Js::from($associationCandidateIds) }})">{{ __('Confirmar') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model="createExpedientOpen" class="w-full md:w-[34rem]">
        <form
            wire:submit="saveCreatedExpedient"
            class="space-y-4"
            x-data="{ expiresAt: Date.parse(@js($operationReservationExpiresAt)), remaining: 0, expired: false, interval: null, update() { this.remaining = Math.max(0, Math.ceil((this.expiresAt - Date.now()) / 1000)); if (this.remaining === 0 && ! this.expired) { this.expired = true; clearInterval(this.interval); $wire.expireOperationForm(); } }, init() { this.update(); this.interval = setInterval(() => this.update(), 1000); }, destroy() { clearInterval(this.interval); } }"
        >
            <flux:heading size="lg">{{ __('Crear expediente desde el correo') }}</flux:heading>
            <flux:text variant="subtle" size="sm">{{ __('Gestionado por') }} {{ $operationReservationOperatorName }} · {{ __('Tiempo restante') }}: <span x-text="remaining"></span>s</flux:text>
            <flux:text variant="subtle">{{ __('Revisá los datos antes de crear y confirmar la asociación con este correo de INBOX.') }}</flux:text>
            <flux:input wire:model="createExpedientNumber" :label="__('Número de expediente')" />
            <flux:input wire:model="createExpedientEmail" type="email" :label="__('Email del solicitante')" />
            <flux:input wire:model="createExpedientPhone" :label="__('Teléfono del solicitante')" />
            <flux:input wire:model="createExpedientType" :label="__('Tipo de solicitud')" />
            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="$set('createExpedientOpen', false)">{{ __('Cancelar') }}</flux:button>
                <flux:button type="submit" x-bind:disabled="expired" variant="primary">{{ __('Crear y asociar') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</x-mail.inbox-layout>
