<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class NetworkEventMapAllowedIps extends AbstractMigration
{
    public function up(): void
    {
        // event_map — second mapping level alongside status_map, but for
        // *values* of the event field rather than the param name. Different
        // networks call the same business event different things
        // ("firstDeposit" / "FTD" / "goal=2"); without value translation the
        // per-event statistics fork into synonym duplicates as soon as 2+
        // networks are live. Semantics differ from status_map deliberately:
        // an unmapped event value passes through unchanged (lowercased)
        // instead of 400ing — event_type is an open taxonomy, an
        // unconfigured value is not a protocol violation, and a money
        // postback must never be dropped over cosmetic naming.
        $this->execute("ALTER TABLE core.affiliate_networks ADD COLUMN event_map jsonb NOT NULL DEFAULT '{}'");

        // allowed_ips — postback source validation. Until now the only gate
        // was the token, which lives in the query string and leaks (logs,
        // referers, screenshots in partner chats) — anyone holding it could
        // fabricate FTD/revshare conversions with curl. Most networks
        // publish static postback egress IPs; a non-empty list here rejects
        // postbacks for this network's offers/campaigns from any other
        // source with 403 before anything is written. Empty list (default) =
        // no restriction, exact today's behavior.
        $this->execute("ALTER TABLE core.affiliate_networks ADD COLUMN allowed_ips jsonb NOT NULL DEFAULT '[]'");
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE core.affiliate_networks DROP COLUMN IF EXISTS allowed_ips');
        $this->execute('ALTER TABLE core.affiliate_networks DROP COLUMN IF EXISTS event_map');
    }
}
