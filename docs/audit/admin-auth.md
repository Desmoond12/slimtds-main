# Security Audit — Admin Auth/Session/CSRF

Scope: `/admin` middleware stack, login/password flows, Postgres session handler, CSRF, real-IP resolution, `.env.example`/bootstrap. Code-only review, no exploitation performed. Repo: slimTDS (PHP 8.4 / Slim 4 / FrankenPHP / PostgreSQL 18), self-hosted single-tenant TDS.

## Summary

| Severity | Count |
|---|---|
| Critical | 0 |
| High | 1 |
| Medium | 3 |
| Low | 4 |
| Info / clean | 7 |

---

## 1. Middleware execution order (`config/routes.php:178-182`)

```php
})
    ->add(\App\Admin\Middleware\PasswordChangeRequiredMiddleware::class)
    ->add(AuthMiddleware::class)
    ->add(CsrfMiddleware::class)
    ->add(LocaleMiddleware::class)
    ->add(SessionMiddleware::class);
```

Slim executes `->add()`'d middleware in **reverse** of call order (last `->add()` = outermost = runs first). Actual runtime order for every `/admin/*` request:

1. `SessionMiddleware` (starts/loads the PG-backed session)
2. `LocaleMiddleware`
3. `CsrfMiddleware`
4. `AuthMiddleware`
5. `PasswordChangeRequiredMiddleware`
6. route handler

For `POST /admin/login` specifically, `RateLimitMiddleware` is attached directly to that route (`routes.php:63-64`), which places it **innermost** (closest to the handler) — i.e. it runs *after* `CsrfMiddleware` and `AuthMiddleware` have already executed for that request, not "Session→Locale→Csrf→RateLimit→Auth→PasswordChangeRequired" as CLAUDE.md's prose summary implies.

**Severity: Info.** Not a bypass: `AuthMiddleware` explicitly exempts `/admin/login`/`/admin/logout`/`/admin/lang/{lang}` (see finding 2), and `CsrfMiddleware` running before `RateLimitMiddleware` doesn't let anyone skip rate limiting — a POST without a valid session-bound CSRF token is rejected with 403 before reaching the handler either way, so no login attempt (successful or failed) can occur without first completing a GET that seeds a session + token, and `RateLimitMiddleware` keys on IP independent of that flow. Worth updating the CLAUDE.md comment for accuracy, but no security impact. No route in the group executes before `AuthMiddleware`/`SessionMiddleware` — confirmed no unintentional bypass.

---

## 2. `AuthMiddleware` exemption list — checked, ok

