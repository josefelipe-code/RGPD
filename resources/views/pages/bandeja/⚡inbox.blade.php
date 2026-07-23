<?php

use App\Enums\MailMessageStatus;
use App\Models\Expedient;
use App\Models\MailAccount;
use App\Models\MailMessage;
use App\Models\User;
use App\Services\Bandeja\ImapSyncService;
use App\Services\Bandeja\ImapMailboxService;
use App\Services\Bandeja\InboundSuggestionService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
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
    public ?string $loadedBodyHtml = null;
    public ?string $loadedBodyText = null;

    protected ?User $currentUser = null;

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
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function updatedSelectedFolder(string $folder): void
    {
        abort_unless(collect($this->remoteFolders)->contains('path', $folder), 403);

        $this->selectedFolder = $folder;
        $this->selectedMessageId = null;
        $this->moveTargetFolder = '';
        $this->loadedBodyHtml = null;
        $this->loadedBodyText = null;
        $this->resetPage();
    }

    public function loadFolders(): void
    {
        abort_unless(Auth::user()->can('bandeja.sincronizar'), 403);

        $account = $this->resolveSelectedAccount();
        abort_if($account === null, 403);

        $this->remoteFolders = app(ImapMailboxService::class)
            ->listFolders($account)
            ->map(fn (array $folder): array => [
                'path' => $folder['path'],
                'name' => $folder['name'],
            ])
            ->all();
    }

    protected function getUser(): User
    {
        return $this->currentUser ??= Auth::user();
    }

    /**
     * Resolve the selected account, validating ownership and active status.
     * Returns null if no valid account is selected.
     */
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
    public function activeAccounts()
    {
        return $this->getUser()->mailAccounts()->where('is_active', true)->orderBy('label')->get();
    }

    #[Computed]
    public function messages()
    {
        $account = $this->resolveSelectedAccount();
        if ($account === null) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $this->perPage);
        }

        $query = MailMessage::query()
            ->where('mail_account_id', $account->id)
            ->with('case')
            ->where(function ($q) {
                if ($this->selectedFolder === 'INBOX') {
                    $q->where('folder', 'INBOX')->orWhereNull('folder');
                    return;
                }

                $q->where('folder', $this->selectedFolder);
            })
            ->when($this->search, function ($q) {
                $q->where(function ($searchQuery) {
                    $searchQuery->where('from_name', 'like', "%{$this->search}%")
                        ->orWhere('from_email', 'like', "%{$this->search}%")
                        ->orWhere('subject', 'like', "%{$this->search}%")
                        ->orWhere('body_text', 'like', "%{$this->search}%");
                });
            })
            ->when($this->statusFilter !== 'all', fn ($q) => $q->where('status', $this->statusFilter))
            ->orderByDesc('received_at');

        return $query->paginate($this->perPage);
    }

    #[Computed]
    public function selectedMessage(): ?MailMessage
    {
        if ($this->selectedMessageId === null) {
            return null;
        }

        $account = $this->resolveSelectedAccount();
        if ($account === null) {
            return null;
        }

        $message = MailMessage::where('mail_account_id', $account->id)->find($this->selectedMessageId);

        if ($message === null) {
            return null;
        }

        // If filters are active, verify the message is still in the visible set.
        // Without this, the reader could show a message that no longer appears in the list.
        if ($this->search !== '' || $this->statusFilter !== 'all') {
            $isVisible = $this->messages->getCollection()->contains(fn ($m) => $m->id === $message->id);
            if (! $isVisible) {
                return null;
            }
        }

        return $message;
    }

    #[Computed]
    public function selectedMessageBody(): HtmlString
    {
        $message = $this->selectedMessage;

        if ($message === null) {
            return new HtmlString('');
        }

        if (filled($this->loadedBodyHtml)) {
            return new HtmlString($this->sanitizeHtmlBody($this->loadedBodyHtml));
        }

        if (filled($this->loadedBodyText)) {
            return new HtmlString(nl2br(e($this->loadedBodyText)));
        }

        // Keep legacy stored bodies as a read-only fallback for pre-IMAP records.
        if (filled($message->body_html)) {
            return new HtmlString($this->sanitizeHtmlBody($message->body_html));
        }

        if (filled($message->body_text)) {
            return new HtmlString(nl2br(e($message->body_text)));
        }

        return new HtmlString('<p>'.e(__('Sin contenido.')).'</p>');
    }

    /**
     * Compute suggestion candidates for the selected message.
     * Only for unassociated, non-discarded incoming messages.
     *
     * @return \Illuminate\Support\Collection<int, array{expedient: Expedient, confidence: string, reason: string}>
     */
    #[Computed]
    public function suggestions()
    {
        $message = $this->selectedMessage;

        if ($message === null) {
            return collect();
        }

        // Only show suggestions for unassociated, non-discarded incoming messages
        if ($message->case_id !== null || $message->status === MailMessageStatus::Discarded) {
            return collect();
        }

        return app(InboundSuggestionService::class)->suggest($message);
    }

    /**
     * Associate a mail message to an expedient (user-confirmed link).
     */
    public function associateMessage(int $messageId, int $expedientId): void
    {
        abort_unless(Auth::user()->can('bandeja.clasificar'), 403);

        $account = $this->resolveSelectedAccount();
        abort_if($account === null, 403);

        $message = MailMessage::where('mail_account_id', $account->id)->findOrFail($messageId);

        // Verify the expedient belongs to the same mail account and is owned by the user
        $expedient = Expedient::where('mail_account_id', $account->id)
            ->where('id', $expedientId)
            ->first();

        abort_if($expedient === null, 403);

        $message->update([
            'case_id' => $expedient->id,
            'status' => MailMessageStatus::Associated,
        ]);

        if ($this->selectedMessageId === $messageId) {
            $this->selectedMessageId = null;
        }

        Flux::toast(
            variant: 'success',
            text: __('Mensaje asociado al expediente :number.', ['number' => $expedient->case_number]),
        );
    }

    #[Computed]
    public function statusLabels(): array
    {
        return collect(MailMessageStatus::cases())
            ->mapWithKeys(fn ($s) => [$s->value => $this->statusLabel($s)])
            ->all();
    }

    #[Computed]
    public function statusColors(): array
    {
        return collect(MailMessageStatus::cases())
            ->mapWithKeys(fn ($s) => [$s->value => $this->statusBadgeColor($s)])
            ->all();
    }

    #[Computed]
    public function statusCounts(): array
    {
        $account = $this->resolveSelectedAccount();
        if ($account === null) {
            return [];
        }

        return MailMessage::query()
            ->where('mail_account_id', $account->id)
            ->where(function ($q) {
                if ($this->selectedFolder === 'INBOX') {
                    $q->where('folder', 'INBOX')->orWhereNull('folder');
                    return;
                }

                $q->where('folder', $this->selectedFolder);
            })
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    public function selectMessage(int $messageId): void
    {
        $account = $this->resolveSelectedAccount();
        abort_if($account === null, 403);

        $message = MailMessage::where('mail_account_id', $account->id)->findOrFail($messageId);
        $this->selectedMessageId = $messageId;
        $this->loadedBodyHtml = null;
        $this->loadedBodyText = null;

        if (filled($message->imap_uid) && filled($message->folder)) {
            $mailbox = app(ImapMailboxService::class);

            try {
                $content = $mailbox->fetchMessage(
                    $account,
                    $message->folder,
                    (int) $message->imap_uid,
                );
                $this->loadedBodyHtml = $content['html'];
                $this->loadedBodyText = $content['text'];
            } catch (\Throwable $e) {
                $this->loadedBodyHtml = null;
                $this->loadedBodyText = null;
                Flux::toast(
                    variant: 'danger',
                    text: __('No se pudo cargar el mensaje desde IMAP.'),
                );

                return;
            }

            try {
                if ($mailbox->setRead($account, $message->folder, (int) $message->imap_uid) === true) {
                    $message->update(['is_read' => true]);
                } else {
                    Flux::toast(
                        variant: 'danger',
                        text: __('No se pudo actualizar el estado de lectura en IMAP.'),
                    );
                }
            } catch (\Throwable $e) {
                Flux::toast(
                    variant: 'danger',
                    text: __('No se pudo actualizar el estado de lectura en IMAP.'),
                );
            }
        }
    }

    public function sync(): void
    {
        abort_unless(Auth::user()->can('bandeja.sincronizar'), 403);

        $account = MailAccount::where('user_id', $this->getUser()->id)
            ->where('id', $this->selectedAccountId)
            ->where('is_active', true)
            ->firstOrFail();

        $syncService = app(ImapSyncService::class);

        try {
            $messages = $this->selectedFolder === 'INBOX'
                ? $syncService->syncAccount($account)
                : $syncService->syncAccount($account, $this->selectedFolder);
            Flux::toast(
                variant: 'success',
                text: __(':count mensajes sincronizados.', ['count' => $messages->count()]),
            );
        } catch (\RuntimeException $e) {
            Flux::toast(
                variant: 'danger',
                text: $e->getMessage(),
            );
        }
    }

    public function discard(int $messageId): void
    {
        abort_unless(Auth::user()->can('bandeja.clasificar'), 403);

        $account = $this->resolveSelectedAccount();
        abort_if($account === null, 403);

        $message = MailMessage::where('mail_account_id', $account->id)->findOrFail($messageId);
        $message->update(['status' => MailMessageStatus::Discarded]);

        if ($this->selectedMessageId === $messageId) {
            $this->selectedMessageId = null;
        }

        Flux::toast(
            variant: 'warning',
            text: __('Mensaje descartado.'),
        );
    }

    public function moveMessage(int $messageId, string $targetFolder): void
    {
        abort_unless(Auth::user()->can('bandeja.sincronizar'), 403);

        $account = $this->resolveSelectedAccount();
        abort_if($account === null, 403);
        abort_unless(collect($this->remoteFolders)->contains('path', $targetFolder), 403);

        $message = MailMessage::where('mail_account_id', $account->id)->findOrFail($messageId);
        abort_if(blank($message->folder) || blank($message->imap_uid), 422);

        try {
            $reference = app(ImapMailboxService::class)->moveMessage(
                $account,
                $message->folder,
                (int) $message->imap_uid,
                $targetFolder,
            );

            $message->update([
                'folder' => $reference['folder'],
                'imap_uid' => $reference['uid'] ?? $message->imap_uid,
            ]);

            $this->clearSelectedMessage($messageId);
            Flux::toast(variant: 'success', text: __('Mensaje movido a :folder.', ['folder' => $targetFolder]));
        } catch (\Throwable) {
            Flux::toast(variant: 'danger', text: __('No se pudo mover el mensaje en IMAP.'));
        }
    }

    public function deleteMessage(int $messageId): void
    {
        abort_unless(Auth::user()->can('bandeja.sincronizar'), 403);

        $account = $this->resolveSelectedAccount();
        abort_if($account === null, 403);

        $message = MailMessage::where('mail_account_id', $account->id)->findOrFail($messageId);
        abort_if(blank($message->folder) || blank($message->imap_uid), 422);

        try {
            $reference = app(ImapMailboxService::class)->deleteMessage(
                $account,
                $message->folder,
                (int) $message->imap_uid,
            );

            $message->update([
                'folder' => $reference['folder'],
                'imap_uid' => $reference['uid'] ?? $message->imap_uid,
            ]);

            $this->clearSelectedMessage($messageId);
            Flux::toast(variant: 'success', text: __('Mensaje movido a la papelera.'));
        } catch (\Throwable) {
            Flux::toast(variant: 'danger', text: __('No se pudo mover el mensaje a la papelera.'));
        }
    }

    public function suggestNewCase(int $messageId): void
    {
        abort_unless(Auth::user()->can('bandeja.clasificar'), 403);

        $account = $this->resolveSelectedAccount();
        abort_if($account === null, 403);

        $message = MailMessage::where('mail_account_id', $account->id)->findOrFail($messageId);

        // Bridge action: mark as pending review so a human can later create the case
        $message->update(['status' => MailMessageStatus::PendingReview]);

        Flux::toast(
            variant: 'info',
            text: __('Sugerencia registrada: se recomienda iniciar expediente para este mensaje.'),
        );
    }

    /**
     * Create a real expediente from the selected inbox message.
     * Prefills sender email, parsed phone, mail account, and subject mapping.
     * Associates the message to the new expedient and opens it.
     */
    public function createExpedientFromMessage(int $messageId): void
    {
        abort_unless(Auth::user()->can('expedientes.crear'), 403);

        $account = $this->resolveSelectedAccount();
        abort_if($account === null, 403);

        $message = MailMessage::where('mail_account_id', $account->id)->findOrFail($messageId);

        // Parse phone from message body
        $phoneParser = app(\App\Services\Bandeja\PhoneParserService::class);
        $senderPhone = $phoneParser->parse($message->body_text ?? $message->body_html ?? '');

        // Generate a unique case number
        $caseNumber = 'EXP-'.now()->format('YmdHis').'-'.str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);

        // Map subject to request type (truncate if too long)
        $requestType = $message->subject ? mb_substr($message->subject, 0, 255) : null;

        // Create the expedient with prefilled data
        $expedient = Expedient::create([
            'case_number' => $caseNumber,
            'sender_email' => $message->from_email,
            'sender_phone' => $senderPhone,
            'mail_account_id' => $account->id,
            'assigned_user_id' => $this->getUser()->id,
            'status' => \App\Enums\CaseStatus::PendingClient,
            'request_type' => $requestType,
        ]);

        // Open the expedient (stamps opened_at + creates Opened milestone)
        $expedient->open($this->getUser());

        // Associate the message to the new expedient
        $message->update([
            'case_id' => $expedient->id,
            'status' => MailMessageStatus::Associated,
        ]);

        // Clear the selected message so the reader resets
        if ($this->selectedMessageId === $messageId) {
            $this->selectedMessageId = null;
        }

        Flux::toast(
            variant: 'success',
            text: __('Expediente :number creado desde el mensaje.', ['number' => $expedient->case_number]),
        );
    }

    public function setStatusFilter(string $status): void
    {
        $this->statusFilter = $status;
    }

    private function clearSelectedMessage(int $messageId): void
    {
        if ($this->selectedMessageId === $messageId) {
            $this->selectedMessageId = null;
            $this->moveTargetFolder = '';
            $this->loadedBodyHtml = null;
            $this->loadedBodyText = null;
        }
    }

    private function statusLabel(MailMessageStatus $status): string
    {
        return match ($status) {
            MailMessageStatus::New => __('Nuevo'),
            MailMessageStatus::Associated => __('Asociado a expediente'),
            MailMessageStatus::RepliedClient => __('Respondido por cliente'),
            MailMessageStatus::RepliedProvider => __('Respondido por proveedor'),
            MailMessageStatus::PendingReview => __('Pendiente de revisión'),
            MailMessageStatus::Discarded => __('Descartado'),
        };
    }

    private function statusBadgeColor(MailMessageStatus $status): string
    {
        return match ($status) {
            MailMessageStatus::New => 'cyan',
            MailMessageStatus::Associated => 'green',
            MailMessageStatus::RepliedClient => 'amber',
            MailMessageStatus::RepliedProvider => 'blue',
            MailMessageStatus::PendingReview => 'orange',
            MailMessageStatus::Discarded => 'zinc',
        };
    }

    private function sanitizeHtmlBody(string $html): string
    {
        $sanitized = preg_replace('/<(script|style|iframe|object|embed|form)[^>]*>.*?<\/\1>/is', '', $html) ?? '';
        $sanitized = strip_tags($sanitized, '<p><br><div><span><strong><b><em><i><u><ul><ol><li><blockquote><h1><h2><h3><h4><h5><h6><table><thead><tbody><tr><th><td><hr>');

        return preg_replace('/<([a-z0-9]+)(?:\s[^>]*)?\x3E/i', '<$1>', $sanitized) ?? '';
    }
}; ?>

