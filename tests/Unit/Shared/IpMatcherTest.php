<?php

declare(strict_types=1);

use App\Shared\Net\IpMatcher;

test('matches exact IPv4', function (): void {
    expect(IpMatcher::matches('203.0.113.10', '203.0.113.10'))->toBeTrue();
    expect(IpMatcher::matches('203.0.113.11', '203.0.113.10'))->toBeFalse();
});

test('matches IPv4 CIDR', function (): void {
    expect(IpMatcher::matches('198.51.100.7', '198.51.100.0/24'))->toBeTrue();
    expect(IpMatcher::matches('198.51.101.7', '198.51.100.0/24'))->toBeFalse();
    expect(IpMatcher::matches('10.128.7.1', '10.128.0.0/9'))->toBeTrue();
    expect(IpMatcher::matches('10.127.7.1', '10.128.0.0/9'))->toBeFalse();
});

test('matches exact IPv6 and IPv6 CIDR', function (): void {
    expect(IpMatcher::matches('2a00:1234::1', '2a00:1234::1'))->toBeTrue();
    expect(IpMatcher::matches('2a00:1234:5678::9', '2a00:1234::/32'))->toBeTrue();
    expect(IpMatcher::matches('2a00:1235::1', '2a00:1234::/32'))->toBeFalse();
});

test('IPv4 never matches an IPv6 entry and vice versa', function (): void {
    expect(IpMatcher::matches('203.0.113.10', '2a00:1234::/32'))->toBeFalse();
    expect(IpMatcher::matches('2a00:1234::1', '203.0.113.0/24'))->toBeFalse();
});

test('garbage input matches nothing', function (): void {
    expect(IpMatcher::matches('', '203.0.113.10'))->toBeFalse();
    expect(IpMatcher::matches('not-an-ip', '203.0.113.10'))->toBeFalse();
    expect(IpMatcher::matches('203.0.113.10', 'not-a-cidr'))->toBeFalse();
    expect(IpMatcher::matches('203.0.113.10', '203.0.113.10/99'))->toBeFalse();
});

test('matchesAny over a mixed list', function (): void {
    $list = ['203.0.113.10', '198.51.100.0/24', '2a00:1234::/32'];
    expect(IpMatcher::matchesAny('198.51.100.200', $list))->toBeTrue();
    expect(IpMatcher::matchesAny('2a00:1234:aaaa::1', $list))->toBeTrue();
    expect(IpMatcher::matchesAny('192.0.2.1', $list))->toBeFalse();
    expect(IpMatcher::matchesAny('192.0.2.1', []))->toBeFalse();
});

test('isValidEntry accepts IPs and CIDRs, rejects garbage', function (): void {
    expect(IpMatcher::isValidEntry('203.0.113.10'))->toBeTrue();
    expect(IpMatcher::isValidEntry('198.51.100.0/24'))->toBeTrue();
    expect(IpMatcher::isValidEntry('2a00:1234::/32'))->toBeTrue();
    expect(IpMatcher::isValidEntry('2a00:1234::1'))->toBeTrue();
    expect(IpMatcher::isValidEntry(''))->toBeFalse();
    expect(IpMatcher::isValidEntry('banana'))->toBeFalse();
    expect(IpMatcher::isValidEntry('203.0.113.10/33'))->toBeFalse();
    expect(IpMatcher::isValidEntry('2a00:1234::/129'))->toBeFalse();
    expect(IpMatcher::isValidEntry('203.0.113.10/'))->toBeFalse();
});
