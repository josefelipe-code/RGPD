<?php

use App\Models\Expedient;
use App\Models\MailAccount;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Expedientes')] class extends Component {
    use WithPagination;

    public string $search = '';
    public int $perPage = 10;
    public string $statusFilter = 'all';
    public int $mailAccountIdFilter = 0;

    // Form state
    public ?int $editingExpedientId = null;
    public ?int $pendingDeleteId = null;
    public string $caseNumber = '';
    public string $senderEmail = '';
    public string $senderPhone = '';
    public ?int $mailAccountId = null;
    public ?int $assignedUserId = null;
    public ?string $requestType = '';

    /** Livewire inicializa filtros y datos del formulario de expedientes. */
    public function mount(): void
    {
        abort_unless(Auth::user()->can('expedientes.ver'), 403);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'caseNumber' => ['required', 'string', 'max:50', Rule::unique('cases', 'case_number')->ignore($this->editingExpedientId)],
            'senderEmail' => ['nullable', 'email', 'max:255'],
            'senderPhone' => ['nullable', 'string', 'max:50'],
            'mailAccountId' => ['required', 'integer', 'exists:mail_accounts,id'],
            'assignedUserId' => ['required', 'integer', 'exists:users,id'],
            'requestType' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** Livewire reinicia la paginación al cambiar la búsqueda. */
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /** Livewire reinicia la paginación al cambiar el estado filtrado. */
    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    /** Livewire reinicia la paginación al cambiar la cuenta filtrada. */
    public function updatedMailAccountIdFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    /** Computed que filtra y pagina expedientes visibles. */
    public function expedients()
    {
        /** @var User $user */
        $user = Auth::user();

        $query = Expedient::query()
            ->with(['assignedUser', 'mailAccount'])
            ->when($this->mailAccountIdFilter, fn ($q) => $q->forMailAccount($this->mailAccountIdFilter))
            ->when($this->search, fn ($q) => $q
                ->where('case_number', 'like', "%{$this->search}%")
                ->orWhere('sender_email', 'like', "%{$this->search}%")
                ->orWhere('request_type', 'like', "%{$this->search}%"))
            ->when($this->statusFilter !== 'all', fn ($q) => $q->where('status', $this->statusFilter))
            ->orderByDesc('opened_at');

        return $query->paginate($this->perPage);
    }

    #[Computed]
    /** Computed que calcula contadores de estado para los filtros. */
    public function statusCounts(): array
    {
        return Expedient::query()
            ->when($this->mailAccountIdFilter, fn ($q) => $q->forMailAccount($this->mailAccountIdFilter))
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    #[Computed]
    /** Computed que lista cuentas activas disponibles para filtrar o asociar. */
    public function mailAccounts()
    {
        return MailAccount::query()->where('is_active', true)->orderBy('label')->get();
    }

    #[Computed]
    /** Computed que lista usuarios disponibles para asignación. */
    public function availableUsers()
    {
        return User::query()->orderBy('name')->get();
    }

    /** Acción `wire:click` que abre el alta de un expediente. */
    public function create(): void
    {
        $this->authorizeAbility('expedientes.crear');
        $this->resetForm();
    }

    /** Acción `wire:click` que carga un expediente para edición. */
    public function edit(int $expedientId): void
    {
        $this->authorizeAbility('expedientes.actualizar');

        $expedient = Expedient::query()->with(['assignedUser', 'mailAccount'])->findOrFail($expedientId);

        $this->editingExpedientId = $expedient->id;
        $this->caseNumber = $expedient->case_number;
        $this->senderEmail = $expedient->sender_email ?? '';
        $this->senderPhone = $expedient->sender_phone ?? '';
        $this->mailAccountId = $expedient->mail_account_id;
        $this->assignedUserId = $expedient->assigned_user_id;
        $this->requestType = $expedient->request_type ?? '';

        $this->resetErrorBag();
    }

    /** Acción `wire:submit` que valida y guarda el expediente. */
    public function save(): void
    {
        $isCreating = $this->editingExpedientId === null;

        $this->authorizeAbility($isCreating ? 'expedientes.crear' : 'expedientes.actualizar');

        $validated = $this->validate();

        $expedient = $isCreating
            ? Expedient::create([
                'case_number' => $validated['caseNumber'],
                'sender_email' => $validated['senderEmail'] ?: null,
                'sender_phone' => $validated['senderPhone'] ?: null,
                'mail_account_id' => $validated['mailAccountId'],
                'assigned_user_id' => $validated['assignedUserId'],
                'status' => \App\Enums\CaseStatus::PendingClient,
                'request_type' => $validated['requestType'] ?: null,
            ])
            : tap(Expedient::query()->findOrFail($this->editingExpedientId), function (Expedient $expedient) use ($validated): void {
                $expedient->update([
                    'case_number' => $validated['caseNumber'],
                    'sender_email' => $validated['senderEmail'] ?: null,
                    'sender_phone' => $validated['senderPhone'] ?: null,
                    'mail_account_id' => $validated['mailAccountId'],
                    'assigned_user_id' => $validated['assignedUserId'],
                    'request_type' => $validated['requestType'] ?: null,
                ]);

            });

        if ($isCreating) {
            $expedient->open(Auth::user());
        }

        $this->resetForm();

        Flux::modal('expedient-form')->close();

        Flux::toast(variant: 'success', text: $isCreating
            ? __('Expediente creado.')
            : __('Expediente actualizado.'));
    }

    /** Acción `wire:click` que cancela y limpia la edición. */
    public function cancel(): void
    {
        $this->resetForm();
        Flux::modal('expedient-form')->close();
    }

    /** Acción `wire:click` que elimina un expediente autorizado. */
    public function delete(int $expedientId): void
    {
        $this->authorizeAbility('expedientes.eliminar');

        $expedient = Expedient::query()->findOrFail($expedientId);
        $expedient->delete();

        $this->pendingDeleteId = null;
        Flux::modal('delete-confirm')->close();

        Flux::toast(variant: 'success', text: __('Expediente eliminado.'));
    }

    /** Acción `wire:click` que prepara la confirmación de eliminación. */
    public function confirmDelete(int $expedientId): void
    {
        $this->pendingDeleteId = $expedientId;
    }

    /** Comprueba el permiso requerido por las operaciones de expedientes. */
    private function authorizeAbility(string $ability): void
    {
        abort_unless(Auth::user()->can($ability), 403);
    }

    /** Restablece el formulario y el expediente en edición. */
    private function resetForm(): void
    {
        $this->reset([
            'editingExpedientId',
            'caseNumber',
            'senderEmail',
            'senderPhone',
            'mailAccountId',
            'assignedUserId',
            'requestType',
        ]);

        $this->resetErrorBag();
    }

    /** Traduce el estado del expediente para la tabla y los filtros. */
    private function statusLabel(string $status): string
    {
        return match ($status) {
            'pending_client' => __('Pendiente del cliente'),
            'pending_provider' => __('Pendiente del proveedor'),
            'concluded' => __('Concluido'),
            default => $status,
        };
    }

    /** Selecciona el color de insignia para el estado mostrado. */
    private function statusBadgeColor(string $status): string
    {
        return match ($status) {
            'pending_client' => 'amber',
            'pending_provider' => 'blue',
            'concluded' => 'green',
            default => 'zinc',
        };
    }
}; ?>

<section class="space-y-6">
    <x-page-heading
        :heading="__('Expedientes')"
        :subheading="__('Gestioná los expedientes y casos del sistema.')"
    />

    {{-- Toolbar --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <flux:input wire:model.live="search" icon="magnifying-glass" :placeholder="__('Buscar por número, email o tipo...')" class="max-w-sm" />

        <flux:select wire:model.live="statusFilter" :label="__('Estado')" size="sm" class="sm:w-48">
            <flux:select.option value="all">{{ __('Todos') }}</flux:select.option>
            @foreach ($this->statusCounts as $status => $count)
                @if ($count > 0 || $statusFilter === $status)
                    <flux:select.option value="{{ $status }}">
                        {{ $this->statusLabel($status) }} ({{ $count }})
                    </flux:select.option>
                @endif
            @endforeach
        </flux:select>

        <flux:select wire:model.live="mailAccountIdFilter" :label="__('Cuenta')" size="sm" class="sm:w-48">
            <flux:select.option value="0">{{ __('Todas las cuentas') }}</flux:select.option>
            @foreach ($this->mailAccounts as $account)
                <flux:select.option value="{{ $account->id }}">{{ $account->label ?? $account->email_address }}</flux:select.option>
            @endforeach
        </flux:select>

        @can('expedientes.crear')
            <flux:modal.trigger name="expedient-form">
                <flux:button variant="primary" wire:click="create" icon="plus">
                    {{ __('Crear expediente') }}
                </flux:button>
            </flux:modal.trigger>
        @endcan
    </div>

    {{-- Expedients table --}}
    <flux:table :paginate="$this->expedients">
        <flux:table.columns>
            <flux:table.column>{{ __('Número') }}</flux:table.column>
            <flux:table.column>{{ __('Estado') }}</flux:table.column>
            <flux:table.column>{{ __('Solicitante') }}</flux:table.column>
            <flux:table.column>{{ __('Tipo') }}</flux:table.column>
            <flux:table.column>{{ __('Asignado a') }}</flux:table.column>
            <flux:table.column>{{ __('Apertura') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Acciones') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->expedients as $expedient)
                <flux:table.row wire:key="expedient-row-{{ $expedient->id }}">
                    <flux:table.cell>
                        <flux:heading size="sm">
                            <flux:link :href="route('expedientes.show', $expedient)" wire:navigate>
                                {{ $expedient->case_number }}
                            </flux:link>
                        </flux:heading>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$this->statusBadgeColor($expedient->status->value)">
                            {{ $this->statusLabel($expedient->status->value) }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:text>{{ $expedient->sender_email ?? '—' }}</flux:text>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:text>{{ $expedient->request_type ?? '—' }}</flux:text>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:text>{{ $expedient->assignedUser?->name ?? '—' }}</flux:text>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:text variant="subtle">
                            {{ $expedient->opened_at?->format('d/m/Y') ?? '—' }}
                        </flux:text>
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        <div class="flex justify-end gap-2">
                            <flux:button variant="ghost" size="sm" icon="eye" :href="route('expedientes.show', $expedient)" wire:navigate>{{ __('Ver') }}</flux:button>

                            @can('expedientes.actualizar')
                                <flux:modal.trigger name="expedient-form">
                                    <flux:button variant="ghost" size="sm" wire:click="edit({{ $expedient->id }})" icon="pencil">{{ __('Editar') }}</flux:button>
                                </flux:modal.trigger>
                            @endcan

                            @can('expedientes.eliminar')
                                <flux:modal.trigger name="delete-confirm">
                                    <flux:button variant="danger" size="sm" icon="trash" wire:click="confirmDelete({{ $expedient->id }})">{{ __('Eliminar') }}</flux:button>
                                </flux:modal.trigger>
                            @endcan
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="7" class="py-6 text-center text-neutral-500">
                        {{ $search ? __('No se encontraron resultados.') : __('No hay expedientes disponibles.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    {{-- Modal crear / editar expediente --}}
    <flux:modal name="expedient-form" class="w-full md:w-[36rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingExpedientId ? __('Editar expediente') : __('Crear expediente') }}</flux:heading>
                <flux:text class="text-neutral-600 dark:text-neutral-300">
                    {{ __('Completa los datos del expediente. Los campos marcados son obligatorios.') }}
                </flux:text>
            </div>

            <form wire:submit="save" class="space-y-5">
                <flux:input wire:model="caseNumber" :label="__('Número de expediente')" type="text" required />

                <flux:field>
                    <flux:label>{{ __('Tipo de solicitud') }}</flux:label>
                    <flux:input wire:model="requestType" type="text" :placeholder="__('consulta, reclamo, solicitud...')" />
                </flux:field>

                <flux:input wire:model="senderEmail" :label="__('Email del solicitante')" type="email" />
                <flux:input wire:model="senderPhone" :label="__('Teléfono del solicitante')" type="text" />

                <flux:field>
                    <flux:label>{{ __('Cuenta de correo') }}</flux:label>
                    <flux:select wire:model="mailAccountId" required>
                        <flux:select.option value="">{{ __('Seleccionar cuenta...') }}</flux:select.option>
                        @foreach ($this->mailAccounts as $account)
                            <flux:select.option value="{{ $account->id }}">{{ $account->label ?? $account->email_address }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Asignado a') }}</flux:label>
                    <flux:select wire:model="assignedUserId" required>
                        <flux:select.option value="">{{ __('Seleccionar usuario...') }}</flux:select.option>
                        @foreach ($this->availableUsers as $user)
                            <flux:select.option value="{{ $user->id }}">{{ $user->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </flux:field>

                <div class="flex justify-end gap-2">
                    <flux:button variant="ghost" wire:click="cancel">{{ __('Cancelar') }}</flux:button>
                    <flux:button variant="primary" type="submit">
                        {{ $editingExpedientId ? __('Actualizar expediente') : __('Crear expediente') }}
                    </flux:button>
                </div>
            </form>
        </div>
    </flux:modal>

    {{-- Modal confirmar eliminación --}}
    <flux:modal name="delete-confirm" class="w-full md:w-[24rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Confirmar eliminación') }}</flux:heading>
                <flux:text class="text-neutral-600 dark:text-neutral-300">
                    {{ __('¿Estás seguro de que querés eliminar este expediente? Esta acción no se puede deshacer.') }}
                </flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancelar') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="delete($pendingDeleteId)">
                    {{ __('Eliminar') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</section>
