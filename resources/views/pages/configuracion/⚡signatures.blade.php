<?php

use App\Models\MailAccount;
use App\Models\Signature;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Firmas')] class extends Component {
    use WithPagination;

    public string $search = '';
    public int $perPage = 10;
    public ?int $editingSignatureId = null;
    public ?string $selectedMailAccountId = null;
    public string $name = '';
    public string $body = '';
    public bool $isDefault = false;
    public bool $showPreview = false;

    protected ?User $currentUser = null;

    public function mount(): void
    {
        abort_unless(Auth::user()->can('firmas.ver'), 403);
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
            'selectedMailAccountId' => ['required', 'integer', Rule::exists('mail_accounts', 'id')->where(function ($query) {
                $query->where('user_id', $this->getUser()->id);
            })],
            'name' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'isDefault' => ['boolean'],
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

    public function updatedSelectedMailAccountId(): void
    {
        $this->resetErrorBag('selectedMailAccountId');
    }

    #[Computed]
    public function signatures()
    {
        $mailAccountIds = $this->getUser()->mailAccounts()->pluck('id');

        return Signature::whereIn('mail_account_id', $mailAccountIds)
            ->when($this->search, fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('body', 'like', "%{$this->search}%"))
            ->with('mailAccount')
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->paginate($this->perPage);
    }

    #[Computed]
    public function userMailAccounts()
    {
        return $this->getUser()->mailAccounts()->orderBy('label')->get();
    }

    #[Computed]
    public function renderedBody(): string
    {
        if (blank($this->body)) {
            return '';
        }

        // Allow common signature HTML tags, strip potentially dangerous ones
        $allowedTags = '<p><br><strong><em><u><a><span><div><h1><h2><h3><h4><h5><h6><ul><ol><li><img><table><thead><tbody><tr><td><th><hr><blockquote><code><pre><b><i><s><sub><sup>';

        return strip_tags($this->body, $allowedTags);
    }

    public function create(): void
    {
        $this->authorizeAbility('firmas.crear');
        $this->resetForm();

        $this->selectedMailAccountId = $this->userMailAccounts->first()
            ? (string) $this->userMailAccounts->first()->id
            : null;
    }

    public function edit(int $id): void
    {
        $this->authorizeAbility('firmas.actualizar');

        $signature = $this->getUserSignature($id);

        $this->editingSignatureId = $signature->id;
        $this->selectedMailAccountId = (string) $signature->mail_account_id;
        $this->name = $signature->name;
        $this->body = $signature->body ?? '';
        $this->isDefault = $signature->is_default;

        $this->resetErrorBag();
    }

    public function save(): void
    {
        $isCreating = $this->editingSignatureId === null;

        $this->authorizeAbility($isCreating ? 'firmas.crear' : 'firmas.actualizar');

        $validated = $this->validate($this->rules());

        $mailAccountId = (int) $validated['selectedMailAccountId'];

        if ($isCreating) {
            $this->handleDefaultConsistency($mailAccountId, $validated['isDefault']);

            $this->getUser()->mailAccounts()
                ->findOrFail($mailAccountId)
                ->signatures()
                ->create([
                    'name' => $validated['name'],
                    'body' => $validated['body'],
                    'is_default' => $validated['isDefault'],
                ]);
        } else {
            $signature = $this->getUserSignature($this->editingSignatureId);

            // If mail account changed, handle default consistency on the new account
            if ($signature->mail_account_id !== $mailAccountId) {
                $this->handleDefaultConsistency($mailAccountId, $validated['isDefault']);
            } elseif ($validated['isDefault'] && ! $signature->is_default) {
                $this->handleDefaultConsistency($mailAccountId, true);
            }

            $signature->update([
                'mail_account_id' => $mailAccountId,
                'name' => $validated['name'],
                'body' => $validated['body'],
                'is_default' => $validated['isDefault'],
            ]);
        }

        $this->resetForm();

        Flux::modal('signature-form')->close();

        Flux::toast(variant: 'success', text: $isCreating
            ? __('Firma creada.')
            : __('Firma actualizada.'));
    }

    public function toggle(int $id): void
    {
        $signature = $this->getUserSignature($id);

        $signature->update(['is_active' => ! $signature->is_active]);

        Flux::toast(variant: 'success', text: $signature->is_active
            ? __('Firma activada.')
            : __('Firma desactivada.'));
    }

    public function toggleDefault(int $id): void
    {
        $this->authorizeAbility('firmas.actualizar');

        $signature = $this->getUserSignature($id);

        // If setting as default, unset others on same mail account
        if (! $signature->is_default) {
            $this->handleDefaultConsistency($signature->mail_account_id, true);
            $signature->update(['is_default' => true]);
        }

        Flux::toast(variant: 'success', text: $signature->is_default
            ? __('Firma establecida como predeterminada.')
            : __('Firma quitada como predeterminada.'));
    }

    public function delete(int $id): void
    {
        $this->authorizeAbility('firmas.eliminar');

        $signature = $this->getUserSignature($id);
        $signature->delete();

        if ($this->editingSignatureId === $id) {
            $this->resetForm();
        }

        Flux::toast(variant: 'success', text: __('Firma eliminada.'));
    }

    public function cancel(): void
    {
        $this->resetForm();
        Flux::modal('signature-form')->close();
    }

    private function getUserSignature(int $id): Signature
    {
        $mailAccountIds = $this->getUser()->mailAccounts()->pluck('id');

        return Signature::whereIn('mail_account_id', $mailAccountIds)->findOrFail($id);
    }

    /**
     * Ensure only one default signature per mail account.
     */
    private function handleDefaultConsistency(int $mailAccountId, bool $setAsDefault): void
    {
        if ($setAsDefault) {
            Signature::where('mail_account_id', $mailAccountId)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }
    }

    private function authorizeAbility(string $ability): void
    {
        abort_unless(Auth::user()->can($ability), 403);
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingSignatureId',
            'selectedMailAccountId',
            'name',
            'body',
            'isDefault',
            'showPreview',
        ]);

        $this->resetErrorBag();
    }

    public function togglePreview(): void
    {
        $this->showPreview = ! $this->showPreview;
    }
}; ?>

