{{--
    Mail Message List Component
    Renders a scrollable list of mail message items with pagination.

    Required props:
    - messages (LengthAwarePaginator)
    - selectedMessageId (?int)
    - selectAction (string) — default 'selectMessage'

    Optional props:
    - emptyMessage (string) — text when no messages
    - showStatus (bool) — default true
    - statusLabels (array) — map of status value => label
    - statusColors (array) — map of status value => color
--}}
@props([
    'messages',
    'selectedMessageId' => null,
    'selectAction' => 'selectMessage',
    'emptyMessage' => null,
    'showStatus' => true,
    'statusLabels' => [],
    'statusColors' => [],
])

<div class="flex min-h-0 flex-1 flex-col overflow-hidden">
    <div class="min-h-0 flex-1 overflow-y-auto">
        @forelse ($messages as $message)
            <x-mail.message-item
                :message="$message"
                :selected="$selectedMessageId === $message->id"
                :selectAction="$selectAction"
                :showStatus="$showStatus"
                :statusLabel="$statusLabels[$message->status?->value] ?? null"
                :statusColor="$statusColors[$message->status?->value] ?? null"
            />
        @empty
            <div class="flex flex-col items-center justify-center px-4 py-12 text-center">
                <flux:icon name="inbox" class="h-10 w-10 text-zinc-600" />
                <flux:text variant="subtle" class="mt-3 text-sm">
                    {{ $emptyMessage ?? __('No hay mensajes en esta bandeja.') }}
                </flux:text>
            </div>
        @endforelse
    </div>

    {{-- Pagination --}}
    @if ($messages->hasPages())
        <div class="px-3 py-2 border-t border-zinc-700/60 bg-zinc-900/80">
            {{ $messages->links(data: ['scrollTo' => false]) }}
        </div>
    @endif
</div>
