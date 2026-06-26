@props([])

{{--
    Mail Inbox Layout Component
    Renders the dark mail-client shell for the inbox content area.

    Slots:
    - header      → Account selector, sync button, page heading
    - filters     → Status filter buttons
    - toolbar     → Search input, per-page selector
    - messageList → Message list with pagination
    - reader      → Reading pane (message detail or empty state)
--}}

<div {{ $attributes->merge(['class' => 'flex min-h-0 flex-col gap-4 lg:h-[calc(100vh-10.5rem)]']) }}>
    {{-- Header area --}}
    @isset($header)
        {{ $header }}
    @endisset

    {{-- Two-pane mail layout: stacked on mobile, side-by-side on desktop --}}
    <div class="grid min-h-0 flex-1 grid-cols-1 overflow-hidden rounded-xl border border-zinc-700/50 bg-zinc-950/60 lg:grid-cols-[420px_minmax(0,1fr)]">
        {{-- Left column: filters + toolbar + message list --}}
        <div class="flex min-h-0 flex-col overflow-hidden border-b border-zinc-700/50 bg-zinc-900/40 lg:border-r lg:border-b-0">
            {{-- Top controls stack: filters then toolbar --}}
            @isset($filters)
                <div class="shrink-0 border-b border-zinc-700/50 px-3 py-2">
                    {{ $filters }}
                </div>
            @endisset

            @isset($toolbar)
                <div class="shrink-0 border-b border-zinc-700/50 px-3 py-3">
                    {{ $toolbar }}
                </div>
            @endisset

            @isset($messageList)
                <div class="min-h-0 flex-1 overflow-hidden px-3 pb-3 pt-3">
                    {{ $messageList }}
                </div>
            @endisset
        </div>

        {{-- Right column: reader pane --}}
        <div class="flex h-full min-h-0 flex-col overflow-hidden bg-zinc-900/20">
            @isset($reader)
                {{ $reader }}
            @endisset
        </div>
    </div>
</div>
