<?php

declare(strict_types=1);

use App\Admin\Repository\AffiliateNetworkRepository;
use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\OfferRepository;
use App\Admin\Repository\ReconciliationRepository;
use App\Shared\CampaignIdGenerator;
use App\Shared\Db\Connection;

beforeEach(function (): void {
    $pdo = pdo();
    $pdo->exec('DELETE FROM core.pp_reports');
    $pdo->exec('DELETE FROM core.pp_report_imports');
    $pdo->exec('DELETE FROM core.conversion_events');
    $pdo->exec('DELETE FROM core.conversions');
    $pdo->exec('DELETE FROM stats.clicks');
    $pdo->exec('DELETE FROM core.offers');
    $pdo->exec('DELETE FROM core.campaigns');
    $pdo->exec('DELETE FROM core.affiliate_networks');

    $this->db = new Connection($pdo);
    $this->repo = new ReconciliationRepository($this->db);
    $nRepo = new AffiliateNetworkRepository($this->db);
    $cRepo = new CampaignRepository($this->db, new CampaignIdGenerator());
    $oRepo = new OfferRepository($this->db);

    $this->network = $nRepo->create([
        'name' => 'Recon PP', 'is_active' => '1',
        // PP reports say "Registration"/"Deposit" — map to our reg/ftd.
        'event_map' => ['registration' => 'reg', 'deposit' => 'ftd'],
    ]);
    $this->camp = $cRepo->create(['name' => 'Recon Camp', 'slug' => 'rec01', 'is_active' => '1']);
    $this->offer = $oRepo->create([
        'name' => 'Recon Offer', 'url' => 'https://example.com/', 'is_active' => '1',
        'network_id' => $this->network->id,
    ]);

    // One PP import with two days of data.
    $importId = (string)$this->db->fetchScalar(
        "INSERT INTO core.pp_report_imports (network_id, filename, row_count) VALUES (:n, 'r.csv', 3) RETURNING id",
        ['n' => $this->network->id],
    );
    $ins = function (string $date, string $event, ?int $clicks, ?int $count, ?string $payout) use ($importId): void {
        $this->db->execute(
            "INSERT INTO core.pp_reports (import_id, network_id, report_date, event_type, clicks, count, payout, raw_row)
             VALUES (:i, :n, :d, :e, :cl, :c, :p, '{}'::jsonb)",
            ['i' => $importId, 'n' => $this->network->id, 'd' => $date, 'e' => $event, 'cl' => $clicks, 'c' => $count, 'p' => $payout],
        );
    };
    $today = (string)$this->db->fetchScalar('SELECT now()::date::text');
    $this->today = $today;
    $ins($today, 'Registration', 100, 5, '0');
    $ins($today, 'Deposit', null, 2, '150.00');

    // Tracker side: 3 clicks today + ledger events reg×5, ftd×1 (50.00).
    for ($i = 0; $i < 3; $i++) {
        $this->db->execute(
            "INSERT INTO stats.clicks (id, campaign_id, offer_id, visitor_uuid, ip) VALUES (uuidv7(), :c, :o, gen_random_uuid()::uuid, '1.1.1.1')",
            ['c' => $this->camp->id, 'o' => $this->offer->id],
        );
    }
    for ($i = 0; $i < 5; $i++) {
        $this->db->execute(
            "INSERT INTO core.conversion_events (click_id, campaign_id, offer_id, network_id, event_type, payout, status, currency)
             VALUES (NULL, :c, :o, :n, 'reg', 0, 'approved', 'USD')",
            ['c' => $this->camp->id, 'o' => $this->offer->id, 'n' => $this->network->id],
        );
    }
    $this->db->execute(
        "INSERT INTO core.conversion_events (click_id, campaign_id, offer_id, network_id, event_type, payout, status, currency)
         VALUES (NULL, :c, :o, :n, 'ftd', 50.00, 'approved', 'USD')",
        ['c' => $this->camp->id, 'o' => $this->offer->id, 'n' => $this->network->id],
    );
});

