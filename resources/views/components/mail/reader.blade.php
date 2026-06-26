{{--
    Mail Reader Pane Component
    Renders the right-side reading pane for the inbox.

    Props:
    - message (?MailMessage) — currently selected message
    - body (HtmlString) — sanitized message body HTML
    - statusLabels (array) — map of status value => label
    - statusColors (array) — map of status value => color

    Slots:
    - actions — action buttons (suggestNewCase, discard, etc.)
--}}
@props([
    'message' => null,
    'body' => null,
    'statusLabels' => [],
    'statusColors' => [],
])

@if ($message)
    <div class="flex h-full min-h-0 flex-col overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm">
        {{-- Toolbar: subject + status + meta actions --}}
        <x-mail.reader-toolbar
            :message="$message"
            :statusLabels="$statusLabels"
            :statusColors="$statusColors"
        />

        {{-- Sender identity block --}}
        <div class="flex items-start gap-3 px-5 py-4 border-b border-zinc-100 dark:border-zinc-800">
            <flux:avatar
                :name="$message->from_name ?? $message->from_email"
                size="md"
                circle
                color="auto"
                :color:seed="$message->from_email"
                class="shrink-0 mt-0.5"
            />
            <div class="flex-1 min-w-0">
                <div class="flex items-baseline justify-between gap-3">
                    <div class="min-w-0">
                        <span class="font-semibold text-sm text-zinc-900 dark:text-zinc-100 truncate block">
                            {{ $message->from_name ?? $message->from_email }}
                        </span>
                        <span class="text-xs text-zinc-500 dark:text-zinc-400 truncate block">
                            {{ $message->from_email }}
                        </span>
                    </div>
                    <time
                        class="shrink-0 text-xs text-zinc-400 dark:text-zinc-500 tabular-nums text-right"
                        datetime="{{ $message->received_at->toIso8601String() }}"
                        title="{{ $message->received_at->format('d/m/Y H:i') }}"
                    >
                        {{ $message->received_at->format('d/m/Y') }}
                        <span class="hidden sm:inline">{{ $message->received_at->format('H:i') }}</span>
                    </time>
                </div>
            </div>
        </div>

        {{-- Message body — scrollable reading surface --}}
        <x-mail.thread-body :body="$body" />

        {{-- Action bar --}}
        @isset($actions)
            <div class="flex items-center gap-2 px-5 py-3 border-t border-zinc-100 dark:border-zinc-800 bg-zinc-50/80 dark:bg-zinc-800/40 flex-wrap">
                {{ $actions }}
            </div>
        @endisset
    </div>
@else
    {{-- Empty state — intentional, calm, professional --}}
    <div class="flex h-full min-h-0 flex-col items-center justify-center rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900/60 min-h-[400px] lg:min-h-0">
        <div class="text-center max-w-xs">
            <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800 ring-1 ring-zinc-200 dark:ring-zinc-700">
                <flux:icon name="envelope-open" class="h-6 w-6 text-zinc-400 dark:text-zinc-500" />
            </div>
            <flux:heading size="md" class="mt-4 text-zinc-700 dark:text-zinc-300">
                {{ __('Ningún mensaje seleccionado') }}
            </flux:heading>
            <flux:text variant="subtle" class="mt-1.5 text-sm leading-relaxed">
                {{ __('Elegí un mensaje de la lista para ver su contenido acá.') }}
            </flux:text>
        </div>
    </div>
@endif
