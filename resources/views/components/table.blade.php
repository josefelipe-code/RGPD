@props([])

<div {{ $attributes->merge(['class' => 'overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700']) }}>
    <div class="overflow-x-auto">
        <table class="w-full text-start text-sm">
            {{ $slot }}
        </table>
    </div>
</div>
