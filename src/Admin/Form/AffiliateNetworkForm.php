<?php

declare(strict_types=1);

namespace App\Admin\Form;

final class AffiliateNetworkForm
{
    private const VALID_STATUSES = ['approved', 'pending', 'hold', 'rejected'];
    private const PARAM_FIELDS = ['click_param', 'status_param', 'payout_param', 'external_id_param', 'event_type_param'];

    /**
     * @param array<string,mixed> $data
     * @return array<string,string>
     */
    public function validate(array $data): array
    {
        $errors = [];

        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = 'validation.required';
        } elseif (mb_strlen($name) > 120) {
            $errors['name'] = 'validation.max_length';
        }

        // Query-param names — must be safe to appear literally in a URL
        // query string key position; anything else would silently never
        // match what the partner actually sends.
        foreach (self::PARAM_FIELDS as $field) {
            $value = trim((string)($data[$field] ?? ''));
            if ($value === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $value)) {
                $errors[$field] = 'validation.pattern';
            }
        }

        [, $mapErrors] = $this->parseStatusMap((string)($data['status_map_raw'] ?? ''));
        if ($mapErrors !== []) {
            $errors['status_map'] = 'validation.pattern';
        }

        [, $eventMapErrors] = $this->parseEventMap((string)($data['event_map_raw'] ?? ''));
        if ($eventMapErrors !== []) {
            $errors['event_map'] = 'validation.pattern';
        }

        [, $ipErrors] = $this->parseAllowedIps((string)($data['allowed_ips_raw'] ?? ''));
        if ($ipErrors !== []) {
            $errors['allowed_ips'] = 'validation.pattern';
        }

        return $errors;
    }

    /**
     * Extract the validated status_map array from submitted form data.
     * Must be called after validate() succeeds.
     *
     * @param array<string,mixed> $data
     * @return array<string,string>
     */
    public function extractStatusMap(array $data): array
    {
        [$map] = $this->parseStatusMap((string)($data['status_map_raw'] ?? ''));
        return $map;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,string>
     */
    public function extractEventMap(array $data): array
    {
        [$map] = $this->parseEventMap((string)($data['event_map_raw'] ?? ''));
        return $map;
    }

    /**
     * @param array<string,mixed> $data
     * @return list<string>
     */
    public function extractAllowedIps(array $data): array
    {
        [$ips] = $this->parseAllowedIps((string)($data['allowed_ips_raw'] ?? ''));
        return $ips;
    }

    /**
     * "raw=canonical" per line, same UX as status_map — but the canonical
     * side is free text (open event taxonomy: reg/ftd/redeposit/...), only
     * required to be a url-safe word so it groups cleanly in stats.
     *
     * @return array{0: array<string,string>, 1: list<string>}
     */
    private function parseEventMap(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [[], []];
        }
        $map = [];
        $errors = [];
        $lines = array_filter(
            array_map('trim', explode("\n", str_replace("\r", '', $raw))),
            static fn (string $l) => $l !== '',
        );
        foreach ($lines as $line) {
            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                $errors[] = $line;
                continue;
            }
            [$rawVal, $canonical] = array_map('trim', $parts);
            $canonical = strtolower($canonical);
            if ($rawVal === '' || !preg_match('/^[a-z0-9_\-]{1,40}$/', $canonical)) {
                $errors[] = $line;
                continue;
            }
            $map[$rawVal] = $canonical;
        }
        return [$map, $errors];
    }

    /**
     * One IP or CIDR per line (IPv4 + IPv6). Invalid entries are hard form
     * errors, not silently dropped — a typo'd allowlist entry that vanished
     * quietly would lock out the partner's real postback IP in production.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function parseAllowedIps(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [[], []];
        }
        $ips = [];
        $errors = [];
        $lines = array_filter(
            array_map('trim', explode("\n", str_replace("\r", '', $raw))),
            static fn (string $l) => $l !== '',
        );
        foreach ($lines as $line) {
            if (!\App\Shared\Net\IpMatcher::isValidEntry($line)) {
                $errors[] = $line;
                continue;
            }
            $ips[] = $line;
        }
        return [array_values(array_unique($ips)), $errors];
    }

    /**
     * "raw=canonical" per line — same one-item-per-line UX as OfferForm's
     * postback_urls_raw. Blank lines skipped; canonical side must be one of
     * the four statuses the postback pipeline already understands.
     *
     * @return array{0: array<string,string>, 1: list<string>}
     */
    private function parseStatusMap(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [[], []];
        }
        $map = [];
        $errors = [];
        $lines = array_filter(
            array_map('trim', explode("\n", str_replace("\r", '', $raw))),
            static fn (string $l) => $l !== '',
        );
        foreach ($lines as $line) {
            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                $errors[] = $line;
                continue;
            }
            [$rawVal, $canonical] = array_map('trim', $parts);
            $canonical = strtolower($canonical);
            if ($rawVal === '' || !in_array($canonical, self::VALID_STATUSES, true)) {
                $errors[] = $line;
                continue;
            }
            $map[$rawVal] = $canonical;
        }
        return [$map, $errors];
    }
}
