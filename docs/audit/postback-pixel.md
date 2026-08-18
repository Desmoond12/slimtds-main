# Security Audit — Postback & Pixel

Scope: `src/Postback/PostbackController.php`, `src/Postback/PostbackOutbox.php` (the outgoing
delivery worker — invoked by `src/Cron/Command/PostbackDeliverCommand.php` on the `postback:deliver`
cron, every minute per `docker/supercronic/crontab:40`; there is no separate `OutgoingDeliveryWorker`
class in this codebase — `CLAUDE.md`'s reference is to `PostbackOutbox`), `src/Pixel/EventController.php`,
`src/Pixel/RecordController.php`, `src/Pixel/ScriptController.php`, `src/Shared/Http/PixelCors.php`,
token generation in `src/Admin/Repository/OfferRepository.php` and the campaign postback-token migration,
`src/Engine/MacroExpander.php`, `config/routes.php`.

Authorized self-review of the product owner's own instance. No code was modified.

## Findings

### 1. [MEDIUM] No rate limiting on `/postback`, `/p/event`, `/p/rec`
**Files:** `config/routes.php:31-40`; `src/Admin/Middleware/RateLimitMiddleware.php`

`RateLimiter`/`RateLimitMiddleware` exist and are generic (IP/login/cookie-keyed, backed by
`core.rate_limits`), but are wired only to `POST /admin/login` (`config/routes.php:64`). The three
public ingestion routes carry no middleware at all — confirmed by reading the route table: `/postback`
(`:39-40`), `/p/event` (`:31-32`), `/p/rec` (`:35-36`) attach no `->add(...)`.

**Exploitation:**
- Campaign `slug` is not a secret (shipped in every lander's `p.js` snippet). Anyone can script:
  ```bash
  while true; do
    curl -s -X POST https://target/p/event -H 'Content-Type: application/json' \
      -d '{"c":"<slug>","event":"pageview"}' &
  done
  ```
  Each hit is one row into `stats.pixel_events_inbox` (and after `inbox:flush` drains it, one row in
  the partitioned `stats.pixel_events`) — cheap DB/disk amplification, and it drowns real analytics in
  noise. Same pattern applies to `/p/rec` against `stats.rrweb_inbox`.
- `/postback` with an invalid/unknown token still costs a `findByToken` + `findByPostbackToken` SELECT
  per request before the 404 — cheap per-request but unbounded in aggregate.
- Both `/p/event` and `/p/rec` reply 200/204 even for garbage/unknown-slug input (`RecordController.php:61-63`
  intentionally hides which slugs exist), so there's no cheap oracle being protected — the actual cost is
  pure resource exhaustion, not information disclosure.

**Fix:** Reuse `RateLimiter::hit()` (already generic) IP-keyed on all three routes with a generous window
(e.g. 120–300/min) — low-risk change, primitive already exists and is tested.

### 2. [MEDIUM/HIGH] Repeated identical postback re-fires outbound S2S delivery and notifications — "idempotent" claim only covers the DB row, not side effects
**File:** `src/Postback/PostbackController.php:185-231`

```php
$existing = (int)$this->db->fetchScalar(
    'SELECT count(*) FROM core.conversions WHERE click_id = :cid', ['cid' => $subid],
);
$updated = $existing > 0;

$convRow = $this->db->fetchOne(<<<'SQL'
    INSERT INTO core.conversions (...)
    VALUES (...)
    ON CONFLICT (click_id) DO UPDATE SET payout = EXCLUDED.payout, ...
    RETURNING id
SQL, [...]);
$conversionId = $convRow !== null ? (string)$convRow['id'] : null;

// Enqueue outgoing S2S postbacks (no-ops if offer has no postback_urls)
if ($conversionId !== null) {
    $this->outbox->enqueue($conversionId, $offer->id, [...]);   // <-- unconditional, every call
}
```

The `ON CONFLICT (click_id)` upsert genuinely makes the **`core.conversions` row** idempotent (confirmed
by `tests/Integration/Postback/PostbackControllerTest.php:92-110`, which asserts `updated=true` and
row-count stays 1 on a second identical call — but that test never asserts anything about the outbox).
`$updated` is computed but never used to guard `outbox->enqueue()`. Every single call —
including an exact repeat of the same `subid`+`status` — inserts a **new row per postback URL** into
`core.postback_deliveries` (`PostbackOutbox.php:41-63`, no dedup check), which `PostbackDeliverCommand`
then fires as a real outbound HTTP GET to the offer's configured `postback_urls`. The Telegram
"conversion" notification (`PostbackController.php:233-291`) fires unconditionally too.

**This directly answers "is there another way to double-count payout despite the idempotent upsert":**
yes — not in slimTDS's own `conversions` table, but in whatever downstream system consumes the offer's
outbound postback URL. If that system credits/pays on each received hit (common for simple S2S
integrations that don't dedup by `click_id`/`external_id` themselves), an attacker (or a flaky/duplicate
partner callback, or someone re-clicking a test link) can trigger N outbound deliveries for one
conversion just by repeating the same request:
```bash
for i in 1 2 3 4 5; do
  curl -s "https://target/postback?token=<valid>&subid=<click_id>&status=approved&payout=100"
done
```
Combined with finding #1 (no rate limit), this is cheap to do at volume, and each repeat also creates
new `postback_deliveries` rows (unbounded growth) and re-sends the Telegram alert (notification spam).
Varying `status` across calls (`pending`→`approved`→`pending`→`approved`, all valid) achieves the same
thing while looking like legitimate status transitions.

**Fix:** Only call `$this->outbox->enqueue(...)` (and the Telegram notify) when `$updated === false`,
or — since legitimate status *changes* should still notify downstream — compare old vs. new `status`/`payout`
and only enqueue when they actually changed, not on a byte-identical repeat. At minimum, add a dedup guard
in `PostbackOutbox::enqueue()` (e.g. skip if an undelivered/delivered row already exists for this
`conversion_id` + `target_url` within a short window).

### 3. [MEDIUM] Unescaped `external_id` (attacker-controlled) spliced raw into the outbound postback URL — HTTP parameter injection against the partner endpoint
**Files:** `src/Postback/PostbackOutbox.php:163-179` (`expand`), `src/Engine/MacroExpander.php:9-49`

```php
$direct = [
    '{payout}'      => (string)($vars['payout']      ?? ''),
    '{status}'      => (string)($vars['status']      ?? ''),
    '{external_id}' => (string)($vars['external_id'] ?? ''),   // <-- raw, no urlencode
    '{currency}'    => (string)($vars['currency']    ?? ''),
];
$template = strtr($template, $direct);
```

`external_id` comes straight from the incoming `/postback` request (`$params['external_id']`, trimmed
but otherwise unvalidated — no length cap, no charset restriction — `PostbackController.php:58`). Neither
this `strtr` nor `MacroExpander::expand()` (also plain string substitution, no `urlencode`/`rawurlencode`
anywhere in either) escapes the value before it is spliced into a URL template that
`PostbackOutbox::fireOne()` then hands straight to `curl_setopt(CURLOPT_URL, ...)`.

`payout` is constrained (`is_numeric()` gate in the controller) and `status` is whitelist-validated, so
those two are effectively safe even though they go through the same unescaped path. `external_id` is not
constrained at all.

**Exploitation:** given a valid token + `subid` (same prerequisite as finding #4 below — this is not a new
auth bypass, it's what an authorized caller can additionally do), an attacker who controls `external_id`
can inject extra query parameters into the *offer's own configured* partner URL, e.g. if the operator's
template is `https://partner.example/pb?subid={click_id}&payout={payout}&status={status}&external_id={external_id}`:
```bash
curl -s "https://target/postback?token=<valid>&subid=<click_id>&status=approved&payout=10&external_id=abc%26payout%3D99999%26status%3Dapproved"
```
(URL-decoded: `external_id=abc&payout=99999&status=approved`) — most HTTP servers take the *last*
occurrence of a duplicate query key, so this can override the operator-intended `payout`/`status` actually
delivered to the partner, independent of what was stored in slimTDS's own DB. The attack is confined to
the query string/path of the **host the admin already configured** (not full SSRF to an arbitrary host —
the scheme+host portion of the template is fixed by the admin), but it is real parameter pollution against
a third-party endpoint that the admin trusted the template to control precisely.

**Fix:** `rawurlencode()` `external_id` (and, for defense in depth, `payout`/`status`/`currency` too) before
substitution in `PostbackOutbox::expand()`. Same treatment is worth applying inside `MacroExpander::resolve()`
for any macro whose source value isn't already charset-constrained (`referer`, `ua`, `lander_*`, `utm_*`).

### 4. [LOW] Outbound postback delivery disables TLS certificate verification unconditionally
**File:** `src/Postback/PostbackOutbox.php:101-113`

```php
CURLOPT_SSL_VERIFYPEER => false, // tolerate self-signed certs on partner networks
```

This is set globally for every outgoing postback HTTP request, not scoped to specific offers that are
known to run self-signed certs. An on-path attacker between the slimTDS host and *any* partner endpoint
(including ones with perfectly valid public certs) can MITM the request, silently swallow it, or return a
spoofed `2xx`/`3xx` — `fireOne()` would then mark the delivery `delivered_at = now()` (`PostbackOutbox.php:123-134`)
even though the real partner never received it, causing silent loss of a conversion notification with no
retry (the row is already marked delivered). Impact is availability/integrity of the delivery itself
(the payload — `click_id`, `payout`, `status`, `external_id` — isn't itself highly sensitive), not data
exfiltration of secrets.

**Fix:** Make this per-offer (a checkbox in offer settings, default verify-on) rather than a blanket
`false`, or verify via CA bundle and let operators explicitly opt a specific offer out.

### 5. [OK] Token generation and lookup — with one asymmetry worth noting
**Files:** `src/Admin/Repository/OfferRepository.php:19-22,128-139`; `src/Admin/Repository/CampaignRepository.php:29-32`; `migrations/20260424000002_init_core_entities.php:59`; `migrations/20260427000001_campaign_postback_token.php`

- **Offer tokens:** `encode(gen_random_bytes(16), 'hex')` — 128 bits, fully CSPRNG. Brute force over the
  network is infeasible. **OK.**
- **Campaign catch-all tokens:** `replace(uuidv7()::text, '-', '')` (PostgreSQL 18 native `uuidv7()`).
  UUIDv7 layout is 48 bits Unix-ms timestamp + 4 bits version + 12 bits `rand_a` + 2 bits variant + 62 bits
  `rand_b` — only ~74 of the 128 bits are actually random; the top 48 bits are a **public, structured,
  monotonically-increasing timestamp** (the campaign's creation time). This is weaker *in construction*
  than the offer token, and the campaign token is the higher-blast-radius one (it authorizes conversions
  for *any offer in the campaign* via the anonymous-ping / click-attributed paths in
  `PostbackController.php:89-146`). ~74 bits of true entropy is still computationally infeasible to
  brute-force remotely (network round-trips dominate any conceivable attempt), so this is **not
  practically exploitable today** — flagging as a design inconsistency, not a live vulnerability. Worth
  switching to the same `gen_random_bytes(16)` hex scheme as offers for consistency and to remove the
  timestamp side-channel (which shrinks if the attacker can narrow the campaign's creation window from
  other information, e.g. WHOIS/first-seen-traffic dates).
- Lookup in both cases is a plain `WHERE token = :t` (no `hash_equals`), but comparison happens inside
  Postgres, not in PHP string comparison — with 128 (or ~74) bits of entropy and normal network/DB jitter,
  a remote timing attack to recover the token byte-by-byte is not practical. **OK, no action needed.**

### 6. [LOW] Reflected-Origin CORS with credentials on pixel endpoints — and it doesn't actually achieve its stated purpose
**File:** `src/Shared/Http/PixelCors.php:16-30`; `src/Engine/VisitorResolver.php:70-83`

`Access-Control-Allow-Origin` echoes whatever `Origin` the caller sends (falls back to `*` only when no
`Origin` header is present) combined with `Access-Control-Allow-Credentials: true`. This is intentional
and documented ("permissive CORS so any external lander can report events" — README/CLAUDE.md), and the
only cookie in play is the `vu` visitor-tracking cookie (`VisitorResolver::attachCookie`) — the admin
session cookie is scoped to `/admin` and middleware-gated separately, never touching these routes.

One nuance worth recording: `vu` is set with `SameSite=Lax` (`VisitorResolver.php:76`). Browsers do **not**
attach `SameSite=Lax` cookies on cross-site `fetch`/XHR (only on top-level navigations with safe methods),
so the `Allow-Credentials: true` + reflected-Origin combination does not actually enable the
cross-domain-cookie-stitching this CORS config appears designed for — a cross-origin `fetch(..., {credentials:'include'})`
from a lander to `/p/event` will simply not send `vu`, and the server will treat it as a new visitor each
time. Net effect: the permissive CORS config is currently *lower*-risk than its own design intent suggests
(no working cross-site credential leakage to exploit), but it's also not delivering the cross-domain
identity stitching that seems to be the point. Not a vulnerability — a functional/documentation mismatch
worth a comment so a future pass doesn't "fix" the CORS half without realizing the cookie half already
neuters it, or accidentally "fix" it by loosening `SameSite`, which *would* then make the credentialed
wildcard-reflection meaningfully riskier.

**Recommendation:** No urgent code change. If cross-domain visitor stitching via cookie is actually wanted,
it requires `SameSite=None; Secure` on `vu`, at which point the reflected-Origin+credentials CORS config
becomes load-bearing and should be tightened to an allow-list of configured lander domains rather than
reflecting any Origin.

### 7. [MEDIUM] Forged conversions via leaked token + click_id (inherent to the S2S postback trust model, but two things widen it)
**File:** `src/Postback/PostbackController.php:150-231`

Given a valid token (offer-scoped or campaign catch-all) *and* a `click_id`, anyone can POST
`status=approved&payout=<any float>` and it upserts `core.conversions` and enqueues real outbound S2S
postbacks (see finding #2 for how that compounds). This is by design — token = authorization is the same
trust model Keitaro/Binom use — but:
- `click_id` (UUIDv7 — time-ordered, ~74 bits random) is exposed anywhere a click is referenced:
  `/admin/clicks` log, the `{click_id}` macro embedded in outbound offer redirect URLs (visible to the
  offer/advertiser itself, browser history, referrer leakage, proxy/CDN logs on the advertiser's side).
  Anyone who legitimately sees a `click_id` and already has (or brute-forces — impractical) a token can
  forge a conversion for a click that never converted.
- No validation that `payout` is bounded to the offer's `payout_default` or any configured max — a forged
  request can set an arbitrary payout, which flows into the stats dashboard and, per finding #2/#3, into
  whatever downstream system consumes the outbound postback.

**Fix (optional, weigh against operational simplicity):**
- Rate-limit `/postback` per-token in addition to per-IP (finding #1), to blunt automated forgery.
- Optionally flag/cap `payout` values that deviate significantly from `offer.payout_default` for manual
  review instead of silently upserting.
- Inherent to the pattern, not slimTDS-specific — token leakage = ability to forge conversions for known
  click_ids in any TDS with this model.

### 8. [LOW] Unknown `status` silently coerced to `'approved'`
**File:** `src/Postback/PostbackController.php:81-84`

```php
if (!in_array($status, self::VALID_STATUSES, true)) {
    $status = 'approved';
}
```

A typo'd or garbage `status` value from a partner is silently recorded as `'approved'` — the *most*
consequential value — instead of being rejected with 400. This can mask integration bugs on the partner
side (a broken `status={statusTypo}` macro would silently look like a real approval) and, combined with
finding #2/#3, means a malformed request still successfully triggers a real outbound postback.

**Fix:** Reject unknown `status` with 400 rather than defaulting to `approved`. If backward-compat with
existing partner configs is a concern, default to the *least* consequential status (`pending`) instead.

### 9. [OK] `/p/event` has no explicit payload-size cap — informational, not currently exploitable in isolation
**File:** `src/Pixel/EventController.php` (whole file); compare `src/Pixel/RecordController.php:30,45-47`

`RecordController` explicitly caps a single rrweb chunk at 2 MiB and drops oversized bodies
(`RecordController.php:30,45-47`). `EventController` has no equivalent check — `props` (an arbitrary
client-supplied object, `EventController.php:123`) and the JSON body as a whole are unbounded by
application code. No `client_max_body_size`/`request_body max_size` equivalent is configured in any of
the three Caddyfiles (`config/frankenphp/Caddyfile.{dev,cf,direct}` — checked, no such directive present),
so the only cap in effect is whatever FrankenPHP/PHP defaults impose (not verified in this pass — would
need a running instance to confirm `post_max_size`/worker-mode body handling). This is the same
resource-exhaustion class as finding #1 (an attacker can send fewer, larger requests instead of many small
ones) rather than a distinct vulnerability, but worth closing for consistency with `RecordController`.

**Fix:** Add the same `MAX_BYTES` early-return pattern to `EventController` (e.g. 64–256 KiB is generous
for a pageview+props payload), and/or add a Caddy `request_body { max_size ... }` directive as a
belt-and-suspenders cap ahead of the app.

### 10. [OK] Input handling / injection
- `subid`, `token`, `status`, `payout`, `external_id` are all parameterized in SQL (no string
  concatenation anywhere in `PostbackController`/`EventController`/`RecordController`) — no SQLi.
- `payout` is `is_numeric()`-gated before being cast to float/string — safe for the DB write; only unsafe
  in the *outbound URL* context (finding #3).
- rrweb/pixel event payloads are stored as opaque `::jsonb` (parameterized) and processed later by an
  async worker (`inbox:flush`/`rrweb:flush`) — no injection surface in the ingestion path itself.
- `RecordController` correctly returns 204 (not 400/404) for an unknown campaign slug specifically to
  avoid leaking which slugs exist (`RecordController.php:61-63`) — good practice, consistent with the
  slug-not-secret model elsewhere.

### 11. [OK] `ScriptController` (`GET /p.js`)
**File:** `src/Pixel/ScriptController.php`

Serves a static file plus one small settings-derived JSON prefix (`rrweb_sample_rate`, an admin-controlled
int). ETag/If-None-Match handled correctly, 503 on missing file, wildcard CORS is appropriate here (it's a
public, non-parameterized script, same as any public JS asset). No user input is reflected. **No issues.**

### 12. [OK] Outgoing delivery worker — retry logic, DoS-loop, and volume cap
**Files:** `src/Postback/PostbackOutbox.php:70-153`, `src/Cron/Command/PostbackDeliverCommand.php`, `docker/supercronic/crontab:40`

- Runs via cron (`postback:deliver`, every 1 minute per crontab — `CLAUDE.md`'s "every 2 min" is stale
  documentation, not a security issue), pulling up to `tick(50)` pending rows per invocation
  (`PostbackDeliverCommand.php:23`) — bounded batch, no unbounded loop.
- Exponential backoff schedule `[60, 300, 1500, 7200, 36000]` seconds, hard cap `MAX_ATTEMPTS = 5`
  (`PostbackOutbox.php:24-27`) — a permanently-failing target stops being retried after ~13.5h, not an
  infinite retry loop.
- `CURLOPT_TIMEOUT => 10`, `CURLOPT_CONNECTTIMEOUT => 5`, `CURLOPT_MAXREDIRS => 5` — bounds worst-case
  time-per-request; a slow/hanging partner endpoint can't stall the batch indefinitely (50 rows ×
  ≤10s worst case ≈ 500s ceiling per tick, acceptable for a 1-minute-scheduled cron whose invocations
  don't overlap-protect explicitly but Symfony Console + supercronic serialize by cron slot in practice).
- Target URLs come from admin-configured `offer.postback_urls` — not attacker-reachable directly, so no
  attacker-controlled destination/SSRF via the URL *host*. (The *content* injected into that URL is
  attacker-influenced — see finding #3 — but the host is fixed by the admin.)
- No cross-request volume cap beyond the batch size and the *inherent* cap from finding #2's fix being
  absent (i.e., today an attacker can inflate the row count feeding this worker — that's finding #2, not a
  flaw in the worker's own retry/DoS posture).

**Conclusion:** the worker itself is well-bounded (no DoS loop, sane batch/timeout/retry limits). The TLS
verification gap (finding #4) and the row-volume issue (finding #2, upstream of this worker) are the real
concerns, not the retry mechanics.

## Summary of action items (by priority)

1. **MEDIUM** — Guard `PostbackController`'s `outbox->enqueue()` (and Telegram notify) so a repeat/identical
   postback doesn't re-fire outbound S2S deliveries — finding #2.
2. **MEDIUM** — `rawurlencode()` `external_id`/`payout`/`status`/`currency` before substitution into
   outbound postback URL templates — finding #3.
3. **MEDIUM** — Add IP-based rate limiting (reuse existing `RateLimiter`) to `/postback`, `/p/event`,
   `/p/rec` — finding #1.
4. **LOW** — Scope `CURLOPT_SSL_VERIFYPEER => false` to specific opted-in offers instead of all outgoing
   postbacks — finding #4.
5. **LOW** — Add a body-size cap to `EventController` matching `RecordController`'s pattern — finding #9.
6. **LOW** — Reject unknown `status` on `/postback` with 400 instead of silently coercing to `approved` —
   finding #8.
7. **LOW** — Switch campaign catch-all `postback_token` generation from `uuidv7()` to
   `gen_random_bytes(16)` hex for consistency with offer tokens — finding #5.
8. **Informational** — Document that permissive reflected-Origin pixel CORS is intentional, and note the
   `SameSite=Lax` interaction so a future pass doesn't "fix" one half without the other — finding #6.
9. **Optional** — Per-token rate limiting and/or payout sanity-bounding on `/postback` — finding #7.
