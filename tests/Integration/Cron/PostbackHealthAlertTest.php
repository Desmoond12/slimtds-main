<?php

declare(strict_types=1);

use App\Shared\Db\Connection;

/*
 * Regression guard for the unknown_click_id alert in TelegramAlertsCommand.
 * The alert's query targeted core.postback_requests.created_at — a column that
 * does not exist (the timestamp column is received_at). The query threw every
 * hour and was swallowed by the command's try/catch, so the alert silently
 * never fired. This test runs the exact query against the real schema, so a
 * wrong column name fails loudly here instead of dying in prod cron.
 */

beforeEach(function (): void {
    pdo()->exec('DELETE FROM core.postback_requests');
});

test('postback-health query runs on the real schema and counts unknown click_id / token in the last hour', function (): void {
    $db = new Connection(pdo());

    $insert = pdo()->prepare(
        "INSERT INTO core.postback_requests (method, processing_status, http_status, received_at)
         VALUES ('GET', :st, 200, now())",
    );
    for ($i = 0; $i < 8; $i++) {
        $insert->execute(['st' => 'OK_NEW']);
    }
    for ($i = 0; $i < 3; $i++) {
        $insert->execute(['st' => 'CLICK_NOT_FOUND']);
    }
    $insert->execute(['st' => 'UNKNOWN_TOKEN']);

    // A stale row (2h ago) must be excluded by the 1-hour window.
    pdo()->prepare(
        "INSERT INTO core.postback_requests (method, processing_status, http_status, received_at)
         VALUES ('GET', 'CLICK_NOT_FOUND', 200, now() - interval '2 hours')",
    )->execute();

    // Exact query from TelegramAlertsCommand::execute().
    $row = $db->fetchOne(
        <<<'SQL'
            SELECT count(*) AS total,
                   count(*) FILTER (WHERE processing_status = 'CLICK_NOT_FOUND') AS unknown_click,
                   count(*) FILTER (WHERE processing_status = 'UNKNOWN_TOKEN')  AS unknown_token
            FROM core.postback_requests
            WHERE received_at >= now() - interval '1 hour'
        SQL,
    );

    expect((int)$row['total'])->toBe(12);            // 8 + 3 + 1, stale row excluded
    expect((int)$row['unknown_click'])->toBe(3);
    expect((int)$row['unknown_token'])->toBe(1);
    // 3/12 = 25% ≥ 20% threshold → the alert would fire.
    expect((int)$row['unknown_click'] / (int)$row['total'])->toBeGreaterThanOrEqual(0.20);
});
