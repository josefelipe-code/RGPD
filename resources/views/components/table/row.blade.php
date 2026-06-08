@props([])

<tr {{ $attributes->merge(['class' => 'border-t border-neutral-200 dark:border-neutral-700']) }}>
    {{ $slot }}
</tr>
