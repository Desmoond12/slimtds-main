<?php

declare(strict_types=1);

namespace App\Shared\Notification;

use App\Shared\Db\Connection;
use App\Shared\Telegram\TelegramNotifier;

/**
 * Durable queue for operator notifications (Telegram today).
 *
 * Request paths (ClickHandler, PostbackController) used to call
 * TelegramNotifier::send() inline — a synchronous cURL with a 5s timeout in
 * front of a waiting visitor or partner network. They now only enqueue() a
 * row here (single INSERT into the DB the request is already writing to),
 * and the notifications:send cron drains the queue out-of-band with the
 * same exponential-backoff schedule as PostbackOutbox.
 *
 * enqueue() is best-effort by contract: a notification must never break the
 * redirect/postback it decorates, so failures are swallowed into error_log.
 */
final class NotificationOutbox
{
    private const MAX_ATTEMPTS = 5;

    /** Delay in seconds indexed by 0-based attempt number. */
    private const RETRY_DELAYS = [60, 300, 1500, 7200, 36000];

    /** Delivered rows older than this are pruned on each tick. */
    private const PRUNE_SENT_AFTER_DAYS = 7;

    public function __construct(
        private readonly Connection $db,
        private readonly TelegramNotifier $tg,
    ) {}

    /** Mirrors TelegramNotifier so callers keep one dependency, not two. */
    public function isConfigured(): bool
    {
        return $this->tg->isConfigured();
    }

    public function enqueue(string $message, string $parseMode = 'HTML'): void
    {
        if (!$this->isConfigured() || $message === '') {
            return;
        }
        try {
            $this->db->execute(
                'INSERT INTO core.notification_outbox (message, parse_mode) VALUES (:message, :parse_mode)',
                ['message' => $message, 'parse_mode' => $parseMode],
            );
        } catch (\Throwable $e) {
            error_log('[notify] outbox enqueue failed: ' . $e->getMessage());
        }
    }

    /**
     * Deliver up to $batchSize pending notifications.
     *
     * @return int number of rows processed
     */
    public function tick(int $batchSize = 50): int
    {
        $rows = $this->db->fetchAll(
            <<<'SQL'
                SELECT id, message, parse_mode, attempts
                FROM core.notification_outbox
                WHERE sent_at IS NULL
                  AND next_attempt_at <= now()
                  AND attempts < :max
                ORDER BY id ASC
                LIMIT :limit
            SQL,
            ['max' => self::MAX_ATTEMPTS, 'limit' => $batchSize],
        );

        foreach ($rows as $row) {
            $ok = $this->tg->send((string)$row['message'], (string)$row['parse_mode']);
            if ($ok) {
                $this->db->execute(
                    'UPDATE core.notification_outbox SET sent_at = now(), attempts = attempts + 1, last_error = NULL WHERE id = :id',
                    ['id' => $row['id']],
                );
                continue;
            }
            $attempt = (int)$row['attempts']; // 0-based for the delay table
            $delay = self::RETRY_DELAYS[min($attempt, count(self::RETRY_DELAYS) - 1)];
            $this->db->execute(
                <<<'SQL'
                    UPDATE core.notification_outbox
                    SET attempts = attempts + 1,
                        next_attempt_at = now() + make_interval(secs => :delay),
                        last_error = 'send failed (see app log)'
                    WHERE id = :id
                SQL,
                ['id' => $row['id'], 'delay' => $delay],
            );
        }

        $this->db->execute(
            'DELETE FROM core.notification_outbox WHERE sent_at IS NOT NULL AND sent_at < now() - make_interval(days => :d)',
            ['d' => self::PRUNE_SENT_AFTER_DAYS],
        );

        return count($rows);
    }
}
