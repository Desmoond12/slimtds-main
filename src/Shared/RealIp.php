<?php

declare(strict_types=1);

namespace App\Shared;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Resolve the real client IP from a PSR-7 request.
 *
 * Client-supplied forwarding headers (X-Real-IP, CF-Connecting-IP,
 * True-Client-IP, X-Forwarded-For) are only honored when the actual TCP
 * peer (REMOTE_ADDR) is itself a trusted proxy — Cloudflare's published
 * edge ranges, or an operator-configured range via TRUSTED_PROXIES. Any
 * other peer is untrusted, so REMOTE_ADDR is used as-is: this IP feeds
 * bot/GeoIP cloaking decisions and rate-limiting, not just analytics, and
 * forwarding headers from a direct internet connection are trivially
 * spoofable (e.g. `CF-Connecting-IP: 1.2.3.4` sent straight to the origin,
 * bypassing Cloudflare entirely).
 *
 * Order once a trusted proxy is confirmed:
 *   1. X-Real-IP — set by a trusted upstream nginx that already resolved
 *      the real client IP (typically via ngx_http_realip_module + CF).
 *      Has to come before CF-Connecting-IP because the proxied flow
 *         client → CF → host nginx → CF → slimTDS
 *      makes the second CF hop overwrite CF-Connecting-IP with the host
 *      nginx IP, while X-Real-IP set by host nginx passes through intact.
 *   2. CF-Connecting-IP — Cloudflare's actual TCP peer for direct
 *      (non-proxied) example.com hits.
 *   3. True-Client-IP — alternative CF header (Enterprise plans).
 *   4. X-Forwarded-For — first IP in the comma-separated chain.
 *   5. REMOTE_ADDR — used whenever no trusted proxy confirmed the chain.
 *
 * This is a defense-in-depth check independent of Caddy's own
 * `trusted_proxies` (config/frankenphp/Caddyfile.cf) — it does not assume
 * the upstream already stripped spoofed headers.
 */
final class RealIp
{
    /**
     * Cloudflare's published edge ranges — keep in sync with
     * config/frankenphp/Caddyfile.cf's `trusted_proxies static` list.
     * https://www.cloudflare.com/ips/
     */
    private const CLOUDFLARE_RANGES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    public static function from(ServerRequestInterface $request): string
    {
        $remote = self::remoteAddr($request);

        if (!self::isRequestFromTrustedProxy($request)) {
            return $remote;
        }

        foreach (['X-Slim-IP', 'X-Real-IP', 'CF-Connecting-IP', 'True-Client-IP'] as $h) {
            $v = trim($request->getHeaderLine($h));
            if ($v !== '' && self::isValid($v)) {
                return self::strip($v);
            }
        }

        $xff = $request->getHeaderLine('X-Forwarded-For');
        if ($xff !== '') {
            $first = trim(explode(',', $xff)[0]);
            if ($first !== '' && self::isValid($first)) {
                return self::strip($first);
            }
        }

        return $remote;
    }

    /**
     * Whether this request's immediate TCP peer is a trusted proxy —
     * Cloudflare or an operator-configured `TRUSTED_PROXIES` range. Other
     * request fields that are only meant to be set by a known upstream
     * (e.g. `X-Lander-Host`/`X-Lander-Path`, set by an operator's own lander
     * nginx) should gate on this too, not just the IP-forwarding headers
     * handled by `from()`.
     */
    public static function isRequestFromTrustedProxy(ServerRequestInterface $request): bool
    {
        return self::isTrustedProxy(self::remoteAddr($request));
    }

    private static function remoteAddr(ServerRequestInterface $request): string
    {
        $params = $request->getServerParams();
        return self::strip((string)($params['REMOTE_ADDR'] ?? '0.0.0.0'));
    }

    /** Whether $ip is allowed to hand us a client IP via forwarding headers. */
    private static function isTrustedProxy(string $ip): bool
    {
        foreach (self::CLOUDFLARE_RANGES as $cidr) {
            if (self::cidrMatch($ip, $cidr)) return true;
        }
        foreach (self::customTrustedRanges() as $cidr) {
            if (self::cidrMatch($ip, $cidr)) return true;
        }
        return false;
    }

    /**
     * Operator-configured extra trust for `direct` mode (e.g. an internal
     * load balancer in front of Caddy). Comma-separated CIDR/IP list via
     * the `TRUSTED_PROXIES` env var. Re-parsed every call — it's a short
     * CSV string, not worth memoizing, and per-process caching previously
     * made this diverge from `$_ENV` after the first read (broke tests
     * that flip `TRUSTED_PROXIES` between cases in the same process).
     *
     * @return list<string>
     */
    private static function customTrustedRanges(): array
    {
        $raw = trim((string)($_ENV['TRUSTED_PROXIES'] ?? ''));
        return $raw === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /** @param string $cidr An IP (exact match) or `ip/prefix` CIDR block. */
    private static function cidrMatch(string $ip, string $cidr): bool
    {
        $slash = strpos($cidr, '/');
        $subnet = $slash === false ? $cidr : substr($cidr, 0, $slash);

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }
        if ($slash === false) {
            return $ipBin === $subnetBin;
        }

        $mask = (int)substr($cidr, $slash + 1);
        $bytes = intdiv($mask, 8);
        $bits = $mask % 8;
        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }
        if ($bits === 0) {
            return true;
        }
        $maskByte = (0xFF << (8 - $bits)) & 0xFF;
        return (ord($ipBin[$bytes]) & $maskByte) === (ord($subnetBin[$bytes]) & $maskByte);
    }

    private static function isValid(string $ip): bool
    {
        $ip = self::strip($ip);
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /** Drop CIDR suffix (`172.64.223.69/32` → `172.64.223.69`) and any port. */
    private static function strip(string $ip): string
    {
        $ip = trim($ip);
        if (($slash = strpos($ip, '/')) !== false) {
            $ip = substr($ip, 0, $slash);
        }
        // IPv4 with port: 1.2.3.4:5678
        if (substr_count($ip, ':') === 1 && filter_var(substr($ip, 0, strpos($ip, ':')), FILTER_VALIDATE_IP)) {
            $ip = substr($ip, 0, strpos($ip, ':'));
        }
        return $ip;
    }
}
