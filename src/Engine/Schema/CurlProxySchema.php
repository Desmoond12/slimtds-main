<?php

declare(strict_types=1);

namespace App\Engine\Schema;

use App\Engine\Context;
use Psr\Http\Message\ResponseInterface;

/**
 * Fetches a URL server-side and relays its response to the visitor.
 *
 * SSRF guard: the target host is resolved and validated *before* curl ever
 * connects, and the validated IP is pinned via CURLOPT_RESOLVE so curl
 * cannot be re-pointed at a different (e.g. private/internal) address
 * between our check and the actual connection (DNS rebinding). Redirects
 * are followed manually, one hop at a time, re-validating each new target
 * the same way — `CURLOPT_FOLLOWLOCATION` alone would skip that check for
 * every hop after the first.
 *
 * This matters because `config['url']` (like `body`) passes through
 * `MacroExpander` in `ClickHandler`, and some macros resolve to
 * visitor-controlled data (`{referer}`, `{ua}`, `{lander_host}`, `{utm_*}`)
 * — an operator building a "smart proxy" URL out of those macros would
 * otherwise hand a visitor partial control of the fetch target.
 */
final class CurlProxySchema implements Schema
{
    private const MAX_REDIRECTS = 5;
    private const MAX_RESPONSE_BYTES = 5 * 1024 * 1024; // 5 MiB
    private const CONNECT_TIMEOUT = 5;

    public function respond(Context $ctx, ?string $outUrl, array $config, ResponseInterface $response): ResponseInterface
    {
        $url = (string)($config['url'] ?? $outUrl ?? '');
        if ($url === '') return $response->withStatus(502);

        $timeout = min(30, max(1, (int)($config['timeout'] ?? 10)));

        $hop = $url;
        for ($i = 0; $i <= self::MAX_REDIRECTS; $i++) {
            $guarded = self::guard($hop);
            if ($guarded === null) return $response->withStatus(502);
            [$safeUrl, $pinnedIp, $host, $port] = $guarded;

            $result = self::fetch($safeUrl, $pinnedIp, $host, $port, $timeout, $ctx->userAgent);
            if ($result === null) return $response->withStatus(502);
            [$code, $body, $type, $location] = $result;

            if (in_array($code, [301, 302, 303, 307, 308], true) && $location !== null) {
                $next = self::resolveRedirect($hop, $location);
                if ($next === null) return $response->withStatus(502);
                $hop = $next;
                continue;
            }

            if ($body === false) return $response->withStatus(502);
            $response->getBody()->write((string)$body);
            return $response->withStatus($code ?: 200)->withHeader('Content-Type', $type ?: 'text/html');
        }

        return $response->withStatus(502); // too many redirects
    }

    /**
     * Validate scheme + resolve the host to a single public IP to pin.
     * @return array{0:string,1:string,2:string,3:int}|null [url, ip, host, port]
     */
    private static function guard(string $url): ?array
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) return null;
        $scheme = strtolower($parts['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) return null;

        $host = $parts['host'];
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        $ip = self::firstPublicIp($host);
        if ($ip === null) return null;

        return [$url, $ip, $host, $port];
    }

    /** @return string|null A public (non-private/reserved/loopback) IP, or null if none found. */
    private static function firstPublicIp(string $host): ?string
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::isPublicIp($host) ? $host : null;
        }

        $ips = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) $ips = array_merge($ips, $v4);
        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $r) {
                if (isset($r['ipv6']) && is_string($r['ipv6'])) $ips[] = $r['ipv6'];
            }
        }

        foreach ($ips as $ip) {
            if (self::isPublicIp($ip)) return $ip;
        }
        return null;
    }

    private static function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /** CURLOPT_RESOLVE requires IPv6 literals bracketed: `host:port:[::1]`. */
    private static function formatPinnedIp(string $ip): string
    {
        return str_contains($ip, ':') ? "[{$ip}]" : $ip;
    }

    /**
     * @return array{0:int,1:string|false,2:?string,3:?string}|null [status, body, content-type, redirect Location] or null on transport failure
     */
    private static function fetch(string $url, string $pinnedIp, string $host, int $port, int $timeout, string $userAgent): ?array
    {
        $ch = curl_init($url);
        if ($ch === false) return null;

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false, // we follow manually, re-validating each hop
            CURLOPT_HEADER         => false,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_USERAGENT      => $userAgent,
            CURLOPT_HTTPHEADER     => ['Accept: */*'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_MAXFILESIZE    => self::MAX_RESPONSE_BYTES,
            // Pin the pre-validated IP so curl can't be redirected (DNS
            // rebinding) to a different address for this exact host:port.
            CURLOPT_RESOLVE        => ["{$host}:{$port}:" . self::formatPinnedIp($pinnedIp)],
        ]);

        // Belt-and-suspenders size cap for responses without a Content-Length
        // (MAXFILESIZE alone doesn't reliably stop a streamed/chunked body).
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, static function ($res, $dlSize, $dlNow) {
            return $dlNow > self::MAX_RESPONSE_BYTES ? 1 : 0;
        });

        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $location = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);

        if ($body === false && $code === 0) return null; // transport-level failure (DNS/connect/TLS/size-abort)

        return [
            $code,
            $body,
            is_string($type) && $type !== '' ? $type : null,
            is_string($location) && $location !== '' ? $location : null,
        ];
    }

    /** Resolve a possibly-relative Location header against the current hop's URL. */
    private static function resolveRedirect(string $currentUrl, string $location): ?string
    {
        if (parse_url($location, PHP_URL_SCHEME) !== null) return $location; // already absolute

        $base = parse_url($currentUrl);
        if ($base === false || !isset($base['host'])) return null;
        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'];
        $port = isset($base['port']) ? ':' . $base['port'] : '';

        if (str_starts_with($location, '/')) {
            return "{$scheme}://{$host}{$port}{$location}";
        }
        $basePath = isset($base['path']) ? preg_replace('#/[^/]*$#', '/', $base['path']) : '/';
        return "{$scheme}://{$host}{$port}{$basePath}{$location}";
    }
}
