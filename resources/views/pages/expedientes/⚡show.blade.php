<?php

use App\Enums\MilestoneAction;
use App\Models\Expedient;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Detalle del Expediente')] class extends Component {
    public Expedient $expedient;

    // Mail composer state
    public ?string $composerMode = null;
    public ?int $composerOriginMessageId = null;

    /** Livewire recibe el expediente de la ruta y prepara el formulario de hitos. */
    public function mount(Expedient $expedient): void
    {
        $this->expedient = $expedient;
        abort_unless(Auth::user()->can('expedientes.ver'), 403);
    }

    #[Computed]
    /** Computed que carga los hitos visibles en el detalle del expediente. */
    public function milestones()
    {
        return $this->expedient
            ->milestones()
            ->with(['user', 'mailMessage'])
            ->latest()
            ->get();
    }

    #[Computed]
    /** Computed que obtiene expedientes relacionados para la vista de detalle. */
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
    /** Computed que obtiene mensajes asociados al expediente. */
    public function associatedMessages()
    {
        return $this->expedient
            ->mailMessages()
            ->with('to')
            ->latest('received_at')
            ->get();
    }

    #[Computed]
    /** Computed que lista usuarios habilitados para asignación. */
    public function availableUsers()
    {
        return User::query()->orderBy('name')->get();
    }

    public function validatePhone(): void
    {
        abort_unless(Auth::user()->can('expedientes.actualizar'), 403);
        $this->expedient->validatePhone(Auth::user());
        Flux::toast(variant: 'success', text: __('Teléfono validado.'));
    }

    public function confirmProvider(): void
    {
        abort_unless(Auth::user()->can('expedientes.actualizar'), 403);
        $this->expedient->confirmProvider(Auth::user());
        Flux::toast(variant: 'success', text: __('Confirmación del proveedor registrada.'));
    }

    public function markClientFingerprintSent(): void
    {
        abort_unless(Auth::user()->can('expedientes.actualizar'), 403);
        $this->expedient->markClientFingerprintSent(Auth::user());
        Flux::toast(variant: 'success', text: __('Envío de huella al cliente registrado.'));
    }

    /** Acción `wire:click` que abre el compositor para un mensaje asociado. */
    public function openComposer(string $mode, int $messageId): void
    {
        abort_unless(Auth::user()->can('expedientes.actualizar'), 403);

        abort_unless(in_array($mode, ['reply_client', 'forward_provider'], true), 404);
        abort_unless($this->expedient->mailAccount?->user_id === Auth::id(), 403);

        if ($mode === 'reply_client') {
            $this->expedient->assertCanReplyClient();
        } else {
            $this->expedient->assertCanForwardProvider();
        }

        abort_unless($this->expedient->mailMessages()->whereKey($messageId)->where('mail_account_id', $this->expedient->mail_account_id)->exists(), 404);

        $this->composerMode = $mode;
        $this->composerOriginMessageId = $messageId;

        Flux::modal('mail-composer')->show();
    }

    /** Acción `wire:click` que cierra el compositor embebido. */
    public function closeComposer(): void
    {
        $this->composerMode = null;
        $this->composerOriginMessageId = null;
    }

    /** Traduce la acción de un hito para la etiqueta mostrada en la vista. */
    private function milestoneLabel(string $action): string
    {
        return match ($action) {
            'opened' => __('Apertura'),
            'replied_client' => __('Respuesta al cliente'),
            'replied_provider' => __('Respuesta al proveedor'),
            'phone_validated' => __('Teléfono validado'),
            'provider_confirmed' => __('Confirmación del proveedor'),
            'client_fingerprint_sent' => __('Huella enviada al cliente'),
            'closed' => __('Cierre'),
            'reopened' => __('Reapertura'),
            default => $action,
        };
    }

    /** Selecciona el icono Flux correspondiente a un hito. */
    private function milestoneIcon(string $action): string
    {
        return match ($action) {
            'opened' => 'folder-open',
            'replied_client' => 'paper-airplane',
            'replied_provider' => 'paper-airplane',
            'phone_validated' => 'phone',
            'provider_confirmed' => 'check-circle',
            'client_fingerprint_sent' => 'document-check',
            'closed' => 'lock-closed',
            'reopened' => 'arrow-path',
            default => 'document',
        };
    }

    /** Selecciona el color de insignia correspondiente a un hito. */
    private function milestoneColor(string $action): string
    {
        return match ($action) {
            'opened' => 'blue',
            'replied_client' => 'green',
            'replied_provider' => 'amber',
            'phone_validated' => 'blue',
            'provider_confirmed' => 'green',
            'client_fingerprint_sent' => 'green',
            'closed' => 'red',
            'reopened' => 'purple',
            default => 'zinc',
        };
    }

    /** Traduce el estado del expediente para la interfaz. */
    private function statusLabel(string $status): string
    {
        return match ($status) {
            'pending_client' => __('Pendiente del cliente'),
            'pending_provider' => __('Pendiente del proveedor'),
            'concluded' => __('Concluido'),
            default => $status,
        };
    }

    /** Selecciona el color de insignia del estado del expediente. */
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

    @can('expedientes.actualizar')
        <flux:card>
            <flux:heading size="md">{{ __('Acciones del ciclo de vida') }}</flux:heading>
            <flux:text variant="subtle" size="sm" class="mb-3">{{ __('Las acciones disponibles dependen del estado actual y quedan registradas en el historial.') }}</flux:text>

            <div class="flex flex-wrap gap-2">
                @if ($expedient->status->value === 'pending_client' && ! $expedient->phone_validated_at)
                    <flux:button variant="primary" wire:click="validatePhone" icon="phone">
                        {{ __('Confirmar validación telefónica') }}
                    </flux:button>
                @endif

                @if ($expedient->status->value === 'pending_provider')
                    <flux:button variant="primary" wire:click="confirmProvider" icon="check-circle">
                        {{ __('Registrar confirmación del proveedor') }}
                    </flux:button>
                    <flux:button variant="primary" wire:click="markClientFingerprintSent" icon="document-check">
                        {{ __('Marcar huella enviada al cliente') }}
                    </flux:button>
                @endif
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
                    @if ($message->direction->value === 'incoming' && $expedient->status->value !== 'concluded')
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
