<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class StatsHourlyOfferCountry extends AbstractMigration
{
    public function up(): void
    {
        // Widen stats.clicks_hourly's grain to include offer_id + country so
        // "leads/deps by offer × country" (a real reporting gap flagged in
        // the audit — the operator's #1 ask) can be queried without
        // rescanning raw stats.clicks. Existing queries (StatsRepository::
        // summary/clicksTimeline) only ever SUM() this view without
        // grouping by the new columns, so they keep working unchanged —
        // this is purely additive.
        $this->execute('DROP INDEX IF EXISTS stats.clicks_hourly_hour_idx');
        $this->execute('DROP INDEX IF EXISTS stats.clicks_hourly_pk');
        $this->execute('DROP MATERIALIZED VIEW IF EXISTS stats.clicks_hourly');

        $this->execute(<<<'SQL'
            CREATE MATERIALIZED VIEW stats.clicks_hourly AS
            SELECT
                campaign_id,
                offer_id,
                country,
                date_trunc('hour', created_at) AS hour,
                count(*)                                          AS clicks,
                count(*) FILTER (WHERE NOT is_bot)                AS clicks_human,
                count(*) FILTER (WHERE is_uniq AND NOT is_bot)    AS clicks_uniq,
                count(*) FILTER (WHERE is_bot)                    AS clicks_bot
            FROM stats.clicks
            WHERE created_at >= now() - interval '90 days'
            GROUP BY 1, 2, 3, 4
        SQL);
        // Unique index must cover every grouping column for REFRESH
        // CONCURRENTLY to work; offer_id/country can be NULL (trash
        // fallthrough / bot with no GeoIP match) — a plain UNIQUE index
        // treats NULLs as distinct, which is exactly what we want here
        // (two NULL-offer rows for the same campaign/hour never happen
        // since they're still distinguished by country, and vice versa).
        $this->execute(
            'CREATE UNIQUE INDEX clicks_hourly_pk ON stats.clicks_hourly (campaign_id, offer_id, country, hour)',
        );
        $this->execute('CREATE INDEX clicks_hourly_hour_idx ON stats.clicks_hourly (hour DESC)');
        $this->execute('CREATE INDEX clicks_hourly_offer_idx ON stats.clicks_hourly (offer_id)');

        // core.conversions has no country column (it's on the originating
        // click, not the conversion) — join it in via click_id. LEFT JOIN
        // so anonymous campaign-pings (click_id IS NULL, see
        // PostbackController) still count, just with country = NULL.
        $this->execute('DROP VIEW IF EXISTS core.conversions_hourly');
        $this->execute(<<<'SQL'
            CREATE VIEW core.conversions_hourly AS
            SELECT
                cv.campaign_id, cv.offer_id, c.country,
                date_trunc('hour', cv.created_at) AS hour,
                count(*)                                                    AS conv,
                count(*) FILTER (WHERE cv.status = 'approved')              AS conv_approved,
                COALESCE(sum(cv.payout) FILTER (WHERE cv.status = 'approved'), 0) AS payout
            FROM core.conversions cv
            LEFT JOIN stats.clicks c ON c.id = cv.click_id
            GROUP BY 1, 2, 3, 4
        SQL);
    }

    public function down(): void
    {
        $this->execute('DROP VIEW IF EXISTS core.conversions_hourly');
        $this->execute(<<<'SQL'
            CREATE VIEW core.conversions_hourly AS
            SELECT
                campaign_id, offer_id,
                date_trunc('hour', created_at) AS hour,
                count(*)                                                 AS conv,
                count(*) FILTER (WHERE status = 'approved')              AS conv_approved,
                COALESCE(sum(payout) FILTER (WHERE status = 'approved'), 0) AS payout
            FROM core.conversions
            GROUP BY 1, 2, 3
        SQL);

        $this->execute('DROP INDEX IF EXISTS stats.clicks_hourly_offer_idx');
        $this->execute('DROP INDEX IF EXISTS stats.clicks_hourly_hour_idx');
        $this->execute('DROP INDEX IF EXISTS stats.clicks_hourly_pk');
        $this->execute('DROP MATERIALIZED VIEW IF EXISTS stats.clicks_hourly');
        $this->execute(<<<'SQL'
            CREATE MATERIALIZED VIEW stats.clicks_hourly AS
            SELECT
                campaign_id,
                date_trunc('hour', created_at) AS hour,
                count(*)                                          AS clicks,
                count(*) FILTER (WHERE NOT is_bot)                AS clicks_human,
                count(*) FILTER (WHERE is_uniq AND NOT is_bot)    AS clicks_uniq,
                count(*) FILTER (WHERE is_bot)                    AS clicks_bot
            FROM stats.clicks
            WHERE created_at >= now() - interval '90 days'
            GROUP BY 1, 2
        SQL);
        $this->execute('CREATE UNIQUE INDEX clicks_hourly_pk ON stats.clicks_hourly (campaign_id, hour)');
        $this->execute('CREATE INDEX clicks_hourly_hour_idx ON stats.clicks_hourly (hour DESC)');
    }
}
