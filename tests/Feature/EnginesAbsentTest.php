<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

test('the avisos engine and the para mí engine are gone and home still opens', function () {
    $gone = [
        'app/Alerts/AlertInbox.php',
        'app/Alerts/NoiseFilter.php',
        'app/Comms/Intents/IntentBoard.php',
        'app/Comms/Intents/MessageUrl.php',
        'app/Avisos/Engine.php',
        'app/Avisos/ForMe.php',
        'app/Jobs/CheckAccountForAlerts.php',
        'app/Console/Commands/WatchInboxes.php',
        'resources/js/intents/intent-board.tsx',
        'resources/js/pages/for-me-inbox.tsx',
    ];

    foreach ($gone as $path) {
        expect(is_file(base_path($path)))->toBeFalse();
    }

    expect(class_exists('App\Alerts\AlertInbox', false))->toBeFalse()
        ->and(class_exists('App\Comms\Intents\IntentBoard', false))->toBeFalse()
        ->and(class_exists('App\Avisos\Engine', false))->toBeFalse()
        ->and(Artisan::all())->not->toHaveKey('alerts:watch')
        ->and(Route::has('para-mi'))->toBeFalse()
        ->and(Route::has('avisos.webhook'))->toBeFalse();

    $this->get(route('home'))->assertOk();

    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('home')
            ->missing('intents')
            ->missing('forMe')
            ->has('alerts'));

    $this->actingAs(User::factory()->create())
        ->get(route('settings.alerts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/alerts'));
});
