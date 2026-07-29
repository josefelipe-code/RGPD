<?php

use App\Models\Category;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Categorías')] class extends Component {
    use WithPagination;

    public string $search = '';
    public int $perPage = 10;
    public ?int $editingCategoryId = null;
    public string $name = '';
    public string $slug = '';
    public string $description = '';
    public string $color = '#3B82F6';

    /** Livewire inicializa filtros y el formulario de categorías. */
    public function mount(): void
    {
        abort_unless(Auth::user()->can('categorias.ver'), 403);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', Rule::unique('categories', 'slug')->ignore($this->editingCategoryId)],
            'description' => ['nullable', 'string', 'max:1000'],
            'color' => ['nullable', 'string', 'max:7'],
        ];
    }

    /** Livewire reinicia la paginación al cambiar la búsqueda. */
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /** Livewire reinicia la paginación al cambiar el tamaño de página. */
    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    #[Computed]
    /** Computed que filtra y pagina las categorías administrables. */
    public function categories()
    {
        return Category::query()
            ->when($this->search, fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('description', 'like', "%{$this->search}%"))
            ->orderBy('name')
            ->paginate($this->perPage);
    }

    /** Acción `wire:click` que abre el alta de una categoría. */
    public function create(): void
    {
        $this->authorizeAbility('categorias.crear');
        $this->resetForm();
    }

    /** Acción `wire:click` que carga una categoría para edición. */
    public function edit(int $categoryId): void
    {
        $this->authorizeAbility('categorias.actualizar');

        $category = Category::query()->findOrFail($categoryId);

        $this->editingCategoryId = $category->id;
        $this->name = $category->name;
        $this->slug = $category->slug;
        $this->description = $category->description ?? '';
        $this->color = $category->color ?? '#3B82F6';

        $this->resetErrorBag();
    }

    /** Acción `wire:submit` que valida y guarda la categoría. */
    public function save(): void
    {
        $isCreating = $this->editingCategoryId === null;

        $this->authorizeAbility($isCreating ? 'categorias.crear' : 'categorias.actualizar');

        $validated = $this->validate();

        if ($isCreating) {
            Category::create($validated);
        } else {
            Category::query()->findOrFail($this->editingCategoryId)->update($validated);
        }

        $this->resetForm();

        Flux::modal('category-form')->close();

        Flux::toast(variant: 'success', text: $isCreating
            ? __('Categoría creada.')
            : __('Categoría actualizada.'));
    }

    /** Acción `wire:click` que elimina una categoría autorizada. */
    public function delete(int $categoryId): void
    {
        $this->authorizeAbility('categorias.eliminar');

        $category = Category::query()->withCount('contacts')->findOrFail($categoryId);

        if ($category->contacts_count > 0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'general' => __('No podés eliminar una categoría que tiene contactos asignados.'),
            ]);
        }

        $category->delete();

        if ($this->editingCategoryId === $categoryId) {
            $this->resetForm();
        }

        Flux::toast(variant: 'success', text: __('Categoría eliminada.'));
    }

    /** Acción `wire:click` que cancela y limpia la edición. */
    public function cancel(): void
    {
        $this->resetForm();
        Flux::modal('category-form')->close();
    }

    /** Comprueba el permiso requerido por las operaciones de categorías. */
    private function authorizeAbility(string $ability): void
    {
        abort_unless(Auth::user()->can($ability), 403);
    }

    /** Restablece el formulario y la categoría en edición. */
    private function resetForm(): void
    {
        $this->reset([
            'editingCategoryId',
            'name',
            'slug',
            'description',
            'color',
        ]);

        $this->resetErrorBag();
    }
}; ?>

