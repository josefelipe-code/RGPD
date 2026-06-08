<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'can:bandeja.ver'])
    ->prefix('bandeja')
    ->name('bandeja.')
    ->group(function () {
        Route::livewire('/', 'pages::bandeja.inbox')
            ->name('inbox');
    });
