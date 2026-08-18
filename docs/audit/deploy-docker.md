# Security Audit — Docker/Deployment/Secrets

Scope: `docker-compose.yml` / `docker-compose.prod.cf.yml` / `docker-compose.prod.direct.yml` /
`docker-compose.override.yml`, `.env.example`, `docker/entrypoint.sh`, `docker/Dockerfile`,
`config/frankenphp/Caddyfile.{cf,direct,dev}`, `src/Admin/Controller/BackupController.php`,
`docker/supercronic/crontab`. Read-only review — no code changed.

---

## 1. CRITICAL — `CF-Connecting-IP`/`X-Real-IP`/`X-Forwarded-For` are trusted by the PHP app with no server-side verification of the sender

**File:** `src/Shared/RealIp.php:30-52` (`RealIp::from()`), consumed by
`src/Engine/ClickHandler.php:140`, `src/Pixel/EventController.php:151`,
`src/Pixel/RecordController.php:71`, `src/Postback/PostbackController.php:48`.

`RealIp::from()` walks `X-Slim-IP → X-Real-IP → CF-Connecting-IP → True-Client-IP →
X-Forwarded-For → REMOTE_ADDR` and returns the **first syntactically valid IP it finds in a raw
request header**, unconditionally. It never checks the actual TCP peer (`REMOTE_ADDR`) against
anything before honoring a client-supplied header.

`config/frankenphp/Caddyfile.cf:11-14` does configure `trusted_proxies static <Cloudflare CIDR
list>` + `client_ip_headers CF-Connecting-IP` — but that global `servers` option only governs
**Caddy's own** internal client-IP resolution (used for Caddy's access log `client_ip` field and
Caddy-level placeholders/matchers). It does **not** strip or rewrite the raw `CF-Connecting-IP` /
`X-Real-IP` / `X-Forwarded-For` headers before `php_server` (`Caddyfile.cf:29`) hands the request
to the FrankenPHP worker — those headers reach `$_SERVER`/PSR-7 exactly as the client sent them.
`docs/DEPLOYMENT.md:60` itself documents the `trusted_proxies` list but never states — and the
code does not implement — that PHP-side trust is likewise restricted to it.

**Exploit scenario:**
- `cf_flex`/`cf_full`: `docker-compose.prod.cf.yml:6-8` publishes `80:80`/`443:443` on all host
  interfaces. If the origin IP is ever discovered (DNS history, certificate-transparency logs for
  the CF Origin Cert, Shodan, a non-proxied `A` record left over from setup, a mail server on the
  same box), an attacker connects to the origin directly — bypassing Cloudflare — and sends
  `CF-Connecting-IP: 1.2.3.4` (or any header earlier in the priority list). `RealIp::from()`
  accepts it as-is.
- `direct` mode: there is no CDN in front at all; the public IP *is* the origin by design, so
  **every internet request can already forge `X-Real-IP`/`X-Forwarded-For`** with no prerequisite
  — `Caddyfile.direct` has no `trusted_proxies` restriction whatsoever.

**Impact:** this is the exact mechanism the product's cloaking/anti-detection and rate-limiting
depend on:
- `BotDetector` and `FlowMatcher` key filters off `$ctx->ip` — a reviewer, compliance crawler, or
  competitor who spoofs a clean residential IP bypasses IP/CIDR/ASN bot lists and geo-targeting
  entirely.
- `RateLimiter`'s per-IP window (`RATE_LIMIT_IP` in `.env.example`) and admin login lockout become
  bypassable by rotating the spoofed header per request — brute-forcing `/admin/login` past the
  primary per-IP limiter (per-login/per-cookie limiters are a partial backstop only).
- Click/postback stats and GEO-split reporting become spoofable, undermining data integrity.

