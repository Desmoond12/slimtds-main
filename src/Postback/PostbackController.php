<?php

declare(strict_types=1);

namespace App\Postback;

use App\Admin\Repository\AffiliateNetworkRepository;
use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\OfferRepository;
use App\Shared\Db\Connection;
use App\Shared\RealIp;
use App\Shared\Referer\SearchEngine;
use App\Shared\Telegram\TelegramNotifier;
use App\Admin\Repository\SettingsRepository;
use App\Shared\Notification\NotificationRegistry;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ramsey\Uuid\Uuid;

final class PostbackController
{
    private const VALID_STATUSES = ['approved', 'pending', 'hold', 'rejected'];

    public function __construct(
        private readonly OfferRepository $offers,
        private readonly CampaignRepository $campaigns,
        private readonly AffiliateNetworkRepository $networks,
        private readonly Connection $db,
        private readonly TelegramNotifier $tg,
        private readonly PostbackOutbox $outbox,
        private readonly SettingsRepository $settings,
        private readonly NotificationRegistry $notifications,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        // Accept both GET (query string) and POST (form-encoded or JSON body)
        // since some partners default to POST. Query takes precedence; body
        // fills in keys that aren't in the query.
        $query = $request->getQueryParams();
        $body  = $request->getParsedBody();
        if (!is_array($body)) $body = [];
        // application/json bodies that PSR didn't auto-parse
        if ($body === [] && str_contains(strtolower($request->getHeaderLine('Content-Type')), 'application/json')) {
            $raw = (string)$request->getBody();
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $body = $decoded;
        }
        $params = $query + $body;

        $sourceIp = RealIp::from($request);
        $rawQuery = $request->getUri()->getQuery();
        if ($rawQuery === '' && $body !== []) {
            $rawQuery = http_build_query($body);
        }

        $subid      = isset($params['subid']) ? trim((string)$params['subid']) : '';
        $token      = isset($params['token']) ? trim((string)$params['token']) : '';
        $payoutRaw  = isset($params['payout']) ? $params['payout'] : '0';
        $status     = isset($params['status']) ? trim((string)$params['status']) : 'approved';
        $externalId = isset($params['external_id']) ? trim((string)$params['external_id']) : null;
        // Optional business-event discriminator (reg/ftd/redeposit/...) — free
        // text for now, no fixed taxonomy yet. Absent (the common case today)
        // defaults to 'conversion', which reproduces the pre-event_type
        // single-event-per-click behavior exactly.
        $eventType  = isset($params['event_type']) ? strtolower(trim((string)$params['event_type'])) : '';
        if ($eventType === '') $eventType = 'conversion';

        $method = $request->getMethod();

        // Token is mandatory; subid is required only for offer-tokens.
        // Campaign-tokens accept subid-less pings as anonymous campaign
        // counters (no specific click attribution — handled below).
        if ($token === '') {
            $this->logRequest($method, $rawQuery, $sourceIp, $subid, $token, $status, $payoutRaw, $externalId, null, null, 'MISSING_TOKEN', 400);
            return $this->json($response, [
                'ok'    => false,
                'error' => 'missing token',
                'debug' => [
                    'method'       => $request->getMethod(),
                    'content_type' => $request->getHeaderLine('Content-Type'),
                    'query_keys'   => array_keys($query),
                    'body_keys'    => array_keys($body),
                ],
            ], 400);
        }
        // Token resolution: try per-offer first, then campaign-level catch-all.
        // Offer-token: locks conversion to that exact offer (multiple partners
        // in one campaign — each must use their own URL).
        // Campaign-token: a single URL works for any offer inside the campaign;
        // we resolve the offer from the click row (where it was bound at click
        // time). Useful when one partner network owns the whole campaign.
        $offer = $this->offers->findByToken($token);
        $tokenScope = $offer !== null ? 'offer' : null;
        $campaignFromToken = null;
        if ($offer === null) {
            $campaignFromToken = $this->campaigns->findByPostbackToken($token);
            if ($campaignFromToken !== null) {
                $tokenScope = 'campaign';
            }
        }
        if ($tokenScope === null) {
            $this->logRequest($method, $rawQuery, $sourceIp, $subid, $token, $status, $payoutRaw, $externalId, null, null, 'UNKNOWN_TOKEN', 404);
            return $this->json($response, ['ok' => false, 'error' => 'unknown token'], 404);
        }

        // Per-network postback param remapping — see
        // migrations/20260811000001_affiliate_networks.php + AffiliateNetworkAdapter.
        // The offer/campaign this token resolved to may be linked to a
        // partner network that doesn't send subid/status/payout/external_id
        // /event_type under our default names. Only known now that we've
        // resolved token → offer/campaign; token itself is never remapped
        // (see plan: we choose that name, the operator embeds it literally
        // when building the postback URL, regardless of network).
        $networkId = $offer?->networkId ?? $campaignFromToken?->networkId;
        $network = $networkId !== null ? $this->networks->findById($networkId) : null;
        if ($network !== null && $network->isActive) {
            $extracted = AffiliateNetworkAdapter::extract($network, $params);
            $subid = $extracted['subid'];
            $externalId = $extracted['externalId'];
            $eventType = $extracted['eventType'];
            $payoutRaw = $extracted['payout'];
            if ($extracted['statusPresent']) {
                $translated = AffiliateNetworkAdapter::translateStatus($network, $extracted['status']);
                // null = configured status_map doesn't cover this raw value;
                // an empty status_map means "pass through unchanged" (the
                // network already sends our own words), which still must be
                // one of our four canonical statuses.
                if ($translated === null || !in_array($translated, self::VALID_STATUSES, true)) {
                    $this->logRequest($method, $rawQuery, $sourceIp, $subid, $token, $extracted['status'], $payoutRaw, $externalId, null, $offer?->id, 'INVALID_STATUS', 400);
                    return $this->json($response, ['ok' => false, 'error' => 'invalid status', 'valid' => self::VALID_STATUSES], 400);
                }
                $status = $translated;
            } else {
                $status = 'approved';
            }
        }

        // Subid placeholder ('{subId1}') leaked through partner test-buttons —
        // treat as empty so we fall into anonymous-ping mode for campaign-tokens.
        if ($subid !== '' && (str_starts_with($subid, '{') || str_ends_with($subid, '}'))) {
            $subid = '';
        }

        // Validate status (default-name path — network path already
        // translated/validated above). A missing status param defaults to
        // 'approved' (existing behavior for approved-only partner
        // integrations that never send one) — but an explicit, garbage
        // value (typo'd macro, integration bug) is rejected rather than
        // silently upgraded to the most consequential status, which would
        // otherwise mask the bug and still fire a real outbound S2S
        // postback for it.
        if ($network === null && isset($params['status']) && !in_array($status, self::VALID_STATUSES, true)) {
            $this->logRequest($method, $rawQuery, $sourceIp, $subid, $token, $status, $payoutRaw, $externalId, null, $offer?->id, 'INVALID_STATUS', 400);
            return $this->json($response, [
                'ok'    => false,
                'error' => 'invalid status',
                'valid' => self::VALID_STATUSES,
            ], 400);
        }

        // Validate and normalise payout. core.conversions.payout is
        // numeric(10,2) — a value whose absolute magnitude reaches 10^8
        // (partner typo, extra zeros, bad currency-conversion multiplier)
        // overflows that column and PDOException's the whole request (22003)
        // before any row is written. Clamp instead of trusting partner input.
        // Negative payout is deliberately still accepted: on a RevShare deal
        // (common in iGaming) a network's payout for a period reflects the
        // operator's net result on a player, which is legitimately negative
        // when the player won more than they lost ("negative carryover").
        // Rejecting negative values here would silently break real revenue
        // postbacks from exactly this project's target vertical.
        $payoutFloat = is_numeric($payoutRaw) ? (float)$payoutRaw : 0.0;
        if (!is_finite($payoutFloat) || abs($payoutFloat) >= 100_000_000.0) {
            $this->logRequest($method, $rawQuery, $sourceIp, $subid, $token, $status, $payoutRaw, $externalId, null, $offer?->id, 'INVALID_PAYOUT', 400);
            return $this->json($response, ['ok' => false, 'error' => 'invalid payout'], 400);
        }
        $payout = (string)$payoutFloat;

        // Anonymous campaign ping — campaign-token without subid. Records a
        // conversion row tied only to the campaign, no specific click/offer.
        // Lets operators count partner events even when the network's test
        // mode or pipeline doesn't propagate sub_id1.
        if ($tokenScope === 'campaign' && $subid === '') {
            $pingParams = [
                'campaign_id' => $campaignFromToken->id,
                'event_type'  => $eventType,
                'payout'      => $payout,
                'status'      => $status,
                'external_id' => $externalId !== '' ? $externalId : null,
                'source_ip'   => $sourceIp !== '' && $sourceIp !== '0.0.0.0' ? $sourceIp : null,
                'raw_query'   => $rawQuery !== '' ? $rawQuery : null,
            ];
            $this->db->execute(
                <<<'SQL'
                    INSERT INTO core.conversions
                        (click_id, campaign_id, offer_id, event_type, payout, status, external_id, currency, source_ip, raw_query)
                    VALUES
                        (NULL, :campaign_id, NULL, :event_type, :payout, :status, :external_id, 'USD', :source_ip, :raw_query)
                SQL,
                $pingParams,
            );
            // Every anon ping is already inherently a fresh, always-appended
            // row (no ON CONFLICT above) — mirror it straight into the ledger.
            $this->db->execute(
                <<<'SQL'
                    INSERT INTO core.conversion_events
                        (click_id, campaign_id, offer_id, event_type, payout, status, external_id, currency, source_ip, raw_query)
                    VALUES
                        (NULL, :campaign_id, NULL, :event_type, :payout, :status, :external_id, 'USD', :source_ip, :raw_query)
                SQL,
                $pingParams,
            );
            if ($this->tg->isConfigured() && $this->settings->getBool('notif_conv_ping_enabled', true)) {
                $appUrl = rtrim((string)($_ENV['APP_URL'] ?? 'https://slimtds.local'), '/');
                $this->tg->send($this->notifications->render(
                    NotificationRegistry::CONV_PING,
                    $this->settings->get('notif_conv_ping_template', ''),
                    [
                        'campaign'  => $campaignFromToken->name,
                        'slug'      => $campaignFromToken->slug,
                        'status'    => $status,
                        'player_id' => $externalId !== '' && $externalId !== null ? (string)$externalId : '',
                        'ip'        => $sourceIp !== '' ? $sourceIp : '—',
                        'payout'    => $payout,
                        'app_url'   => $appUrl,
                    ],
                ));
            }
            $this->logRequest($method, $rawQuery, $sourceIp, $subid, $token, $status, $payoutRaw, $externalId, null, null, 'CAMPAIGN_PING_OK', 200);
            return $this->json($response, ['ok' => true, 'mode' => 'campaign-ping']);
        }

        // From here on subid must be present (offer-token or campaign-token
        // with explicit click attribution).
        if ($subid === '') {
            $this->logRequest($method, $rawQuery, $sourceIp, $subid, $token, $status, $payoutRaw, $externalId, null, $offer?->id, 'MISSING_SUBID', 400);
            return $this->json($response, ['ok' => false, 'error' => 'missing subid'], 400);
        }
        // stats.clicks.id is a uuid column — a malformed subid (partner
        // truncation/mangling, a hand-typed test value) would otherwise hit
        // Postgres as an untyped parameter and throw a PDOException (22P02)
        // *before* the "click not found" 404 below ever gets a chance to
        // fire, crashing the whole request with a 500 instead of a clean
        // partner-facing error.
        if (!Uuid::isValid($subid)) {
            $this->logRequest($method, $rawQuery, $sourceIp, $subid, $token, $status, $payoutRaw, $externalId, null, $offer?->id, 'INVALID_SUBID', 400);
            return $this->json($response, ['ok' => false, 'error' => 'invalid subid'], 400);
        }

        // Lookup click by subid (need offer_id when token came from a campaign).
        // Also pulls lander/geo/device fields used to build a richer Telegram message.
        $clickRow = $this->db->fetchOne(
            'SELECT id, campaign_id, offer_id, visitor_uuid, lander_host, lander_button, country, device,
                    extract(epoch from (now() - created_at))::int AS age_sec
             FROM stats.clicks WHERE id = :id',
            ['id' => $subid],
        );
        if ($clickRow === null) {
            $this->logRequest($method, $rawQuery, $sourceIp, $subid, $token, $status, $payoutRaw, $externalId, null, $offer?->id, 'CLICK_NOT_FOUND', 404);
            return $this->json($response, ['ok' => false, 'error' => 'click not found'], 404);
        }

        $campaignId = (string)$clickRow['campaign_id'];

        // Campaign-token: pull offer from the click. Click without an offer
        // (trash-fallthrough) can't be attributed — there's no destination
        // offer to credit, so we 422.
        if ($tokenScope === 'campaign') {
            // Safety: token must match the campaign of the original click
            if ($campaignFromToken->id !== $campaignId) {
                $this->logRequest($method, $rawQuery, $sourceIp, $subid, $token, $status, $payoutRaw, $externalId, null, null, 'CAMPAIGN_MISMATCH', 409);
                return $this->json($response, ['ok' => false, 'error' => 'token/click campaign mismatch'], 409);
            }
            if (empty($clickRow['offer_id'])) {
                $this->logRequest($method, $rawQuery, $sourceIp, $subid, $token, $status, $payoutRaw, $externalId, null, null, 'TRASH_FALLTHROUGH', 422);
                return $this->json($response, ['ok' => false, 'error' => 'click has no offer (trash fallthrough)'], 422);
            }
            $offer = $this->offers->findById((string)$clickRow['offer_id']);
            if ($offer === null) {
                // offer_id intentionally left null here (not the click's dangling
                // offer_id) — core.postback_requests.offer_id is FK'd to
                // core.offers, and the whole point of this offer no longer
                // existing is that no row is there to reference.
                $this->logRequest($method, $rawQuery, $sourceIp, $subid, $token, $status, $payoutRaw, $externalId, null, null, 'OFFER_GONE', 410);
                return $this->json($response, ['ok' => false, 'error' => 'click offer no longer exists'], 410);
            }
        }

        // Check whether a conversion already exists for this click_id, and
        // at what status — a repeat postback with the *same* status is a
        // partner retry/duplicate, not a new event: outbound S2S postbacks
        // and Telegram notifications below only fire for a genuinely new
        // conversion or an actual status transition (e.g. pending→approved),
        // never for an identical resend (which would otherwise re-fire a
        // real payout postback to the partner network every time).
        //
        // The read (previousStatus) and the write (UPSERT below) must be
        // atomic together, or two concurrent identical postbacks for the
        // same brand-new click_id can both read previousStatus=null, both
        // decide isNewEvent=true, and both enqueue an outbound S2S postback
        // — confirmed live: 15 concurrent identical postbacks for a new
        // click produced 1 conversion row (UPSERT's own atomicity holds)
        // but 4 outbound deliveries. A plain `SELECT ... FOR UPDATE` can't
        // close this gap either — there's no row to lock yet on the very
        // first postback. pg_advisory_xact_lock keyed on click_id serializes
        // all concurrent postbacks for the same click through this section;
        // the lock is released automatically at transaction end either way.
        // Identity is now (click_id, event_type, external_id) — REG and FTD
        // on the same click are different identities and never collide;
        // repeat REDEP postbacks only collide if the partner reuses the same
        // external_id (or sends none at all — see migration comment, an
        // honest limitation when the partner gives us no differentiator).
        [$conversionId, $isNewEvent, $updated] = $this->db->transactional(function () use ($subid, $campaignId, $offer, $payout, $status, $externalId, $eventType, $sourceIp, $rawQuery) {
            $this->db->execute(
                'SELECT pg_advisory_xact_lock(hashtext(:key))',
                ['key' => 'conversion:' . $subid . ':' . $eventType . ':' . ($externalId ?? '')],
            );

            $previous = $this->db->fetchOne(
                'SELECT status, payout FROM core.conversions
                 WHERE click_id = :cid AND event_type = :et AND COALESCE(external_id, \'\') = :ext',
                ['cid' => $subid, 'et' => $eventType, 'ext' => $externalId ?? ''],
            );
            $updated = $previous !== null;
            // isNewEvent also compares payout (not just status) — a same-
            // status repostback with a different payout (e.g. a repeat
            // redeposit the partner couldn't give us an external_id for)
            // must still land in the append-only ledger, not be silently
            // treated as an identical-retry no-op.
            // numeric(10,2) round-trips through PDO as a fixed 2-decimal
            // string ("12.50"); $payout is a plain (string)(float) cast
            // ("12.5") — comparing them raw would false-positive as a "new"
            // event on every single repostback. Normalise both to the same
            // 2-decimal format before comparing.
            $isNewEvent = $previous === null
                || (string)$previous['status'] !== $status
                || number_format((float)$previous['payout'], 2, '.', '') !== number_format((float)$payout, 2, '.', '');

            $convRow = $this->db->fetchOne(
                <<<'SQL'
                    INSERT INTO core.conversions
                        (click_id, campaign_id, offer_id, event_type, payout, status, external_id, currency, source_ip, raw_query)
                    VALUES
                        (:click_id, :campaign_id, :offer_id, :event_type, :payout, :status, :external_id, 'USD', :source_ip, :raw_query)
                    ON CONFLICT (click_id, event_type, (COALESCE(external_id, '')))
                    DO UPDATE SET
                        payout      = EXCLUDED.payout,
                        status      = EXCLUDED.status,
                        external_id = EXCLUDED.external_id,
                        source_ip   = EXCLUDED.source_ip,
                        raw_query   = EXCLUDED.raw_query,
                        updated_at  = now()
                    RETURNING id
                SQL,
                [
                    'click_id'    => $subid,
                    'campaign_id' => $campaignId,
                    'offer_id'    => $offer->id,
                    'event_type'  => $eventType,
                    'payout'      => $payout,
                    'status'      => $status,
                    'external_id' => $externalId !== '' ? $externalId : null,
                    'source_ip'   => $sourceIp !== '' && $sourceIp !== '0.0.0.0' ? $sourceIp : null,
                    'raw_query'   => $rawQuery !== '' ? $rawQuery : null,
                ],
            );

            if ($isNewEvent) {
                $this->db->execute(
                    <<<'SQL'
                        INSERT INTO core.conversion_events
                            (click_id, campaign_id, offer_id, event_type, payout, status, external_id, currency, source_ip, raw_query)
                        VALUES
                            (:click_id, :campaign_id, :offer_id, :event_type, :payout, :status, :external_id, 'USD', :source_ip, :raw_query)
                    SQL,
                    [
                        'click_id'    => $subid,
                        'campaign_id' => $campaignId,
                        'offer_id'    => $offer->id,
                        'event_type'  => $eventType,
                        'payout'      => $payout,
                        'status'      => $status,
                        'external_id' => $externalId !== '' ? $externalId : null,
                        'source_ip'   => $sourceIp !== '' && $sourceIp !== '0.0.0.0' ? $sourceIp : null,
                        'raw_query'   => $rawQuery !== '' ? $rawQuery : null,
                    ],
                );
            }

            return [$convRow !== null ? (string)$convRow['id'] : null, $isNewEvent, $updated];
        });

        // Enqueue outgoing S2S postbacks (no-ops if offer has no postback_urls)
        if ($conversionId !== null && $isNewEvent) {
            $this->outbox->enqueue($conversionId, $offer->id, [
                'click_id'    => $subid,
                'payout'      => $payout,
                'status'      => $status,
                'external_id' => $externalId ?? '',
                'currency'    => $offer->currency,
            ]);
        }

        if ($isNewEvent && $this->tg->isConfigured() && $this->settings->getBool('notif_conv_click_enabled', true)) {
            $appUrl = rtrim((string)($_ENV['APP_URL'] ?? 'https://slimtds.local'), '/');
            $landerHost = (string)($clickRow['lander_host'] ?? '');
            $landerBtn  = (string)($clickRow['lander_button'] ?? '');
            $country    = (string)($clickRow['country'] ?? '');
            $device     = (string)($clickRow['device'] ?? '');
            $ageSec     = (int)($clickRow['age_sec'] ?? 0);
            $visitorUuid = (string)($clickRow['visitor_uuid'] ?? '');

            // route: lander → button · 🇦🇷 AR · device. Each part skipped when empty.
            $route = [];
            if ($landerHost !== '') {
                $route[] = $landerHost . ($landerBtn !== '' ? ' → ' . $landerBtn : '');
            }
            if ($country !== '') {
                $flag = self::flagOf($country);
                $route[] = trim($flag . ' ' . strtoupper($country));
            }
            if ($device !== '') $route[] = $device;

            // entry source — first pixel-event referer of this visitor.
            $source = '';
            if ($visitorUuid !== '') {
                $entryRef = (string)($this->db->fetchScalar(
                    "SELECT referer FROM stats.pixel_events
                     WHERE visitor_uuid = :v AND created_at >= now() - interval '30 days'
                     ORDER BY created_at ASC LIMIT 1",
                    ['v' => $visitorUuid],
                ) ?? '');
                if ($entryRef !== '') {
                    $src = SearchEngine::classify($entryRef);
                    $emoji = match ($src) {
                        'chatgpt', 'perplexity', 'claude', 'gemini', 'copilot' => '🤖',
                        null => '🔗',
                        default => '🔍',
                    };
                    $source = $emoji . ' ' . ($src ?? (parse_url($entryRef, PHP_URL_HOST) ?: 'direct'));
                }
            }

            $msg = $this->notifications->render(
                NotificationRegistry::CONV_CLICK,
                $this->settings->get('notif_conv_click_template', ''),
                [
                    'offer_name' => $offer->name,
                    'status'     => $status,
                    'payout'     => $payout,
                    'route'      => implode(' · ', $route),
                    'source'     => $source,
                    'player_id'  => $externalId !== '' && $externalId !== null ? (string)$externalId : '',
                    'age'        => self::humanAge($ageSec),
                    'country'    => $country !== '' ? strtoupper($country) : '',
                    'device'     => $device,
                    'click_id'   => $subid,
                    'app_url'    => $appUrl,
                ],
            );
            $this->tg->send($msg);
        }

        $processingStatus = !$updated ? 'OK_NEW' : ($isNewEvent ? 'OK_TRANSITION' : 'OK_DUPLICATE');
        $this->logRequest($method, $rawQuery, $sourceIp, $subid, $token, $status, $payoutRaw, $externalId, $conversionId, $offer->id, $processingStatus, 200);
        return $this->json($response, ['ok' => true, 'updated' => $updated]);
    }

    /**
     * Append-only audit log of every /postback hit, regardless of outcome —
     * see migrations/20260809000002_postback_requests_log.php. Best-effort:
     * a logging failure must never break the actual postback response the
     * partner network is waiting on.
     */
    private function logRequest(
        string $method,
        string $rawQuery,
        string $sourceIp,
        string $subid,
        string $token,
        string $status,
        mixed $payoutRaw,
        ?string $externalId,
        ?string $conversionId,
        ?string $offerId,
        string $processingStatus,
        int $httpStatus,
    ): void {
        try {
            $this->db->execute(
                <<<'SQL'
                    INSERT INTO core.postback_requests
                        (method, raw_query, source_ip, parsed_subid, parsed_token, parsed_status,
                         parsed_payout, parsed_external_id, conversion_id, offer_id, processing_status, http_status)
                    VALUES
                        (:method, :raw_query, :source_ip, :subid, :token, :status,
                         :payout, :external_id, :conversion_id, :offer_id, :processing_status, :http_status)
                SQL,
                [
                    'method'             => $method,
                    'raw_query'          => $rawQuery !== '' ? $rawQuery : null,
                    'source_ip'          => $sourceIp !== '' && $sourceIp !== '0.0.0.0' ? $sourceIp : null,
                    'subid'              => $subid !== '' ? $subid : null,
                    'token'              => $token !== '' ? $token : null,
                    'status'             => $status,
                    'payout'             => (string)$payoutRaw,
                    'external_id'        => $externalId !== '' ? $externalId : null,
                    'conversion_id'      => $conversionId,
                    'offer_id'           => $offerId,
                    'processing_status'  => $processingStatus,
                    'http_status'        => $httpStatus,
                ],
            );
        } catch (\Throwable $e) {
            error_log('[postback] request log insert failed: ' . $e->getMessage());
        }
    }

    /** ISO-2 country → 🇦🇷-style regional-indicator flag (or '' on bad input). */
    private static function flagOf(string $cc): string
    {
        $cc = strtoupper(trim($cc));
        if (strlen($cc) !== 2 || !ctype_alpha($cc)) return '';
        return mb_chr(0x1F1E6 + ord($cc[0]) - 65, 'UTF-8') . mb_chr(0x1F1E6 + ord($cc[1]) - 65, 'UTF-8');
    }

    /** Seconds → "30s" / "5m" / "2h" / "3d" — coarse age suitable for ops alerts. */
    private static function humanAge(int $sec): string
    {
        if ($sec < 60)    return $sec . 's';
        if ($sec < 3600)  return intdiv($sec, 60) . 'm';
        if ($sec < 86400) return intdiv($sec, 3600) . 'h';
        return intdiv($sec, 86400) . 'd';
    }

    /** @param array<string,mixed> $data */
    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
