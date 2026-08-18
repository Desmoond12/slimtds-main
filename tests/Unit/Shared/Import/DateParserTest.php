<?php

declare(strict_types=1);

use App\Shared\Import\DateParser;

test('parses a value matching the given format', function (): void {
    $dt = DateParser::parse('2026-03-05', 'Y-m-d');
    expect($dt->format('Y-m-d'))->toBe('2026-03-05');
});

test('parses non-ISO formats explicitly, not by guessing', function (): void {
    $dt = DateParser::parse('05/03/2026', 'd/m/Y');
    expect($dt->format('Y-m-d'))->toBe('2026-03-05');
});

test('date-only format resets time to midnight, not "now"', function (): void {
    $dt = DateParser::parse('2026-03-05', 'Y-m-d');
    expect($dt->format('H:i:s'))->toBe('00:00:00');
});

test('throws with a clear message including the row number on mismatch', function (): void {
    expect(fn () => DateParser::parse('not-a-date', 'Y-m-d', 7))
        ->toThrow(InvalidArgumentException::class, 'row 7');
});

test('throws on a value that matches the format shape but is not a real date', function (): void {
    // 2026-02-30 doesn't exist — DateTime flags this as a warning, not just
    // a format mismatch, and we must not silently roll it over to March.
    expect(fn () => DateParser::parse('2026-02-30', 'Y-m-d'))
        ->toThrow(InvalidArgumentException::class);
});

test('trims surrounding whitespace before parsing', function (): void {
    $dt = DateParser::parse('  2026-03-05  ', 'Y-m-d');
    expect($dt->format('Y-m-d'))->toBe('2026-03-05');
});
