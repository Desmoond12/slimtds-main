<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class PpReports extends AbstractMigration
{
    public function up(): void
    {
        // Same "configure once per network, reuse on every future upload"
        // principle as AffiliateNetworkAdapter's postback param mapping —
        // each partner's report export uses its own column headers and date
        // format. report_column_map: their CSV header -> our canonical field
        // (date/campaign/offer/event_type/clicks/count/payout/currency).
        $this->execute("ALTER TABLE core.affiliate_networks ADD COLUMN report_column_map jsonb NOT NULL DEFAULT '{}'");
        $this->execute("ALTER TABLE core.affiliate_networks ADD COLUMN report_date_format text NOT NULL DEFAULT 'Y-m-d'");

        // One row per uploaded file — audit trail + a single point to
        // "undo" a bad import (delete cascades to its pp_reports rows).
        $this->execute(<<<'SQL'
            CREATE TABLE core.pp_report_imports (
                id         uuid        PRIMARY KEY DEFAULT uuidv7(),
                network_id uuid        NOT NULL REFERENCES core.affiliate_networks(id) ON DELETE CASCADE,
                filename   text        NOT NULL,
                row_count  int         NOT NULL DEFAULT 0,
                admin_id   bigint      REFERENCES core.admins(id) ON DELETE SET NULL,
                created_at timestamptz NOT NULL DEFAULT now()
            )
        SQL);
        $this->execute('CREATE INDEX idx_pp_report_imports_network ON core.pp_report_imports (network_id, created_at DESC)');

        // Normalised partner-side numbers — the future Reconciliation Center
        // joins this against our own tracker aggregates. Same target table
        // regardless of source (file upload today, API connector later —
        // see migration comment / plan): raw_row keeps the untouched
        // original row (CSV line or, later, an API response) for debugging
        // a bad column mapping without re-uploading.
        $this->execute(<<<'SQL'
            CREATE TABLE core.pp_reports (
                id          uuid          PRIMARY KEY DEFAULT uuidv7(),
                import_id   uuid          NOT NULL REFERENCES core.pp_report_imports(id) ON DELETE CASCADE,
                network_id  uuid          NOT NULL REFERENCES core.affiliate_networks(id) ON DELETE CASCADE,
                campaign_id uuid          REFERENCES core.campaigns(id) ON DELETE SET NULL,
                offer_id    uuid          REFERENCES core.offers(id) ON DELETE SET NULL,
                report_date date          NOT NULL,
                event_type  text          NOT NULL DEFAULT 'conversion',
                clicks      int,
                count       int,
                payout      numeric(12,2),
                currency    text          NOT NULL DEFAULT 'USD',
                raw_row     jsonb         NOT NULL,
                created_at  timestamptz   NOT NULL DEFAULT now()
            )
        SQL);
        $this->execute('CREATE INDEX idx_pp_reports_network_date ON core.pp_reports (network_id, report_date)');
        $this->execute('CREATE INDEX idx_pp_reports_import ON core.pp_reports (import_id)');
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS core.pp_reports');
        $this->execute('DROP TABLE IF EXISTS core.pp_report_imports');
        $this->execute('ALTER TABLE core.affiliate_networks DROP COLUMN IF EXISTS report_date_format');
        $this->execute('ALTER TABLE core.affiliate_networks DROP COLUMN IF EXISTS report_column_map');
    }
}