**Fix (defense-in-depth, do both):**
1. In `RealIp::from()`, only honor `CF-Connecting-IP`/`X-Real-IP`/`True-Client-IP`/
   `X-Forwarded-For` when `REMOTE_ADDR` (the actual socket peer — safe, Caddy can't be tricked
   about this) is itself within the same Cloudflare CIDR list already hardcoded in
   `Caddyfile.cf`. Otherwise fall straight to `REMOTE_ADDR`. In `direct` mode, none of these
   headers should be trusted at all unless the operator explicitly fronts the box with their own
   reverse proxy (and that proxy's IP is verified the same way).
2. OS-level firewall (ufw/iptables) on the VPS restricting inbound 80/443 to Cloudflare's
   published ranges when `DEPLOY_MODE=cf_*`. This is the only way to actually close the
   "connect directly to the origin IP, bypass CF" path — Caddy/PHP-level trust logic alone cannot,
   since the attacker's packets do legitimately arrive with `REMOTE_ADDR` = attacker IP either
   way. Not currently documented in `docs/DEPLOYMENT.md`'s `prod-cf` section.

---

## 2. LOW/MEDIUM — `DB_PASSWORD` ships a real (not placeholder) weak default that `make env` never rotates

**Files:** `.env.example:17` (`DB_PASSWORD=slimtds`), `docker-compose.yml:32`
(`POSTGRES_PASSWORD: ${DB_PASSWORD:-slimtds}`), `Makefile:15-19` (`env` target).

Unlike `APP_SECRET` (`.env.example:5`, ships the obviously-fake placeholder
`CHANGE_ME_32_bytes_random_string`) and `ADMIN_PASSWORD` (ships blank), `DB_PASSWORD` ships the
literal working value `slimtds`, which also happens to equal `DB_USER`/`DB_NAME`. `make env`
(`Makefile:15-19`) only rewrites the `APP_SECRET=` and `ADMIN_PASSWORD=` lines via `awk`; it never
touches `DB_PASSWORD=`. So a freshly-bootstrapped instance always runs Postgres with credentials
`slimtds`/`slimtds` unless the operator manually edits `.env`.

README.md:35 and DEPLOYMENT.md:27 both claim `make env` "generates .env with random secrets" —
true for `APP_SECRET`/`ADMIN_PASSWORD`, not true for `DB_PASSWORD`, so the docs slightly overstate
what gets rotated.

**Exploitability today is low**, not zero: no prod compose file (`docker-compose.prod.cf.yml`,
`docker-compose.prod.direct.yml`) publishes the `db` service's port — confirmed no `ports:` block
on `db` in either file (§ below). Only `docker-compose.override.yml:26-28` binds
`127.0.0.1:5432:5432`, and that file only auto-merges when compose is invoked with no explicit
`-f` list; `make prod-up-cf`/`make prod-up-direct` (`Makefile:130-141`) always pass
`-f docker-compose.yml -f docker-compose.prod.*.yml` explicitly, which excludes the override file
per Compose semantics. So Postgres is not reachable from outside the Docker network in the
documented prod flows. Risk surface: lateral movement if another container on the same bridge
network is ever compromised, or if an operator later copies the dev override's port-mapping habit
into a prod file "for debugging."

**Fix:** have `make env`'s `awk` step also randomize `DB_PASSWORD=` (same treatment as
`APP_SECRET`/`ADMIN_PASSWORD`), and update `docker-compose.yml:32`'s fallback default to something
that visibly fails closed rather than a real usable password.

**Secondary, same class:** if an operator skips `make env` and hand-copies `.env.example` →
`.env`, `APP_SECRET` stays literally `CHANGE_ME_32_bytes_random_string` — guessable/public (it's
in the public repo) and cited by DEPLOYMENT.md:97 as the value that "signs sessions and CSRF
tokens." Purely an operator-error path (the documented flow via `make env` avoids it), noting for
completeness.

---

## 3. Postgres port exposure in prod compose files — checked, ok

`docker-compose.yml:27-42` (base `db` service) has no `ports:` block at all — only reachable on
the internal `slimtds` bridge network. Neither `docker-compose.prod.cf.yml` nor
`docker-compose.prod.direct.yml` adds one for `db`. The only `ports:` mappings for `db` anywhere
in the repo are in `docker-compose.override.yml:26-28`, which is dev-only (`127.0.0.1:5432`, and
per §2 above does not auto-merge into the explicit `-f` invocations `make prod-up-*` use).
`app`/`geoipupdate` publish `80:80`/`443:443` in both prod files, which is required and expected.
No public Postgres exposure in the documented prod paths.

---

## 4. `environment:`/`env_file` blocks — checked, ok (no hardcoded secrets in yml)

