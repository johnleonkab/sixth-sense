<?php

use App\Http\Controllers\AlertFeedController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\PendingActionController;
use App\Http\Controllers\Settings\AccountAuthorizationController;
use App\Http\Controllers\Settings\AccountCapabilityController;
use App\Http\Controllers\Settings\AlertController;
use App\Http\Controllers\Settings\ConnectionController;
use App\Http\Controllers\Settings\ImpactController;
use App\Http\Controllers\Settings\JiraRulesController;
use App\Http\Controllers\Settings\SystemController;
use App\Http\Controllers\Settings\WingmanController;
use App\Http\Controllers\Settings\WorkContextController;
use App\Http\Controllers\VoiceSessionController;
use App\Http\Controllers\VoiceToolController;
use App\Http\Controllers\Wingman\ActionController;
use App\Http\Controllers\Wingman\InsightController;
use App\Http\Controllers\Wingman\LiveController;
use App\Http\Controllers\Wingman\MeetingController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
    Route::get('register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('register.store');
});

// Public: the landing for visitors, the assistant once signed in.
Route::get('/', HomeController::class)->name('home');

Route::middleware('auth')->group(function () {
    Route::post('voice/sessions', VoiceSessionController::class)
        ->middleware('throttle:10,1')
        ->name('voice.sessions.store');
    Route::post('voice/tools/{tool}', VoiceToolController::class)
        ->middleware('throttle:60,1')
        ->name('voice.tools.run');

    Route::get('alerts/feed', AlertFeedController::class)->middleware('throttle:120,1')->name('alerts.feed');
    Route::get('alerts/history', [AlertFeedController::class, 'history'])->middleware('throttle:120,1')->name('alerts.history');
    Route::post('alerts/{item}/dismiss', [AlertFeedController::class, 'dismiss'])->whereNumber('item')->name('alerts.dismiss');
    Route::get('media/chat/{file}', [MediaController::class, 'chatFile'])->name('media.chat');
    Route::get('media/mail/{message}/{index}', [MediaController::class, 'mailAttachment'])->whereNumber('index')->name('media.mail');

    Route::prefix('actions/{action}')->whereNumber('action')->name('actions.')->group(function () {
        Route::get('/', [PendingActionController::class, 'show'])->name('show');
        Route::post('confirm', [PendingActionController::class, 'confirm'])->name('confirm');
        Route::post('cancel', [PendingActionController::class, 'cancel'])->name('cancel');
        Route::post('undo', [PendingActionController::class, 'undo'])->name('undo');
    });

    Route::prefix('wingman')->name('wingman.')->group(function () {
        Route::get('/', [MeetingController::class, 'index'])->name('index');
        Route::get('live', [LiveController::class, 'show'])->name('live');
        Route::post('{meeting}/pause', [LiveController::class, 'pause'])->whereNumber('meeting')->name('pause');
        Route::post('insights/{insight}/feedback', [InsightController::class, 'feedback'])->whereNumber('insight')->name('insights.feedback');
        Route::post('insights/{insight}/dismiss', [InsightController::class, 'dismiss'])->whereNumber('insight')->name('insights.dismiss');
        Route::post('actions/{action}/review', [ActionController::class, 'review'])->whereNumber('action')->name('actions.review');
        Route::post('actions/{action}/dismiss', [ActionController::class, 'dismiss'])->whereNumber('action')->name('actions.dismiss');
        Route::post('actions/{action}/restore', [ActionController::class, 'restore'])->whereNumber('action')->name('actions.restore');
        Route::get('{meeting}', [MeetingController::class, 'show'])->whereNumber('meeting')->name('show');
        Route::post('{meeting}/prepare', [MeetingController::class, 'prepare'])->whereNumber('meeting')->name('prepare');
    });

    Route::prefix('settings')->name('settings.')->group(function () {
        Route::redirect('/', '/settings/connections')->name('index');
        Route::redirect('display', '/settings/system');
        Route::get('system', [SystemController::class, 'index'])->name('system.index');
        Route::get('impact', [ImpactController::class, 'index'])->name('impact.index');
        Route::get('wingman', [WingmanController::class, 'edit'])->name('wingman.edit');
        Route::put('wingman', [WingmanController::class, 'update'])->name('wingman.update');
        Route::put('wingman/settings', [WingmanController::class, 'updateSettings'])->name('wingman.settings');
        Route::post('wingman/token', [WingmanController::class, 'issueToken'])->name('wingman.token');
        Route::delete('wingman/token', [WingmanController::class, 'revokeToken'])->name('wingman.token.revoke');
        Route::put('system/display', [SystemController::class, 'updateDisplay'])->name('system.display');
        Route::put('system/voice', [SystemController::class, 'updateVoice'])->name('system.voice');
        Route::get('system/voices/{voice}/sample', [SystemController::class, 'sample'])->name('system.voice.sample');
        Route::get('alerts', [AlertController::class, 'index'])->name('alerts.index');
        Route::put('alerts/{source}', [AlertController::class, 'update'])->name('alerts.update');
        Route::get('context', [WorkContextController::class, 'index'])->name('context.index');
        Route::put('context', [WorkContextController::class, 'update'])->name('context.update');
        Route::post('context/build', [WorkContextController::class, 'build'])
            ->middleware('throttle:5,10')
            ->name('context.build');
        Route::get('connections', [ConnectionController::class, 'index'])->name('connections.index');
        Route::delete('connections/{account}', [ConnectionController::class, 'destroy'])
            ->whereNumber('account')
            ->name('connections.destroy');
        Route::put('connections/{account}/capabilities/{capability}', [AccountCapabilityController::class, 'update'])
            ->whereNumber('account')
            ->name('connections.capabilities.update');
        Route::get('connections/{account}/rules', [JiraRulesController::class, 'edit'])->whereNumber('account')->name('connections.rules.edit');
        Route::put('connections/{account}/rules', [JiraRulesController::class, 'update'])->whereNumber('account')->name('connections.rules.update');
        Route::get('connections/{provider}/redirect', [AccountAuthorizationController::class, 'redirect'])
            ->name('connections.redirect');

    });

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});

// Outside "auth": Slack may return through an HTTPS tunnel without the session; its state says who it is.
Route::get('settings/connections/{provider}/callback', [AccountAuthorizationController::class, 'callback'])
    ->name('settings.connections.callback');
