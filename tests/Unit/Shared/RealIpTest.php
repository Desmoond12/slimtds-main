<?php

declare(strict_types=1);

use App\Shared\RealIp;
use Slim\Psr7\Factory\ServerRequestFactory;

afterEach(function (): void {
    unset($_ENV['TRUSTED_PROXIES']);
});

test('untrusted peer: spoofed forwarding headers are ignored, REMOTE_ADDR wins', function (): void {
    $req = (new ServerRequestFactory())
        ->createServerRequest('GET', '/x', ['REMOTE_ADDR' => '198.51.100.7'])
        ->withHeader('CF-Connecting-IP', '1.2.3.4')
        ->withHeader('X-Forwarded-For', '5.6.7.8');

    expect(RealIp::from($req))->toBe('198.51.100.7');
});

test('trusted Cloudflare peer: CF-Connecting-IP is honored', function (): void {
    $req = (new ServerRequestFactory())
        // 172.64.0.0/13 is a published Cloudflare range
        ->createServerRequest('GET', '/x', ['REMOTE_ADDR' => '172.64.10.5'])
        ->withHeader('CF-Connecting-IP', '203.0.113.42');

    expect(RealIp::from($req))->toBe('203.0.113.42');
});

test('untrusted peer + no headers: REMOTE_ADDR used directly', function (): void {
    $req = (new ServerRequestFactory())->createServerRequest('GET', '/x', ['REMOTE_ADDR' => '198.51.100.9']);
    expect(RealIp::from($req))->toBe('198.51.100.9');
});

test('operator-configured TRUSTED_PROXIES range is honored for X-Forwarded-For', function (): void {
    $_ENV['TRUSTED_PROXIES'] = '10.0.0.0/8, 192.168.1.1';

    $req = (new ServerRequestFactory())
        ->createServerRequest('GET', '/x', ['REMOTE_ADDR' => '10.1.2.3'])
        ->withHeader('X-Forwarded-For', '203.0.113.99, 10.1.2.3');

    expect(RealIp::from($req))->toBe('203.0.113.99');
});

test('exact-IP entry in TRUSTED_PROXIES matches only that IP', function (): void {
    $_ENV['TRUSTED_PROXIES'] = '192.168.1.1';

    $trusted = (new ServerRequestFactory())
        ->createServerRequest('GET', '/x', ['REMOTE_ADDR' => '192.168.1.1'])
        ->withHeader('X-Real-IP', '203.0.113.5');
    expect(RealIp::from($trusted))->toBe('203.0.113.5');

    $untrusted = (new ServerRequestFactory())
        ->createServerRequest('GET', '/x', ['REMOTE_ADDR' => '192.168.1.2'])
        ->withHeader('X-Real-IP', '203.0.113.5');
    expect(RealIp::from($untrusted))->toBe('192.168.1.2');
});

test('IPv6 Cloudflare range trusts CF-Connecting-IP', function (): void {
    $req = (new ServerRequestFactory())
        // 2606:4700::/32 is a published Cloudflare IPv6 range
        ->createServerRequest('GET', '/x', ['REMOTE_ADDR' => '2606:4700:1234::1'])
        ->withHeader('CF-Connecting-IP', '2001:db8::1');

    expect(RealIp::from($req))->toBe('2001:db8::1');
});

test('malformed header value is skipped, falls through to REMOTE_ADDR', function (): void {
    $req = (new ServerRequestFactory())
        ->createServerRequest('GET', '/x', ['REMOTE_ADDR' => '172.64.10.5'])
        ->withHeader('CF-Connecting-IP', 'not-an-ip');

    expect(RealIp::from($req))->toBe('172.64.10.5');
});

test('missing REMOTE_ADDR defaults to 0.0.0.0 and headers are not trusted', function (): void {
    $req = (new ServerRequestFactory())
        ->createServerRequest('GET', '/x')
        ->withHeader('CF-Connecting-IP', '1.2.3.4');

    expect(RealIp::from($req))->toBe('0.0.0.0');
});
