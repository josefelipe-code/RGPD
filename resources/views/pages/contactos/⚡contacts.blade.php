<?php

use App\Models\Category;
use App\Models\Contact;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Contactos')] class extends Component {
    use WithPagination;

    public string $search = '';
    public int $perPage = 10;
    public ?int $editingContactId = null;
    public string $name = '';
    public string $email = '';
    public string $phone = '';
    public string $company = '';
    public string $notes = '';

    /**
     * @var array<int, int>
     */
    public array $selectedCategories = [];

    /** Livewire inicializa filtros y categorías al montar la página. */
    public function mount(): void
    {
        abort_unless(Auth::user()->can('contactos.ver'), 403);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    /** Define la validación del formulario de contactos. */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'company' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'selectedCategories' => ['array'],
            'selectedCategories.*' => ['integer', 'exists:categories,id'],
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
    /** Computed que filtra y pagina los contactos mostrados. */
    public function contacts()
    {
        return Contact::query()
            ->with('categories')
            ->when($this->search, fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('email', 'like', "%{$this->search}%")
                ->orWhere('phone', 'like', "%{$this->search}%")
                ->orWhere('company', 'like', "%{$this->search}%"))
            ->orderBy('name')
            ->paginate($this->perPage);
    }

    #[Computed]
    /** Computed que lista categorías disponibles para el formulario. */
    public function availableCategories()
    {
        return Category::query()->orderBy('name')->get();
    }

    /** Acción `wire:click` que abre el formulario de alta. */
    public function create(): void
    {
        $this->authorizeAbility('contactos.crear');
        $this->resetForm();
    }

    /** Acción `wire:click` que carga un contacto existente para edición. */
    public function edit(int $contactId): void
    {
        $this->authorizeAbility('contactos.actualizar');

        $contact = Contact::query()->with('categories')->findOrFail($contactId);

        $this->editingContactId = $contact->id;
        $this->name = $contact->name;
        $this->email = $contact->email ?? '';
        $this->phone = $contact->phone ?? '';
        $this->company = $contact->company ?? '';
        $this->notes = $contact->notes ?? '';
        $this->selectedCategories = $contact->categories->pluck('id')->all();

        $this->resetErrorBag();
    }

    /** Acción `wire:submit` que valida y guarda el contacto. */
    public function save(): void
    {
        $isCreating = $this->editingContactId === null;

        $this->authorizeAbility($isCreating ? 'contactos.crear' : 'contactos.actualizar');

        $validated = $this->validate();

        $contact = $isCreating
            ? Contact::create([
                'name' => $validated['name'],
                'email' => $validated['email'] ?: null,
                'phone' => $validated['phone'] ?: null,
                'company' => $validated['company'] ?: null,
                'notes' => $validated['notes'] ?: null,
            ])
            : tap(Contact::query()->findOrFail($this->editingContactId), function (Contact $contact) use ($validated): void {
                $contact->update([
                    'name' => $validated['name'],
                    'email' => $validated['email'] ?: null,
                    'phone' => $validated['phone'] ?: null,
                    'company' => $validated['company'] ?: null,
                    'notes' => $validated['notes'] ?: null,
                ]);
            });

        $contact->categories()->sync($validated['selectedCategories'] ?? []);

        $this->resetForm();

        Flux::modal('contact-form')->close();

        Flux::toast(variant: 'success', text: $isCreating
            ? __('Contacto creado.')
            : __('Contacto actualizado.'));
    }

    /** Acción `wire:click` que elimina el contacto autorizado. */
    public function delete(int $contactId): void
    {
        $this->authorizeAbility('contactos.eliminar');

        Contact::query()->findOrFail($contactId)->delete();

        if ($this->editingContactId === $contactId) {
            $this->resetForm();
        }

        Flux::toast(variant: 'success', text: __('Contacto eliminado.'));
    }

    /** Acción `wire:click` que cancela la edición y limpia el formulario. */
    public function cancel(): void
    {
        $this->resetForm();
        Flux::modal('contact-form')->close();
    }

    /** Comprueba el permiso requerido por las acciones CRUD de contactos. */
    private function authorizeAbility(string $ability): void
    {
        abort_unless(Auth::user()->can($ability), 403);
    }

    /** Restablece los campos y el identificador del formulario. */
    private function resetForm(): void
    {
        $this->reset([
            'editingContactId',
            'name',
            'email',
            'phone',
            'company',
            'notes',
            'selectedCategories',
        ]);

        $this->resetErrorBag();
    }
}; ?>

