<?php

use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

new #[Title('Roles')] class extends Component {
    use WithPagination;

    public string $search = '';
    public int $perPage = 10;
    public ?int $editingRoleId = null;
    public string $name = '';

    /**
     * @var array<int, string>
     */
    public array $selectedPermissions = [];

    public function mount(): void
    {
        abort_unless(Auth::user()->can('roles.ver'), 403);
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
                Rule::unique('roles', 'name')->ignore($this->editingRoleId),
            ],
            'selectedPermissions' => ['array'],
            'selectedPermissions.*' => ['string', 'exists:permissions,name'],
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
    public function roles()
    {
        return Role::query()
            ->with('permissions')
            ->when($this->search, fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
            ->orderBy('name')
            ->paginate($this->perPage);
    }

    #[Computed]
    public function permissions(): Collection
    {
        return Permission::query()
            ->orderBy('name')
            ->get();
    }

    public function create(): void
    {
        $this->authorizeAbility('roles.crear');
        $this->resetForm();
    }

    public function edit(int $roleId): void
    {
        $this->authorizeAbility('roles.actualizar');

        $role = Role::query()->with('permissions')->findOrFail($roleId);

        $this->editingRoleId = $role->id;
        $this->name = $role->name;
        $this->selectedPermissions = $role->permissions->pluck('name')->all();

        $this->resetErrorBag();
    }

    public function save(): void
    {
        $isCreating = $this->editingRoleId === null;

        $this->authorizeAbility($isCreating ? 'roles.crear' : 'roles.actualizar');

        $validated = $this->validate();

        $role = $isCreating
            ? Role::create(['name' => $validated['name'], 'guard_name' => 'web'])
            : tap(Role::query()->findOrFail($this->editingRoleId), function (Role $role) use ($validated): void {
                $role->update([
                    'name' => $validated['name'],
                ]);
            });

        $role->syncPermissions($validated['selectedPermissions'] ?? []);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->resetForm();

        Flux::modal('role-form')->close();

        Flux::toast(variant: 'success', text: $isCreating
            ? __('Rol creado.')
            : __('Rol actualizado.'));
    }

    public function delete(int $roleId): void
    {
        $this->authorizeAbility('roles.eliminar');

        $role = Role::query()->findOrFail($roleId);

        if ($role->name === 'Super Administrador') {
            throw ValidationException::withMessages([
                'general' => __('El rol Super Administrador no se puede eliminar.'),
            ]);
        }

        $role->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if ($this->editingRoleId === $roleId) {
            $this->resetForm();
        }

        Flux::toast(variant: 'success', text: __('Rol eliminado.'));
    }

    public function cancel(): void
    {
        $this->resetForm();
        Flux::modal('role-form')->close();
    }

    private function authorizeAbility(string $ability): void
    {
        abort_unless(Auth::user()->can($ability), 403);
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingRoleId',
            'name',
            'selectedPermissions',
        ]);

        $this->resetErrorBag();
    }
}; ?>

<section class="space-y-6">
    <x-page-heading :heading="__('Roles')" :subheading="__('Agrupá permisos en roles reutilizables para que los usuarios hereden acceso de forma consistente.')" />

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

        @can('roles.crear')
            <flux:modal.trigger name="role-form">
                <flux:button variant="primary" wire:click="create" icon="plus">{{ __('Crear rol') }}</flux:button>
            </flux:modal.trigger>
        @endcan
    </div>

    {{-- Tabla de roles --}}
    <flux:table :paginate="$this->roles">
        <flux:table.columns>
            <flux:table.column>{{ __('ID') }}</flux:table.column>
            <flux:table.column>{{ __('Nombre') }}</flux:table.column>
            <flux:table.column>{{ __('Permisos') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Acciones') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->roles as $role)
                <flux:table.row wire:key="role-row-{{ $role->id }}">
                    <flux:table.cell>
                        <flux:text variant="subtle">{{ $role->id }}</flux:text>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:heading size="sm">{{ $role->name }}</flux:heading>
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex flex-wrap gap-1">
                            @forelse ($role->permissions as $permission)
                                <flux:badge color="zinc">{{ $permission->name }}</flux:badge>
                            @empty
                                <flux:badge color="red">{{ __('Sin permisos') }}</flux:badge>
                            @endforelse
                        </div>
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        <div class="flex justify-end gap-2">
                            @can('roles.actualizar')
                                <flux:modal.trigger name="role-form">
                                    <flux:button variant="ghost" size="sm" wire:click="edit({{ $role->id }})" icon="pencil">{{ __('Editar') }}</flux:button>
                                </flux:modal.trigger>
                            @endcan

                            @can('roles.eliminar')
                                <flux:button variant="danger" size="sm" wire:click="delete({{ $role->id }})" :disabled="$role->name === 'Super Administrador'" icon="trash">
                                    {{ __('Eliminar') }}
                                </flux:button>
                            @endcan
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="4" class="py-6 text-center text-neutral-500">
                        {{ $search ? __('No se encontraron resultados.') : __('No hay roles disponibles.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    {{-- Modal crear / editar --}}
    <flux:modal name="role-form" class="w-full md:w-[36rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingRoleId ? __('Editar rol') : __('Crear rol') }}</flux:heading>
                <flux:text class="text-neutral-600 dark:text-neutral-300">
                    {{ __('Asigná permisos a cada rol y reutilizalos entre usuarios.') }}
                </flux:text>
            </div>

            <form wire:submit="save" class="space-y-5">
                <flux:input wire:model="name" :label="__('Nombre del rol')" type="text" required />

                <div class="space-y-3">
                    <flux:heading size="sm">{{ __('Permisos') }}</flux:heading>

                    <div class="grid gap-3">
                        @foreach ($this->permissions as $permission)
                            <label wire:key="modal-role-permission-{{ $permission->id }}" class="flex items-center gap-3 rounded-lg border border-neutral-200 px-3 py-2 dark:border-neutral-700">
                                <flux:checkbox wire:model="selectedPermissions" value="{{ $permission->name }}" />
                                <div class="flex flex-col">
                                    <span class="text-sm text-neutral-700 dark:text-neutral-200">{{ $permission->name }}</span>
                                    <span class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('Guard') }}: {{ $permission->guard_name }}</span>
                                </div>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancelar') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" type="submit">
                        {{ $editingRoleId ? __('Actualizar rol') : __('Crear rol') }}
                    </flux:button>
                </div>
            </form>
        </div>
    </flux:modal>
</section>
