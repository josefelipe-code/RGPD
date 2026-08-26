<flux:header class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900" data-test="application-topbar">
    <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

    <div class="hidden items-center gap-4 lg:flex">
        <x-layouts.app.topbar-clock />
        <x-layouts.app.topbar-appearance-switcher />
    </div>

    <flux:spacer />

    <livewire:incidents.notification-bell />

    <x-layouts.app.topbar-user-menu />
</flux:header>
