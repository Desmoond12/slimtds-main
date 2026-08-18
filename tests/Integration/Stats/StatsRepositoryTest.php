<?php

declare(strict_types=1);

use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\OfferRepository;
use App\Shared\CampaignIdGenerator;
use App\Shared\Db\Connection;
use App\Stats\StatsRepository;

beforeEach(function (): void {
    $pdo = pdo();
    $pdo->exec('DELETE FROM core.conversions');
    $pdo->exec('DELETE FROM stats.clicks');
    $pdo->exec('DELETE FROM core.offers');
    $pdo->exec('DELETE FROM core.campaigns');

    $this->db    = new Connection($pdo);
    $this->stats = new StatsRepository($this->db);
    $cRepo       = new CampaignRepository($this->db, new CampaignIdGenerator());
    $oRepo       = new OfferRepository($this->db);

    $this->camp = $cRepo->create(['name' => 'Stats Campaign', 'slug' => 'stx01', 'is_active' => '1']);
    $this->offerIt = $oRepo->create(['name' => 'Offer IT', 'url' => 'https://example.com/it', 'is_active' => '1']);
    $this->offerDe = $oRepo->create(['name' => 'Offer DE', 'url' => 'https://example.com/de', 'is_active' => '1']);
});

/** Insert a click row and return its id. */
function insertStatsClick(Connection $db, string $campaignId, ?string $offerId, string $country, bool $isBot = false, bool $isUniq = true): string
{
    $row = $db->fetchOne(
        <<<'SQL'
            INSERT INTO stats.clicks (campaign_id, offer_id, visitor_uuid, ip, country, is_bot, is_uniq)
            VALUES (:c, :o, gen_random_uuid(), '1.2.3.4', :country, :bot, :uniq)
            RETURNING id
        SQL,
        ['c' => $campaignId, 'o' => $offerId, 'country' => $country, 'bot' => $isBot ? 'true' : 'false', 'uniq' => $isUniq ? 'true' : 'false'],
    );
    return (string)$row['id'];
}

test('byOfferCountry aggregates clicks and conversions per offer × country', function (): void {
    $c1 = insertStatsClick($this->db, $this->camp->id, $this->offerIt->id, 'it');
    $c2 = insertStatsClick($this->db, $this->camp->id, $this->offerIt->id, 'it');
    $c3 = insertStatsClick($this->db, $this->camp->id, $this->offerDe->id, 'de');
    $this->stats->refreshClicksHourly();

    $this->db->execute(
        "INSERT INTO core.conversions (click_id, campaign_id, offer_id, payout, status, currency)
         VALUES (:cid, :camp, :offer, '10.00', 'approved', 'USD')",
        ['cid' => $c1, 'camp' => $this->camp->id, 'offer' => $this->offerIt->id],
    );

    $rows = $this->stats->byOfferCountry(null, date('c', time() - 3600));
    $byKey = [];
    foreach ($rows as $r) {
        $byKey[($r['offer_name'] ?? '?') . '|' . $r['country']] = $r;
    }

    expect($byKey['Offer IT|it']['clicks'])->toBe(2);
    expect($byKey['Offer IT|it']['conversions'])->toBe(1);
    expect($byKey['Offer IT|it']['approved'])->toBe(1);
    expect($byKey['Offer IT|it']['payout'])->toBe('10.00');

    expect($byKey['Offer DE|de']['clicks'])->toBe(1);
    expect($byKey['Offer DE|de']['conversions'])->toBe(0);
});

test('byOfferCountry filters by campaign_id', function (): void {
    $cRepo = new CampaignRepository($this->db, new CampaignIdGenerator());
    $otherCamp = $cRepo->create(['name' => 'Other', 'slug' => 'stx02', 'is_active' => '1']);

    insertStatsClick($this->db, $this->camp->id, $this->offerIt->id, 'it');
    insertStatsClick($this->db, $otherCamp->id, $this->offerIt->id, 'fr');
    $this->stats->refreshClicksHourly();

    $rows = $this->stats->byOfferCountry($this->camp->id, date('c', time() - 3600));
    $countries = array_column($rows, 'country');

    expect($countries)->toContain('it');
    expect($countries)->not->toContain('fr');
});

