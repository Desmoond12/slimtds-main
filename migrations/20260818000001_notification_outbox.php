<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class NotificationOutbox extends AbstractMigration
{
    public function up(): void
    {
        // Telegram sends used to happen synchronously inside the two hottest
        // request paths — ClickHandler (a real visitor waits up to 5s for a
        // redirect while cURL talks to api.telegram.org) and
        // PostbackController (the partner network waits for our 200; a slow
        // TG hop provokes their retry storm). This outbox decouples them:
        // request paths only INSERT a row (sub-ms, same DB the request
        // already writes to), and the notifications:send cron drains it with
        // the same exponential-backoff pattern as core.postback_deliveries.
        $this->execute(<<<'SQL'
            CREATE TABLE core.notification_outbox (
                id              bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                channel         text        NOT NULL DEFAULT 'telegram',
                message         text        NOT NULL,
                parse_mode      text        NOT NULL DEFAULT 'HTML',
                attempts        int         NOT NULL DEFAULT 0,
                next_attempt_at timestamptz NOT NULL DEFAULT now(),
                sent_at         timestamptz,
                last_error      text,
                created_at      timestamptz NOT NULL DEFAULT now()
            )
        SQL);
        $this->execute(<<<'SQL'
            CREATE INDEX idx_notification_outbox_pending
                ON core.notification_outbox (next_attempt_at)
                WHERE sent_at IS NULL
        SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS core.notification_outbox');
    }
}
