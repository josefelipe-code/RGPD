<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'can:expedientes.ver'])
    ->prefix('expedientes')
    ->name('expedientes.')
    ->group(function () {
        Route::livewire('/', 'pages::expedientes.index')
            ->name('index');

        Route::livewire('/{expedient}', 'pages::expedientes.show')
            ->name('show');
    });