test('byOfferCountry includes clicks with no offer (trash fallthrough) under no_offer', function (): void {
    insertStatsClick($this->db, $this->camp->id, null, 'es');
    $this->stats->refreshClicksHourly();

    $rows = $this->stats->byOfferCountry($this->camp->id, date('c', time() - 3600));
    $row = null;
    foreach ($rows as $r) {
        if ($r['country'] === 'es') { $row = $r; break; }
    }

    expect($row)->not->toBeNull();
    expect($row['offer_id'])->toBeNull();
    expect($row['offer_name'])->toBeNull();
    expect($row['clicks'])->toBe(1);
});

/** Insert a click row with a specific lander_host and return its id. */
function insertLanderClick(Connection $db, string $campaignId, ?string $offerId, ?string $landerHost, bool $isBot = false): string
{
    $row = $db->fetchOne(
        <<<'SQL'
            INSERT INTO stats.clicks (campaign_id, offer_id, visitor_uuid, ip, lander_host, is_bot)
            VALUES (:c, :o, gen_random_uuid(), '1.2.3.4', :lander, :bot)
            RETURNING id
        SQL,
        ['c' => $campaignId, 'o' => $offerId, 'lander' => $landerHost, 'bot' => $isBot ? 'true' : 'false'],
    );
    return (string)$row['id'];
}

test('bySite aggregates clicks and approved revenue per lander_host', function (): void {
    $c1 = insertLanderClick($this->db, $this->camp->id, $this->offerIt->id, 'site-a.com');
    insertLanderClick($this->db, $this->camp->id, $this->offerIt->id, 'site-a.com');
    $c3 = insertLanderClick($this->db, $this->camp->id, $this->offerDe->id, 'site-b.com');

    $this->db->execute(
        "INSERT INTO core.conversions (click_id, campaign_id, offer_id, payout, status, currency)
         VALUES (:cid, :camp, :offer, '25.00', 'approved', 'USD')",
        ['cid' => $c1, 'camp' => $this->camp->id, 'offer' => $this->offerIt->id],
    );
    // Pending conversion on site-b must not count toward approved payout.
    $this->db->execute(
        "INSERT INTO core.conversions (click_id, campaign_id, offer_id, payout, status, currency)
         VALUES (:cid, :camp, :offer, '99.00', 'pending', 'USD')",
        ['cid' => $c3, 'camp' => $this->camp->id, 'offer' => $this->offerDe->id],
    );

    $rows = $this->stats->bySite(null, date('c', time() - 3600));
    $byHost = [];
    foreach ($rows as $r) $byHost[$r['lander_host']] = $r;

    expect($byHost['site-a.com']['clicks'])->toBe(2);
    expect($byHost['site-a.com']['approved'])->toBe(1);
    expect($byHost['site-a.com']['payout'])->toBe('25.00');

    expect($byHost['site-b.com']['clicks'])->toBe(1);
    expect($byHost['site-b.com']['conversions'])->toBe(1);
    expect($byHost['site-b.com']['approved'])->toBe(0);
    expect($byHost['site-b.com']['payout'])->toBe('0');
});

test('bySite sorts by approved revenue descending, most successful site first', function (): void {
    $cHigh = insertLanderClick($this->db, $this->camp->id, $this->offerIt->id, 'big-earner.com');
    $cLow  = insertLanderClick($this->db, $this->camp->id, $this->offerDe->id, 'small-earner.com');
    $this->db->execute(
        "INSERT INTO core.conversions (click_id, campaign_id, offer_id, payout, status, currency) VALUES (:cid, :camp, :offer, '500.00', 'approved', 'USD')",
        ['cid' => $cHigh, 'camp' => $this->camp->id, 'offer' => $this->offerIt->id],
    );
    $this->db->execute(
        "INSERT INTO core.conversions (click_id, campaign_id, offer_id, payout, status, currency) VALUES (:cid, :camp, :offer, '5.00', 'approved', 'USD')",
        ['cid' => $cLow, 'camp' => $this->camp->id, 'offer' => $this->offerDe->id],
    );

    $rows = $this->stats->bySite(null, date('c', time() - 3600));
    expect($rows[0]['lander_host'])->toBe('big-earner.com');
});

