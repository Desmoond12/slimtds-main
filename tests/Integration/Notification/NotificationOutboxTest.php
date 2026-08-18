<?php

declare(strict_types=1);

use App\Shared\Db\Connection;
use App\Shared\Notification\NotificationOutbox;
use App\Shared\Telegram\TelegramNotifier;

beforeEach(function (): void {
    $pdo = pdo();
    $pdo->exec('DELETE FROM core.notification_outbox');
    $this->db = new Connection($pdo);
});

test('enqueue inserts a pending row when TG is configured', function (): void {
    // Fake-but-present credentials: enqueue never talks to the network, it
    // only INSERTs — the actual send happens in tick() from the cron.
    $outbox = new NotificationOutbox($this->db, new TelegramNotifier('fake-token', '123'));
    $outbox->enqueue('<b>test</b> message');

    $row = $this->db->fetchOne('SELECT message, parse_mode, attempts, sent_at FROM core.notification_outbox');
    expect($row)->not->toBeNull();
    expect($row['message'])->toBe('<b>test</b> message');
    expect($row['parse_mode'])->toBe('HTML');
    expect((int)$row['attempts'])->toBe(0);
    expect($row['sent_at'])->toBeNull();
});

test('enqueue is a no-op when TG is not configured', function (): void {
    $outbox = new NotificationOutbox($this->db, new TelegramNotifier(null, null));
    $outbox->enqueue('never stored');
    expect($this->db->fetchScalar('SELECT count(*) FROM core.notification_outbox'))->toBe(0);
});

test('tick schedules an exponential retry when the send fails', function (): void {
    // Unconfigured notifier: send() returns false immediately (no cURL) —
    // exercises the failure path without touching the network.
    $failing = new NotificationOutbox($this->db, new TelegramNotifier(null, null));
    $this->db->execute("INSERT INTO core.notification_outbox (message) VALUES ('will fail')");

    $processed = $failing->tick();
    expect($processed)->toBe(1);

    $row = $this->db->fetchOne('SELECT attempts, sent_at, next_attempt_at > now() AS deferred FROM core.notification_outbox');
    expect((int)$row['attempts'])->toBe(1);
    expect($row['sent_at'])->toBeNull();
    expect((bool)$row['deferred'])->toBeTrue();
});

test('tick gives up after MAX_ATTEMPTS and prunes old sent rows', function (): void {
    $outbox = new NotificationOutbox($this->db, new TelegramNotifier(null, null));

    // Exhausted row — must not be picked up again.
    $this->db->execute("INSERT INTO core.notification_outbox (message, attempts) VALUES ('dead', 5)");
    // Old delivered row — must be pruned.
    $this->db->execute("INSERT INTO core.notification_outbox (message, sent_at) VALUES ('old sent', now() - interval '8 days')");
    // Fresh delivered row — must survive.
    $this->db->execute("INSERT INTO core.notification_outbox (message, sent_at) VALUES ('fresh sent', now())");

    $processed = $outbox->tick();
    expect($processed)->toBe(0);

    $messages = array_map(
        static fn (array $r) => $r['message'],
        $this->db->fetchAll('SELECT message FROM core.notification_outbox ORDER BY id'),
    );
    expect($messages)->toBe(['dead', 'fresh sent']);
});
