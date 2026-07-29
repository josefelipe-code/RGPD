{{--
    Mail Message Item Component
    Compact dark mail-client row showing sender, subject, time, and status.

    Required props:
    - message (MailMessage)
    - selected (bool)
    - selectAction (string) — Livewire action name, default 'selectMessage'

    Optional props:
    - showStatus (bool) — show status badge, default true
    - statusLabel (string|null) — pre-resolved status label
    - statusColor (string|null) — pre-resolved status badge color
--}}
@props([
    'message',
    'selected' => false,
    'selectAction' => 'selectMessage',
    'showStatus' => true,
    'statusLabel' => null,
    'statusColor' => null,
])

@php
    $isUnread = ! $message->is_read;
    $snippet = $message->body_text
        ? \Illuminate\Support\Str::limit(strip_tags($message->body_text), 80, '…')
        : null;

    // Fallback to message status value if no explicit label/color provided
    $displayLabel = $statusLabel ?? $message->status?->value ?? '';
    $displayColor = $statusColor ?? 'zinc';
@endphp

<button
    wire:key="message-{{ $message->id }}"
    wire:click="{{ $selectAction }}({{ $message->id }})"
    {{ $attributes->merge([
        'class' => implode(' ', [
            'group w-full text-start px-3 py-2.5 border-b border-zinc-800/60 transition-colors duration-100',
            'hover:bg-zinc-800/70',
            $selected
                ? 'bg-zinc-800/90 border-l-2 border-l-blue-500 pl-[11px]'
                : 'border-l-2 border-l-transparent',
            $isUnread ? 'bg-zinc-800/40' : '',
        ]),
    ]) }}
>
    {{-- Top row: sender + time --}}
    <div class="flex items-center justify-between gap-2">
        <span class="flex items-center gap-1.5 min-w-0">
            @if ($isUnread)
                <span class="h-2 w-2 shrink-0 rounded-full bg-blue-400"></span>
            @endif
            <span class="truncate text-sm font-medium text-zinc-100">
                {{ $message->from_name ?? $message->from_email }}
            </span>
        </span>
        <time class="shrink-0 text-[11px] text-zinc-500 tabular-nums">
            {{ $message->received_at->format('H:i') }}
        </time>
    </div>

    {{-- Subject line --}}
    <div class="mt-0.5 truncate text-sm {{ $isUnread ? 'text-zinc-100 font-medium' : 'text-zinc-300' }}">
        {{ $message->subject ?: __('(Sin asunto)') }}
    </div>

    {{-- Preview snippet + status --}}
    @if ($snippet || $showStatus)
        <div class="mt-0.5 flex items-center gap-2">
            @if ($snippet)
                <span class="truncate text-xs text-zinc-500">{{ $snippet }}</span>
            @endif
            @if ($showStatus && $message->status)
                <flux:badge size="xs" :color="$displayColor" class="shrink-0">
                    {{ $displayLabel }}
                </flux:badge>
            @endif
        </div>
    @endif
</button>