test('bySite groups direct hits (no lander) under an empty-string bucket', function (): void {
    insertLanderClick($this->db, $this->camp->id, $this->offerIt->id, null);

    $rows = $this->stats->bySite($this->camp->id, date('c', time() - 3600));
    expect($rows)->toHaveCount(1);
    expect($rows[0]['lander_host'])->toBe('');
    expect($rows[0]['clicks'])->toBe(1);
});

test('bySite filters by campaign_id', function (): void {
    $cRepo = new CampaignRepository($this->db, new CampaignIdGenerator());
    $otherCamp = $cRepo->create(['name' => 'Other Site Test', 'slug' => 'stx03', 'is_active' => '1']);

    insertLanderClick($this->db, $this->camp->id, $this->offerIt->id, 'ours.com');
    insertLanderClick($this->db, $otherCamp->id, $this->offerIt->id, 'theirs.com');

    $rows = $this->stats->bySite($this->camp->id, date('c', time() - 3600));
    $hosts = array_column($rows, 'lander_host');

    expect($hosts)->toContain('ours.com');
    expect($hosts)->not->toContain('theirs.com');
});

test('bySite does not inflate clicks when a click has multiple conversion_events identities (REG + FTD)', function (): void {
    // core.conversions can now hold several rows per click_id (one per
    // event_type/external_id identity). A naive LEFT JOIN would fan the
    // click row out by however many conversions rows match, inflating
    // `clicks` itself, not just `conversions`. This is the regression guard.
    $c1 = insertLanderClick($this->db, $this->camp->id, $this->offerIt->id, 'multi-event.com');

    $this->db->execute(
        "INSERT INTO core.conversions (click_id, campaign_id, offer_id, event_type, payout, status, currency)
         VALUES (:cid, :camp, :offer, 'reg', '0.00', 'approved', 'USD')",
        ['cid' => $c1, 'camp' => $this->camp->id, 'offer' => $this->offerIt->id],
    );
    $this->db->execute(
        "INSERT INTO core.conversions (click_id, campaign_id, offer_id, event_type, payout, status, currency)
         VALUES (:cid, :camp, :offer, 'ftd', '40.00', 'approved', 'USD')",
        ['cid' => $c1, 'camp' => $this->camp->id, 'offer' => $this->offerIt->id],
    );

    $rows = $this->stats->bySite(null, date('c', time() - 3600));
    $byHost = [];
    foreach ($rows as $r) $byHost[$r['lander_host']] = $r;

    expect($byHost['multi-event.com']['clicks'])->toBe(1);
    expect($byHost['multi-event.com']['conversions'])->toBe(2);
    expect($byHost['multi-event.com']['approved'])->toBe(2);
    expect($byHost['multi-event.com']['payout'])->toBe('40.00');
});

test('byOfferCountry excludes rows outside the time window', function (): void {
    $c1 = insertStatsClick($this->db, $this->camp->id, $this->offerIt->id, 'it');
    // Backdate it outside the 90-day matview window and outside our query window.
    $this->db->execute(
        "UPDATE stats.clicks SET created_at = now() - interval '5 days' WHERE id = :id",
        ['id' => $c1],
    );
    $this->stats->refreshClicksHourly();

    $rows = $this->stats->byOfferCountry(null, date('c', time() - 3600)); // last 1h only
    $totalClicks = array_sum(array_column($rows, 'clicks'));
    expect($totalClicks)->toBe(0);
});
