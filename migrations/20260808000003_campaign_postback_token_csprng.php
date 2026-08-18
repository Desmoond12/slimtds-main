<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CampaignPostbackTokenCsprng extends AbstractMigration
{
    public function up(): void
    {
        // uuidv7() is time-ordered (the leading 48 bits are a millisecond
        // timestamp) — weaker than a token needs to be, even though the
        // remaining ~74 bits still make brute-force infeasible in practice.
        // Match core.offers.postback_token's scheme (see
        // 20260424000002_init_core_entities.php) for a plain 128-bit CSPRNG.
        // Only changes the DEFAULT for campaigns created from now on —
        // existing tokens are left alone since partner networks may already
        // have them configured; rotate manually if a specific one needs it.
        $this->execute(
            "ALTER TABLE core.campaigns ALTER COLUMN postback_token SET DEFAULT encode(gen_random_bytes(16), 'hex')",
        );
    }

    public function down(): void
    {
        $this->execute(
            "ALTER TABLE core.campaigns ALTER COLUMN postback_token SET DEFAULT replace(uuidv7()::text, '-', '')",
        );
    }
}
