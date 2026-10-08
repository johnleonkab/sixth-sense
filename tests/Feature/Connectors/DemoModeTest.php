<?php

use App\Connectors\Calendar\CalendarReader;
use App\Connectors\Calendar\FakeCalendarReader;
use App\Connectors\Google\GoogleCalendarReader;

test('connected accounts are used by default', function () {
    expect(app(CalendarReader::class))->toBeInstanceOf(GoogleCalendarReader::class);
});

test('demo mode uses the sample calendar', function () {
    config(['connectors.demo' => true]);

    expect(app(CalendarReader::class))->toBeInstanceOf(FakeCalendarReader::class);
});