<section class="space-y-6">
    <x-page-heading :heading="__('Firmas')" :subheading="__('Creá y gestioná las firmas que usás en tus correos electrónicos.')" />

    @if ($errors->has('general'))
        <flux:callout variant="danger" icon="x-circle" :heading="$errors->first('general')" />
    @endif

    @if ($this->userMailAccounts->isEmpty())
        <flux:callout variant="warning" icon="exclamation-triangle" heading="{{ __('Sin cuentas de correo') }}">
            {{ __('Para crear firmas, primero necesitás configurar al menos una cuenta de correo.') }}
            <flux:link :href="route('configuracion.cuentas-correo.index')" wire:navigate class="ml-1">
                {{ __('Ir a Cuentas de correo') }}
            </flux:link>
        </flux:callout>
    @else
        {{-- Toolbar --}}
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <flux:input wire:model.live="search" icon="magnifying-glass" :placeholder="__('Buscar por nombre o contenido...')" class="max-w-sm" />

            <flux:select wire:model.live="perPage" :label="__('Resultados')" size="sm" class="sm:w-40">
                <flux:select.option value="10">10</flux:select.option>
                <flux:select.option value="20">20</flux:select.option>
                <flux:select.option value="50">50</flux:select.option>
                <flux:select.option value="100">100</flux:select.option>
            </flux:select>

            @can('firmas.crear')
                <flux:modal.trigger name="signature-form">
                    <flux:button variant="primary" wire:click="create" icon="plus">{{ __('Crear firma') }}</flux:button>
                </flux:modal.trigger>
            @endcan
        </div>

        {{-- Tabla de firmas --}}
        <flux:table :paginate="$this->signatures">
            <flux:table.columns>
                <flux:table.column>{{ __('Nombre') }}</flux:table.column>
                <flux:table.column>{{ __('Cuenta') }}</flux:table.column>
                <flux:table.column>{{ __('Vista previa') }}</flux:table.column>
                <flux:table.column>{{ __('Estado') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Acciones') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->signatures as $signature)
                    <flux:table.row wire:key="signature-row-{{ $signature->id }}">
                        <flux:table.cell>
                            <div class="flex items-center gap-2">
                                <flux:heading size="sm">{{ $signature->name }}</flux:heading>
                                @if ($signature->is_default)
                                    <flux:badge color="blue" size="sm">{{ __('Predeterminada') }}</flux:badge>
                                @endif
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:text>{{ $signature->mailAccount->email_address }}</flux:text>
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="max-w-xs overflow-hidden">
                                <flux:text variant="subtle" class="line-clamp-2">{!! strip_tags($signature->body, '<p><br><strong><em><a><span><div><ul><ol><li><hr><b><i>') !!}</flux:text>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($signature->is_active)
                                <flux:badge color="green">{{ __('Activa') }}</flux:badge>
                            @else
                                <flux:badge color="zinc">{{ __('Inactiva') }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell align="end">
                            <div class="flex justify-end gap-2">
                                @can('firmas.actualizar')
                                    <flux:modal.trigger name="signature-form">
                                        <flux:button variant="ghost" size="sm" wire:click="edit({{ $signature->id }})" icon="pencil">{{ __('Editar') }}</flux:button>
                                    </flux:modal.trigger>

                                    @if (! $signature->is_default)
                                        <flux:button variant="ghost" size="sm" wire:click="toggleDefault({{ $signature->id }})" icon="star">{{ __('Predeterminada') }}</flux:button>
                                    @endif
                                @endcan

                                <flux:button
                                    variant="{{ $signature->is_active ? 'ghost' : 'primary' }}"
                                    size="sm"
                                    wire:click="toggle({{ $signature->id }})"
                                    icon="{{ $signature->is_active ? 'pause' : 'play' }}"
                                >
                                    {{ $signature->is_active ? __('Desactivar') : __('Activar') }}
                                </flux:button>

                                @can('firmas.eliminar')
                                    <flux:button variant="danger" size="sm" wire:click="delete({{ $signature->id }})" icon="trash">{{ __('Eliminar') }}</flux:button>
                                @endcan
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" class="py-6 text-center text-neutral-500">
                            {{ $search ? __('No se encontraron resultados.') : __('No hay firmas configuradas.') }}
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        {{-- Modal crear / editar --}}
        <flux:modal name="signature-form" class="w-full md:w-[35rem]">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ $editingSignatureId ? __('Editar firma') : __('Crear firma') }}</flux:heading>
                    <flux:text class="text-neutral-600 dark:text-neutral-300">
                        {{ __('Configurá el nombre y contenido de la firma para tu cuenta de correo.') }}
                    </flux:text>
                </div>

                <form wire:submit="save" class="space-y-5">
                    <flux:select
                        wire:model.change.live="selectedMailAccountId"
                        :label="__('Cuenta de correo')"
                        :invalid="$errors->has('selectedMailAccountId')"
                        placeholder="{{ __('Seleccioná una cuenta de correo...') }}"
                        required
                    >
                        @foreach ($this->userMailAccounts as $account)
                            <flux:select.option :value="(string) $account->id">{{ $account->label }} ({{ $account->email_address }})</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:input wire:model="name" :label="__('Nombre de la firma')" type="text" required :placeholder="__('Ej: Firma profesional, Firma interna')" />

                    <flux:textarea wire:model="body" :label="__('Contenido de la firma')" :rows="6" :placeholder="__('Escribí el contenido de tu firma... Podés pegar HTML directamente.')" />

                    @if (filled($body))
                        <div class="space-y-2">
                            <div class="flex items-center justify-between">
                                <flux:text class="font-medium">{{ __('Vista previa') }}</flux:text>
                                <flux:button variant="ghost" size="sm" wire:click="togglePreview" icon="{{ $showPreview ? 'eye-slash' : 'eye' }}">
                                    {{ $showPreview ? __('Ocultar') : __('Mostrar') }}
                                </flux:button>
                            </div>
                            @if ($showPreview)
                                <div class="rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-800">
                                    {!! $this->renderedBody !!}
                                </div>
                            @else
                                <flux:text variant="subtle">{{ __('Hacé clic en "Mostrar" para ver la vista previa.') }}</flux:text>
                            @endif
                        </div>
                    @endif

                    <flux:checkbox wire:model="isDefault" :label="__('Establecer como firma predeterminada para esta cuenta')" />

                    <div class="flex justify-end gap-2">
                        <flux:button variant="ghost" wire:click="cancel">{{ __('Cancelar') }}</flux:button>
                        <flux:button variant="primary" type="submit">
                            {{ $editingSignatureId ? __('Actualizar firma') : __('Crear firma') }}
                        </flux:button>
                    </div>
                </form>
            </div>
        </flux:modal>
    @endif
</section>
