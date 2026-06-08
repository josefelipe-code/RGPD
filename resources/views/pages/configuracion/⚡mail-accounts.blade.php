<?php

use App\Models\MailAccount;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Cuentas de correo')] class extends Component {
    use WithPagination;

    public string $search = '';
    public int $perPage = 10;
    public ?int $editingMailAccountId = null;
    public string $label = '';
    public string $email_address = '';
    public string $imap_host = '';
    public int $imap_port = 993;
    public ?string $imap_encryption = 'ssl';
    public string $imap_username = '';
    public string $imap_password = '';
    public string $smtp_host = '';
    public int $smtp_port = 587;
    public ?string $smtp_encryption = 'tls';
    public string $smtp_username = '';
    public string $smtp_password = '';

    protected ?User $currentUser = null;

    public function mount(): void
    {
        abort_unless(Auth::user()->can('cuentas-correo.ver'), 403);
    }

    protected function getUser(): User
    {
        return $this->currentUser ??= Auth::user();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:255'],
            'email_address' => ['required', 'email', 'max:255', Rule::unique('mail_accounts', 'email_address')->ignore($this->editingMailAccountId)],
            'imap_host' => ['required', 'string', 'max:255'],
            'imap_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'imap_encryption' => ['nullable', 'string', 'in:ssl,tls'],
            'imap_username' => ['required', 'string', 'max:255'],
            'imap_password' => ['nullable', 'string', 'max:255'],
            'smtp_host' => ['required', 'string', 'max:255'],
            'smtp_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'smtp_encryption' => ['nullable', 'string', 'in:ssl,tls'],
            'smtp_username' => ['required', 'string', 'max:255'],
            'smtp_password' => ['nullable', 'string', 'max:255'],
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
    public function mailAccounts()
    {
        return $this->getUser()->mailAccounts()
            ->when($this->search, fn ($q) => $q
                ->where('label', 'like', "%{$this->search}%")
                ->orWhere('email_address', 'like', "%{$this->search}%"))
            ->orderByDesc('created_at')
            ->paginate($this->perPage);
    }

    public function create(): void
    {
        $this->authorizeAbility('cuentas-correo.crear');
        $this->resetForm();
    }

    public function edit(int $id): void
    {
        $this->authorizeAbility('cuentas-correo.actualizar');

        $account = $this->getUser()->mailAccounts()->findOrFail($id);

        $this->editingMailAccountId = $account->id;
        $this->label = $account->label;
        $this->email_address = $account->email_address;
        $this->imap_host = $account->imap_host;
        $this->imap_port = $account->imap_port;
        $this->imap_encryption = $account->imap_encryption;
        $this->imap_username = $account->imap_username;
        $this->imap_password = '';
        $this->smtp_host = $account->smtp_host;
        $this->smtp_port = $account->smtp_port;
        $this->smtp_encryption = $account->smtp_encryption;
        $this->smtp_username = $account->smtp_username;
        $this->smtp_password = '';

        $this->resetErrorBag();
    }

    public function save(): void
    {
        $isCreating = $this->editingMailAccountId === null;

        $this->authorizeAbility($isCreating ? 'cuentas-correo.crear' : 'cuentas-correo.actualizar');

        // Require passwords only when creating
        $rules = $this->rules();
        if ($isCreating) {
            $rules['imap_password'] = ['required', 'string', 'max:255'];
            $rules['smtp_password'] = ['required', 'string', 'max:255'];
        }

        $validated = $this->validate($rules);

        // Verify SMTP connectivity before persisting
        $this->verifySmtpConnection($validated);

        // Verify IMAP connectivity before persisting
        $this->verifyImapConnection($validated);

        if ($isCreating) {
            $this->getUser()->mailAccounts()->create($validated);
        } else {
            $account = $this->getUser()->mailAccounts()->findOrFail($this->editingMailAccountId);

            if ($validated['imap_password'] === '' || $validated['imap_password'] === null) {
                unset($validated['imap_password']);
            }

            if ($validated['smtp_password'] === '' || $validated['smtp_password'] === null) {
                unset($validated['smtp_password']);
            }

            $account->update($validated);
        }

        $this->resetForm();

        Flux::modal('mail-account-form')->close();

        Flux::toast(variant: 'success', text: $isCreating
            ? __('Cuenta de correo creada.')
            : __('Cuenta de correo actualizada.'));
    }

    /**
     * Verify SMTP connection using validated form data.
     *
     * @param  array<string, mixed>  $validated
     */
    private function verifySmtpConnection(array $validated): void
    {
        $service = app(\App\Services\MailAccountConfigService::class);

        $smtpConfig = [
            'host' => $validated['smtp_host'],
            'port' => $validated['smtp_port'],
            'encryption' => $validated['smtp_encryption'],
            'username' => $validated['smtp_username'],
            'password' => $validated['smtp_password'],
        ];

        try {
            $service->verifySmtpConnection($smtpConfig);
        } catch (\RuntimeException $e) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'smtp_connection' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Verify IMAP connection using validated form data.
     *
     * @param  array<string, mixed>  $validated
     */
    private function verifyImapConnection(array $validated): void
    {
        $service = app(\App\Services\MailAccountConfigService::class);

        $imapConfig = [
            'host' => $validated['imap_host'],
            'port' => $validated['imap_port'],
            'protocol' => 'imap',
            'encryption' => $validated['imap_encryption'],
            'validate_cert' => true,
            'username' => $validated['imap_username'],
            'password' => $validated['imap_password'],
            'authentication' => null,
            'timeout' => 30,
        ];

        try {
            $service->verifyImapConnection($imapConfig);
        } catch (\RuntimeException $e) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'imap_connection' => $e->getMessage(),
            ]);
        }
    }

    public function toggle(int $id): void
    {
        $account = $this->getUser()->mailAccounts()->findOrFail($id);

        $account->update(['is_active' => ! $account->is_active]);

        Flux::toast(variant: 'success', text: $account->is_active
            ? __('Cuenta activada.')
            : __('Cuenta desactivada.'));
    }

    public function delete(int $id): void
    {
        $this->authorizeAbility('cuentas-correo.eliminar');

        $this->getUser()->mailAccounts()->findOrFail($id)->delete();

        if ($this->editingMailAccountId === $id) {
            $this->resetForm();
        }

        Flux::toast(variant: 'success', text: __('Cuenta de correo eliminada.'));
    }

    public function cancel(): void
    {
        $this->resetForm();
        Flux::modal('mail-account-form')->close();
    }

    private function authorizeAbility(string $ability): void
    {
        abort_unless(Auth::user()->can($ability), 403);
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingMailAccountId',
            'label',
            'email_address',
            'imap_host',
            'imap_port',
            'imap_encryption',
            'imap_username',
            'imap_password',
            'smtp_host',
            'smtp_port',
            'smtp_encryption',
            'smtp_username',
            'smtp_password',
        ]);

        $this->resetErrorBag();
    }
}; ?>

