<?php

declare(strict_types=1);

namespace App\Engine;

final class MacroExpander
{
    /**
     * @param string $escape How each *substituted macro value* is escaped
     *   before being spliced back into the template — the template's own
     *   literal text (an operator-authored URL/HTML/JSON skeleton) is never
     *   touched, only what a macro resolves to. Several macros resolve to
     *   visitor-controlled data (referer, UA, lander_*, utm_*) that must not
     *   be spliced raw into an HTML or JSON context:
     *     'raw'  — no escaping (default; correct for building URLs, where
     *              the destination schema does its own escaping if the
     *              result is later embedded in HTML — see MetaRefreshSchema
     *              /JsRedirectSchema/IframeSchema).
     *     'html' — htmlspecialchars(ENT_QUOTES) each value; use whenever the
     *              template is HTML that gets written straight to the
     *              response body (HtmlPageSchema, FormulaSchema, HttpCodeSchema).
     *     'json' — JSON-string-escape each value (quotes stripped, so it
     *              drops into a template where the operator already wrote
     *              the surrounding `"..."`); use for JsonSchema bodies.
     *     'url'  — rawurlencode each value; use when the template is itself
     *              a URL and a macro fills a query-string value (e.g. an
     *              outgoing postback URL template), so a value containing
     *              `&`/`=`/`#` can't inject extra query params or fragments.
     */
    /**
     * Macro names `resolve()` understands. Used to tell "known macro whose
     * current value happens to be null" (e.g. {country} with no GeoIP DB
     * loaded — substitute '', a graceful degrade) apart from "not a macro at
     * all" (typo, or literal `{...}` in operator-authored text unrelated to
     * this system — leave untouched rather than guess). Without this split,
     * a null-valued known macro used to leak its literal `{name}` straight
     * into outbound offer/postback URLs sent to real partners.
     */
    private const KNOWN_MACROS = [
        'country', 'region', 'city', 'device', 'os', 'browser', 'bot', 'lang',
        'ip', 'ua', 'referer', 'click_id', 'visitor_uuid', 'campaign_slug',
        'lander_host', 'lander_domain', 'lander_button', 'timestamp',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'rand', 'randstr', 'spin',
    ];

    public function expand(string $template, Context $ctx, string $escape = 'raw'): string
    {
        return preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]*))?\}/',
            function (array $m) use ($ctx, $escape) {
                $value = $this->resolve($m[1], $m[2] ?? '', $ctx);
                if ($value !== null) return self::escapeValue($value, $escape);
                return in_array($m[1], self::KNOWN_MACROS, true) ? self::escapeValue('', $escape) : $m[0];
            },
            $template,
        ) ?? $template;
    }

    private static function escapeValue(string $value, string $escape): string
    {
        return match ($escape) {
            'html' => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'),
            'json' => substr(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '""', 1, -1),
            'url'  => rawurlencode($value),
            default => $value,
        };
    }

    private function resolve(string $name, string $arg, Context $ctx): ?string
    {
        return match ($name) {
            'country'      => $ctx->country,
            'region'       => $ctx->region,
            'city'         => $ctx->city,
            'device'       => $ctx->device,
            'os'           => $ctx->os,
            'browser'      => $ctx->browser,
            'bot'          => $ctx->botName,
            'lang'         => $ctx->lang,
            'ip'           => $ctx->ip,
            'ua'           => $ctx->userAgent,
            'referer'      => $ctx->referer,
            'click_id'     => $ctx->clickId,
            'visitor_uuid' => $ctx->visitorUuid,
            'campaign_slug' => $ctx->campaignSlug,
            'lander_host'  => $ctx->landerHost,
            'lander_domain' => $ctx->landerDomain,
            'lander_button' => $ctx->landerButton,
            'timestamp'    => (string)$ctx->timestamp,
            'utm_source'   => $ctx->utm['source']   ?? null,
            'utm_medium'   => $ctx->utm['medium']   ?? null,
            'utm_campaign' => $ctx->utm['campaign'] ?? null,
            'utm_term'     => $ctx->utm['term']     ?? null,
            'utm_content'  => $ctx->utm['content']  ?? null,
            'rand'         => $this->rand($arg),
            'randstr'      => $this->randStr($arg),
            'spin'         => $this->spin($arg),
            default        => null,
        };
    }

    /**
     * Uniform random pick from a pipe-separated list: {spin:a|b|c}.
     * Whitespace is trimmed, empty segments dropped, values substituted raw.
     * Returns '' when no valid values so the literal never leaks into a URL.
     * A skew is expressed by repeating a value: {spin:a|a|b} → 2/3 a.
     */
    private function spin(string $arg): string
    {
        $values = [];
        foreach (explode('|', $arg) as $v) {
            $v = trim($v);
            if ($v !== '') $values[] = $v;
        }
        if ($values === []) return '';
        return $values[random_int(0, count($values) - 1)];
    }

    private function rand(string $arg): string
    {
        if (!preg_match('/^(-?\d+)-(-?\d+)$/', $arg, $m)) return '';
        return (string)random_int((int)$m[1], (int)$m[2]);
    }

    private function randStr(string $arg): string
    {
        $len = max(1, min(64, (int)$arg ?: 8));
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }
}
