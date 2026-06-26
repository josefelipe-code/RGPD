{{--
    Mail Reader Toolbar
    Top bar of the reader pane showing subject, status badge, and contextual metadata.

    Props:
    - message (MailMessage) — the selected message
    - statusLabels (array) — map of status value => label
    - statusColors (array) — map of status value => color
--}}
@props([
    'message',
    'statusLabels' => [],
    'statusColors' => [],
])

<div class="flex items-center justify-between gap-3 px-5 py-3 border-b border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900">
    <div class="flex items-center gap-2 min-w-0 flex-1">
        <flux:icon name="envelope" class="h-4 w-4 text-zinc-400 dark:text-zinc-500 shrink-0 hidden sm:block" />
        <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100 truncate text-base font-semibold">
            {{ $message->subject ?: __('(Sin asunto)') }}
        </flux:heading>
    </div>
    <div class="flex items-center gap-2 shrink-0">
        @if ($message->status)
            <flux:badge size="sm" :color="$statusColors[$message->status->value] ?? 'zinc'" class="shrink-0">
                {{ $statusLabels[$message->status->value] ?? $message->status->value }}
            </flux:badge>
        @endif
        @if ($message->case)
            <flux:badge size="sm" color="violet" class="shrink-0">
                <flux:icon name="folder" class="h-3 w-3 mr-1" />
                {{ __('Expediente') }}
            </flux:badge>
        @endif
    </div>
</div>
