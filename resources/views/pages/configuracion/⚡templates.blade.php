<?php

use App\Models\Template;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Plantillas')] class extends Component {
    use WithPagination;

    public string $search = '';
    public int $perPage = 10;
    public ?int $editingTemplateId = null;
    public string $name = '';
    public string $subject = '';
    public string $body = '';
    public bool $isActive = true;

    /** Livewire inicializa el formulario y filtros de plantillas. */
    public function mount(): void
    {
        abort_unless(Auth::user()->can('plantillas.ver'), 403);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    /** Define la validación de plantillas de correo. */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'isActive' => ['boolean'],
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
    /** Computed que filtra y pagina las plantillas visibles. */
    public function templates()
    {
        return Template::query()
            ->when($this->search, fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('subject', 'like', "%{$this->search}%")
                ->orWhere('body', 'like', "%{$this->search}%"))
            ->orderByDesc('created_at')
            ->paginate($this->perPage);
    }

    /** Acción `wire:click` que abre el alta de una plantilla. */
    public function create(): void
    {
        $this->authorizeAbility('plantillas.crear');
        $this->resetForm();
    }

    /** Acción `wire:click` que carga una plantilla para edición. */
    public function edit(int $id): void
    {
        $this->authorizeAbility('plantillas.actualizar');

        $template = $this->getTemplate($id);

        $this->editingTemplateId = $template->id;
        $this->name = $template->name;
        $this->subject = $template->subject ?? '';
        $this->body = $template->body ?? '';
        $this->isActive = $template->is_active;

        $this->resetErrorBag();
    }

    /** Acción `wire:submit` que valida y guarda la plantilla. */
    public function save(): void
    {
        $isCreating = $this->editingTemplateId === null;

        $this->authorizeAbility($isCreating ? 'plantillas.crear' : 'plantillas.actualizar');

        $validated = $this->validate($this->rules());

        if ($isCreating) {
            Template::create([
                'name' => $validated['name'],
                'subject' => $validated['subject'],
                'body' => $validated['body'],
                'is_active' => $validated['isActive'],
            ]);
        } else {
            $template = $this->getTemplate($this->editingTemplateId);

            $template->update([
                'name' => $validated['name'],
                'subject' => $validated['subject'],
                'body' => $validated['body'],
                'is_active' => $validated['isActive'],
            ]);
        }

        $this->resetForm();

        Flux::modal('template-form')->close();

        Flux::toast(variant: 'success', text: $isCreating
            ? __('Plantilla creada.')
            : __('Plantilla actualizada.'));
    }

    /** Acción `wire:click` que activa o desactiva una plantilla. */
    public function toggle(int $id): void
    {
        $template = $this->getTemplate($id);

        $template->update(['is_active' => ! $template->is_active]);

        Flux::toast(variant: 'success', text: $template->is_active
            ? __('Plantilla activada.')
            : __('Plantilla desactivada.'));
    }

    /** Acción `wire:click` que elimina una plantilla autorizada. */
    public function delete(int $id): void
    {
        $this->authorizeAbility('plantillas.eliminar');

        $template = $this->getTemplate($id);
        $template->delete();

        if ($this->editingTemplateId === $id) {
            $this->resetForm();
        }

        Flux::toast(variant: 'success', text: __('Plantilla eliminada.'));
    }

    /** Acción `wire:click` que cancela y limpia la edición. */
    public function cancel(): void
    {
        $this->resetForm();
        Flux::modal('template-form')->close();
    }

    /** Resuelve una plantilla existente dentro de las operaciones CRUD. */
    private function getTemplate(int $id): Template
    {
        return Template::findOrFail($id);
    }

    /** Comprueba el permiso requerido por la operación de plantillas. */
    private function authorizeAbility(string $ability): void
    {
        abort_unless(Auth::user()->can($ability), 403);
    }

    /** Restablece el formulario y el identificador de edición. */
    private function resetForm(): void
    {
        $this->reset([
            'editingTemplateId',
            'name',
            'subject',
            'body',
            'isActive',
        ]);

        $this->resetErrorBag();
    }
}; ?>