<section class="space-y-6">
    <x-page-heading :heading="__('Cuentas de correo')" :subheading="__('Configurá las cuentas de correo que usás para leer y enviar mensajes.')" />

    @if ($errors->has('general'))
        <flux:callout variant="danger" icon="x-circle" :heading="$errors->first('general')" />
    @endif

    {{-- Toolbar --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <flux:input wire:model.live="search" icon="magnifying-glass" :placeholder="__('Buscar por nombre o email...')" class="max-w-sm" />

        <flux:select wire:model.live="perPage" :label="__('Resultados')" size="sm" class="sm:w-40">
            <flux:select.option value="10">10</flux:select.option>
            <flux:select.option value="20">20</flux:select.option>
            <flux:select.option value="50">50</flux:select.option>
            <flux:select.option value="100">100</flux:select.option>
        </flux:select>

        @can('cuentas-correo.crear')
            <flux:modal.trigger name="mail-account-form">
                <flux:button variant="primary" wire:click="create" icon="plus">{{ __('Crear cuenta') }}</flux:button>
            </flux:modal.trigger>
        @endcan
    </div>

    {{-- Tabla de cuentas --}}
    <flux:table :paginate="$this->mailAccounts">
        <flux:table.columns>
            <flux:table.column>{{ __('Etiqueta') }}</flux:table.column>
            <flux:table.column>{{ __('Email') }}</flux:table.column>
            <flux:table.column>{{ __('IMAP') }}</flux:table.column>
            <flux:table.column>{{ __('SMTP') }}</flux:table.column>
            <flux:table.column>{{ __('Estado') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Acciones') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->mailAccounts as $account)
                <flux:table.row wire:key="mail-account-row-{{ $account->id }}">
                    <flux:table.cell>
                        <flux:heading size="sm">{{ $account->label }}</flux:heading>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:text>{{ $account->email_address }}</flux:text>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:text variant="subtle">{{ $account->imap_host }}:{{ $account->imap_port }}</flux:text>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:text variant="subtle">{{ $account->smtp_host }}:{{ $account->smtp_port }}</flux:text>
                    </flux:table.cell>
                    <flux:table.cell>
                        @if ($account->is_active)
                            <flux:badge color="green">{{ __('Activa') }}</flux:badge>
                        @else
                            <flux:badge color="zinc">{{ __('Inactiva') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        <div class="flex justify-end gap-2">
                            @can('cuentas-correo.actualizar')
                                <flux:modal.trigger name="mail-account-form">
                                    <flux:button variant="ghost" size="sm" wire:click="edit({{ $account->id }})" icon="pencil">{{ __('Editar') }}</flux:button>
                                </flux:modal.trigger>
                            @endcan

                            <flux:button
                                variant="{{ $account->is_active ? 'ghost' : 'primary' }}"
                                size="sm"
                                wire:click="toggle({{ $account->id }})"
                                icon="{{ $account->is_active ? 'pause' : 'play' }}"
                            >
                                {{ $account->is_active ? __('Desactivar') : __('Activar') }}
                            </flux:button>

                            @can('cuentas-correo.eliminar')
                                <flux:button variant="danger" size="sm" wire:click="delete({{ $account->id }})" icon="trash">{{ __('Eliminar') }}</flux:button>
                            @endcan
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="py-6 text-center text-neutral-500">
                        {{ $search ? __('No se encontraron resultados.') : __('No hay cuentas de correo configuradas.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    {{-- Modal crear / editar --}}
    <flux:modal name="mail-account-form" class="w-full md:w-[40rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingMailAccountId ? __('Editar cuenta de correo') : __('Crear cuenta de correo') }}</flux:heading>
                <flux:text class="text-neutral-600 dark:text-neutral-300">
                    {{ __('Configurá los servidores IMAP y SMTP para esta cuenta.') }}
                </flux:text>
            </div>

            <form wire:submit="save" class="space-y-5">
                <flux:input wire:model="label" :label="__('Etiqueta')" type="text" required :placeholder="__('Ej: Trabajo, Personal')" />
                <flux:input wire:model="email_address" :label="__('Email')" type="email" required />

                {{-- IMAP --}}
                <div class="space-y-3">
                    <flux:heading size="sm">{{ __('Servidor IMAP (recepción)') }}</flux:heading>

                    @if ($errors->has('imap_connection'))
                        <flux:callout variant="danger" icon="server" :heading="$errors->first('imap_connection')" />
                    @endif

                    <div class="grid gap-3 sm:grid-cols-2">
                        <flux:input wire:model="imap_host" :label="__('Host')" type="text" required />
                        <flux:input wire:model="imap_port" :label="__('Puerto')" type="number" required />
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <flux:input wire:model="imap_username" :label="__('Usuario')" type="text" required />
                        <flux:input wire:model="imap_password" :label="__('Contraseña')" type="password" viewable :required="$editingMailAccountId === null" />
                    </div>

                    <flux:select wire:model="imap_encryption" :label="__('Encriptación')">
                        <flux:select.option value="ssl">SSL</flux:select.option>
                        <flux:select.option value="tls">TLS</flux:select.option>
                    </flux:select>
                </div>

                {{-- SMTP --}}
                <div class="space-y-3">
                    <flux:heading size="sm">{{ __('Servidor SMTP (envío)') }}</flux:heading>

                    @if ($errors->has('smtp_connection'))
                        <flux:callout variant="danger" icon="server" :heading="$errors->first('smtp_connection')" />
                    @endif

                    <div class="grid gap-3 sm:grid-cols-2">
                        <flux:input wire:model="smtp_host" :label="__('Host')" type="text" required />
                        <flux:input wire:model="smtp_port" :label="__('Puerto')" type="number" required />
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <flux:input wire:model="smtp_username" :label="__('Usuario')" type="text" required />
                        <flux:input wire:model="smtp_password" :label="__('Contraseña')" type="password" viewable :required="$editingMailAccountId === null" />
                    </div>

                    <flux:select wire:model="smtp_encryption" :label="__('Encriptación')">
                        <flux:select.option value="ssl">SSL</flux:select.option>
                        <flux:select.option value="tls">TLS</flux:select.option>
                    </flux:select>
                </div>

                <div class="flex justify-end gap-2">
                    <flux:button variant="ghost" wire:click="cancel">{{ __('Cancelar') }}</flux:button>
                    <flux:button variant="primary" type="submit">
                        {{ $editingMailAccountId ? __('Actualizar cuenta') : __('Crear cuenta') }}
                    </flux:button>
                </div>
            </form>
        </div>
    </flux:modal>
</section>
