<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AdminLoginLockout extends AbstractMigration
{
    public function up(): void
    {
        // Escalating lockout, independent of RateLimitMiddleware's fixed
        // 5-minute windows: those reset cleanly every window with no memory
        // of prior failures, so a determined attacker (even from one IP —
        // let alone rotating IPs, which bypass the per-IP bucket entirely)
        // can keep trying indefinitely at a steady rate. This tracks
        // consecutive failures per account and locks with a doubling
        // duration once RateLimitMiddleware's friction alone isn't enough.
        $this->execute(
            'ALTER TABLE core.admins ADD COLUMN IF NOT EXISTS failed_login_count int NOT NULL DEFAULT 0',
        );
        $this->execute(
            'ALTER TABLE core.admins ADD COLUMN IF NOT EXISTS locked_until timestamptz',
        );
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE core.admins DROP COLUMN IF EXISTS locked_until');
        $this->execute('ALTER TABLE core.admins DROP COLUMN IF EXISTS failed_login_count');
    }
}
