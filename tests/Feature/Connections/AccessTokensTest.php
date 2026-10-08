<?php

use App\Connections\AccessTokens;
use App\Connections\AccountNeedsReconnection;
use App\Connections\AccountStatus;
use App\Connections\TokenRefreshFailed;
use App\Models\ConnectedAccount;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->freezeSecond();
    config(['services.google.client_id' => 'client-id', 'services.google.client_secret' => 'secret']);
});

test('a valid access token is returned without calling google', function () {
    Http::fake();
    $account = ConnectedAccount::factory()->create(['access_token' => 'ya29.current']);

    expect(app(AccessTokens::class)->for($account))->toBe('ya29.current');

    Http::assertNothingSent();
});

test('an expired access token is refreshed and stored', function () {
    Http::fake(['oauth2.googleapis.com/token' => Http::response([
        'access_token' => 'ya29.fresh',
        'expires_in' => 3599,
        'scope' => 'https://www.googleapis.com/auth/calendar.readonly openid',
        'token_type' => 'Bearer',
    ])]);
    $account = ConnectedAccount::factory()->expired()->create(['refresh_token' => '1//refresh']);

    expect(app(AccessTokens::class)->for($account))->toBe('ya29.fresh');

    $account->refresh();

    expect($account->access_token)->toBe('ya29.fresh')
        ->and($account->refresh_token)->toBe('1//refresh')
        ->and($account->expires_at->equalTo(now()->addSeconds(3599)))->toBeTrue()
        ->and($account->last_refreshed_at->equalTo(now()))->toBeTrue();

    Http::assertSent(fn (Request $request) => $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === '1//refresh'
        && $request['client_id'] === 'client-id'
        && $request['client_secret'] === 'secret');
});

test('a token about to expire is refreshed ahead of time', function () {
    Http::fake(['*' => Http::response(['access_token' => 'ya29.fresh', 'expires_in' => 3599])]);
    $account = ConnectedAccount::factory()->create(['expires_at' => now()->addSeconds(30)]);

    expect(app(AccessTokens::class)->for($account))->toBe('ya29.fresh');
});

test('a revoked authorisation marks the account for reconnection', function () {
    Http::fake(['*' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400)]);
    $account = ConnectedAccount::factory()->expired()->create();

    expect(fn () => app(AccessTokens::class)->for($account))->toThrow(AccountNeedsReconnection::class);
    expect($account->refresh()->status)->toBe(AccountStatus::NeedsReconnection);
});

test('a temporary failure keeps the account connected', function () {
    Http::fake(['*' => Http::response(['error' => 'internal_failure'], 503)]);
    $account = ConnectedAccount::factory()->expired()->create();

    expect(fn () => app(AccessTokens::class)->for($account))->toThrow(TokenRefreshFailed::class);
    expect($account->refresh()->status)->toBe(AccountStatus::Active);
});

test('accounts waiting for reconnection never call google', function () {
    Http::fake();
    $account = ConnectedAccount::factory()->needsReconnection()->create();

    expect(fn () => app(AccessTokens::class)->for($account))->toThrow(AccountNeedsReconnection::class);

    Http::assertNothingSent();
});