<x-mail.inbox-layout>
    {{-- Header: page heading + sync --}}
    <x-slot:header>
        <x-page-heading
            :heading="__('Bandeja de entrada')"
            :subheading="__('Revisá, clasificá y sincronizá mensajes de tus cuentas de correo.')"
        />

        @can('bandeja.sincronizar')
            <flux:button
                wire:click="sync"
                wire:target="sync"
                variant="primary"
                size="sm"
                icon="arrow-path"
            >
                {{ __('Sincronizar') }}
            </flux:button>
        @endcan
    </x-slot:header>

    {{-- Filters: status buttons (rendered inside left pane above toolbar) --}}
    <x-slot:filters>
        <div class="flex items-center gap-2 flex-wrap">
            <flux:button
                wire:click="setStatusFilter('all')"
                variant="{{ $statusFilter === 'all' ? 'primary' : 'ghost' }}"
                size="xs"
            >
                {{ __('Todos') }}
            </flux:button>
            @foreach (MailMessageStatus::cases() as $statusCase)
                @php
                    $count = $this->statusCounts[$statusCase->value] ?? 0;
                @endphp
                @if ($count > 0 || $statusFilter === $statusCase->value)
                    <flux:button
                        wire:key="status-{{ $statusCase->value }}"
                        wire:click="setStatusFilter('{{ $statusCase->value }}')"
                        variant="{{ $statusFilter === $statusCase->value ? 'primary' : 'ghost' }}"
                        size="xs"
                    >
                        {{ $this->statusLabel($statusCase) }} ({{ $count }})
                    </flux:button>
                @endif
            @endforeach
        </div>
    </x-slot:filters>

    {{-- Toolbar: search + perPage --}}
    <x-slot:toolbar>
        <div class="grid grid-cols-[minmax(0,1fr)_minmax(140px,auto)_88px] items-center gap-2">
            <div class="min-w-0">
                <flux:input
                    wire:model.live="search"
                    icon="magnifying-glass"
                    :placeholder="__('Buscar por remitente, asunto o contenido...')"
                    class="w-full"
                />
            </div>

            <div class="flex items-center gap-1">
                @can('bandeja.sincronizar')
                    <flux:button wire:click="loadFolders" size="sm" icon="arrow-path" :aria-label="__('Cargar carpetas IMAP')" />
                    @if ($remoteFolders !== [])
                        <flux:select wire:model.live="selectedFolder" size="sm" :aria-label="__('Carpeta IMAP')">
                            @foreach ($remoteFolders as $folder)
                                <flux:select.option value="{{ $folder['path'] }}">{{ $folder['name'] }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif
                @endcan
            </div>

            <div class="w-[88px] shrink-0">
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
            :statusLabels="$this->statusLabels"
            :statusColors="$this->statusColors"
            :emptyMessage="$selectedAccountId === null
                ? __('Seleccioná una cuenta para ver mensajes.')
                : ($search ? __('No se encontraron resultados.') : null)"
        />
    </x-slot:messageList>

    {{-- Reader pane --}}
    <x-slot:reader>
        <x-mail.reader
            :message="$this->selectedMessage"
            :body="$this->selectedMessageBody"
            :statusLabels="$this->statusLabels"
            :statusColors="$this->statusColors"
        >
            <x-slot:actions>
                @can('bandeja.clasificar')
                    @if ($this->selectedMessage && $this->selectedMessage->status !== MailMessageStatus::Discarded && $this->selectedMessage->case_id === null)
                        {{-- Suggestion candidates from InboundSuggestionService --}}
                        @php
                            $suggestions = $this->suggestions;
                        @endphp

                        @if ($suggestions->isNotEmpty())
                            <div class="w-full space-y-2">
                                <flux:heading size="xs" class="text-zinc-500 dark:text-zinc-400">
                                    {{ __('Expedientes sugeridos') }}
                                </flux:heading>

                                @foreach ($suggestions as $suggestion)
                                    <div class="flex items-center justify-between gap-2 rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50 px-3 py-2">
                                        <div class="min-w-0">
                                            <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                                {{ $suggestion['expedient']->case_number }}
                                            </span>
                                            <span class="ml-2 text-xs text-zinc-500 dark:text-zinc-400">
                                                {{ $suggestion['reason'] }}
                                            </span>
                                        </div>

                                        <flux:button
                                            wire:click="associateMessage({{ $this->selectedMessage->id }}, {{ $suggestion['expedient']->id }})"
                                            variant="primary"
                                            size="xs"
                                            icon="link"
                                        >
                                            {{ __('Asociar') }}
                                        </flux:button>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            {{-- No matching expedientes — offer create-new --}}
                            <flux:button
                                wire:click="createExpedientFromMessage({{ $this->selectedMessage->id }})"
                                variant="primary"
                                size="sm"
                                icon="folder-plus"
                            >
                                {{ __('Crear expediente') }}
                            </flux:button>
                        @endif

                        <flux:button
                            wire:click="discard({{ $this->selectedMessage->id }})"
                            variant="danger"
                            size="sm"
                            icon="trash"
                        >
                            {{ __('Descartar') }}
                        </flux:button>
                    @endif
                @endcan

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
                    @endif
                @endcan
            </x-slot:actions>
        </x-mail.reader>
    </x-slot:reader>
</x-mail.inbox-layout>
