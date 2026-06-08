<?php

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Permission\Models\Role;

new #[Title('Usuarios')] class extends Component {
    use PasswordValidationRules;
    use ProfileValidationRules;
    use WithPagination;

    public string $search = '';
    public int $perPage = 10;
    public ?int $editingUserId = null;
    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';

    /**
     * @var array<int, string>
     */
    public array $selectedRoles = [];

    public function mount(): void
    {
        abort_unless(Auth::user()->can('usuarios.ver'), 403);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        $passwordRules = $this->editingUserId === null
            ? $this->passwordRules()
            : ['nullable', 'string', ...array_slice($this->passwordRules(), 2)];

        return [
            ...$this->profileRules($this->editingUserId),
            'password' => $passwordRules,
            'selectedRoles' => ['array'],
            'selectedRoles.*' => ['string', 'exists:roles,name'],
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
    public function users()
    {
        return User::query()
            ->with('roles')
            ->when($this->search, fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('email', 'like', "%{$this->search}%"))
            ->orderBy('name')
            ->paginate($this->perPage);
    }

    #[Computed]
    public function availableRoles(): Collection
    {
        return Role::query()
            ->orderBy('name')
            ->get();
    }

    public function create(): void
    {
        $this->authorizeAbility('usuarios.crear');
        $this->resetForm();
    }

    public function edit(int $userId): void
    {
        $this->authorizeAbility('usuarios.actualizar');

        $user = User::query()->with('roles')->findOrFail($userId);

        $this->editingUserId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->password = '';
        $this->password_confirmation = '';
        $this->selectedRoles = $user->roles->pluck('name')->all();

        $this->resetErrorBag();
    }

    public function save(): void
    {
        $isCreating = $this->editingUserId === null;

        $this->authorizeAbility($isCreating ? 'usuarios.crear' : 'usuarios.actualizar');

        $validated = $this->validate();

        $user = $isCreating
            ? User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'email_verified_at' => now(),
            ])
            : tap(User::query()->findOrFail($this->editingUserId), function (User $user) use ($validated): void {
                $attributes = [
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                ];

                if ($validated['password'] !== null && $validated['password'] !== '') {
                    $attributes['password'] = $validated['password'];
                }

                $user->update($attributes);
            });

        $user->syncRoles($validated['selectedRoles'] ?? []);

        $this->resetForm();

        Flux::modal('user-form')->close();

        Flux::toast(variant: 'success', text: $isCreating
            ? __('Usuario creado.')
            : __('Usuario actualizado.'));
    }

    public function delete(int $userId): void
    {
        $this->authorizeAbility('usuarios.eliminar');

        if (Auth::id() === $userId) {
            throw ValidationException::withMessages([
                'general' => __('No podés eliminar tu propia cuenta desde el panel de administración.'),
            ]);
        }

        User::query()->findOrFail($userId)->delete();

        if ($this->editingUserId === $userId) {
            $this->resetForm();
        }

        Flux::toast(variant: 'success', text: __('Usuario eliminado.'));
    }

    public function cancel(): void
    {
        $this->resetForm();
        Flux::modal('user-form')->close();
    }

    private function authorizeAbility(string $ability): void
    {
        abort_unless(Auth::user()->can($ability), 403);
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingUserId',
            'name',
            'email',
            'password',
            'password_confirmation',
            'selectedRoles',
        ]);

        $this->resetErrorBag();
    }
}; ?>

<section class="space-y-6">
    <x-page-heading :heading="__('Usuarios')" :subheading="__('Creá usuarios, asignales roles y mantené el acceso administrativo ordenado.')" />

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

        @can('usuarios.crear')
            <flux:modal.trigger name="user-form">
                <flux:button variant="primary" wire:click="create" icon="plus">{{ __('Crear usuario') }}</flux:button>
            </flux:modal.trigger>
        @endcan
    </div>

    {{-- Tabla de usuarios --}}
    <flux:table :paginate="$this->users">
        <flux:table.columns>
            <flux:table.column>{{ __('ID') }}</flux:table.column>
            <flux:table.column>{{ __('Nombre') }}</flux:table.column>
            <flux:table.column>{{ __('Email') }}</flux:table.column>
            <flux:table.column>{{ __('Roles') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Acciones') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->users as $user)
                <flux:table.row wire:key="user-row-{{ $user->id }}">
                    <flux:table.cell>
                        <flux:text variant="subtle">{{ $user->id }}</flux:text>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:heading size="sm">{{ $user->name }}</flux:heading>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:text>{{ $user->email }}</flux:text>
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex flex-wrap gap-1">
                            @forelse ($user->roles as $role)
                                <flux:badge color="zinc">{{ $role->name }}</flux:badge>
                            @empty
                                <flux:badge color="red">{{ __('Sin roles') }}</flux:badge>
                            @endforelse
                        </div>
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        <div class="flex justify-end gap-2">
                            @can('usuarios.actualizar')
                                <flux:modal.trigger name="user-form">
                                    <flux:button variant="ghost" size="sm" wire:click="edit({{ $user->id }})" icon="pencil">{{ __('Editar') }}</flux:button>
                                </flux:modal.trigger>
                            @endcan

                            @can('usuarios.eliminar')
                                <flux:button variant="danger" size="sm" wire:click="delete({{ $user->id }})" icon="trash">{{ __('Eliminar') }}</flux:button>
                            @endcan
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="py-6 text-center text-neutral-500">
                        {{ $search ? __('No se encontraron resultados.') : __('No hay usuarios disponibles.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    {{-- Modal crear / editar --}}
    <flux:modal name="user-form" class="w-full md:w-[36rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingUserId ? __('Editar usuario') : __('Crear usuario') }}</flux:heading>
                <flux:text class="text-neutral-600 dark:text-neutral-300">
                    {{ __('Los usuarios reciben permisos solamente a través de roles.') }}
                </flux:text>
            </div>

            <form wire:submit="save" class="space-y-5">
                <flux:input wire:model="name" :label="__('Nombre')" type="text" required />
                <flux:input wire:model="email" :label="__('Email')" type="email" required />
                <flux:input wire:model="password" :label="__('Contraseña')" type="password" viewable :required="$editingUserId === null" />
                <flux:input wire:model="password_confirmation" :label="__('Confirmar contraseña')" type="password" viewable :required="$editingUserId === null" />

                <div class="space-y-3">
                    <flux:heading size="sm">{{ __('Roles') }}</flux:heading>

                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach ($this->availableRoles as $role)
                            <label wire:key="modal-user-role-{{ $role->id }}" class="flex items-center gap-3 rounded-lg border border-neutral-200 px-3 py-2 dark:border-neutral-700">
                                <flux:checkbox wire:model="selectedRoles" value="{{ $role->name }}" />
                                <span class="text-sm text-neutral-700 dark:text-neutral-200">{{ $role->name }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancelar') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" type="submit">
                        {{ $editingUserId ? __('Actualizar usuario') : __('Crear usuario') }}
                    </flux:button>
                </div>
            </form>
        </div>
    </flux:modal>
</section>
