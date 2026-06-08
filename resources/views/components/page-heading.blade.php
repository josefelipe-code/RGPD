@props(['heading', 'subheading' => null])

<div {{ $attributes->merge(['class' => '']) }}>
    <flux:heading size="xl" level="1">{{ $heading }}</flux:heading>
    @if ($subheading)
        <flux:subheading size="lg" class="mt-1">{{ $subheading }}</flux:subheading>
    @endif
    <flux:separator variant="subtle" class="mt-4" />
</div>
