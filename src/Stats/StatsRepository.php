<?php

declare(strict_types=1);

namespace App\Stats;

use App\Shared\Db\Connection;

final class StatsRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Time-series: clicks per hour for a campaign (or all) in given window.
     * @return list<array{hour:string, clicks:int, uniq:int, bot:int}>
     */
    public function clicksTimeline(?string $campaignId, string $sinceIso): array
    {
        $params = ['since' => $sinceIso];
        $where = 'WHERE hour >= :since';
        if ($campaignId !== null) {
            $where .= ' AND campaign_id = :cid';
            $params['cid'] = $campaignId;
        }
        $rows = $this->db->fetchAll(
            "SELECT to_char(hour, 'YYYY-MM-DD\"T\"HH24:00:00\"Z\"') AS hour,
                    sum(clicks)::int       AS clicks,
                    sum(clicks_uniq)::int  AS uniq,
                    sum(clicks_bot)::int   AS bot
             FROM stats.clicks_hourly
             {$where}
             GROUP BY hour
             ORDER BY hour",
            $params,
        );
        return array_map(static fn ($r) => [
            'hour'   => (string)$r['hour'],
            'clicks' => (int)$r['clicks'],
            'uniq'   => (int)$r['uniq'],
            'bot'    => (int)$r['bot'],
        ], $rows);
    }

    /**
     * Top-line KPIs for a window: total clicks, unique clicks, conversions, approved-payout.
     * @return array{clicks:int, uniq:int, bots:int, conversions:int, approved:int, payout:string, cr:float, epc:float}
     */
    public function summary(?string $campaignId, string $sinceIso): array
    {
        $cidWhere = $campaignId !== null ? 'AND campaign_id = :cid' : '';
        $clickRow = $this->db->fetchOne(
            "SELECT COALESCE(sum(clicks), 0)::int AS clicks,
                    COALESCE(sum(clicks_uniq), 0)::int AS uniq,
                    COALESCE(sum(clicks_bot), 0)::int  AS bots
             FROM stats.clicks_hourly WHERE hour >= :since {$cidWhere}",
            $campaignId !== null ? ['since' => $sinceIso, 'cid' => $campaignId] : ['since' => $sinceIso],
        ) ?? ['clicks' => 0, 'uniq' => 0, 'bots' => 0];

        $convRow = $this->db->fetchOne(
            "SELECT COALESCE(sum(conv), 0)::int          AS conversions,
                    COALESCE(sum(conv_approved), 0)::int AS approved,
                    COALESCE(sum(payout), 0)::text       AS payout
             FROM core.conversions_hourly WHERE hour >= :since {$cidWhere}",
            $campaignId !== null ? ['since' => $sinceIso, 'cid' => $campaignId] : ['since' => $sinceIso],
        ) ?? ['conversions' => 0, 'approved' => 0, 'payout' => '0'];

        $clicks = (int)$clickRow['clicks'];
        $approved = (int)$convRow['approved'];
        $cr = $clicks > 0 ? round($approved / $clicks * 100, 2) : 0.0;
        $epc = $clicks > 0 ? round((float)$convRow['payout'] / max(1, $clicks), 4) : 0.0;
        return [
            'clicks'      => $clicks,
            'uniq'        => (int)$clickRow['uniq'],
            'bots'        => (int)$clickRow['bots'],
            'conversions' => (int)$convRow['conversions'],
            'approved'    => $approved,
            'payout'      => (string)$convRow['payout'],
            'cr'          => $cr,
            'epc'         => $epc,
        ];
    }

    /**
     * Breakdown by offer × country — clicks, uniques, conversions, approved
     * count, and approved payout, one row per (offer, country) pair seen in
     * the window. Sorted by clicks desc so the busiest combinations lead.
     *
     * @return list<array{offer_id:?string, offer_name:?string, country:?string, clicks:int, uniq:int, conversions:int, approved:int, payout:string}>
     */
    public function byOfferCountry(?string $campaignId, string $sinceIso, int $limit = 200): array
    {
        $cidWhere = $campaignId !== null ? 'AND campaign_id = :cid' : '';
        $params = ['since' => $sinceIso, 'limit' => $limit];
        if ($campaignId !== null) $params['cid'] = $campaignId;

        $rows = $this->db->fetchAll(
            <<<SQL
                WITH c AS (
                    SELECT offer_id, country, SUM(clicks)::int AS clicks, SUM(clicks_uniq)::int AS uniq
                    FROM stats.clicks_hourly
                    WHERE hour >= :since {$cidWhere}
                    GROUP BY offer_id, country
                ),
                v AS (
                    SELECT offer_id, country,
                           SUM(conv)::int AS conversions,
                           SUM(conv_approved)::int AS approved,
                           SUM(payout)::text AS payout
                    FROM core.conversions_hourly
                    WHERE hour >= :since {$cidWhere}
                    GROUP BY offer_id, country
                )
                SELECT
                    COALESCE(c.offer_id, v.offer_id)  AS offer_id,
                    o.name                            AS offer_name,
                    COALESCE(c.country, v.country)    AS country,
                    COALESCE(c.clicks, 0)              AS clicks,
                    COALESCE(c.uniq, 0)                AS uniq,
                    COALESCE(v.conversions, 0)          AS conversions,
                    COALESCE(v.approved, 0)             AS approved,
                    COALESCE(v.payout, '0')             AS payout
                FROM c
                FULL OUTER JOIN v
                    ON COALESCE(c.offer_id, '00000000-0000-0000-0000-000000000000') = COALESCE(v.offer_id, '00000000-0000-0000-0000-000000000000')
                   AND COALESCE(c.country, '') = COALESCE(v.country, '')
                LEFT JOIN core.offers o ON o.id = COALESCE(c.offer_id, v.offer_id)
                ORDER BY clicks DESC NULLS LAST, conversions DESC
                LIMIT :limit
            SQL,
            $params,
        );

        return array_map(static fn ($r) => [
            'offer_id'    => $r['offer_id'] !== null ? (string)$r['offer_id'] : null,
            'offer_name'  => $r['offer_name'] !== null ? (string)$r['offer_name'] : null,
            'country'     => $r['country'] !== null ? (string)$r['country'] : null,
            'clicks'      => (int)$r['clicks'],
            'uniq'        => (int)$r['uniq'],
            'conversions' => (int)$r['conversions'],
            'approved'    => (int)$r['approved'],
            'payout'      => (string)$r['payout'],
        ], $rows);
    }

    /**
     * Revenue by SEO lander domain (stats.clicks.lander_host — set by the
     * site's nginx proxy via X-Lander-Host, only from a trusted peer; see
     * ClickHandler::buildContext). Answers "which of our sites actually
     * earns" — not covered by stats.clicks_hourly, which doesn't carry this
     * dimension. Queries raw stats.clicks + core.conversions directly
     * (BRIN-indexed on created_at) rather than the hourly matview.
     *
     * @return list<array{lander_host:string, clicks:int, clicks_human:int, conversions:int, approved:int, payout:string}>
     */
    public function bySite(?string $campaignId, string $sinceIso, int $limit = 200): array
    {
        $cidWhere = $campaignId !== null ? 'AND cl.campaign_id = :cid' : '';
        $params = ['since' => $sinceIso, 'limit' => $limit];
        if ($campaignId !== null) $params['cid'] = $campaignId;

        // core.conversions can now hold several rows per click_id (one per
        // distinct event_type/external_id identity — REG + FTD + REDEP...).
        // A plain LEFT JOIN would fan out stats.clicks rows by however many
        // conversions each click has, inflating `clicks` itself, not just
        // the conversion counts. Pre-aggregate per click_id first so each
        // click still contributes exactly one row to the join.
        $rows = $this->db->fetchAll(
            <<<SQL
                WITH conv_per_click AS (
                    SELECT click_id,
                           count(*)                                                 AS n,
                           count(*) FILTER (WHERE status = 'approved')              AS n_approved,
                           COALESCE(sum(payout) FILTER (WHERE status = 'approved'), 0) AS payout_approved
                    FROM core.conversions
                    WHERE click_id IS NOT NULL
                    GROUP BY click_id
                )
                SELECT
                    COALESCE(cl.lander_host, '') AS lander_host,
                    count(*)::int AS clicks,
                    count(*) FILTER (WHERE NOT cl.is_bot)::int AS clicks_human,
                    COALESCE(sum(cpc.n), 0)::int AS conversions,
                    COALESCE(sum(cpc.n_approved), 0)::int AS approved,
                    COALESCE(sum(cpc.payout_approved), 0) AS payout_num,
                    COALESCE(sum(cpc.payout_approved), 0)::text AS payout
                FROM stats.clicks cl
                LEFT JOIN conv_per_click cpc ON cpc.click_id = cl.id
                WHERE cl.created_at >= :since {$cidWhere}
                GROUP BY cl.lander_host
                ORDER BY payout_num DESC, clicks DESC
                LIMIT :limit
            SQL,
            $params,
        );

        return array_map(static fn ($r) => [
            'lander_host'  => (string)$r['lander_host'],
            'clicks'       => (int)$r['clicks'],
            'clicks_human' => (int)$r['clicks_human'],
            'conversions'  => (int)$r['conversions'],
            'approved'     => (int)$r['approved'],
            'payout'       => (string)$r['payout'],
        ], $rows);
    }

    public function refreshClicksHourly(): void
    {
        try {
            $this->db->pdo->exec('REFRESH MATERIALIZED VIEW CONCURRENTLY stats.clicks_hourly');
        } catch (\PDOException $e) {
            // First refresh after migration must be non-concurrent (matview must be populated first)
            $this->db->pdo->exec('REFRESH MATERIALIZED VIEW stats.clicks_hourly');
        }
    }
}
