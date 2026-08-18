<?php

declare(strict_types=1);

namespace App\Shared\Net;

/**
 * Reusable IP-vs-CIDR matching (IPv4 + IPv6). Same binary-prefix algorithm
 * RealIp uses internally for the trusted-proxy check, extracted so other
 * layers (postback source allowlists) can share it without reaching into
 * RealIp's private API.
 */
final class IpMatcher
{
    /**
     * @param list<string> $entries IPs ("1.2.3.4", "2a00::1") or CIDRs ("1.2.3.0/24")
     */
    public static function matchesAny(string $ip, array $entries): bool
    {
        foreach ($entries as $entry) {
            if (is_string($entry) && self::matches($ip, trim($entry))) {
                return true;
            }
        }
        return false;
    }

    public static function matches(string $ip, string $cidr): bool
    {
        if ($ip === '' || $cidr === '') {
            return false;
        }
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
        $maxBits = strlen($ipBin) * 8;
        if ($mask < 0 || $mask > $maxBits) {
            return false;
        }
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

    /** Quick validity check for operator-entered allowlist entries. */
    public static function isValidEntry(string $entry): bool
    {
        $entry = trim($entry);
        if ($entry === '') {
            return false;
        }
        $slash = strpos($entry, '/');
        $addr = $slash === false ? $entry : substr($entry, 0, $slash);
        if (filter_var($addr, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if ($slash === false) {
            return true;
        }
        $mask = substr($entry, $slash + 1);
        if ($mask === '' || !ctype_digit($mask)) {
            return false;
        }
        $maxBits = str_contains($addr, ':') ? 128 : 32;
        return (int)$mask >= 0 && (int)$mask <= $maxBits;
    }
}
