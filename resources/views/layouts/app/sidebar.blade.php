<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        @php
            $sidebarSectionHeadingClasses = 'px-3 pt-3 pb-2 in-data-flux-sidebar-collapsed-desktop:hidden';
            $sidebarSectionHeadingTextClasses = 'text-sm text-zinc-400 font-medium leading-none';
        @endphp

        <flux:sidebar sticky :collapsible="true" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse
                    class="in-data-flux-sidebar-on-desktop:not-in-data-flux-sidebar-collapsed-desktop:-mr-2"
                />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                    {{ __('Dashboard') }}
                </flux:sidebar.item>

                @can('bandeja.ver')
                    <flux:sidebar.group expandable icon="inbox" heading="{{ __('Bandeja de entrada') }}" :expanded="request()->routeIs('bandeja.*')">
                        <x-inbox.sidebar-accounts />
                    </flux:sidebar.group>
                @endcan

                @can('expedientes.ver')
                    <div class="{{ $sidebarSectionHeadingClasses }}">
                        <div class="{{ $sidebarSectionHeadingTextClasses }}">{{ __('Expedientes') }}</div>
                    </div>

                    <flux:sidebar.item icon="folder" :href="route('expedientes.index')" :current="request()->routeIs('expedientes.index', 'expedientes.show')" wire:navigate>
                        {{ __('Expedientes') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="adjustments-horizontal" :href="route('expedientes.states.index')" :current="request()->routeIs('expedientes.states.*')" wire:navigate>
                        {{ __('Estados') }}
                    </flux:sidebar.item>
                @endcan

                @can('configuracion.acceder')
                    <div class="{{ $sidebarSectionHeadingClasses }}">
                        <div class="{{ $sidebarSectionHeadingTextClasses }}">{{ __('Configuración') }}</div>
                    </div>

                    <flux:sidebar.item icon="envelope" :href="route('configuracion.cuentas-correo.index')" :current="request()->routeIs('configuracion.cuentas-correo.*')" wire:navigate>
                        {{ __('Cuentas de correo') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="pencil-square" :href="route('configuracion.firmas.index')" :current="request()->routeIs('configuracion.firmas.*')" wire:navigate>
                        {{ __('Firmas') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="document-text" :href="route('configuracion.plantillas.index')" :current="request()->routeIs('configuracion.plantillas.*')" wire:navigate>
                        {{ __('Plantillas') }}
                    </flux:sidebar.item>
                @endcan

                @can('contactos.ver')
                    <div class="{{ $sidebarSectionHeadingClasses }}">
                        <div class="{{ $sidebarSectionHeadingTextClasses }}">{{ __('Contactos') }}</div>
                    </div>

                    <flux:sidebar.item icon="user-group" :href="route('contactos.contacts.index')" :current="request()->routeIs('contactos.contacts.*')" wire:navigate>
                        {{ __('Contactos') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="hashtag" :href="route('contactos.categories.index')" :current="request()->routeIs('contactos.categories.*')" wire:navigate>
                        {{ __('Categorías') }}
                    </flux:sidebar.item>
                @endcan

                @can('admin.acceder')
                    <div class="{{ $sidebarSectionHeadingClasses }}">
                        <div class="{{ $sidebarSectionHeadingTextClasses }}">{{ __('Administración') }}</div>
                    </div>

                    <flux:sidebar.item icon="users" :href="route('admin.users.index')" :current="request()->routeIs('admin.users.*')" wire:navigate>
                        {{ __('Usuarios') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="shield-check" :href="route('admin.roles.index')" :current="request()->routeIs('admin.roles.*')" wire:navigate>
                        {{ __('Roles') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="key" :href="route('admin.permissions.index')" :current="request()->routeIs('admin.permissions.*')" wire:navigate>
                        {{ __('Permisos') }}
                    </flux:sidebar.item>
                @endcan
            </flux:sidebar.nav>

            <flux:spacer />

            <flux:sidebar.nav>
                <flux:sidebar.item icon="folder-git-2" href="https://github.com/laravel/livewire-starter-kit" target="_blank">
                    {{ __('Repository') }}
                </flux:sidebar.item>

                <flux:sidebar.item icon="book-open-text" href="https://laravel.com/docs/starter-kits#livewire" target="_blank">
                    {{ __('Documentation') }}
                </flux:sidebar.item>
            </flux:sidebar.nav>

        </flux:sidebar>

        <x-layouts.app.topbar />

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