All three compose files source secrets via `env_file: .env` (`docker-compose.yml:8`, applied to
`app` and `cron`) or `${VAR}` interpolation from the environment (`DB_NAME`, `DB_USER`,
`DB_PASSWORD` in `docker-compose.yml:30-32`; `MAXMIND_ACCOUNT_ID`/`MAXMIND_LICENSE_KEY` in both
prod files' `geoipupdate` service). No literal API tokens, passwords, or keys are hardcoded
directly in any `docker-compose*.yml`. The only literal values present are non-secret defaults
(`slimtds` as `DB_NAME`/`DB_USER` fallback — see §2 for the password fallback specifically).

---

## 5. `.env.example` secrets inventory and `make env` cross-check

| Var | Default in `.env.example` | Rotated by `make env`? |
|---|---|---|
| `APP_SECRET` | `CHANGE_ME_32_bytes_random_string` (placeholder) | Yes — `bin2hex(random_bytes(32))`, 256-bit |
| `ADMIN_PASSWORD` | blank | Yes — `bin2hex(random_bytes(8))`, 64-bit / 16 hex chars |
| `DB_PASSWORD` | `slimtds` (real value, not a placeholder) | **No** — see Finding 2 |
| `DB_USER` / `DB_NAME` | `slimtds` | No (not secret-sensitive by itself) |
| `TELEGRAM_BOT_TOKEN` | blank | N/A, operator-supplied |
| `MAXMIND_ACCOUNT_ID` / `MAXMIND_LICENSE_KEY` | blank | N/A, operator-supplied |
| `TRUSTED_PROXIES` | blank | N/A — confirmed dead/unread by any code (`.env.example:42-45`, `docs/DEPLOYMENT.md:62`); real-IP trust is hardcoded in `Caddyfile.cf`, see Finding 1 |
| Cloudflare API token | **not present anywhere in this repo** | N/A — `cf_*` modes only use Cloudflare as a reverse proxy/CDN in front of Caddy; no Cloudflare API integration exists in slimTDS (grepped `CLOUDFLARE`/`CF_API`/`CF_TOKEN`, zero hits) |

README.md:35/41-42 and DEPLOYMENT.md:27 claim random generation of "APP_SECRET + ADMIN_PASSWORD"
specifically (README) — accurate. DEPLOYMENT.md's shorter "generates .env with random secrets"
phrasing is the one that overstates scope (doesn't call out `DB_PASSWORD` staying static); see
Finding 2.

`.env` itself is git-ignored (`.gitignore:11`) and not present/committed in this working tree —
checked, ok.

---

## 6. `docker/entrypoint.sh` — Caddyfile selection and privilege check

`docker/entrypoint.sh:19-25` selects the Caddyfile via a literal `case "$MODE" in dev|cf_flex|
cf_full|demo|direct)` match — `$MODE` comes only from `DEPLOY_MODE` in `.env`, which is
operator-controlled (not derived from any request-time or attacker-influenced input), and the
value is never interpolated into a shell command (only used as a `case` label and to pick a
constant `$CFG` path) — no injection surface. The `sed -i`/classmap-wipe block (lines 13-18) only
fires when `MODE=dev` and only touches a fixed vendor-autoload path — not attacker-influenced.
Unknown modes exit(2) rather than falling through to something unsafe. Checked, ok.

**Elevated privileges / root:** `docker/Dockerfile` has **no `USER` directive** anywhere in the
`runtime` stage (`docker/Dockerfile:27-77`). The image is built `FROM
dunglas/frankenphp:1.12.4-php8.4-alpine`, which itself does not switch to an unprivileged user
either — so both `entrypoint.sh` and the `frankenphp run` process it execs run **as root inside
the container**. `su-exec` is installed (`docker/Dockerfile:36`) but never invoked anywhere in
`entrypoint.sh` or the Dockerfile — it's a leftover/unused dependency, not a privilege-drop
mechanism actually wired up.

This is a real (if standard-for-the-ecosystem) gap: FrankenPHP/Caddy needs to bind ports 80/443
(<1024), which ordinarily requires root or `CAP_NET_BIND_SERVICE`; the current setup takes the
"just run as root" shortcut instead of granting the capability to a non-root user. Severity is
**medium** — this is defense-in-depth, not a directly-exploitable bug by itself, but it widens the
blast radius of any future RCE-class bug in the PHP app or a FrankenPHP/Caddy CVE (root-in-
container is one hop closer to a container-escape/host-compromise than a capped non-root user
would be), and any file the process writes (e.g. `var/backups/*.dump`, bind-mounted to the host at
`./var/backups`) is host-owned by root.

**Fix:** add a non-root `USER` in the runtime stage and grant `cap_net_bind_service` to the
`frankenphp` binary via `setcap 'cap_net_bind_service=+ep' $(which frankenphp)` in the Dockerfile
(standard pattern for this exact class of image), or bind to unprivileged ports internally and let
Docker's `ports:` host-mapping (`"80:8080"`, `"443:8443"`) do the privileged-bind at the host/
Docker-proxy layer instead of inside the container.

---

## 7. `config/frankenphp/Caddyfile.cf` — Cloudflare trust scope

`Caddyfile.cf:12` hardcodes Cloudflare's published IPv4 CIDR ranges into `trusted_proxies
static ...` + `client_ip_headers CF-Connecting-IP:13`. As documented in `docs/DEPLOYMENT.md:60`,
this list is **static and unmaintained by any automation** — no cron job, no build-time fetch of
`https://www.cloudflare.com/ips-v4`. Two related but distinct issues:

- As covered in Finding 1, this directive protects only **Caddy's own** client-IP notion, not what
  reaches PHP — so even a perfectly-maintained list doesn't close the spoofing gap by itself.
- Independent of Finding 1: if Cloudflare ever reallocates a CIDR block out of this hardcoded list
  to a different customer, and nobody updates `Caddyfile.cf`, traffic from that block would still
  be treated as "from Cloudflare" by Caddy's own resolution — low likelihood, but zero-effort
  mitigation is a documented periodic review (`docs/DEPLOYMENT.md` doesn't currently set an
  expectation for how often to refresh it). Also note **IPv6** Cloudflare ranges are absent from
  the list entirely (only IPv4 CIDRs are present) — if the origin has an AAAA record or otherwise
  accepts IPv6, this list provides no protection on that path at all.

Severity: **Low** (operational drift risk), separate from and secondary to Finding 1.

---

## 8. `Caddyfile.direct` — HTTP→HTTPS redirect and security headers

`config/frankenphp/Caddyfile.direct:12` (`{$DOMAIN} { ... }`) uses Caddy's automatic HTTPS with a
plain domain-name site address and no `auto_https off`/`http://` override — Caddy's documented
default behavior for this pattern is to auto-provision a Let's Encrypt cert **and** auto-redirect
plain `http://` requests to `https://` on the implicit `:80` listener. No explicit `redir` block is
needed or present, and none should be — this is standard/correct. **Checked, ok** (redirect is
implicit-but-real, not missing).

**Missing:** no security response headers are set anywhere — grepped the whole `config/` tree and
`src/` for `Strict-Transport-Security`/HSTS, `X-Content-Type-Options`, `X-Frame-Options`,
`Content-Security-Policy`; only hits are `Referrer-Policy: no-referrer` on the two outbound
redirect schemas (`src/Engine/Schema/HttpRedirectSchema.php:34`,
`src/Engine/Schema/DoubleMetaRefreshSchema.php:23` — deliberate, to keep the affiliate offer from
seeing the referring TDS URL, unrelated to this finding) and a `Access-Control-Allow-Origin: *` on
`/p.js` in all three Caddyfiles (intentional — pixel is meant to be embedded cross-domain per
README.md:51-55). No HSTS anywhere means a user who once visits over plain HTTP (e.g. a stale
bookmark, a link shared without the scheme) is not instructed by the browser to force HTTPS on
subsequent visits, leaving a narrow SSL-stripping/MITM window; no `X-Content-Type-Options: nosniff`
/`X-Frame-Options` on the admin UI is a minor hardening gap for a single-tenant internal tool but
still worth closing cheaply.

**Fix:** add to both `Caddyfile.direct` and `Caddyfile.cf` (for `cf_full`, where TLS reaches the
origin; HSTS is meaningless/counterproductive for `cf_flex`'s plain-HTTP origin leg — apply it
edge-side via Cloudflare instead in that mode):
```
header {
    Strict-Transport-Security "max-age=31536000; includeSubDomains"
    X-Content-Type-Options "nosniff"
    X-Frame-Options "DENY"
}
```
Severity: **Low** — real gap, low likelihood of practical exploitation for this app's threat model
(no third-party content embedding, single admin/small operator team), but a one-line, zero-risk
fix.

---

## 9. `src/Admin/Controller/BackupController.php` — `{name}` path-traversal check

**Routes:** `download($request, $response, string $name)` (`BackupController.php:69-93`) and
`delete($request, $response, string $name)` (`BackupController.php:98-114`).

Both handlers apply the same two-layer defense before touching the filesystem:
```php
$base = basename($name);
$path = self::BACKUPS_DIR . '/' . $base;
if (!preg_match('/^[A-Za-z0-9_\-]+\.dump$/', $base) || !is_file($path)) { ... 404/flash+redirect ... }
```
1. `basename($name)` strips any directory component — a value like
   `../../../../etc/passwd` reduces to `passwd`; `..%2f..%2fetc%2fpasswd` (URL-decoded by the
   router before reaching the handler) reduces to `passwd` as well. There is no way to get a `/`
   or leading `..` segment past `basename()` on any platform Docker runs this on (Linux
   container — no `\`-as-separator ambiguity to worry about either).
2. The regex whitelist `^[A-Za-z0-9_\-]+\.dump$` is a second, independent gate: even a
   theoretical `basename()` bypass would additionally have to produce only alphanumerics/`_`/`-`
   plus a literal `.dump` suffix — no `.`, `..`, `/`, null bytes, or any other traversal
   metacharacter is in the allowed set.
3. `is_file($path)` (post-whitelist) additionally requires the resolved path to exist as a
   regular file inside `BACKUPS_DIR`, so even a same-name collision elsewhere can't be served.

No path-traversal or IDOR-via-filename is possible here. Both routes also sit behind the shared
`/admin` middleware stack (Auth+Csrf+RateLimit — verified by the calling agent, out of scope for
this pass) for authorization. **Checked, ok — no finding.**

(Note, unrelated to `{name}`: `create()` at `BackupController.php:37-43` passes `PGPASSWORD` via
the `Process` env array rather than argv — correct, avoids leaking the DB password via `ps`/
`/proc/*/cmdline` on the host or in other containers sharing the PID namespace.)

---

## 10. `docker/supercronic/crontab` — job review

All 13 scheduled jobs (`docker/supercronic/crontab:10-55`) invoke fixed console commands with
**no arguments derived from external/request-time input** — every command is a bare
`bin/console <name>` with no interpolated variables, no `$()`/backticks, no data read from a
user-writable location before being placed on a command line. Examples: `partitions:rotate`,
`bots:update`, `db:backup`, `postback:deliver`, `inbox:flush`, `rrweb:flush`, `demo:reset || true`.
Since none of these take shell-interpolated arguments, there is no injection surface here distinct
from whatever each command does internally to the data it reads from the DB (out of scope for this
pass — that's an application-logic concern per command, not a crontab-configuration one). The
`demo:reset || true` guard (line 55) is intentionally permissive so a non-demo host's expected
non-zero exit doesn't get logged as a cron failure — comment in the file explains this is bounded
by `DEMO_MODE` inside the command itself, not by anything in the crontab. **Checked, ok — no
finding.**

---

## Summary

| # | Finding | Severity |
|---|---|---|
| 1 | `RealIp.php` trusts client-supplied IP headers with no server-side hop verification — defeats bot detection, per-IP rate limiting, geo-targeting; trivial to exploit in `direct` mode, exploitable in `cf_*` mode if origin IP leaks | **Critical** |
| 2 | `DB_PASSWORD` weak default (`slimtds`) never rotated by `make env` | Low/Medium |
| 6 | Container runs as root — no `USER` directive, `su-exec` installed but unused | Medium |
| 7 | Hardcoded Cloudflare IPv4 CIDR list in `Caddyfile.cf` has no refresh mechanism; IPv6 ranges absent entirely | Low |
| 8 | No HSTS / `X-Content-Type-Options` / `X-Frame-Options` response headers in `direct` or `cf_full` mode | Low |
| 3 | Postgres port not published in any prod compose file | Checked, ok |
| 4 | No hardcoded secrets in any `docker-compose*.yml` `environment:` block | Checked, ok |
| 5 | `.env.example` secrets inventory / `make env` scope matches docs except `DB_PASSWORD` (→ Finding 2); no Cloudflare API token exists in this codebase | Checked, ok / see Finding 2 |
| 6 (entrypoint) | `DEPLOY_MODE` Caddyfile selection has no injection surface | Checked, ok |
| 9 | `BackupController` `{name}` param: `basename()` + regex whitelist + `is_file()` — no path traversal | Checked, ok |
| 10 | `docker/supercronic/crontab` — all jobs are fixed commands, no unvalidated input | Checked, ok |
