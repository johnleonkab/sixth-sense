<?php

use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake(['oauth2.googleapis.com/revoke' => Http::response()]);
    $this->user = User::factory()->create();
});

test('users can disconnect an account, which revokes it at google', function () {
    $account = ConnectedAccount::factory()->for($this->user)->create(['refresh_token' => '1//refresh']);

    $this->actingAs($this->user)
        ->delete(route('settings.connections.destroy', $account))
        ->assertRedirect(route('settings.connections.index'))
        ->assertInertiaFlash('toast.type', 'success');

    expect(ConnectedAccount::count())->toBe(0);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://oauth2.googleapis.com/revoke'
        && $request['token'] === '1//refresh');
});

test('disconnecting does not revoke an account another user also connected', function () {
    $account = ConnectedAccount::factory()->for($this->user)->create(['external_account_id' => '42']);
    ConnectedAccount::factory()->create(['external_account_id' => '42']);

    $this->actingAs($this->user)->delete(route('settings.connections.destroy', $account));

    expect(ConnectedAccount::count())->toBe(1);
    Http::assertNothingSent();
});

test('the account is disconnected even if google can not be reached', function () {
    Http::fake(['*' => Http::failedConnection()]);
    $account = ConnectedAccount::factory()->for($this->user)->create();

    $this->actingAs($this->user)->delete(route('settings.connections.destroy', $account));

    expect(ConnectedAccount::count())->toBe(0);
});

test('users can not disconnect someone else account', function () {
    $account = ConnectedAccount::factory()->create();

    $this->actingAs($this->user)
        ->delete(route('settings.connections.destroy', $account))
        ->assertNotFound();

    expect(ConnectedAccount::count())->toBe(1);
});
