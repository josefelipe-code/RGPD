<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
require __DIR__.'/configuracion.php';
require __DIR__.'/contactos.php';
require __DIR__.'/bandeja.php';
require __DIR__.'/expedientes.php';
