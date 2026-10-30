<?php

use App\Connections\Capability;
use App\Connections\Provider;
use App\Models\ConnectedAccount;
use App\Models\User;

test('google asks for exactly the scopes its capabilities need', function () {
    expect(Provider::Google->scopes())->toBe([
        'https://www.googleapis.com/auth/calendar.readonly',
        'https://www.googleapis.com/auth/gmail.readonly',
        'https://www.googleapis.com/auth/documents.readonly',
        'https://www.googleapis.com/auth/spreadsheets.readonly',
        'https://www.googleapis.com/auth/drive.readonly',
        'https://www.googleapis.com/auth/analytics.readonly',
        'https://www.googleapis.com/auth/documents',
        'https://www.googleapis.com/auth/drive',
        'https://www.googleapis.com/auth/gmail.send',
        'https://www.googleapis.com/auth/calendar.events',
    ]);
});

test('a capability is available when it is granted and not switched off', function () {
    $account = ConnectedAccount::factory()->make([
        'scopes' => [Capability::CalendarRead->scope(), Capability::GmailRead->scope()],
        'disabled_capabilities' => [Capability::GmailRead->value],
    ]);

    expect($account->can(Capability::CalendarRead))->toBeTrue()
        ->and($account->can(Capability::GmailRead))->toBeFalse()
        ->and($account->can(Capability::DocsRead))->toBeFalse();
});

test('accounts waiting for reconnection keep their preferences but can not be used', function () {
    $account = ConnectedAccount::factory()->needsReconnection()->make();

    expect($account->allows(Capability::CalendarRead))->toBeTrue()
        ->and($account->can(Capability::CalendarRead))->toBeFalse();
});

test('reads combine every account of the user that can use the capability', function () {
    $user = User::factory()->create();
    $work = ConnectedAccount::factory()->for($user)->create(['email' => 'work@example.com']);
    $personal = ConnectedAccount::factory()->for($user)->create(['email' => 'personal@example.com']);
    ConnectedAccount::factory()->for($user)->create(['disabled_capabilities' => [Capability::CalendarRead->value]]);
    ConnectedAccount::factory()->for($user)->needsReconnection()->create();
    ConnectedAccount::factory()->for($user)->create(['scopes' => [Capability::GmailRead->scope()]]);
    ConnectedAccount::factory()->create();

    expect($user->accountsWith(Capability::CalendarRead)->modelKeys())
        ->toEqualCanonicalizing([$work->id, $personal->id]);
});
