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

    // Inline status control
    public ?string $statusTarget = null;

    // Add milestone form
    public string $milestoneAction = '';
    public string $milestoneNotes = '';

    // Mail composer state
    public ?string $composerMode = null;
    public ?int $composerOriginMessageId = null;

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
            'statusTarget' => ['nullable', Rule::enum(CaseStatus::class)],
        ];
    }

    #[Computed]
    public function milestones()
    {
        return $this->expedient
            ->milestones()
            ->with(['user', 'mailMessage'])
            ->latest()
            ->get();
    }

    #[Computed]
    public function related()
    {
        return $this->expedient
            ->relatedTo(
                $this->expedient->sender_email,
                $this->expedient->sender_phone,
                5
            )
            ->with('assignedUser')
            ->get();
    }

    #[Computed]
    public function associatedMessages()
    {
        return $this->expedient
            ->mailMessages()
            ->with('to')
            ->latest('received_at')
            ->get();
    }

    #[Computed]
    public function availableUsers()
    {
        return User::query()->orderBy('name')->get();
    }

    public function changeStatus(): void
    {
        abort_unless(Auth::user()->can('expedientes.actualizar'), 403);

        $validated = $this->validate([
            'statusTarget' => ['required', Rule::enum(CaseStatus::class)],
        ]);

        $this->expedient->transitionTo(
            CaseStatus::from($validated['statusTarget']),
            Auth::user()
        );

        $this->statusTarget = null;

        Flux::toast(variant: 'success', text: __('Estado actualizado.'));
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

    public function openComposer(string $mode, int $messageId): void
    {
        abort_unless(Auth::user()->can('expedientes.actualizar'), 403);

        $this->composerMode = $mode;
        $this->composerOriginMessageId = $messageId;

        Flux::modal('mail-composer')->show();
    }

    public function closeComposer(): void
    {
        $this->composerMode = null;
        $this->composerOriginMessageId = null;
    }

    private function milestoneLabel(string $action): string
    {
        return match ($action) {
            'opened' => __('Apertura'),
            'replied_client' => __('Respuesta al cliente'),
            'replied_provider' => __('Respuesta al proveedor'),
            'closed' => __('Cierre'),
            'reopened' => __('Reapertura'),
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
            'reopened' => 'arrow-path',
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
            'reopened' => 'purple',
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

    {{-- Inline status control --}}
    @can('expedientes.actualizar')
        <flux:card>
            <flux:heading size="md">{{ __('Cambiar estado') }}</flux:heading>
            <flux:text variant="subtle" size="sm" class="mb-3">{{ __('Actualizá el estado del expediente. Los cambios se registran automáticamente en el historial.') }}</flux:text>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <flux:field class="flex-1">
                    <flux:label>{{ __('Nuevo estado') }}</flux:label>
                    <flux:select wire:model="statusTarget">
                        <flux:select.option value="">{{ __('Seleccionar...') }}</flux:select.option>
                        @if ($expedient->status === \App\Enums\CaseStatus::Concluded)
                            <flux:select.option value="pending_client">{{ __('Pendiente del cliente') }}</flux:select.option>
                            <flux:select.option value="pending_provider">{{ __('Pendiente del proveedor') }}</flux:select.option>
                        @else
                            <flux:select.option value="concluded">{{ __('Concluido') }}</flux:select.option>
                        @endif
                    </flux:select>
                </flux:field>

                <flux:button variant="primary" wire:click="changeStatus" icon="check" :disabled="! $statusTarget">
                    {{ __('Aplicar') }}
                </flux:button>
            </div>
        </flux:card>
    @endcan

    {{-- Related expedientes panel --}}
    <flux:card>
        <flux:heading size="md">{{ __('Expedientes relacionados') }}</flux:heading>
        <flux:text variant="subtle" size="sm" class="mb-3">{{ __('Expedientes que comparten email o teléfono con este caso.') }}</flux:text>

        @forelse ($this->related as $related)
            <div class="flex items-center justify-between py-2 @if (! $loop->first) border-t border-neutral-200 dark:border-neutral-700 @endif" wire:key="related-{{ $related->id }}">
                <div class="flex items-center gap-3">
                    <flux:link :href="route('expedientes.show', $related)" wire:navigate class="font-medium">
                        {{ $related->case_number }}
                    </flux:link>
                    <flux:badge size="sm" :color="$this->statusBadgeColor($related->status->value)">
                        {{ $this->statusLabel($related->status->value) }}
                    </flux:badge>
                </div>
                <flux:text variant="subtle" size="sm">
                    {{ $related->assignedUser?->name ?? '—' }}
                </flux:text>
            </div>
        @empty
            <flux:text variant="subtle" class="text-center py-2">
                {{ __('Sin expedientes relacionados') }}
            </flux:text>
        @endforelse

        @if ($this->related->count() >= 5)
            <flux:text variant="subtle" size="xs" class="mt-2 text-neutral-400">
                {{ __('Mostrando 5 más recientes') }}
            </flux:text>
        @endif
    </flux:card>

    {{-- Associated mail messages --}}
    <flux:card>
        <flux:heading size="md">{{ __('Mensajes asociados') }}</flux:heading>
        <flux:text variant="subtle" size="sm" class="mb-3">{{ __('Mensajes de correo vinculados a este expediente.') }}</flux:text>

        @forelse ($this->associatedMessages as $message)
            <div class="flex items-start gap-3 py-2 @if (! $loop->first) border-t border-neutral-200 dark:border-neutral-700 @endif" wire:key="msg-{{ $message->id }}">
                <flux:badge size="sm" :color="$message->direction->value === 'incoming' ? 'blue' : 'green'">
                    {{ $message->direction->value === 'incoming' ? __('Entrante') : __('Saliente') }}
                </flux:badge>
                <div class="flex-1 min-w-0">
                    <flux:text class="truncate">{{ $message->subject ?? __('Sin asunto') }}</flux:text>
                    <flux:text variant="subtle" size="xs">{{ $message->received_at?->format('d/m/Y H:i') ?? '—' }}</flux:text>
                </div>
                @can('expedientes.actualizar')
                    @if ($message->direction->value === 'incoming')
                        <div class="flex gap-1 shrink-0">
                            <flux:button
                                variant="ghost"
                                size="xs"
                                wire:click="openComposer('reply_client', {{ $message->id }})"
                            >
                                <flux:icon name="arrow-uturn-left" class="w-3 h-3" />
                                {{ __('Responder') }}
                            </flux:button>
                            <flux:button
                                variant="ghost"
                                size="xs"
                                wire:click="openComposer('forward_provider', {{ $message->id }})"
                            >
                                <flux:icon name="arrow-turn-down-right" class="w-3 h-3" />
                                {{ __('Reenviar') }}
                            </flux:button>
                        </div>
                    @endif
                @endcan
            </div>
        @empty
            <flux:text variant="subtle" class="text-center py-2">
                {{ __('Sin mensajes asociados') }}
            </flux:text>
        @endforelse
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
                                @if ($milestone->mailMessage)
                                    <span>·</span>
                                    <flux:link size="xs" icon="envelope" class="truncate">
                                        {{ $milestone->mailMessage->subject ?? __('Mensaje de correo') }}
                                    </flux:link>
                                @endif
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

    {{-- Mail composer modal --}}
    @can('expedientes.actualizar')
        @if ($composerMode && $composerOriginMessageId)
            @livewire('pages::bandeja.mail-composer', [
                'mode' => $composerMode,
                'expedientId' => $expedient->id,
                'originMessageId' => $composerOriginMessageId,
            ], key('mail-composer-'.$composerMode.'-'.$composerOriginMessageId))
        @endif
    @endcan
</section>
