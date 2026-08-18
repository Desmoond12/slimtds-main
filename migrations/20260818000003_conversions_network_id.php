<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ConversionsNetworkId extends AbstractMigration
{
    public function up(): void
    {
        // Denormalize the network onto conversions at write time. Two reasons:
        // 1. Reconciliation (Tracker vs PP) groups by network — resolving it
        //    later through offers.network_id silently shifts history whenever
        //    the operator re-links an offer to a different network. The value
        //    the postback was actually processed under is the truthful one.
        // 2. It makes the cross-click duplicate guard below expressible as a
        //    real constraint instead of a racy SELECT.
        // ON DELETE SET NULL mirrors offers/campaigns — deleting a network
        // must never take conversions down with it.
        $this->execute('ALTER TABLE core.conversions ADD COLUMN network_id uuid REFERENCES core.affiliate_networks(id) ON DELETE SET NULL');
        $this->execute('ALTER TABLE core.conversion_events ADD COLUMN network_id uuid REFERENCES core.affiliate_networks(id) ON DELETE SET NULL');
        $this->execute('CREATE INDEX idx_conversions_network ON core.conversions (network_id)');
        $this->execute('CREATE INDEX idx_conversion_events_network ON core.conversion_events (network_id)');

        // Cross-click duplicate guard: the same transaction id arriving under
        // two DIFFERENT click_ids (partner-side bug, player converting from
        // two devices) is invisible to the (click_id, event_type, external_id)
        // identity and would double revenue. This partial index catches it.
        // event_type is part of the key on purpose — many networks reuse one
        // player id as external_id across REG and FTD, which is legitimate
        // and must not collide. Scoped to attributed (click_id IS NOT NULL),
        // network-bound rows with a real external_id, so anonymous campaign
        // pings and default-path postbacks behave exactly as before.
        // PostbackController catches the 23505 from this index and answers
        // 200 'duplicate' (not 500) so partner retries don't storm.
        $this->execute(<<<'SQL'
            CREATE UNIQUE INDEX uq_conversions_network_txid
                ON core.conversions (network_id, event_type, external_id)
                WHERE network_id IS NOT NULL
                  AND external_id IS NOT NULL
                  AND click_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        $this->execute('DROP INDEX IF EXISTS core.uq_conversions_network_txid');
        $this->execute('DROP INDEX IF EXISTS core.idx_conversion_events_network');
        $this->execute('DROP INDEX IF EXISTS core.idx_conversions_network');
        $this->execute('ALTER TABLE core.conversion_events DROP COLUMN IF EXISTS network_id');
        $this->execute('ALTER TABLE core.conversions DROP COLUMN IF EXISTS network_id');
    }
}