<section class="space-y-6">
    <x-page-heading :heading="__('Plantillas')" :subheading="__('Plantillas de correo compartidas para todo el equipo. Usalas con cualquier cuenta al responder o reenviar.')" />

    @if ($errors->has('general'))
        <flux:callout variant="danger" icon="x-circle" :heading="$errors->first('general')" />
    @endif

    {{-- Toolbar --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <flux:input wire:model.live="search" icon="magnifying-glass" :placeholder="__('Buscar por nombre, asunto o contenido...')" class="max-w-sm" />

        <flux:select wire:model.live="perPage" :label="__('Resultados')" size="sm" class="sm:w-40">
            <flux:select.option value="10">10</flux:select.option>
            <flux:select.option value="20">20</flux:select.option>
            <flux:select.option value="50">50</flux:select.option>
            <flux:select.option value="100">100</flux:select.option>
        </flux:select>

        @can('plantillas.crear')
            <flux:modal.trigger name="template-form">
                <flux:button variant="primary" wire:click="create" icon="plus">{{ __('Crear plantilla') }}</flux:button>
            </flux:modal.trigger>
        @endcan
    </div>

    {{-- Tabla de plantillas --}}
    <flux:table :paginate="$this->templates">
        <flux:table.columns>
            <flux:table.column>{{ __('Nombre') }}</flux:table.column>
            <flux:table.column>{{ __('Asunto') }}</flux:table.column>
            <flux:table.column>{{ __('Vista previa') }}</flux:table.column>
            <flux:table.column>{{ __('Estado') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Acciones') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->templates as $template)
                <flux:table.row wire:key="template-row-{{ $template->id }}">
                    <flux:table.cell>
                        <flux:heading size="sm">{{ $template->name }}</flux:heading>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:text>{{ $template->subject ?: '—' }}</flux:text>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:text variant="subtle" class="max-w-xs truncate">{{ Str::limit(strip_tags($template->body), 60) }}</flux:text>
                    </flux:table.cell>
                    <flux:table.cell>
                        @if ($template->is_active)
                            <flux:badge color="green">{{ __('Activa') }}</flux:badge>
                        @else
                            <flux:badge color="zinc">{{ __('Inactiva') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        <div class="flex justify-end gap-2">
                            @can('plantillas.actualizar')
                                <flux:modal.trigger name="template-form">
                                    <flux:button variant="ghost" size="sm" wire:click="edit({{ $template->id }})" icon="pencil">{{ __('Editar') }}</flux:button>
                                </flux:modal.trigger>
                            @endcan

                            <flux:button
                                variant="{{ $template->is_active ? 'ghost' : 'primary' }}"
                                size="sm"
                                wire:click="toggle({{ $template->id }})"
                                icon="{{ $template->is_active ? 'pause' : 'play' }}"
                            >
                                {{ $template->is_active ? __('Desactivar') : __('Activar') }}
                            </flux:button>

                            @can('plantillas.eliminar')
                                <flux:button variant="danger" size="sm" wire:click="delete({{ $template->id }})" icon="trash">{{ __('Eliminar') }}</flux:button>
                            @endcan
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="py-6 text-center text-neutral-500">
                        {{ $search ? __('No se encontraron resultados.') : __('No hay plantillas configuradas.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    {{-- Modal crear / editar --}}
    <flux:modal name="template-form" class="w-full md:w-[35rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingTemplateId ? __('Editar plantilla') : __('Crear plantilla') }}</flux:heading>
                <flux:text class="text-neutral-600 dark:text-neutral-300">
                    {{ __('Configurá el nombre, asunto y contenido de la plantilla. Todos los usuarios con permiso pueden ver y usar estas plantillas.') }}
                </flux:text>
            </div>

            <form wire:submit="save" class="space-y-5">
                <flux:input wire:model="name" :label="__('Nombre de la plantilla')" type="text" required :placeholder="__('Ej: Respuesta inicial, Seguimiento de caso')" />

                <flux:input wire:model="subject" :label="__('Asunto')" type="text" :placeholder="__('Ej: Re: Consulta sobre su caso')" />

                <flux:textarea wire:model="body" :label="__('Contenido de la plantilla')" :rows="8" :placeholder="__('Escribí el contenido de tu plantilla...')" />

                <flux:checkbox wire:model="isActive" :label="__('Plantilla activa')" />

                <div class="flex justify-end gap-2">
                    <flux:button variant="ghost" wire:click="cancel">{{ __('Cancelar') }}</flux:button>
                    <flux:button variant="primary" type="submit">
                        {{ $editingTemplateId ? __('Actualizar plantilla') : __('Crear plantilla') }}
                    </flux:button>
                </div>
            </form>
        </div>
    </flux:modal>
</section>
