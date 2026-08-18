# Cloaking: suspicious/non-target traffic → official site, real visitors → offer

No new code is needed for this — the engine already supports it end to end
(`is_bot` filter field + a flow that redirects to a static URL instead of an
offer). This is 2 minutes of admin UI configuration per campaign. This doc
is the recipe.

## How it works

A campaign's flows are evaluated top to bottom (`position` order, drag to
reorder in the admin UI); the first one whose filter matches the visitor
wins. Put the cloak flow **above** your real-offer flow(s):

1. **Cloak flow** (position 0): filter `is_bot = 1` → target `none` →
   schema `HTTP Redirect (302)` → `schema_config.url` = the official
   brand's own site (or any safe landing page).
2. **Real-offer flow(s)** (position 1+): your existing GEO/device-split
   flows targeting the actual offer(s), unchanged.

`is_bot` is set by `BotDetector` (`src/Engine/BotDetector.php`) and is
already a multi-signal check, not just UA sniffing:

- Explicit IP list (`core.bot_ips` — known search-engine crawlers)
- Datacenter/VPN CIDR ranges (`core.bot_cidrs` — ~50k ranges, refreshed by
  the `bots:update` cron) — this is the one that matters most for cloaking:
  a compliance reviewer, competitor, or ad-network auditor almost always
  connects from a datacenter or VPN IP, not a residential one
- Hosting-provider ASNs (`core.bot_asns`)
- ISP-name regex for the major search engines
- User-Agent regex (bot/crawler/spider/scraper/curl/wget/python-requests/…)

Any one of these sets `is_bot = true` — so a single `is_bot = 1` filter
condition already covers "suspicious", not just "obviously a bot".

## Admin UI steps

1. `/admin/campaigns/{id}/flows` → **New flow**.
2. Filters: field `is_bot`, operator `=`, value `1`.
3. Target type: **None**.
4. Schema: **HTTP Redirect** (301 or 302).
5. Schema config → `url`: the official site, e.g.
   `https://officialbrand.com/`.
6. Save, then drag it to the **top** of the flow list (position 0) — flows
   are matched in order and the first match wins, so the cloak check must
   run before the real-offer flows.

## Optional: also gate on referer, not just is_bot

If you want organic/direct hits (no referer, or a referer that isn't one of
your own lander domains) to also get cloaked — not just detected bots — add
a second filter group to the same flow (groups are OR'd, conditions within
a group are AND'd):

- Group 1: `is_bot = 1` (bots/datacenter/VPN)
- Group 2: `referer_domain not_in <your lander domains>` (direct/unknown
  traffic) — use with care, this also catches legitimate direct typers of
  the campaign URL.

## Verifying it

After saving, hit the campaign slug with a UA that matches a bot pattern
(e.g. `curl -A "curl/8.0" https://yourdomain/<slug>`) — should 302 to the
official site. Hit it normally from a browser — should reach the real
offer. Check `/admin/clicks` — the bot hit should show `is_bot = true` and
`out_url` = the official site.
