<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ConversionEvents extends AbstractMigration
{
    public function up(): void
    {
        // core.conversions had UNIQUE(click_id) — one row per click, period.
        // Real affiliate flows need REG, FTD, REDEP#1, REDEP#2... on the same
        // click as distinct business events, not overwrites of each other.
        // event_type widens the identity; external_id (the partner's own
        // transaction id, when they send one) lets repeatable event types
        // (redeposit) coexist too instead of collapsing into one row.
        $this->execute("ALTER TABLE core.conversions ADD COLUMN event_type text NOT NULL DEFAULT 'conversion'");

        // The old UNIQUE(click_id) is backed by a constraint, not a bare
        // index — DROP CONSTRAINT removes its backing index too, dropping
        // the index directly first would fail with "dependent objects
        // still exist" since the constraint still references it.
        $this->execute('ALTER TABLE core.conversions DROP CONSTRAINT IF EXISTS conversions_click_id_key');
        $this->execute(
            <<<'SQL'
                CREATE UNIQUE INDEX idx_conversions_identity
                ON core.conversions (click_id, event_type, (COALESCE(external_id, '')))
            SQL,
        );

        // Append-only ledger — one row per accepted event/transition, never
        // updated or deleted. core.conversions stays the "current state per
        // identity" cache (postback_deliveries / conversion_status_history
        // FKs keep pointing at it unchanged); this table is what powers
        // taxonomy/cohort/reconciliation reporting going forward without ever
        // losing a REG because an FTD (or a same-type repostback) arrived
        // later on the same click.
        $this->execute(<<<'SQL'
            CREATE TABLE core.conversion_events (
                id            uuid        PRIMARY KEY DEFAULT uuidv7(),
                click_id      uuid,
                campaign_id   uuid        NOT NULL,
                offer_id      uuid,
                event_type    text        NOT NULL DEFAULT 'conversion',
                status        text        NOT NULL CHECK (status IN ('approved','pending','hold','rejected')),
                payout        numeric(10,2) NOT NULL DEFAULT 0,
                currency      text        NOT NULL DEFAULT 'USD',
                external_id   text,
                source_ip     inet,
                raw_query     text,
                received_at   timestamptz NOT NULL DEFAULT now()
            )
        SQL);
        $this->execute('CREATE INDEX idx_conversion_events_click ON core.conversion_events (click_id)');
        $this->execute('CREATE INDEX idx_conversion_events_campaign ON core.conversion_events (campaign_id, received_at DESC)');
        $this->execute('CREATE INDEX idx_conversion_events_offer_type ON core.conversion_events (offer_id, event_type)');

        // conversions_hourly widened with event_type — additive, same trick
        // as the offer/country widening in stats_hourly_offer_country.php:
        // existing SUM()-only consumers (StatsRepository::summary/clicksTimeline)
        // keep working unchanged.
        $this->execute('DROP VIEW IF EXISTS core.conversions_hourly');
        $this->execute(<<<'SQL'
            CREATE VIEW core.conversions_hourly AS
            SELECT
                cv.campaign_id, cv.offer_id, cv.event_type, c.country,
                date_trunc('hour', cv.created_at) AS hour,
                count(*)                                                    AS conv,
                count(*) FILTER (WHERE cv.status = 'approved')              AS conv_approved,
                COALESCE(sum(cv.payout) FILTER (WHERE cv.status = 'approved'), 0) AS payout
            FROM core.conversions cv
            LEFT JOIN stats.clicks c ON c.id = cv.click_id
            GROUP BY 1, 2, 3, 4, 5
        SQL);
    }

    public function down(): void
    {
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

        $this->execute('DROP TABLE IF EXISTS core.conversion_events');

        $this->execute('DROP INDEX IF EXISTS core.idx_conversions_identity');
        // down() assumes no duplicate (click_id) rows exist yet — true on
        // dev/fresh installs; a prod rollback after real multi-event traffic
        // would need a manual dedup pass first, same trade-off Phinx down()
        // always carries for narrowing constraints.
        $this->execute('CREATE UNIQUE INDEX conversions_click_id_key ON core.conversions (click_id)');
        $this->execute('ALTER TABLE core.conversions DROP COLUMN event_type');
    }
}
