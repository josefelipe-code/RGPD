<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'can:contactos.ver'])
    ->prefix('contactos')
    ->name('contactos.')
    ->group(function () {
        Route::redirect('/', '/contactos/lista')->name('index');

        Route::livewire('lista', 'pages::contactos.contacts')
            ->middleware('can:contactos.ver')
            ->name('contacts.index');

        Route::livewire('categorias', 'pages::contactos.categories')
            ->middleware('can:categorias.ver')
            ->name('categories.index');
    });
