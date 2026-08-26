<?php

use App\Models\SharedIncident;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public function mount(): void
    {
        abort_unless(Auth::check(), 403);
    }

    #[Computed]
    public function openIncidentCount(): int
    {
        return SharedIncident::query()->open()->count();
    }

    #[Computed]
    public function openIncidents()
    {
        return SharedIncident::query()
            ->open()
            ->with('expedient:id,case_number')
            ->latest()
            ->limit(10)
            ->get();
    }

    public function claim(int $incidentId): void
    {
        $user = Auth::user();

        abort_unless($user !== null, 403);

        if (SharedIncident::claim($incidentId, $user) === null) {
            Flux::toast(variant: 'warning', text: __('La incidencia ya fue tomada por otro usuario.'));

            return;
        }

        unset($this->openIncidentCount, $this->openIncidents);
        Flux::toast(variant: 'success', text: __('Incidencia tomada.'));
    }
};
?>

<flux:dropdown position="bottom" align="end">
    <flux:button variant="ghost" icon="bell" :aria-label="__('Incidencias abiertas: :count', ['count' => $this->openIncidentCount])" data-test="shared-incidents-bell">
        @if ($this->openIncidentCount > 0)
            <span class="rounded-full bg-red-600 px-1.5 py-0.5 text-xs font-semibold text-white dark:bg-red-500" aria-hidden="true" data-test="shared-incidents-count">{{ $this->openIncidentCount }}</span>
        @endif
    </flux:button>

    <flux:menu class="w-80">
        <div class="px-2 py-1.5">
            <flux:heading size="sm">{{ __('Incidencias abiertas') }}</flux:heading>
        </div>

        <flux:menu.separator />

        @forelse ($this->openIncidents as $incident)
            @if ($incident->expedient !== null)
                <flux:menu.item :href="route('expedientes.show', $incident->expedient)" icon="folder" wire:navigate wire:key="shared-incident-link-{{ $incident->id }}">
                    {{ $incident->title }} · {{ $incident->expedient->case_number }}
                </flux:menu.item>
            @else
                <flux:menu.item disabled icon="exclamation-triangle" wire:key="shared-incident-title-{{ $incident->id }}">
                    {{ $incident->title }}
                </flux:menu.item>
            @endif

            <flux:menu.item as="button" type="button" wire:click="claim({{ $incident->id }})" icon="hand-raised" wire:key="shared-incident-claim-{{ $incident->id }}">
                {{ __('Tomar incidencia') }}
            </flux:menu.item>
        @empty
            <div class="px-2 py-3 text-sm text-zinc-500 dark:text-zinc-400">{{ __('No hay incidencias abiertas.') }}</div>
        @endforelse
    </flux:menu>
</flux:dropdown>
