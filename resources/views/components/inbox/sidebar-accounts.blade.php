@php
$accounts = auth()->user()->mailAccounts()->where('is_active', true)->orderBy('label')->get();
@endphp

@foreach ($accounts as $account)
    <flux:sidebar.item
        wire:key="sidebar-account-{{ $account->id }}"
        :href="route('bandeja.inbox', ['account' => $account->id])"
        :current="request()->routeIs('bandeja.*') && (int) request()->query('account') === $account->id"
        wire:navigate
    >
        {{ $account->label ?? $account->email_address }}
    </flux:sidebar.item>
@endforeach
