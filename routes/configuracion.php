<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'can:configuracion.acceder'])
    ->prefix('configuracion')
    ->name('configuracion.')
    ->group(function () {
        Route::redirect('/', '/configuracion/cuentas-correo')->name('index');

        Route::livewire('cuentas-correo', 'pages::configuracion.mail-accounts')
            ->middleware('can:cuentas-correo.ver')
            ->name('cuentas-correo.index');

        Route::livewire('firmas', 'pages::configuracion.signatures')
            ->middleware('can:firmas.ver')
            ->name('firmas.index');

        Route::livewire('plantillas', 'pages::configuracion.templates')
            ->middleware('can:plantillas.ver')
            ->name('plantillas.index');
    });
