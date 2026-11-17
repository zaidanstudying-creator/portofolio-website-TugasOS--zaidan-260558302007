<?php

use Carbon\CarbonImmutable;

test('application renders translated dates in Indonesian by default', function () {
    $date = CarbonImmutable::parse('2026-11-07');

    expect(config('app.locale'))->toBe('id')
        ->and($date->translatedFormat('l d F Y'))->toBe('Sabtu 07 November 2026')
        ->and(now()->diffForHumans())->toContain('yang lalu');
});
