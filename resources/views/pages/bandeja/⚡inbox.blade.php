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
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Bandeja de entrada')] class extends Component {
    use WithPagination;

    public ?int $selectedAccountId = null;
    public ?int $selectedMessageId = null;
    public string $statusFilter = 'all';
    public string $search = '';
    public int $perPage = 15;

    protected ?User $currentUser = null;

    public function mount(): void
    {
        abort_unless(Auth::user()->can('bandeja.ver'), 403);

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

        return MailMessage::where('mail_account_id', $account->id)->find($this->selectedMessageId);
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

    public function selectAccount(int $accountId): void
    {
        $account = $this->getUser()->mailAccounts()->findOrFail($accountId);
        abort_unless($account->is_active, 403);

        $this->selectedAccountId = $accountId;
        $this->selectedMessageId = null;
        $this->statusFilter = 'all';
        $this->search = '';
        $this->resetPage();
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

    private function authorizeAbility(string $ability): void
    {
        abort_unless(Auth::user()->can($ability), 403);
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

<section class="space-y-4">
    <x-page-heading
        :heading="__('Bandeja de entrada')"
        :subheading="__('Revisá, clasificá y sincronizá mensajes de tus cuentas de correo.')"
    />

    {{-- Account selector + sync --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-2 flex-wrap">
            @foreach ($this->activeAccounts as $account)
                <flux:button
                    wire:key="account-{{ $account->id }}"
                    wire:click="selectAccount({{ $account->id }})"
                    variant="{{ $selectedAccountId === $account->id ? 'primary' : 'ghost' }}"
                    size="sm"
                >
                    {{ $account->label ?? $account->email_address }}
                </flux:button>
            @endforeach
        </div>

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
    </div>

    {{-- Status filter --}}
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
                    {{ $statusCase->value }} ({{ $count }})
                </flux:button>
            @endif
        @endforeach
    </div>

    {{-- Two-column webmail layout --}}
    <div class="grid grid-cols-1 gap-4 md:grid-cols-12 md:min-h-[70vh]">
        {{-- Left column: message list --}}
        <div class="flex flex-col md:col-span-5">
            {{-- Toolbar: search + perPage --}}
            <div class="flex flex-col sm:flex-row gap-2 mb-3">
                <flux:input
                    wire:model.live="search"
                    icon="magnifying-glass"
                    :placeholder="__('Buscar por remitente, asunto o contenido...')"
                    class="flex-1"
                />
                <flux:select wire:model.live="perPage" :label="__('Resultados')" size="sm" class="sm:w-28">
                    <flux:select.option value="10">10</flux:select.option>
                    <flux:select.option value="15">15</flux:select.option>
                    <flux:select.option value="25">25</flux:select.option>
                    <flux:select.option value="50">50</flux:select.option>
                </flux:select>
            </div>

            {{-- Message list --}}
            <div class="flex flex-1 flex-col overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 md:min-h-0">
                <div class="flex-1 overflow-y-auto">
                    @forelse ($this->messages as $message)
                        <button
                            wire:key="message-{{ $message->id }}"
                            wire:click="selectMessage({{ $message->id }})"
                            class="w-full text-start px-4 py-3 border-b border-zinc-100 dark:border-zinc-800 hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors {{ $selectedMessageId === $message->id ? 'bg-zinc-100 dark:bg-zinc-800 ring-1 ring-inset ring-zinc-300 dark:ring-zinc-600' : '' }}"
                        >
                            <div class="flex items-center justify-between gap-2">
                                <flux:heading size="sm" class="truncate">{{ $message->from_name ?? $message->from_email }}</flux:heading>
                                <flux:badge size="sm" :color="$this->statusBadgeColor($message->status)">
                                    {{ $this->statusLabel($message->status) }}
                                </flux:badge>
                            </div>
                            <flux:text class="mt-0.5 truncate">{{ $message->subject ?: __('(Sin asunto)') }}</flux:text>
                            <flux:text variant="subtle" size="sm" class="mt-0.5">
                                {{ $message->received_at->format('d/m/Y H:i') }}
                            </flux:text>
                        </button>
                    @empty
                        <div class="px-4 py-8 text-center text-neutral-500">
                            {{ $selectedAccountId === null
                                ? __('Seleccioná una cuenta para ver mensajes.')
                                : ($search ? __('No se encontraron resultados.') : __('No hay mensajes en esta bandeja.')) }}
                        </div>
                    @endforelse
                </div>

                {{-- Pagination --}}
                @if ($this->messages->hasPages())
                    <div class="px-3 py-2 border-t border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/30">
                        {{ $this->messages->links(data: ['scrollTo' => false]) }}
                    </div>
                @endif
            </div>
        </div>

        {{-- Right column: reading pane --}}
        <div class="md:col-span-7 md:min-h-0">
            @if ($this->selectedMessage)
                <div class="flex h-full flex-col rounded-lg border border-zinc-200 dark:border-zinc-700 p-6 space-y-4 md:min-h-0">
                    {{-- Header --}}
                    <div>
                        <flux:heading size="lg">{{ $this->selectedMessage->subject ?: __('(Sin asunto)') }}</flux:heading>
                        <div class="mt-2 flex items-center gap-3 flex-wrap">
                            <flux:text class="font-medium">{{ $this->selectedMessage->from_name ?? $this->selectedMessage->from_email }}</flux:text>
                            <flux:text variant="subtle">&lt;{{ $this->selectedMessage->from_email }}&gt;</flux:text>
                            <flux:badge size="sm" :color="$this->statusBadgeColor($this->selectedMessage->status)">
                                {{ $this->statusLabel($this->selectedMessage->status) }}
                            </flux:badge>
                        </div>
                        <flux:text variant="subtle" size="sm" class="mt-1">
                            {{ $this->selectedMessage->received_at->format('d/m/Y H:i') }}
                        </flux:text>
                    </div>

                    <flux:separator />

                    {{-- Body --}}
                    <div class="prose prose-sm max-w-none flex-1 overflow-y-auto break-words dark:prose-invert">
                        {!! $this->selectedMessageBody !!}
                    </div>

                    <flux:separator />

                    {{-- Actions --}}
                    <div class="flex items-center gap-2 flex-wrap">
                        @can('bandeja.clasificar')
                            @if ($this->selectedMessage->status !== MailMessageStatus::Discarded)
                                <flux:button
                                    wire:click="suggestNewCase({{ $this->selectedMessage->id }})"
                                    variant="primary"
                                    size="sm"
                                    icon="folder-plus"
                                >
                                    {{ __('Sugerir iniciar expediente') }}
                                </flux:button>
                            @endif

                            @if ($this->selectedMessage->status !== MailMessageStatus::Discarded)
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
                    </div>
                </div>
            @else
                <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-8 text-center text-neutral-500 flex items-center justify-center min-h-[400px]">
                    <div>
                        <flux:icon name="inbox" class="mx-auto h-12 w-12 text-neutral-300 dark:text-neutral-600" />
                        <flux:heading size="md" class="mt-3">{{ __('Seleccioná un mensaje para leerlo.') }}</flux:heading>
                    </div>
                </div>
            @endif
        </div>
    </div>
</section>
