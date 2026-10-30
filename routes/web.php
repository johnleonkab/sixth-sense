<?php

use App\Http\Controllers\AlertFeedController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\PendingActionController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\PwaController;
use App\Http\Controllers\SceneController;
use App\Http\Controllers\Settings\AccountAuthorizationController;
use App\Http\Controllers\Settings\AccountCapabilityController;
use App\Http\Controllers\Settings\AlertController;
use App\Http\Controllers\Settings\ArchiveAgentController;
use App\Http\Controllers\Settings\ArchiveFileController;
use App\Http\Controllers\Settings\ConnectionController;
use App\Http\Controllers\Settings\GithubRepositoryController;
use App\Http\Controllers\Settings\ImpactController;
use App\Http\Controllers\Settings\JiraRulesController;
use App\Http\Controllers\Settings\McpServerController;
use App\Http\Controllers\Settings\SenseWorkspaceController;
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

Route::get('site.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');
Route::get('sw.js', [PwaController::class, 'serviceWorker'])->name('pwa.worker');

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
    Route::post('push-subscriptions', [PushSubscriptionController::class, 'store'])->middleware('throttle:10,1')->name('push-subscriptions.store');
    Route::delete('push-subscriptions', [PushSubscriptionController::class, 'destroy'])->name('push-subscriptions.destroy');
    Route::get('media/chat/{file}', [MediaController::class, 'chatFile'])->name('media.chat');
    Route::get('media/mail/{message}/{index}', [MediaController::class, 'mailAttachment'])->whereNumber('index')->name('media.mail');

    Route::prefix('scenes/{scene}')->whereNumber('scene')->name('scenes.')->group(function () {
        Route::get('/', [SceneController::class, 'show'])->name('show');
        Route::post('confirm', [SceneController::class, 'confirm'])->name('confirm');
    });

    Route::prefix('actions/{action}')->whereNumber('action')->name('actions.')->group(function () {
        Route::get('/', [PendingActionController::class, 'show'])->name('show');
        Route::post('confirm', [PendingActionController::class, 'confirm'])->name('confirm');
        Route::post('sense', [PendingActionController::class, 'override'])->name('sense');
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
        Route::get('mcp', [McpServerController::class, 'index'])->name('mcp.index');
        Route::post('mcp', [McpServerController::class, 'store'])->middleware('throttle:10,1')->name('mcp.store');
        Route::get('mcp/oauth/callback', [McpServerController::class, 'callback'])->name('mcp.callback');
        Route::get('mcp/{server}/connect', [McpServerController::class, 'connect'])->whereNumber('server')->middleware('throttle:10,1')->name('mcp.connect');
        Route::post('mcp/{server}/refresh', [McpServerController::class, 'refresh'])->whereNumber('server')->middleware('throttle:10,1')->name('mcp.refresh');
        Route::put('mcp/{server}', [McpServerController::class, 'update'])->whereNumber('server')->name('mcp.update');
        Route::delete('mcp/{server}', [McpServerController::class, 'destroy'])->whereNumber('server')->name('mcp.destroy');
        Route::get('wingman', [WingmanController::class, 'edit'])->name('wingman.edit');
        Route::put('wingman', [WingmanController::class, 'update'])->name('wingman.update');
        Route::put('wingman/settings', [WingmanController::class, 'updateSettings'])->name('wingman.settings');
        Route::post('wingman/token', [WingmanController::class, 'issueToken'])->name('wingman.token');
        Route::delete('wingman/token', [WingmanController::class, 'revokeToken'])->name('wingman.token.revoke');
        Route::put('system/display', [SystemController::class, 'updateDisplay'])->name('system.display');
        Route::put('system/voice', [SystemController::class, 'updateVoice'])->name('system.voice');
        Route::put('system/keys', [SystemController::class, 'updateKeys'])->middleware('throttle:6,1')->name('system.keys');
        Route::get('system/voices/{voice}/sample', [SystemController::class, 'sample'])->name('system.voice.sample');
        Route::get('alerts', [AlertController::class, 'index'])->name('alerts.index');
        Route::post('slack-autonomy/lower', [AlertController::class, 'lowerAutonomy'])->name('slack-autonomy.lower');
        Route::post('slack-autonomy/promote', [AlertController::class, 'promoteAutonomy'])->name('slack-autonomy.promote');
        Route::post('slack-autonomy/{pending}/confirm', [AlertController::class, 'confirmAutonomy'])->whereNumber('pending')->name('slack-autonomy.confirm');
        Route::post('slack-autonomy/{pending}/cancel', [AlertController::class, 'cancelAutonomy'])->whereNumber('pending')->name('slack-autonomy.cancel');
        Route::put('alerts/{source}', [AlertController::class, 'update'])->name('alerts.update');
        Route::get('context', [WorkContextController::class, 'index'])->name('context.index');
        Route::put('context', [WorkContextController::class, 'update'])->name('context.update');
        Route::post('context/build', [WorkContextController::class, 'build'])
            ->middleware('throttle:5,10')
            ->name('context.build');
        Route::redirect('archive', '/settings/archive/agents')->name('archive.index');
        Route::get('archive/agents', [ArchiveAgentController::class, 'index'])->name('archive.agents.index');
        Route::post('archive/agents/sync', [ArchiveAgentController::class, 'sync'])->name('archive.agents.sync');
        Route::get('archive/agents/create', [ArchiveAgentController::class, 'create'])->name('archive.agents.create');
        Route::post('archive/agents', [ArchiveAgentController::class, 'store'])->name('archive.agents.store');
        Route::get('archive/agents/{slug}', [ArchiveAgentController::class, 'edit'])
            ->where('slug', '[^/]+')
            ->name('archive.agents.edit');
        Route::put('archive/agents/{slug}', [ArchiveAgentController::class, 'update'])
            ->where('slug', '[^/]+')
            ->name('archive.agents.update');
        Route::get('archive/files', [ArchiveFileController::class, 'index'])->name('archive.files.index');
        Route::get('archive/files/create', [ArchiveFileController::class, 'create'])->name('archive.files.create');
        Route::post('archive/files', [ArchiveFileController::class, 'store'])->name('archive.files.store');
        Route::get('archive/files/{path}', [ArchiveFileController::class, 'show'])
            ->where('path', '.*')
            ->name('archive.files.show');
        Route::put('archive/files/{path}', [ArchiveFileController::class, 'update'])
            ->where('path', '.*')
            ->name('archive.files.update');
        Route::get('connections', [ConnectionController::class, 'index'])->name('connections.index');
        Route::delete('connections/{account}', [ConnectionController::class, 'destroy'])
            ->whereNumber('account')
            ->name('connections.destroy');
        Route::put('connections/{account}/capabilities/{capability}', [AccountCapabilityController::class, 'update'])
            ->whereNumber('account')
            ->name('connections.capabilities.update');
        Route::post('connections/{account}/repositories', [GithubRepositoryController::class, 'refresh'])
            ->whereNumber('account')
            ->name('connections.repositories.refresh');
        Route::put('connections/{account}/repositories/{repository}', [GithubRepositoryController::class, 'update'])
            ->whereNumber(['account', 'repository'])
            ->name('connections.repositories.update');
        Route::post('connections/{account}/sense', [SenseWorkspaceController::class, 'store'])
            ->whereNumber('account')
            ->name('connections.sense.store');
        Route::post('connections/{account}/repositories/{repository}/sense', [SenseWorkspaceController::class, 'adopt'])
            ->whereNumber(['account', 'repository'])
            ->name('connections.sense.adopt');
        Route::post('connections/{account}/repositories/{repository}/sense/fill', [SenseWorkspaceController::class, 'fill'])
            ->whereNumber(['account', 'repository'])
            ->name('connections.sense.fill');
        Route::post('connections/{account}/repositories/{repository}/sense/sync', [SenseWorkspaceController::class, 'sync'])
            ->whereNumber(['account', 'repository'])
            ->name('connections.sense.sync');
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
