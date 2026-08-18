# Feature Gap vs Keitaro

Scope: functional comparison only, no security review (see the other files in this directory for that). Read `StatsController`/`StatsRepository`, `ConversionController`/`ConversionRepository`, `OfferController`, `resources/views/admin/*` structure, `docs/AI-INSTALL.md`.

User's stated needs (SEO-affiliate, casino/betting niche): (1) uniques/leads/deps by offer and by country, (2) GEO-split offers, (3) swap offer links in a couple clicks, (4) cloak suspicious/non-target traffic to the official brand site, real traffic to the real affiliate link.

## Already closes the user's needs

| Feature | Status | Comment |
|---|---|---|
| Unique/total/bot clicks over time | Yes | `StatsRepository::clicksTimeline`/`summary`, hourly matview, filterable by campaign + 24h/7d/30d window. |
| Leads/deps (conversions) tracking | Yes | `core.conversions` via postback, `status` field (approved/pending/hold/rejected), `ConversionController` log + `statusBreakdown()`. |
| GEO-split offers | Yes | Flow `filters` on `country` (in-list op) + weighted `OfferPicker` — exactly Keitaro's "stream" concept. |
| Swap an offer's link | Yes | `OfferController::update` — one form, offers are global (not per-campaign duplicated), takes effect on the next click, no redeploy. |
| Cloaking (bot/suspicious → safe page, real → offer) | Yes (architecturally) | `is_bot` is an ordinary flow filter field; `BotDetector` already covers datacenter/VPN/ASN/UA. Not pre-wired as "→ official site" out of the box (seed only shows `is_bot=1 → blank 200`), but this is a 2-click flow config, not missing code. **Caveat:** the CRITICAL IP-spoofing finding in `deployment.md`/`deploy-docker.md` currently lets anyone bypass this by forging `CF-Connecting-IP` — fix that first or the cloak is decorative. |
| Per-click detail (country, offer, device, referer, is_bot, lander) | Yes | `/admin/clicks` raw log has all these as columns (customizable via `ColumnPreferences`), just not pre-aggregated (see gap below). |
| Weighted A/B split within a GEO | Yes | `OfferPicker` weighted random over `target_offers`. |
| Bot filtering | Yes, decent | Multi-stage: explicit IP list, CIDR datacenter/VPN (~50k ranges), ASN hosting list, UA regex. Weaker than Keitaro's JS-fingerprint-based detection but same category as most self-hosted TDS. |
| S2S postback (incoming) | Yes | Per-offer + campaign catch-all tokens, idempotent UPSERT. |
| Outgoing postback to upstream network | Yes | Decoupled outbox + retry worker. |

## Real gap vs Keitaro, but not something this user needs

| Feature | Status | Comment |
|---|---|---|
| Cost/spend tracking, ROI per traffic source | No | No `cost`/`spend` field anywhere in campaigns/offers/clicks. Matters for buyers of paid traffic (Keitaro's core use case); irrelevant for organic SEO traffic with no per-click cost. |
| Landing page rotation/hosting (Keitaro's built-in lander CMS) | No | Not needed — landers are the user's own combo-built SEO sites, outside the tracker's scope by design (that's what `X-Lander-Host` integration is for). |
| Multi-tenant / sub-accounts / white-label | No | Single-operator tool by design (CLAUDE.md: "self-hosted, single-tenant, internal tool"). Not needed for a solo/small-team affiliate operation. |
| Public API for external BI tools | Not checked in depth, likely thin/absent | Keitaro has a documented REST API; slimTDS's automation surface is the admin UI only. Only matters if the user wants to pull stats into an external dashboard — not stated as a need. |
| Smartlink / auto-CR-optimization (auto-favor the better-converting offer) | No | Flagged as a "Phase 3" idea in old project memory, not built. Traffic volume at this user's scale likely doesn't need it yet. |

## Real gap, probably worth closing for this SEO-affiliate use case

| Feature | Status | Comment |
|---|---|---|
| **Aggregated breakdown by offer × country (or any two dimensions at once)** | **No** | This is the literal ask ("сколько лидов/депов с каких офферов, с каких стран") and it's the biggest real gap. `StatsController`/`StatsRepository` only aggregate by campaign_id + time window (no offer/country grouping). `ConversionRepository::statusBreakdown` only groups by status, filtered by campaign/status/since — no `GROUP BY offer_id, country`. Today the only way to get "deps by offer by country" is to eyeball/export the raw `/admin/clicks` and `/admin/conversions` logs and manually cross-reference — exactly the kind of drill-down Keitaro's stats grid does natively with clickable dimension pivots. **Recommended fix:** add a `GROUP BY offer_id, country` (and similar) query to `ConversionRepository`/`StatsRepository` and a pivot-style view, reusing the existing `stats.clicks_hourly`/`core.conversions_hourly` matviews (they likely already carry offer_id/country columns to aggregate over — worth checking during implementation). |
| Per-offer / per-flow conversion rate (CR) and EPC | Partial | `summary()` computes CR/EPC only campaign-wide, not per-offer. Same underlying gap as above — once offer/country grouping exists, CR/EPC should be computed per group too. |
| A pre-built "cloak to official site" flow template/wizard | No | Since this is the user's #1 differentiator ask, worth a small UX addition: a one-click "add cloak flow" button on campaign creation that pre-fills `is_bot=1 OR referer_domain not_in <lander list> → 301 → official URL`, rather than requiring manual flow authoring every time. Not a missing capability, just missing convenience — low effort, high value given how central this is to the user's model. |

## Bottom line

Functionally, slimTDS already covers everything the user listed except **multi-dimension (offer × country) aggregate reporting**, which is a real, worth-fixing gap — not a missing subsystem, just a couple of `GROUP BY` queries and a view away. The cloaking mechanism the user cares about most is architecturally present and reasonably sophisticated (better bot/datacenter detection than a naive UA-only cloak), but its practical strength right now is undermined by the IP-spoofing finding from the security audit — that's the one item that should be fixed before leaning on cloaking in production.