<section class="space-y-6">
    <x-page-heading :heading="__('Contactos')" :subheading="__('Gestioná tu agenda de contactos categorizados para reutilizar en todo el sistema.')" />

    @if ($errors->has('general'))
        <flux:callout variant="danger" icon="x-circle" :heading="$errors->first('general')" />
    @endif

    {{-- Toolbar --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <flux:input wire:model.live="search" icon="magnifying-glass" :placeholder="__('Buscar por nombre, email, teléfono o empresa...')" class="max-w-sm" />

        <flux:select wire:model.live="perPage" :label="__('Resultados')" size="sm" class="sm:w-40">
            <flux:select.option value="10">10</flux:select.option>
            <flux:select.option value="20">20</flux:select.option>
            <flux:select.option value="50">50</flux:select.option>
            <flux:select.option value="100">100</flux:select.option>
        </flux:select>

        @can('contactos.crear')
            <flux:modal.trigger name="contact-form">
                <flux:button variant="primary" wire:click="create" icon="plus">{{ __('Crear contacto') }}</flux:button>
            </flux:modal.trigger>
        @endcan
    </div>

    {{-- Tabla de contactos --}}
    <flux:table :paginate="$this->contacts">
        <flux:table.columns>
            <flux:table.column>{{ __('ID') }}</flux:table.column>
            <flux:table.column>{{ __('Nombre') }}</flux:table.column>
            <flux:table.column>{{ __('Email') }}</flux:table.column>
            <flux:table.column>{{ __('Empresa') }}</flux:table.column>
            <flux:table.column>{{ __('Categorías') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Acciones') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->contacts as $contact)
                <flux:table.row wire:key="contact-row-{{ $contact->id }}">
                    <flux:table.cell>
                        <flux:text variant="subtle">{{ $contact->id }}</flux:text>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:heading size="sm">{{ $contact->name }}</flux:heading>
                        @if ($contact->phone)
                            <flux:text variant="subtle">{{ $contact->phone }}</flux:text>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:text>{{ $contact->email ?? '—' }}</flux:text>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:text>{{ $contact->company ?? '—' }}</flux:text>
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex flex-wrap gap-1">
                            @forelse ($contact->categories as $category)
                                <flux:badge color="zinc">{{ $category->name }}</flux:badge>
                            @empty
                                <flux:badge color="red">{{ __('Sin categorías') }}</flux:badge>
                            @endforelse
                        </div>
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        <div class="flex justify-end gap-2">
                            @can('contactos.actualizar')
                                <flux:modal.trigger name="contact-form">
                                    <flux:button variant="ghost" size="sm" wire:click="edit({{ $contact->id }})" icon="pencil">{{ __('Editar') }}</flux:button>
                                </flux:modal.trigger>
                            @endcan

                            @can('contactos.eliminar')
                                <flux:button variant="danger" size="sm" wire:click="delete({{ $contact->id }})" icon="trash">{{ __('Eliminar') }}</flux:button>
                            @endcan
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="py-6 text-center text-neutral-500">
                        {{ $search ? __('No se encontraron resultados.') : __('No hay contactos disponibles.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    {{-- Modal crear / editar --}}
    <flux:modal name="contact-form" class="w-full md:w-[36rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingContactId ? __('Editar contacto') : __('Crear contacto') }}</flux:heading>
                <flux:text class="text-neutral-600 dark:text-neutral-300">
                    {{ __('Los contactos pueden usarse como destinatarios, CC o BCC en comunicaciones futuras.') }}
                </flux:text>
            </div>

            <form wire:submit="save" class="space-y-5">
                <flux:input wire:model="name" :label="__('Nombre')" type="text" required />
                <flux:input wire:model="email" :label="__('Email')" type="email" />
                <flux:input wire:model="phone" :label="__('Teléfono')" type="text" />
                <flux:input wire:model="company" :label="__('Empresa')" type="text" />
                <flux:textarea wire:model="notes" :label="__('Notas')" :rows="3" :placeholder="__('Notas adicionales...')" />

                <div class="space-y-3">
                    <flux:heading size="sm">{{ __('Categorías') }}</flux:heading>

                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach ($this->availableCategories as $category)
                            <label wire:key="modal-category-{{ $category->id }}" class="flex items-center gap-3 rounded-lg border border-neutral-200 px-3 py-2 dark:border-neutral-700">
                                <flux:checkbox wire:model="selectedCategories" value="{{ $category->id }}" />
                                <span class="flex items-center gap-2 text-sm text-neutral-700 dark:text-neutral-200">
                                    @if ($category->color)
                                        <span class="inline-block h-3 w-3 rounded" style="background-color: {{ $category->color }}"></span>
                                    @endif
                                    {{ $category->name }}
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    <flux:button variant="ghost" wire:click="cancel">{{ __('Cancelar') }}</flux:button>
                    <flux:button variant="primary" type="submit">
                        {{ $editingContactId ? __('Actualizar contacto') : __('Crear contacto') }}
                    </flux:button>
                </div>
            </form>
        </div>
    </flux:modal>
</section>
