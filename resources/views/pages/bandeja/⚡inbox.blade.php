<?php

use App\Enums\MailMessageStatus;
use App\Models\MailAccount;
use App\Models\MailMessage;
use App\Models\User;
use App\Services\Bandeja\ImapSyncService;
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
            ->when($this->search, function ($q) {
                $q->where('from_name', 'like', "%{$this->search}%")
                    ->orWhere('from_email', 'like', "%{$this->search}%")
                    ->orWhere('subject', 'like', "%{$this->search}%")
                    ->orWhere('body_text', 'like', "%{$this->search}%");
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

        if (filled($message->body_html)) {
            return new HtmlString($this->sanitizeHtmlBody($message->body_html));
        }

        if (filled($message->body_text)) {
            return new HtmlString(nl2br(e($message->body_text)));
        }

        return new HtmlString('<p>'.e(__('Sin contenido.')).'</p>');
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
            $messages = $syncService->syncAccount($account);
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

    public function setStatusFilter(string $status): void
    {
        $this->statusFilter = $status;
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
        <div class="grid grid-cols-[minmax(0,1fr)_88px] items-center gap-2">
            <div class="min-w-0">
                <flux:input
                    wire:model.live="search"
                    icon="magnifying-glass"
                    :placeholder="__('Buscar por remitente, asunto o contenido...')"
                    class="w-full"
                />
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
                    @if ($this->selectedMessage && $this->selectedMessage->status !== MailMessageStatus::Discarded)
                        <flux:button
                            wire:click="suggestNewCase({{ $this->selectedMessage->id }})"
                            variant="primary"
                            size="sm"
                            icon="folder-plus"
                        >
                            {{ __('Sugerir iniciar expediente') }}
                        </flux:button>

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
            </x-slot:actions>
        </x-mail.reader>
    </x-slot:reader>
</x-mail.inbox-layout>
