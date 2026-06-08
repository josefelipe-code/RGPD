<?php

use App\Enums\CaseStatus;
use App\Enums\MilestoneAction;
use App\Models\CaseMilestone;
use App\Models\Expedient;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Detalle del Expediente')] class extends Component {
    public Expedient $expedient;

    // Add milestone form
    public string $milestoneAction = '';
    public string $milestoneNotes = '';

    public function mount(Expedient $expedient): void
    {
        $this->expedient = $expedient;
        abort_unless(Auth::user()->can('expedientes.ver'), 403);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'milestoneAction' => ['required', Rule::enum(MilestoneAction::class)],
            'milestoneNotes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    #[Computed]
    public function milestones()
    {
        return $this->expedient
            ->milestones()
            ->with('user')
            ->latest()
            ->get();
    }

    #[Computed]
    public function availableUsers()
    {
        return User::query()->orderBy('name')->get();
    }

    public function addMilestone(): void
    {
        abort_unless(Auth::user()->can('hitos.crear'), 403);

        $validated = $this->validate();

        $this->expedient->milestones()->create([
            'user_id' => Auth::id(),
            'action' => $validated['milestoneAction'],
            'notes' => $validated['milestoneNotes'] ?: null,
        ]);

        $this->reset(['milestoneAction', 'milestoneNotes']);

        Flux::toast(variant: 'success', text: __('Hito registrado.'));
    }

    private function milestoneLabel(string $action): string
    {
        return match ($action) {
            'opened' => __('Apertura'),
            'replied_client' => __('Respuesta al cliente'),
            'replied_provider' => __('Respuesta al proveedor'),
            'closed' => __('Cierre'),
            default => $action,
        };
    }

    private function milestoneIcon(string $action): string
    {
        return match ($action) {
            'opened' => 'folder-open',
            'replied_client' => 'paper-airplane',
            'replied_provider' => 'paper-airplane',
            'closed' => 'lock-closed',
            default => 'document',
        };
    }

    private function milestoneColor(string $action): string
    {
        return match ($action) {
            'opened' => 'blue',
            'replied_client' => 'green',
            'replied_provider' => 'amber',
            'closed' => 'red',
            default => 'zinc',
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'pending_client' => __('Pendiente del cliente'),
            'pending_provider' => __('Pendiente del proveedor'),
            'concluded' => __('Concluido'),
            default => $status,
        };
    }

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
    {{-- Header con navegación --}}
    <div class="flex items-center gap-3">
        <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('expedientes.index')" wire:navigate>
            {{ __('Volver') }}
        </flux:button>
        <x-page-heading
            :heading="$expedient->case_number"
            :subheading="$this->statusLabel($expedient->status->value)"
        />
    </div>

    {{-- Info principal del expediente --}}
    <flux:card>
        <div class="grid gap-6 sm:grid-cols-2">
            <div>
                <flux:text variant="subtle" size="sm">{{ __('Número de expediente') }}</flux:text>
                <flux:heading size="lg">{{ $expedient->case_number }}</flux:heading>
            </div>

            <div>
                <flux:text variant="subtle" size="sm">{{ __('Estado') }}</flux:text>
                <flux:badge size="md" :color="$this->statusBadgeColor($expedient->status->value)">
                    {{ $this->statusLabel($expedient->status->value) }}
                </flux:badge>
            </div>

            <div>
                <flux:text variant="subtle" size="sm">{{ __('Tipo de solicitud') }}</flux:text>
                <flux:text>{{ $expedient->request_type ?? '—' }}</flux:text>
            </div>

            <div>
                <flux:text variant="subtle" size="sm">{{ __('Email del solicitante') }}</flux:text>
                <flux:text>{{ $expedient->sender_email ?? '—' }}</flux:text>
            </div>

            <div>
                <flux:text variant="subtle" size="sm">{{ __('Teléfono del solicitante') }}</flux:text>
                <flux:text>{{ $expedient->sender_phone ?? '—' }}</flux:text>
            </div>

            <div>
                <flux:text variant="subtle" size="sm">{{ __('Cuenta de correo') }}</flux:text>
                <flux:text>{{ $expedient->mailAccount?->label ?? $expedient->mailAccount?->email_address ?? '—' }}</flux:text>
            </div>

            <div>
                <flux:text variant="subtle" size="sm">{{ __('Asignado a') }}</flux:text>
                <flux:text>{{ $expedient->assignedUser?->name ?? '—' }}</flux:text>
            </div>

            <div>
                <flux:text variant="subtle" size="sm">{{ __('Fecha de apertura') }}</flux:text>
                <flux:text>{{ $expedient->opened_at?->format('d/m/Y H:i') ?? '—' }}</flux:text>
            </div>

            @if ($expedient->closed_at)
                <div>
                    <flux:text variant="subtle" size="sm">{{ __('Fecha de cierre') }}</flux:text>
                    <flux:text>{{ $expedient->closed_at->format('d/m/Y H:i') }}</flux:text>
                </div>
            @endif
        </div>
    </flux:card>

    {{-- Sección de hitos --}}
    <div class="space-y-4">
        <flux:heading size="lg">{{ __('Historial de hitos') }}</flux:heading>

        {{-- Agregar hito --}}
        @can('hitos.crear')
            <flux:card>
                <form wire:submit="addMilestone" class="flex flex-col gap-4 sm:flex-row sm:items-end">
                    <flux:field class="flex-1">
                        <flux:label>{{ __('Acción') }}</flux:label>
                        <flux:select wire:model="milestoneAction" required>
                            <flux:select.option value="">{{ __('Seleccionar acción...') }}</flux:select.option>
                            <flux:select.option value="opened">{{ __('Apertura') }}</flux:select.option>
                            <flux:select.option value="replied_client">{{ __('Respuesta al cliente') }}</flux:select.option>
                            <flux:select.option value="replied_provider">{{ __('Respuesta al proveedor') }}</flux:select.option>
                            <flux:select.option value="closed">{{ __('Cierre') }}</flux:select.option>
                        </flux:select>
                    </flux:field>

                    <flux:field class="flex-[2]">
                        <flux:label>{{ __('Notas') }}</flux:label>
                        <flux:input wire:model="milestoneNotes" :placeholder="__('Observaciones opcionales...')" />
                    </flux:field>

                    <flux:button variant="primary" type="submit" icon="plus">
                        {{ __('Agregar') }}
                    </flux:button>
                </form>
            </flux:card>
        @endcan

        {{-- Timeline de hitos --}}
        <div class="space-y-3">
            @forelse ($this->milestones as $milestone)
                <flux:card>
                    <div class="flex items-start gap-3">
                        <div class="flex-shrink-0">
                            <flux:badge :color="$this->milestoneColor($milestone->action->value)" size="sm" icon="{{ $this->milestoneIcon($milestone->action->value) }}">
                                {{ $this->milestoneLabel($milestone->action->value) }}
                            </flux:badge>
                        </div>
                        <div class="flex-1 space-y-1">
                            @if ($milestone->notes)
                                <flux:text>{{ $milestone->notes }}</flux:text>
                            @endif
                            <div class="flex items-center gap-2 text-xs text-neutral-500">
                                <span>{{ $milestone->user?->name ?? __('Usuario desconocido') }}</span>
                                <span>·</span>
                                <span>{{ $milestone->created_at->diffForHumans() }}</span>
                                <span>·</span>
                                <span>{{ $milestone->created_at->format('d/m/Y H:i') }}</span>
                            </div>
                        </div>
                    </div>
                </flux:card>
            @empty
                <flux:card>
                    <flux:text variant="subtle" class="text-center">
                        {{ __('No hay hitos registrados para este expediente.') }}
                    </flux:text>
                </flux:card>
            @endforelse
        </div>
    </div>
</section>