<section class="space-y-6">
    <x-page-heading :heading="__('Categorías')" :subheading="__('Clasificá tus contactos con categorías reutilizables en todo el sistema.')" />

    @if ($errors->has('general'))
        <flux:callout variant="danger" icon="x-circle" :heading="$errors->first('general')" />
    @endif

    {{-- Toolbar --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <flux:input wire:model.live="search" icon="magnifying-glass" :placeholder="__('Buscar por nombre o descripción...')" class="max-w-sm" />

        <flux:select wire:model.live="perPage" :label="__('Resultados')" size="sm" class="sm:w-40">
            <flux:select.option value="10">10</flux:select.option>
            <flux:select.option value="20">20</flux:select.option>
            <flux:select.option value="50">50</flux:select.option>
            <flux:select.option value="100">100</flux:select.option>
        </flux:select>

        @can('categorias.crear')
            <flux:modal.trigger name="category-form">
                <flux:button variant="primary" wire:click="create" icon="plus">{{ __('Crear categoría') }}</flux:button>
            </flux:modal.trigger>
        @endcan
    </div>

    {{-- Tabla de categorías --}}
    <flux:table :paginate="$this->categories">
        <flux:table.columns>
            <flux:table.column>{{ __('ID') }}</flux:table.column>
            <flux:table.column>{{ __('Nombre') }}</flux:table.column>
            <flux:table.column>{{ __('Descripción') }}</flux:table.column>
            <flux:table.column>{{ __('Color') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Acciones') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->categories as $category)
                <flux:table.row wire:key="category-row-{{ $category->id }}">
                    <flux:table.cell>
                        <flux:text variant="subtle">{{ $category->id }}</flux:text>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:heading size="sm">{{ $category->name }}</flux:heading>
                        <flux:text variant="subtle">{{ $category->slug }}</flux:text>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:text>{{ Str::limit($category->description ?? '', 60) }}</flux:text>
                    </flux:table.cell>
                    <flux:table.cell>
                        @if ($category->color)
                            <div class="flex items-center gap-2">
                                <span class="inline-block h-4 w-4 rounded" style="background-color: {{ $category->color }}"></span>
                                <flux:text variant="subtle">{{ $category->color }}</flux:text>
                            </div>
                        @else
                            <flux:text variant="subtle">—</flux:text>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        <div class="flex justify-end gap-2">
                            @can('categorias.actualizar')
                                <flux:modal.trigger name="category-form">
                                    <flux:button variant="ghost" size="sm" wire:click="edit({{ $category->id }})" icon="pencil">{{ __('Editar') }}</flux:button>
                                </flux:modal.trigger>
                            @endcan

                            @can('categorias.eliminar')
                                <flux:button variant="danger" size="sm" wire:click="delete({{ $category->id }})" icon="trash">{{ __('Eliminar') }}</flux:button>
                            @endcan
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="py-6 text-center text-neutral-500">
                        {{ $search ? __('No se encontraron resultados.') : __('No hay categorías disponibles.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    {{-- Modal crear / editar --}}
    <flux:modal name="category-form" class="w-full md:w-[36rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingCategoryId ? __('Editar categoría') : __('Crear categoría') }}</flux:heading>
                <flux:text class="text-neutral-600 dark:text-neutral-300">
                    {{ __('Las categorías permiten organizar contactos por tipo de relación.') }}
                </flux:text>
            </div>

            <form wire:submit="save" class="space-y-5">
                <flux:input wire:model="name" :label="__('Nombre')" type="text" required :placeholder="__('Ej: Proveedores, Soporte')" />
                <flux:input wire:model="slug" :label="__('Slug')" type="text" required :placeholder="__('proveedores')" />
                <flux:textarea wire:model="description" :label="__('Descripción')" :rows="3" :placeholder="__('Descripción opcional de la categoría...')" />

                <div class="grid gap-3 sm:grid-cols-2">
                    <flux:input wire:model="color" :label="__('Color')" type="color" />
                    <flux:input wire:model="color" :label="__('Código de color')" type="text" :placeholder="'#3B82F6'" />
                </div>

                <div class="flex justify-end gap-2">
                    <flux:button variant="ghost" wire:click="cancel">{{ __('Cancelar') }}</flux:button>
                    <flux:button variant="primary" type="submit">
                        {{ $editingCategoryId ? __('Actualizar categoría') : __('Crear categoría') }}
                    </flux:button>
                </div>
            </form>
        </div>
    </flux:modal>
</section>
