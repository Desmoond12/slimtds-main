<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AffiliateNetworks extends AbstractMigration
{
    public function up(): void
    {
        // Some partner networks dictate their own postback query-param names
        // (clickid instead of subid, amount instead of payout, numeric status
        // codes instead of approved/pending/hold/rejected) and the operator
        // has no way to rename them. Per-offer/per-campaign network_id opts
        // that identity into a remapping instead of forcing every partner to
        // match our hardcoded field names. network_id absent (the default,
        // NULL) reproduces today's hardcoded subid/status/payout/external_id
        // /event_type behavior exactly — zero risk for existing integrations.
        $this->execute(<<<'SQL'
            CREATE TABLE core.affiliate_networks (
                id                uuid        PRIMARY KEY DEFAULT uuidv7(),
                name              text        NOT NULL,
                click_param       text        NOT NULL DEFAULT 'subid',
                status_param      text        NOT NULL DEFAULT 'status',
                payout_param      text        NOT NULL DEFAULT 'payout',
                external_id_param text        NOT NULL DEFAULT 'external_id',
                event_type_param  text        NOT NULL DEFAULT 'event_type',
                status_map        jsonb       NOT NULL DEFAULT '{}',
                notes             text,
                is_active         boolean     NOT NULL DEFAULT true,
                created_at        timestamptz NOT NULL DEFAULT now(),
                updated_at        timestamptz NOT NULL DEFAULT now()
            )
        SQL);

        // ON DELETE SET NULL — deleting a network must not take down offers
        // or campaigns that reference it; they just fall back to the default
        // (hardcoded) parameter names.
        $this->execute('ALTER TABLE core.offers ADD COLUMN network_id uuid REFERENCES core.affiliate_networks(id) ON DELETE SET NULL');
        $this->execute('ALTER TABLE core.campaigns ADD COLUMN network_id uuid REFERENCES core.affiliate_networks(id) ON DELETE SET NULL');
        $this->execute('CREATE INDEX idx_offers_network ON core.offers (network_id)');
        $this->execute('CREATE INDEX idx_campaigns_network ON core.campaigns (network_id)');
    }

    public function down(): void
    {
        $this->execute('DROP INDEX IF EXISTS core.idx_campaigns_network');
        $this->execute('DROP INDEX IF EXISTS core.idx_offers_network');
        $this->execute('ALTER TABLE core.campaigns DROP COLUMN IF EXISTS network_id');
        $this->execute('ALTER TABLE core.offers DROP COLUMN IF EXISTS network_id');
        $this->execute('DROP TABLE IF EXISTS core.affiliate_networks');
    }
}
