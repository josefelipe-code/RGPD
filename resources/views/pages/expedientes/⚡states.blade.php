<?php

use App\Models\ExpedientState;
use App\Models\MailAccount;
use App\Services\Bandeja\ImapMailboxService;
use App\Services\Expedientes\ExpedientStateService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Estados de expedientes')] class extends Component {
    use WithPagination;

    public string $search = '';
    public int $perPage = 10;
    public ?int $editingStateId = null;
    public ?string $selectedMailAccountId = null;
    public string $name = '';
    public string $key = '';
    public string $imapFolder = '';
    public string $newImapFolder = '';
    public bool $isFinal = false;
    public array $stateFolders = [];

    public function mount(): void
    {
        abort_unless(Auth::user()->can('expedientes.ver'), 403);
    }

    protected function rules(): array
    {
        return [
            'selectedMailAccountId' => ['required', 'integer', Rule::exists('mail_accounts', 'id')->where(fn ($query) => $query->where('is_active', true))],
            'name' => ['required', 'string', 'max:255'],
            'key' => ['required', 'alpha_dash:ascii', 'max:100', Rule::unique('expedient_states', 'key')->where(fn ($query) => $query->where('mail_account_id', $this->selectedMailAccountId))->ignore($this->editingStateId)],
            'imapFolder' => ['nullable', 'string', 'max:255'],
            'newImapFolder' => ['nullable', 'string', 'max:255'],
            'isFinal' => ['boolean'],
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function states()
    {
        return ExpedientState::query()
            ->whereIn('mail_account_id', MailAccount::query()->active()->select('id'))
            ->with('mailAccount')
            ->withCount('expedients')
            ->when($this->search, fn ($query) => $query->where(fn ($query) => $query->where('name', 'like', "%{$this->search}%")->orWhere('key', 'like', "%{$this->search}%")))
            ->orderByDesc('is_final')
            ->orderBy('name')
            ->paginate($this->perPage);
    }

    #[Computed]
    public function activeMailAccounts()
    {
        return MailAccount::query()->active()->orderBy('label')->get();
    }

    public function create(ImapMailboxService $mailbox): void
    {
        $this->authorizeStateConfiguration();
        $this->resetForm();
        $this->selectedMailAccountId = $this->activeMailAccounts->first()?->id ? (string) $this->activeMailAccounts->first()->id : null;
        $this->loadFolders($mailbox);
    }

    public function edit(int $id, ImapMailboxService $mailbox): void
    {
        $this->authorizeStateConfiguration();
        $state = $this->getState($id);
        $this->editingStateId = $state->id;
        $this->selectedMailAccountId = (string) $state->mail_account_id;
        $this->name = $state->name;
        $this->key = $state->key;
        $this->imapFolder = $state->imap_folder ?? '';
        $this->isFinal = $state->is_final;
        $this->loadFolders($mailbox);
        $this->resetErrorBag();
    }

    public function loadFolders(ImapMailboxService $mailbox): void
    {
        if (blank($this->selectedMailAccountId)) {
            $this->stateFolders = [];

            return;
        }

        $account = MailAccount::query()->active()->findOrFail($this->selectedMailAccountId);
        $this->stateFolders = $mailbox->listFolders($account)->all();
    }

    public function save(ExpedientStateService $states): void
    {
        $isCreating = $this->editingStateId === null;
        $this->authorizeStateConfiguration();
        $validated = $this->validate();
        $account = MailAccount::query()->active()->findOrFail($validated['selectedMailAccountId']);
        $state = $isCreating ? null : $this->getState($this->editingStateId);

        $states->save($account, Auth::user(), $state, [
            'name' => $validated['name'],
            'key' => $validated['key'],
            'imap_folder' => $validated['imapFolder'] ?: null,
            'is_final' => $validated['isFinal'],
        ], $validated['newImapFolder'] ?: null);

        $this->resetForm();
        Flux::modal('expedient-state-form')->close();
        Flux::toast(variant: 'success', text: $isCreating ? __('Estado creado.') : __('Estado actualizado.'));
    }

    public function delete(int $id, ExpedientStateService $states): void
    {
        $this->authorizeStateConfiguration();
        $state = $this->getState($id);
        $states->delete($state->mailAccount, Auth::user(), $state);
        Flux::toast(variant: 'success', text: __('Estado eliminado.'));
    }

    public function cancel(): void
    {
        $this->resetForm();
        Flux::modal('expedient-state-form')->close();
    }

    private function getState(int $id): ExpedientState
    {
        return ExpedientState::query()
            ->whereIn('mail_account_id', MailAccount::query()->active()->select('id'))
            ->findOrFail($id);
    }

    private function authorizeStateConfiguration(): void
    {
        abort_unless(Auth::user()->can('expedientes.ver'), 403);
    }

    private function resetForm(): void
    {
        $this->reset(['editingStateId', 'selectedMailAccountId', 'name', 'key', 'imapFolder', 'newImapFolder', 'isFinal', 'stateFolders']);
        $this->resetErrorBag();
    }
};
?>

<section class="space-y-6">
    <x-page-heading :heading="__('Estados de expedientes')" :subheading="__('Configurá los estados y las carpetas IMAP de cada cuenta de correo. Las transiciones quedan auditadas en cada expediente.')" />

    @if ($this->activeMailAccounts->isEmpty())
        <flux:callout variant="warning" icon="exclamation-triangle" heading="{{ __('Sin cuentas de correo') }}">
            {{ __('Para gestionar estados, primero necesitás configurar una cuenta de correo.') }}
        </flux:callout>
    @else
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <flux:input wire:model.live="search" icon="magnifying-glass" :placeholder="__('Buscar por nombre o clave...')" class="max-w-sm" />
            <div class="flex items-end gap-3">
                <flux:select wire:model.live="perPage" :label="__('Resultados')" size="sm" class="w-32">
                    <flux:select.option value="10">10</flux:select.option>
                    <flux:select.option value="20">20</flux:select.option>
                    <flux:select.option value="50">50</flux:select.option>
                </flux:select>
                @can('expedientes.ver')
                    <flux:modal.trigger name="expedient-state-form">
                        <flux:button variant="primary" wire:click="create" icon="plus">{{ __('Crear estado') }}</flux:button>
                    </flux:modal.trigger>
                @endcan
            </div>
        </div>

        <flux:table :paginate="$this->states">
            <flux:table.columns>
                <flux:table.column>{{ __('Estado') }}</flux:table.column>
                <flux:table.column>{{ __('Cuenta') }}</flux:table.column>
                <flux:table.column>{{ __('Carpeta IMAP') }}</flux:table.column>
                <flux:table.column>{{ __('Tipo') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Acciones') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->states as $state)
                    <flux:table.row wire:key="expedient-state-row-{{ $state->id }}">
                        <flux:table.cell><flux:heading size="sm">{{ $state->name }}</flux:heading><flux:text variant="subtle">{{ $state->key }}</flux:text></flux:table.cell>
                        <flux:table.cell>{{ $state->mailAccount->label }}</flux:table.cell>
                        <flux:table.cell>{{ $state->imap_folder ?: __('Sin carpeta') }}</flux:table.cell>
                        <flux:table.cell><flux:badge :color="$state->is_final ? 'green' : 'zinc'">{{ $state->is_final ? __('Final') : __('Abierto') }}</flux:badge></flux:table.cell>
                        <flux:table.cell align="end">
                            <div class="flex justify-end gap-2">
                                @can('expedientes.ver')
                                    <flux:modal.trigger name="expedient-state-form"><flux:button variant="ghost" size="sm" wire:click="edit({{ $state->id }})" icon="pencil">{{ __('Editar') }}</flux:button></flux:modal.trigger>
                                @endcan
                                @can('expedientes.ver')
                                    @if (! $state->is_final && $state->expedients_count === 0)
                                        <flux:button variant="danger" size="sm" wire:click="delete({{ $state->id }})" icon="trash">{{ __('Eliminar') }}</flux:button>
                                    @endif
                                @endcan
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="5" class="py-6 text-center text-neutral-500">{{ __('No hay estados configurados.') }}</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    @endif

    <flux:modal name="expedient-state-form" class="w-full md:w-[36rem]">
        <form wire:submit="save" class="space-y-5">
            <div><flux:heading size="lg">{{ $editingStateId ? __('Editar estado') : __('Crear estado') }}</flux:heading><flux:text>{{ __('Elegí una cuenta y mapeá una carpeta IMAP existente o creá una nueva.') }}</flux:text></div>
            <flux:select wire:model="selectedMailAccountId" :label="__('Cuenta de correo')" required>
                @foreach ($this->activeMailAccounts as $account)
                    <flux:select.option :value="(string) $account->id">{{ $account->label }} ({{ $account->email_address }})</flux:select.option>
                @endforeach
            </flux:select>
            <flux:button type="button" variant="ghost" size="sm" wire:click="loadFolders" icon="arrow-path">{{ __('Actualizar carpetas IMAP') }}</flux:button>
            <div class="grid gap-3 sm:grid-cols-2"><flux:input wire:model="name" :label="__('Nombre')" required /><flux:input wire:model="key" :label="__('Clave')" required /></div>
            <flux:select wire:model="imapFolder" :label="__('Carpeta IMAP existente')"><flux:select.option value="">{{ __('Sin carpeta') }}</flux:select.option>@foreach ($stateFolders as $folder)<flux:select.option :value="$folder['path']">{{ $folder['name'] }}</flux:select.option>@endforeach</flux:select>
            <flux:input wire:model="newImapFolder" :label="__('O crear carpeta IMAP')" />
            <flux:checkbox wire:model="isFinal" :label="__('Estado final')" />
            <div class="flex justify-end gap-2"><flux:button type="button" variant="ghost" wire:click="cancel">{{ __('Cancelar') }}</flux:button><flux:button type="submit" variant="primary">{{ $editingStateId ? __('Actualizar estado') : __('Crear estado') }}</flux:button></div>
        </form>
    </flux:modal>
</section>
