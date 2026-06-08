<?php

use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

new #[Title('Permisos')] class extends Component {
    use WithPagination;

    public string $search = '';
    public int $perPage = 10;
    public ?int $editingPermissionId = null;
    public string $name = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function mount(): void
    {
        abort_unless(Auth::user()->can('permisos.ver'), 403);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('permissions', 'name')->ignore($this->editingPermissionId),
            ],
        ];
    }

    #[Computed]
    public function permissions()
    {
        return Permission::query()
            ->with('roles')
            ->when($this->search, fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
            ->orderBy('name')
            ->paginate($this->perPage);
    }

    public function create(): void
    {
        $this->authorizeAbility('permisos.crear');
        $this->resetForm();
    }

    public function edit(int $permissionId): void
    {
        $this->authorizeAbility('permisos.actualizar');

        $permission = Permission::query()->findOrFail($permissionId);

        $this->editingPermissionId = $permission->id;
        $this->name = $permission->name;

        $this->resetErrorBag();
    }

    public function save(): void
    {
        $isCreating = $this->editingPermissionId === null;

        $this->authorizeAbility($isCreating ? 'permisos.crear' : 'permisos.actualizar');

        $validated = $this->validate();

        if ($isCreating) {
            Permission::create([
                'name' => $validated['name'],
                'guard_name' => 'web',
            ]);
        } else {
            Permission::query()->findOrFail($this->editingPermissionId)->update([
                'name' => $validated['name'],
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->resetForm();

        Flux::modal('permission-form')->close();

        Flux::toast(variant: 'success', text: $isCreating
            ? __('Permiso creado.')
            : __('Permiso actualizado.'));
    }

    public function delete(int $permissionId): void
    {
        $this->authorizeAbility('permisos.eliminar');

        $permission = Permission::query()->findOrFail($permissionId);

        if ($permission->name === 'admin.acceder') {
            throw ValidationException::withMessages([
                'general' => __('El permiso admin.acceder no se puede eliminar.'),
            ]);
        }

        $permission->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if ($this->editingPermissionId === $permissionId) {
            $this->resetForm();
        }

        Flux::toast(variant: 'success', text: __('Permiso eliminado.'));
    }

    public function cancel(): void
    {
        $this->resetForm();
        Flux::modal('permission-form')->close();
    }

    private function authorizeAbility(string $ability): void
    {
        abort_unless(Auth::user()->can($ability), 403);
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingPermissionId',
            'name',
        ]);

        $this->resetErrorBag();
    }
}; ?>

<section class="space-y-6">
    <x-page-heading :heading="__('Permisos')" :subheading="__('Definí las capacidades de bajo nivel que después se agrupan y distribuyen en roles.')" />

    @if ($errors->has('general'))
        <flux:callout variant="danger" icon="x-circle" :heading="$errors->first('general')" />
    @endif

    {{-- Toolbar --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <flux:input wire:model.live="search" icon="magnifying-glass" :placeholder="__('Buscar por nombre...')" class="max-w-sm" />

        <flux:select wire:model.live="perPage" :label="__('Resultados')" size="sm" class="sm:w-40">
            <flux:select.option value="10">10</flux:select.option>
            <flux:select.option value="20">20</flux:select.option>
            <flux:select.option value="50">50</flux:select.option>
            <flux:select.option value="100">100</flux:select.option>
        </flux:select>

        @can('permisos.crear')
            <flux:modal.trigger name="permission-form">
                <flux:button variant="primary" wire:click="create" icon="plus">{{ __('Crear permiso') }}</flux:button>
            </flux:modal.trigger>
        @endcan
    </div>

    {{-- Tabla de permisos --}}
    <flux:table :paginate="$this->permissions">
        <flux:table.columns>
            <flux:table.column>{{ __('ID') }}</flux:table.column>
            <flux:table.column>{{ __('Nombre') }}</flux:table.column>
            <flux:table.column>{{ __('Guard') }}</flux:table.column>
            <flux:table.column>{{ __('Asignado a') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Acciones') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->permissions as $permission)
                <flux:table.row wire:key="permission-row-{{ $permission->id }}">
                    <flux:table.cell>
                        <flux:text variant="subtle">{{ $permission->id }}</flux:text>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:heading size="sm">{{ $permission->name }}</flux:heading>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:text>{{ $permission->guard_name }}</flux:text>
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex flex-wrap gap-1">
                            @forelse ($permission->roles as $role)
                                <flux:badge color="zinc">{{ $role->name }}</flux:badge>
                            @empty
                                <flux:badge color="red">{{ __('Sin asignar') }}</flux:badge>
                            @endforelse
                        </div>
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        <div class="flex justify-end gap-2">
                            @can('permisos.actualizar')
                                <flux:modal.trigger name="permission-form">
                                    <flux:button variant="ghost" size="sm" wire:click="edit({{ $permission->id }})" icon="pencil">{{ __('Editar') }}</flux:button>
                                </flux:modal.trigger>
                            @endcan

                            @can('permisos.eliminar')
                                <flux:button variant="danger" size="sm" wire:click="delete({{ $permission->id }})" :disabled="$permission->name === 'admin.acceder'" icon="trash">
                                    {{ __('Eliminar') }}
                                </flux:button>
                            @endcan
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="py-6 text-center text-neutral-500">
                        {{ $search ? __('No se encontraron resultados.') : __('No hay permisos disponibles.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    {{-- Modal crear / editar --}}
    <flux:modal name="permission-form" class="w-full md:w-[36rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingPermissionId ? __('Editar permiso') : __('Crear permiso') }}</flux:heading>
                <flux:text class="text-neutral-600 dark:text-neutral-300">
                    {{ __('Usá nombres claros para que las reglas de acceso sigan siendo legibles.') }}
                </flux:text>
            </div>

            <form wire:submit="save" class="space-y-5">
                <flux:input wire:model="name" :label="__('Nombre del permiso')" type="text" required />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancelar') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" type="submit">
                        {{ $editingPermissionId ? __('Actualizar permiso') : __('Crear permiso') }}
                    </flux:button>
                </div>
            </form>
        </div>
    </flux:modal>
</section>
