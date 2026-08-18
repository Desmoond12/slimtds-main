<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class SessionsAbsoluteTtl extends AbstractMigration
{
    public function up(): void
    {
        // core.sessions previously only tracked expires_at, which
        // PgSessionHandler::write() re-extends on every request — a pure
        // sliding window with no cap, so a session cookie used at least
        // once every SESSION_LIFETIME never truly expires. created_at lets
        // the handler cap the sliding window against an absolute maximum
        // age regardless of activity.
        $this->execute(
            'ALTER TABLE core.sessions ADD COLUMN IF NOT EXISTS created_at timestamptz NOT NULL DEFAULT now()',
        );
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE core.sessions DROP COLUMN IF EXISTS created_at');
    }
}
