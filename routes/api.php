<?php

use App\Http\Controllers\Wingman\ExtensionController;
use App\Http\Middleware\AuthenticateWingmanExtension;
use Illuminate\Support\Facades\Route;

// The "Wingman para Meet" browser extension, with its personal token.
Route::prefix('wingman/extension')
    ->name('wingman.extension.')
    ->middleware([AuthenticateWingmanExtension::class, 'throttle:240,1'])
    ->group(function () {
        Route::get('ping', [ExtensionController::class, 'ping'])->name('ping');
        Route::post('calls', [ExtensionController::class, 'start'])->name('start');
        Route::post('calls/{meeting}/segments', [ExtensionController::class, 'segments'])->whereNumber('meeting')->name('segments');
        Route::post('calls/{meeting}/end', [ExtensionController::class, 'end'])->whereNumber('meeting')->name('end');
    });
