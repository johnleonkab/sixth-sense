<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\PendingActionController;
use App\Http\Controllers\Settings\AccountAuthorizationController;
use App\Http\Controllers\Settings\AccountCapabilityController;
use App\Http\Controllers\Settings\AlertController;
use App\Http\Controllers\Settings\ConnectionController;
use App\Http\Controllers\Settings\DisplayController;
use App\Http\Controllers\VoiceSessionController;
use App\Http\Controllers\VoiceToolController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::get('/', HomeController::class)->name('home');
    Route::post('voice/sessions', VoiceSessionController::class)
        ->middleware('throttle:10,1')
        ->name('voice.sessions.store');
    Route::post('voice/tools/{tool}', VoiceToolController::class)
        ->middleware('throttle:60,1')
        ->name('voice.tools.run');

    Route::get('media/chat/{file}', [MediaController::class, 'chatFile'])->name('media.chat');
    Route::get('media/mail/{message}/{index}', [MediaController::class, 'mailAttachment'])->whereNumber('index')->name('media.mail');

    Route::prefix('actions/{action}')->whereNumber('action')->name('actions.')->group(function () {
        Route::get('/', [PendingActionController::class, 'show'])->name('show');
        Route::post('confirm', [PendingActionController::class, 'confirm'])->name('confirm');
        Route::post('cancel', [PendingActionController::class, 'cancel'])->name('cancel');
        Route::post('undo', [PendingActionController::class, 'undo'])->name('undo');
    });

    Route::prefix('settings')->name('settings.')->group(function () {
        Route::redirect('/', '/settings/connections')->name('index');
        Route::get('display', [DisplayController::class, 'index'])->name('display.index');
        Route::put('display', [DisplayController::class, 'update'])->name('display.update');
        Route::get('alerts', [AlertController::class, 'index'])->name('alerts.index');
        Route::put('alerts/{source}', [AlertController::class, 'update'])->name('alerts.update');
        Route::get('connections', [ConnectionController::class, 'index'])->name('connections.index');
        Route::delete('connections/{account}', [ConnectionController::class, 'destroy'])
            ->whereNumber('account')
            ->name('connections.destroy');
        Route::put('connections/{account}/capabilities/{capability}', [AccountCapabilityController::class, 'update'])
            ->whereNumber('account')
            ->name('connections.capabilities.update');
        Route::get('connections/{provider}/redirect', [AccountAuthorizationController::class, 'redirect'])
            ->name('connections.redirect');

    });

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});

// Outside "auth": Slack may return through an HTTPS tunnel without the session; its state says who it is.
Route::get('settings/connections/{provider}/callback', [AccountAuthorizationController::class, 'callback'])
    ->name('settings.connections.callback');