test('events joins tracker ledger vs PP rows per day+event, translating PP event names through event_map', function (): void {
    $rows = $this->repo->events($this->network->id, $this->today, $this->today);

    $byEvent = [];
    foreach ($rows as $r) {
        $byEvent[$r['event_type']] = $r;
    }

    // "Registration" (PP) merged into the same bucket as our 'reg'.
    expect($byEvent)->toHaveKey('reg');
    expect((int)$byEvent['reg']['tracker_count'])->toBe(5);
    expect((int)$byEvent['reg']['pp_count'])->toBe(5);

    // "Deposit" (PP) → 'ftd': we saw 1, they claim 2 — both sides present.
    expect($byEvent)->toHaveKey('ftd');
    expect((int)$byEvent['ftd']['tracker_count'])->toBe(1);
    expect((int)$byEvent['ftd']['pp_count'])->toBe(2);
    expect((float)$byEvent['ftd']['tracker_payout'])->toBe(50.0);
    expect((float)$byEvent['ftd']['pp_payout'])->toBe(150.0);

    // No stray un-translated 'registration'/'deposit' buckets.
    expect($byEvent)->not->toHaveKey('registration');
    expect($byEvent)->not->toHaveKey('deposit');
});

test('events falls back to the offer network link for ledger rows without network_id', function (): void {
    // Historical row written before conversions.network_id existed.
    $this->db->execute(
        "INSERT INTO core.conversion_events (click_id, campaign_id, offer_id, network_id, event_type, payout, status, currency)
         VALUES (NULL, :c, :o, NULL, 'reg', 0, 'approved', 'USD')",
        ['c' => $this->camp->id, 'o' => $this->offer->id],
    );
    $rows = $this->repo->events($this->network->id, $this->today, $this->today);
    $reg = null;
    foreach ($rows as $r) {
        if ($r['event_type'] === 'reg') $reg = $r;
    }
    expect((int)$reg['tracker_count'])->toBe(6);
});

test('clicks compares our redirect counts with the PP clicks column', function (): void {
    $rows = $this->repo->clicks($this->network->id, $this->today, $this->today);
    expect($rows)->toHaveCount(1);
    expect((int)$rows[0]['tracker_clicks'])->toBe(3);
    expect((int)$rows[0]['pp_clicks'])->toBe(100);
});

test('defaultRange spans the imported PP data, and falls back to last 30 days without data', function (): void {
    [$from, $to] = $this->repo->defaultRange($this->network->id);
    expect($from)->toBe($this->today);
    expect($to)->toBe($this->today);

    $this->db->execute('DELETE FROM core.pp_reports');
    [$from2, $to2] = $this->repo->defaultRange($this->network->id);
    expect($to2)->toBe($this->today);
    expect($from2 < $to2)->toBeTrue();
});

test('a PP-only day shows with empty tracker side (nothing recorded by us)', function (): void {
    $rows = $this->repo->events($this->network->id, '2020-01-01', '2020-01-02');
    expect($rows)->toBe([]);

    $importId = (string)$this->db->fetchScalar(
        "INSERT INTO core.pp_report_imports (network_id, filename, row_count) VALUES (:n, 'old.csv', 1) RETURNING id",
        ['n' => $this->network->id],
    );
    $this->db->execute(
        "INSERT INTO core.pp_reports (import_id, network_id, report_date, event_type, count, payout, raw_row)
         VALUES (:i, :n, '2020-01-01', 'Deposit', 4, '99.00', '{}'::jsonb)",
        ['i' => $importId, 'n' => $this->network->id],
    );
    $rows = $this->repo->events($this->network->id, '2020-01-01', '2020-01-02');
    expect($rows)->toHaveCount(1);
    expect($rows[0]['event_type'])->toBe('ftd');
    expect($rows[0]['tracker_count'])->toBeNull();
    expect((int)$rows[0]['pp_count'])->toBe(4);
});
