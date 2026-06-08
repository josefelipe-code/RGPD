<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'can:admin.acceder'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::redirect('/', '/admin/users')->name('index');

        Route::livewire('users', 'pages::admin.users')
            ->middleware('can:usuarios.ver')
            ->name('users.index');

        Route::livewire('roles', 'pages::admin.roles')
            ->middleware('can:roles.ver')
            ->name('roles.index');

        Route::livewire('permissions', 'pages::admin.permissions')
            ->middleware('can:permisos.ver')
            ->name('permissions.index');
    });
