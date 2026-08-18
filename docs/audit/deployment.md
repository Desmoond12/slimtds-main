# Deployment/Docker/Secrets Audit

Scope: docker-compose*.yml, .env.example, docker/entrypoint.sh, docker/Dockerfile, Caddyfile.{cf,direct}, BackupController, TRUSTED_PROXIES / real-IP trust chain. Read-only inspection, no code changes made.

## Findings

### 1. CRITICAL — Client-supplied IP headers are trusted by the PHP app with no verification that they came from a trusted hop

`src/Shared/RealIp.php:34` walks `X-Slim-IP → X-Real-IP → CF-Connecting-IP → True-Client-IP → X-Forwarded-For → REMOTE_ADDR` and **trusts the first well-formed IP it finds in a raw HTTP request header**, unconditionally. It does not check `REMOTE_ADDR` (the actual TCP peer, which Caddy *does* correctly protect via `trusted_proxies` in `Caddyfile.cf`) against anything before honoring `CF-Connecting-IP`/`X-Real-IP`/`X-Forwarded-For`.

`Caddyfile.cf`'s `trusted_proxies static <CF ranges>` + `client_ip_headers CF-Connecting-IP` only governs **Caddy's own** client-IP placeholders (logging, `{client_ip}`) — Caddy's `php_server` directive still passes the original, unmodified `CF-Connecting-IP` / `X-Forwarded-For` / any other request header straight through to the PHP process as `HTTP_*`/headers. There is no `header_up`/`request_header` rule anywhere that strips or overwrites these headers when the connecting peer is *not* in the Cloudflare range.

**Exploitation:**
- `cf_flex`/`cf_full` mode: both prod compose files (`docker-compose.prod.cf.yml`) publish `80:80` and `443:443` on all interfaces — the origin is directly reachable by IP, bypassing Cloudflare entirely, if the origin IP is ever discovered (DNS history / crt.sh cert-transparency logs / Shodan / a misconfigured non-proxied DNS record / mail server on the same box). Anyone who does so can send `CF-Connecting-IP: 8.8.8.8` (or any IP) and the app will use it as-is.
- `direct` mode (`Caddyfile.direct`): there is no upstream CDN at all — the public IP *is* the origin by design — so **every single internet request can already forge `X-Real-IP`/`CF-Connecting-IP`/`X-Forwarded-For`** with zero prerequisite.

**Impact — this defeats the exact anti-detection/cloaking mechanism the tracker exists for, plus auth hardening:**
- `BotDetector` (Stage 1/2a/2b) keys off `$ctx->ip` — a reviewer/compliance crawler/competitor who spoofs a clean residential IP bypasses the IP/CIDR/ASN bot lists entirely and sees the real offer instead of the cloaked "official site" fallback. Datacenter/VPN detection (the strongest signal you have) becomes opt-out for anyone who knows to send one extra header.
- `RATE_LIMIT_IP` (`RateLimitMiddleware`, keyed on IP) and login-lockout become trivially bypassable — rotate the spoofed header per request to brute-force `/admin/login` past the per-IP window (the per-login and per-cookie limiters are a partial mitigation, but per-IP is the primary layer per `.env.example`).
- GeoIP-based flow routing/reporting is spoofable, undermining GEO-split accuracy and stats integrity.

**Fix (do both, defense-in-depth):**
1. OS-level firewall on the VPS (ufw/iptables) restricting inbound 80/443 to Cloudflare's published IP ranges when `DEPLOY_MODE=cf_*`. This is the only way to actually close the "connect directly, bypass CF" path — Caddy-level trust config cannot do it. Not currently documented anywhere in `docs/DEPLOYMENT.md`.
2. In `RealIp.php`, only honor `CF-Connecting-IP`/`X-Real-IP`/`True-Client-IP`/`X-Forwarded-For` when the request's `REMOTE_ADDR` server param (which Caddy *does* correctly resolve/protect) is itself within the trusted proxy range — i.e. re-derive trust server-side instead of trusting client headers unconditionally. For `direct` mode, these headers should not be trusted at all unless you explicitly front the box with your own reverse proxy.

### 2. LOW/MEDIUM — `DB_PASSWORD` ships a hardcoded weak default that `make env` never rotates

`.env.example:17` ships `DB_PASSWORD=slimtds` (not a `CHANGE_ME` placeholder like `APP_SECRET`), and `docker-compose.yml:32` falls back to the same literal `slimtds` if the env var is ever unset. `make env` (`Makefile:15-19`) randomizes `APP_SECRET` and `ADMIN_PASSWORD` but does **not** touch `DB_PASSWORD`. Exploitability today is low since no prod compose file publishes the Postgres port publicly (only `docker-compose.override.yml`, dev-only, binds it to `127.0.0.1:5432`) — but it's a real weak-default sitting one config mistake away (e.g. someone copies the dev override's port mapping habit into a prod file, or the box is later multi-tenant). Cheap fix: have `make env` randomize `DB_PASSWORD` too, same as the other two secrets.

### 3. INFO — No findings on path traversal / IDOR in backups

`BackupController::download()`/`delete()` (`src/Admin/Controller/BackupController.php:69-114`) correctly `basename()`s the input *and* whitelists it against `^[A-Za-z0-9_\-]+\.dump$` before touching the filesystem — no traversal possible. Both routes sit behind the full `/admin` middleware stack (Auth+Csrf+RateLimit). No issue.

### 4. INFO — Dockerfile / entrypoint

- `docker/Dockerfile`: multi-stage build (assets-builder → composer vendor → `dunglas/frankenphp` alpine runtime), no dev tools/composer left in the final `runtime` stage, no secrets baked into layers. FrankenPHP/Caddy process is **not** explicitly dropped to a non-root user (no `USER` directive in the runtime stage) — worth adding `USER` for the app process, though FrankenPHP itself needs root or `CAP_NET_BIND_SERVICE` to bind :80/:443, so this needs care (e.g. setcap instead of running fully as root). Not urgent but standard hardening.
- `docker/entrypoint.sh` — `DEPLOY_MODE` selects the Caddyfile via a `case` statement (no shell injection, values are compared literally, not interpolated into a command). The `sed -i` dev-only classmap patch only fires when `MODE=dev` and only touches a file path that's not attacker-influenced. No issue.
- Postgres is never exposed in any prod compose file. Good.

### 5. NOT AUDITED (out of this fork's scope, flagging for the parent)

- FrankenPHP **worker mode** statefulness: the app process stays resident across requests (per `CLAUDE.md`/README). This is a real class of bug risk — any static/singleton PHP state that isn't reset per-request can leak one visitor's data (session, Context, DB connection state) into another visitor's response. This needs a dedicated pass over `config/frankenphp/worker.php` and any `static`/global state in DI-registered services — did not check this here, recommend the Engine-hot-path or Auth/session fork cover it explicitly if not already.