`src/Admin/Middleware/AuthMiddleware.php:19-20`: exemptions are `/admin/login`, `/admin/logout` (exact string match, not prefix) plus a regex `#^/admin/lang/[a-z]{2}$#`. All three are intentionally pre-auth (login page/POST target, logout, language switch). No wildcard/prefix exemption that could be abused (e.g. `/admin/login/../campaigns` would not match `/admin/login` exactly, and Slim's router normalizes the path before middleware sees it). Clean.

---

## 3. User enumeration via timing side-channel — `LoginController::postLogin` (Medium)

`src/Admin/Controller/LoginController.php:38-45`:

```php
$admin = $this->admins->findByLogin($login);
if ($admin === null) {
    return $this->fail(...);           // fast path — no hash computation
}
if (!$this->hasher->verify($pass, $admin->passwordHash)) {
    return $this->fail(...);           // slow path — Argon2id verify
}
```

Both failure paths return the identical `auth.wrong_credentials` message (good — no enumeration via response *content*), but the "unknown login" path returns immediately while the "wrong password" path pays for a full Argon2id hash verification. Argon2id is deliberately slow (default work factor), so the timing gap is measurable over the network with enough samples, letting an attacker enumerate valid admin logins.

**Exploit scenario:** attacker scripts login attempts with random passwords across a login-name wordlist, measures response latency; logins that take measurably longer are confirmed to exist.

**Impact is low in practice** here — single-tenant app, `ADMIN_LOGIN` defaults to `admin`, and `RateLimitMiddleware` throttles by login/IP/cookie (5 attempts/5min by login) which also limits how many timing samples an attacker can collect. Still worth a cheap fix.

**Fix:** always run `password_verify()`/hasher against a constant dummy hash when `$admin === null`, e.g.:
```php
$admin = $this->admins->findByLogin($login);
$hash = $admin->passwordHash ?? self::DUMMY_HASH; // a pre-computed Argon2id hash
$ok = $this->hasher->verify($pass, $hash);
if ($admin === null || !$ok) { return $this->fail(...); }
```

---

## 4. Password change does not invalidate other sessions — `PasswordController::post` (Medium)

`src/Admin/Controller/PasswordController.php:45-95` updates `password_hash` via `AdminRepository::updatePassword()` but never calls `session_regenerate_id()` nor purges any other live session for the same admin. Given this is a single-admin tool, the realistic scenario is: a session cookie is stolen (XSS, shared/compromised machine, session-hijack) and the legitimate admin changes their password believing that revokes the attacker's access — it does not; the stolen session cookie remains valid until natural expiry (`SESSION_LIFETIME`, default 14 days).

Notably, `core.sessions` (migration `20260424000001_init_core_auth.php:28-39`) **already has `admin_id`, `ip`, and `user_agent` columns with an index (`idx_sessions_admin`)** — schema clearly anticipated per-admin session tracking/revocation. But `PgSessionHandler::write()` (`src/Shared/Session/PgSessionHandler.php:45-60`) only ever `INSERT`s `id`, `data`, `expires_at`, `updated_at` — **`admin_id`, `ip`, and `user_agent` are never populated**, so they're permanently `NULL` and there is currently no query that could select "all other sessions belonging to admin X" even if someone wanted to add the invalidation call. This looks like an unfinished feature rather than a deliberate design choice.

**Fix:** populate `admin_id` (from `$_SESSION['admin_id']` once set) and `ip`/`user_agent` on write, then in `PasswordController::post()` (and ideally also on logout-everywhere / suspicious-login response) run `DELETE FROM core.sessions WHERE admin_id = :id AND id != :current_id`.

---

## 5. `RealIp::from()` trusts client-supplied headers with no proxy allowlist (High)

`src/Shared/RealIp.php:34-47` walks `X-Slim-IP`, `X-Real-IP`, `CF-Connecting-IP`, `True-Client-IP`, then `X-Forwarded-For`, taking the **first structurally-valid IP found in client-controlled headers**, with no check of `REMOTE_ADDR` against a trusted-proxy allowlist and no `TRUSTED_PROXIES` env var consulted (that env var exists in `.env.example:45` but its own comment says `UNUSED: nothing reads this key`). This is used in:

- `src/Engine/ClickHandler.php:140` — feeds `Context->ip`, which drives `GeoLookup` (country/city targeting) and `BotDetector` (IP/ASN blocklist) on the public redirect hot path.
- `src/Postback/PostbackController.php:48`, `src/Pixel/EventController.php:151`, `src/Pixel/RecordController.php:71` — source-IP attribution for conversions/events/session-replay.

The class's own docblock (`RealIp.php:26-28`) acknowledges this: *"X-Real-IP can be spoofed by anyone hitting the public endpoint directly, so this layer is 'best effort attribution', not a security boundary. Adequate for analytics/pixel events."* — but it's also used by `BotDetector` and `GeoLookup`, which are filtering/targeting decisions, not just analytics.

Mitigating factor: for `cf_flex`/`cf_full` mode, `config/frankenphp/Caddyfile.cf:12-13` sets `trusted_proxies static <Cloudflare CIDR ranges>` + `client_ip_headers CF-Connecting-IP`, so **`REMOTE_ADDR` itself is correctly resolved by Caddy** for requests actually passing through Cloudflare. The problem is that `RealIp::from()` doesn't use Caddy's resolved `REMOTE_ADDR` — it re-parses the raw headers off the PSR-7 request itself, so:
- Any client that reaches the origin directly (origin IP not firewalled to Cloudflare-only, a common oversight) can set `CF-Connecting-IP: 1.2.3.4` and have it trusted unconditionally.
- In `direct` and `dev` mode there is no upstream proxy trust configuration at all (`Caddyfile.direct`/`Caddyfile.dev` have no `trusted_proxies` block), so `REMOTE_ADDR` there is the real raw TCP peer — but `RealIp::from()` still prefers the spoofable headers over it, so *any* internet client hitting a `direct`-mode instance can set `X-Forwarded-For`/`CF-Connecting-IP` and completely control the IP the engine believes it's serving.

**Exploit scenario:** an operator running `direct` mode geo-targets an offer to `US` only. A visitor from a blocked GEO sends `CF-Connecting-IP: 8.8.8.8` and is now geo-matched as US, bypassing the flow filter. Similarly a blocked/bot IP can rotate the header value per request to evade `BotDetector`'s IP list.

**Not an issue for admin auth specifically** — `RateLimitMiddleware::resolveIp()` (`src/Admin/Middleware/RateLimitMiddleware.php:74-80`) deliberately reads raw `REMOTE_ADDR` only ("In dev we trust REMOTE_ADDR. In prod behind CF, Caddy already rewrites it.") and does not call `RealIp::from()`, so admin login rate-limiting is not spoofable this way. Flagging as High rather than Critical because it doesn't touch authentication, but it does undermine two other security controls (bot filtering, geo targeting) app-wide, and is trivially exploitable by any external client with a plain HTTP client.

**Fix:** either (a) stop re-parsing headers in `RealIp` and use `$request->getServerParams()['REMOTE_ADDR']` (already correctly trust-resolved by Caddy per deploy mode), or (b) make `RealIp` deploy-mode-aware: only trust `CF-Connecting-IP`/`X-Forwarded-For` when `REMOTE_ADDR` itself is within the same trusted CIDR list Caddy uses, sourced from a real `TRUSTED_PROXIES` config instead of the currently-dead env var.

---

## 6. Fixed-window rate limiter boundary burst — `RateLimiter::hit()` (Low)

`src/Shared/RateLimit/RateLimiter.php:27-52` uses discrete, epoch-aligned fixed windows (`intdiv($epoch, $windowSeconds) * $windowSeconds`). Classic fixed-window artifact: an attacker who times requests can get up to `2 × maxPerWindow` attempts by clustering just before and just after a window boundary (e.g. up to ~10 login attempts for a nominal 5/5min limit). The code's own docblock acknowledges this ("Not a true sliding window ... adequate for login-rate-limiting"). Low impact given `RATE_LIMIT_LOGIN=5` default and Argon2id's cost per attempt. No fix required unless the threat model changes; sliding-window or token-bucket would close it if desired.

---

## 7. No `session.use_strict_mode` — mitigated by regenerate-on-login (Low)

No `php.ini` override was found setting `session.use_strict_mode = 1` (PHP default is `0`), and `SessionMiddleware::process()` (`src/Admin/Middleware/SessionMiddleware.php:38-41`) adopts whatever session ID arrives in the cookie via `session_id($cookies[$name])` before `session_start()`, without first calling `PgSessionHandler::validateId()`. Without strict mode, PHP will happily start a session under an attacker-chosen ID that doesn't yet exist in `core.sessions` (classic session-fixation setup), *if* an attacker can get that ID into the victim's cookie jar (requires XSS, a sibling-subdomain cookie-scope issue, or a MITM on non-HTTPS — none of which this review found evidence of as an existing vector).

**This is mitigated in practice**: `LoginController::postLogin()` calls `session_regenerate_id(true)` unconditionally on successful authentication (`LoginController.php:53`), which issues a fresh cryptographically random ID and destroys the old one — so even a pre-planted attacker session ID becomes worthless the moment the victim authenticates. Recommend enabling `session.use_strict_mode=1` anyway as defense-in-depth (no functional downside), but this is not independently exploitable today.

---

## 8. No account-level lockout beyond rate limiting — info

Only `RateLimitMiddleware`'s per-IP/per-login/per-cookie fixed-window throttling exists; there's no persistent "N failures → lock this account until admin action" counter on `core.admins`. Given this is a single-admin, self-hosted internal tool (not multi-tenant SaaS), and the login-scoped rate limit (default 5/5min) already caps guess throughput heavily against an Argon2id hash, this is an acceptable trade-off for the stated threat model, not a defect. No action recommended.

---

## 9. `RateLimiter::reset()` exists but is never called on success — info

`src/Shared/RateLimit/RateLimiter.php:76-79` provides `reset()` explicitly for "used e.g. on successful login to clear previous failures", but `LoginController::postLogin()` never calls it. Net effect: failed-attempt counters keep counting toward the window even after a legitimate successful login, which is a (very minor) usability wrinkle, not a vulnerability — if anything it's slightly more conservative/safer to leave it uncalled. No fix required.

---

## 10. CSRF — synchronizer-token pattern, session-bound — checked, ok

`src/Admin/Middleware/CsrfMiddleware.php`:
- Token: `bin2hex(random_bytes(32))` — 256 bits, generated once per session and stored server-side in `$_SESSION['csrf_token']` (`CsrfMiddleware.php:21-22`). This is the synchronizer-token pattern (session-bound), not a double-submit cookie — good, immune to the sub-domain cookie-injection weakness double-submit schemes have.
- Verification uses `hash_equals()` (`CsrfMiddleware.php:40`) — constant-time comparison, no timing leak on token guessing.
- Applied to all `POST/PUT/PATCH/DELETE` under `/admin/*` uniformly via group middleware (`routes.php:180`) — no admin mutation route found registered outside the group (confirmed no `$app->post('/admin/...')` outside the group in `routes.php`).
- Token accepted from either the `_csrf` form field or `X-CSRF-Token` header (`CsrfMiddleware.php:30-37`) — reasonable for both classic forms and any fetch()-based admin JS.

Clean.

---

## 11. Session cookie flags — checked, ok

`src/Admin/Middleware/SessionMiddleware.php:29-35`:
```php
session_set_cookie_params([
    'lifetime' => $lifetime,
    'path' => '/',
    'httponly' => true,
    'secure' => ($request->getUri()->getScheme() === 'https'),
    'samesite' => 'Lax',
]);
```
`HttpOnly` ✔, `SameSite=Lax` ✔ (appropriate for a top-level-navigation admin login flow; `Strict` would break the login-redirect UX from bookmarks/emails but isn't needed here since there's no cross-site state-changing GET), `Secure` is conditional on the *current* request's scheme rather than hardcoded — correct behavior for `dev` (plain HTTP, OrbStack) while still `Secure` in `cf_*`/`direct` prod modes served over TLS. Matches the `ui_lang` cookie's explicit flags set in `routes.php:56` (`HttpOnly; Secure; SameSite=Lax`), so no inconsistency between the two cookies as the prompt asked to check. Session ID entropy: `PgSessionHandler::create_sid()` returns `bin2hex(random_bytes(32))` — 256 bits, cryptographically secure (`PgSessionHandler.php:73-76`). Clean.

---

## 12. Session fixation on login — checked, ok

`LoginController::postLogin()` calls `session_regenerate_id(true)` (deletes old session row via PHP's session machinery) immediately before setting `$_SESSION['admin_id']` (`LoginController.php:53-54`). `getLogout()` does the same plus explicit `session_destroy()` (`LoginController.php:79-82`). Both privilege-boundary transitions correctly rotate the session identifier. Clean (see finding 7 for the residual defense-in-depth note).

---

## 13. SQL injection — checked, ok

`AdminRepository` (`findByLogin`, `findById`, `updatePassword`, `updateUiLang`, `flagPasswordChange`) and `RateLimiter`/`PgSessionHandler` all use parameterized queries exclusively (`:name` placeholders via `Connection`), no string interpolation of user input into SQL anywhere in the reviewed files. Clean.

---

## 14. Password requirements — `PasswordController` (info)

`MIN_LEN = 10` (`PasswordController.php:16`), enforced via `mb_strlen($new) < self::MIN_LEN` — no complexity requirements (uppercase/digit/symbol), no check against common-password lists, no max-length cap (Argon2id handles long inputs fine, no DoS concern at reasonable lengths since this is a single low-traffic admin form behind the rate limiter). Also correctly checks: current password must verify (`hasher->verify`), new must differ from current (`hasher->verify($new, ...)` inverted), confirm must match via `hash_equals()` (not `===`, good — avoids any short-circuit timing difference on the confirm compare, though this field isn't secret so it's a minor nicety not a real requirement). 10-char minimum with Argon2id-hashed storage is reasonable for a single-admin internal tool; consider a passphrase-length nudge (e.g. 12-14) or haveibeenpwned k-anonymity check if the threat model includes credential-stuffing reuse, but not required.

---

## 15. `.env.example` / bootstrap — checked, ok

- `.env.example:5` ships `APP_SECRET=CHANGE_ME_32_bytes_random_string` as a placeholder — but `make env` (`Makefile:12-21`) always overwrites it: `APP_SECRET=$(php -r 'echo bin2hex(random_bytes(32));')` (32 bytes = 256 bits) and `ADMIN_PASSWORD=$(php -r 'echo bin2hex(random_bytes(8));')` (8 bytes = 64 bits, 16 hex chars) via `awk` substitution into the generated `.env`. No path leaves the literal `CHANGE_ME...` placeholder active if the documented `make env` flow is followed.
- `AdminInitCommand` (`src/Admin/Command/AdminInitCommand.php:34-46`): if `ADMIN_PASSWORD` is empty it generates its own `bin2hex(random_bytes(10))` and sets `must_change_password=true`; if `ADMIN_PASSWORD` is non-empty (the `make env`-generated value) it uses that value verbatim with `must_change_password=false`. Since the value that reaches this point via the documented flow is always the random one from `make env`, not a hardcoded weak default, this is fine. Note: this means a user who manually edits `.env` to set a memorable/weak `ADMIN_PASSWORD` before first `admin:init` run will *not* be forced to change it — worth a one-line comment in `.env.example` next to `ADMIN_PASSWORD=` warning that a manually-set value skips the forced-change flow, but not a code defect.
- `APP_DEBUG` defaults to `true` in `.env.example:3` (appropriate for the `dev` default `DEPLOY_MODE`); `addErrorMiddleware(displayErrorDetails: filter_var($_ENV['APP_DEBUG']...))` in `config/app.php:14-15` means this must be set to `false` for `cf_*`/`direct` prod deploys or stack traces leak to visitors on unhandled exceptions. Confirmed: `docs/AI-INSTALL.md:201` documents `APP_DEBUG=false` for all three prod modes (`cf_flex`/`cf_full`/`direct`), only `dev` keeps `true`. Documentation is correct; this is only a risk if an operator deploys prod without following the documented install flow.

Clean overall, one documentation nit noted.

---

## Findings index

| # | Title | Severity |
|---|---|---|
| 5 | `RealIp::from()` trusts client headers with no proxy allowlist — spoofable geo/bot-detection | High |
| 3 | Timing side-channel user enumeration in login (found-vs-not-found hash skip) | Medium |
| 4 | Password change doesn't invalidate other sessions; `core.sessions.admin_id` schema unused | Medium |
| 6 | Fixed-window rate limiter boundary burst | Low |
| 7 | `session.use_strict_mode` not set (mitigated by regenerate-on-login) | Low |
| 15 | Manually-set `ADMIN_PASSWORD` skips forced-change flow (doc nit) | Low |
| 1 | Middleware order differs from CLAUDE.md prose (no actual impact) | Info |
| 2, 8, 9, 10, 11, 12, 13, 14 | Checked, ok | Info / clean |
