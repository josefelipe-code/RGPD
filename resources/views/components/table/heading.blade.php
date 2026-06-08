@props(['sortable' => false, 'direction' => null])

<th {{ $attributes->merge(['class' => 'px-4 py-3 text-start text-xs font-semibold uppercase tracking-wider text-neutral-500 dark:text-neutral-400']) }}>
    {{ $slot }}
</th>
