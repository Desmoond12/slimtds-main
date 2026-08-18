<?php

declare(strict_types=1);

namespace App\Admin\Repository;

use App\Shared\Db\Connection;

/**
 * Tracker-vs-PP reconciliation queries — the "does the network's report
 * agree with what we recorded" screen.
 *
 * Tracker side: core.conversion_events (append-only ledger; grouped by day +
 * event_type) and stats.clicks (grouped by day). Network attribution prefers
 * the network_id stamped on the row at postback time and falls back to the
 * offer's CURRENT network link for rows written before that column existed.
 *
 * PP side: core.pp_reports (normalized report rows from CSV imports).
 * PP event_type values are passed through the network's event_map (same
 * mapping the postback pipeline applies) so "Registration" in their export
 * and "reg" in our ledger land in the same bucket.
 *
 * Both sides bucket by calendar date; ours in APP_TZ (the PDO session TZ),
 * theirs in whatever timezone the network built the report in. A ±1-day
 * smear around midnight is inherent to that and is a data property, not a
 * query bug — the UI says so.
 */
final class ReconciliationRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Per-day, per-event comparison of conversion counts and payouts.
     *
     * @return list<array{d:string, event_type:string, tracker_count:?int, tracker_payout:?string, pp_count:?int, pp_payout:?string}>
     */
    public function events(string $networkId, string $from, string $to): array
    {
        /** @var list<array{d:string, event_type:string, tracker_count:?int, tracker_payout:?string, pp_count:?int, pp_payout:?string}> */
        return $this->db->fetchAll(
            <<<'SQL'
                WITH net AS (
                    SELECT event_map FROM core.affiliate_networks WHERE id = :nid
                ),
                tracker AS (
                    SELECT ce.received_at::date AS d,
                           lower(ce.event_type) AS event_type,
                           count(*)::int AS cnt,
                           sum(ce.payout) AS payout
                    FROM core.conversion_events ce
                    LEFT JOIN core.offers o ON o.id = ce.offer_id
                    WHERE COALESCE(ce.network_id, o.network_id) = :nid
                      AND ce.received_at >= :from::date
                      AND ce.received_at < :to::date + 1
                    GROUP BY 1, 2
                ),
                pp AS (
                    SELECT pr.report_date AS d,
                           lower(COALESCE((SELECT event_map ->> lower(pr.event_type) FROM net), pr.event_type)) AS event_type,
                           sum(pr.count)::int AS cnt,
                           sum(pr.payout) AS payout
                    FROM core.pp_reports pr
                    WHERE pr.network_id = :nid
                      AND pr.report_date BETWEEN :from::date AND :to::date
                      AND (pr.count IS NOT NULL OR pr.payout IS NOT NULL)
                    GROUP BY 1, 2
                )
                SELECT COALESCE(t.d, p.d)::text AS d,
                       COALESCE(t.event_type, p.event_type) AS event_type,
                       t.cnt AS tracker_count,
                       t.payout::text AS tracker_payout,
                       p.cnt AS pp_count,
                       p.payout::text AS pp_payout
                FROM tracker t
                FULL OUTER JOIN pp p ON t.d = p.d AND t.event_type = p.event_type
                ORDER BY 1 DESC, 2 ASC
            SQL,
            ['nid' => $networkId, 'from' => $from, 'to' => $to],
        );
    }

    /**
     * Per-day comparison of click counts (our redirects to this network's
     * offers vs the clicks column of their report, when they provide one).
     *
     * @return list<array{d:string, tracker_clicks:?int, pp_clicks:?int}>
     */
    public function clicks(string $networkId, string $from, string $to): array
    {
        /** @var list<array{d:string, tracker_clicks:?int, pp_clicks:?int}> */
        return $this->db->fetchAll(
            <<<'SQL'
                WITH tracker AS (
                    SELECT c.created_at::date AS d, count(*)::int AS clicks
                    FROM stats.clicks c
                    JOIN core.offers o ON o.id = c.offer_id
                    WHERE o.network_id = :nid
                      AND c.created_at >= :from::date
                      AND c.created_at < :to::date + 1
                    GROUP BY 1
                ),
                pp AS (
                    SELECT pr.report_date AS d, sum(pr.clicks)::int AS clicks
                    FROM core.pp_reports pr
                    WHERE pr.network_id = :nid
                      AND pr.report_date BETWEEN :from::date AND :to::date
                      AND pr.clicks IS NOT NULL
                    GROUP BY 1
                )
                SELECT COALESCE(t.d, p.d)::text AS d,
                       t.clicks AS tracker_clicks,
                       p.clicks AS pp_clicks
                FROM tracker t
                FULL OUTER JOIN pp p ON t.d = p.d
                ORDER BY 1 DESC
            SQL,
            ['nid' => $networkId, 'from' => $from, 'to' => $to],
        );
    }

    /**
     * Default [from, to] range for the screen: the span of imported PP data
     * for this network (clamped to the last 90 days), or the last 30 days
     * when nothing has been imported yet.
     *
     * @return array{0:string, 1:string}
     */
    public function defaultRange(string $networkId): array
    {
        $row = $this->db->fetchOne(
            <<<'SQL'
                SELECT greatest(min(report_date), (now()::date - 90))::text AS from_d,
                       max(report_date)::text AS to_d
                FROM core.pp_reports
                WHERE network_id = :nid
            SQL,
            ['nid' => $networkId],
        );
        if ($row === null || $row['from_d'] === null || $row['to_d'] === null) {
            $today = (string)$this->db->fetchScalar('SELECT now()::date::text');
            $start = (string)$this->db->fetchScalar("SELECT (now()::date - 30)::text");
            return [$start, $today];
        }
        return [(string)$row['from_d'], (string)$row['to_d']];
    }
}
