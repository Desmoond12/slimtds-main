<?php

declare(strict_types=1);

use App\Admin\Repository\AffiliateNetworkRepository;
use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\OfferRepository;
use App\Engine\MacroExpander;
use App\Postback\PostbackController;
use App\Postback\PostbackOutbox;
use App\Shared\CampaignIdGenerator;
use App\Shared\Db\Connection;
use App\Shared\Telegram\TelegramNotifier;
use App\Admin\Repository\SettingsRepository;
use App\Shared\Notification\NotificationRegistry;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

beforeEach(function (): void {
    $pdo = pdo();
    $pdo->exec('DELETE FROM core.postback_requests');
    $pdo->exec('DELETE FROM core.conversion_events');
    $pdo->exec('DELETE FROM core.conversions');
    $pdo->exec('DELETE FROM stats.clicks');
    $pdo->exec('DELETE FROM core.offers');
    $pdo->exec('DELETE FROM core.campaigns');
    $pdo->exec('DELETE FROM core.affiliate_networks');

    $this->db   = new Connection($pdo);
    $cRepo      = new CampaignRepository($this->db, new CampaignIdGenerator());
    $this->oRepo = new OfferRepository($this->db);
    $this->networksRepo = new AffiliateNetworkRepository($this->db);

    $outbox     = new PostbackOutbox($this->db, $this->oRepo, new MacroExpander());
    $this->ctrl = new PostbackController(
        $this->oRepo,
        $cRepo,
        $this->networksRepo,
        $this->db,
        new TelegramNotifier(null, null),
        $outbox,
        new SettingsRepository($this->db),
        new NotificationRegistry(),
    );
    $this->cRepo = $cRepo;

    $this->camp  = $cRepo->create(['name' => 'PB Campaign', 'slug' => 'pb01', 'is_active' => '1']);
    $this->offer = $this->oRepo->create([
        'name'      => 'PB Offer',
        'url'       => 'https://example.com/',
        'is_active' => '1',
    ]);

    // Insert a click row directly for the current month partition
    // visitor_uuid and ip are NOT NULL
    $pdo->exec(
        "INSERT INTO stats.clicks (id, campaign_id, visitor_uuid, ip)
         VALUES (uuidv7(), '{$this->camp->id}', gen_random_uuid()::uuid, '1.1.1.1')",
    );
});

function pbRequest(array $params): \Psr\Http\Message\ServerRequestInterface
{
    $uri = '/postback?' . http_build_query($params);
    return (new ServerRequestFactory())->createServerRequest('GET', $uri);
}

// Helper to get (and cache per test) a stable click id inserted in beforeEach
function clickId(object $test): string
{
    /** @var object{db: Connection} $test */
    return (string)$test->db->fetchScalar(
        "SELECT id FROM stats.clicks ORDER BY created_at DESC LIMIT 1",
    );
}

test('happy path: postback creates conversion and returns ok+updated=false', function (): void {
    $cid   = clickId($this);
    $token = $this->offer->postbackToken;

    $req  = pbRequest(['subid' => $cid, 'token' => $token, 'payout' => '5.50', 'status' => 'approved']);
    $resp = ($this->ctrl)($req, new Response());

    expect($resp->getStatusCode())->toBe(200);

    $body = json_decode((string)$resp->getBody(), true);
    expect($body['ok'])->toBeTrue();
    expect($body['updated'])->toBeFalse();

    $row = $this->db->fetchOne(
        'SELECT payout, status FROM core.conversions WHERE click_id = :cid',
        ['cid' => $cid],
    );
    expect($row)->not->toBeNull();
    expect((float)$row['payout'])->toBe(5.5);
    expect($row['status'])->toBe('approved');
});

test('second postback updates existing row, updated=true, count stays 1', function (): void {
    $cid   = clickId($this);
    $token = $this->offer->postbackToken;

    // First call
    ($this->ctrl)(pbRequest(['subid' => $cid, 'token' => $token, 'payout' => '5.50', 'status' => 'approved']), new Response());

    // Second call
    $resp = ($this->ctrl)(pbRequest(['subid' => $cid, 'token' => $token, 'payout' => '7.00', 'status' => 'approved']), new Response());

    expect($resp->getStatusCode())->toBe(200);

    $body = json_decode((string)$resp->getBody(), true);
    expect($body['ok'])->toBeTrue();
    expect($body['updated'])->toBeTrue();

    $count = (int)$this->db->fetchScalar(
        'SELECT count(*) FROM core.conversions WHERE click_id = :cid',
        ['cid' => $cid],
    );
    expect($count)->toBe(1);

    $row = $this->db->fetchOne(
        'SELECT payout FROM core.conversions WHERE click_id = :cid',
        ['cid' => $cid],
    );
    expect((float)$row['payout'])->toBe(7.0);
});

test('unknown explicit status is rejected with 400, not silently coerced to approved', function (): void {
    $cid   = clickId($this);
    $token = $this->offer->postbackToken;

    $req  = pbRequest(['subid' => $cid, 'token' => $token, 'status' => 'aproved']); // typo
    $resp = ($this->ctrl)($req, new Response());

    expect($resp->getStatusCode())->toBe(400);
    $body = json_decode((string)$resp->getBody(), true);
    expect($body['ok'])->toBeFalse();

    $count = (int)$this->db->fetchScalar('SELECT count(*) FROM core.conversions WHERE click_id = :cid', ['cid' => $cid]);
    expect($count)->toBe(0);
});

test('missing status param still defaults to approved (backward compatible)', function (): void {
    $cid   = clickId($this);
    $token = $this->offer->postbackToken;

    $req  = pbRequest(['subid' => $cid, 'token' => $token]);
    $resp = ($this->ctrl)($req, new Response());

    expect($resp->getStatusCode())->toBe(200);
    $row = $this->db->fetchOne('SELECT status FROM core.conversions WHERE click_id = :cid', ['cid' => $cid]);
    expect($row['status'])->toBe('approved');
});

test('unknown token returns 404', function (): void {
    $cid = clickId($this);

    $req  = pbRequest(['subid' => $cid, 'token' => 'totally_invalid_token_xyz']);
    $resp = ($this->ctrl)($req, new Response());

    expect($resp->getStatusCode())->toBe(404);
});

test('malformed (non-uuid) subid is rejected with 400, not a PDO crash', function (): void {
    $token = $this->offer->postbackToken;

    $req  = pbRequest(['subid' => 'not-a-uuid-at-all', 'token' => $token, 'status' => 'approved', 'payout' => '10']);
    $resp = ($this->ctrl)($req, new Response());

    expect($resp->getStatusCode())->toBe(400);
    $body = json_decode((string)$resp->getBody(), true);
    expect($body['ok'])->toBeFalse();
    expect($body['error'])->toBe('invalid subid');
});

test('payout that overflows numeric(10,2) is rejected with 400, not a PDO crash', function (): void {
    $cid   = clickId($this);
    $token = $this->offer->postbackToken;

    $req  = pbRequest(['subid' => $cid, 'token' => $token, 'status' => 'approved', 'payout' => '999999999999999']);
    $resp = ($this->ctrl)($req, new Response());

    expect($resp->getStatusCode())->toBe(400);
    $body = json_decode((string)$resp->getBody(), true);
    expect($body['error'])->toBe('invalid payout');

    $count = (int)$this->db->fetchScalar('SELECT count(*) FROM core.conversions WHERE click_id = :cid', ['cid' => $cid]);
    expect($count)->toBe(0);
});

test('non-finite payout (scientific-notation overflow to INF) is rejected with 400', function (): void {
    $cid   = clickId($this);
    $token = $this->offer->postbackToken;

    $req  = pbRequest(['subid' => $cid, 'token' => $token, 'status' => 'approved', 'payout' => '1e400']);
    $resp = ($this->ctrl)($req, new Response());

    expect($resp->getStatusCode())->toBe(400);
});

test('repeat postback with identical status does not re-enqueue outbound S2S delivery', function (): void {
    $offer = $this->oRepo->create([
        'name' => 'PB Offer w/ postback', 'url' => 'https://example.com/', 'is_active' => '1',
        'postback_urls' => ['https://network.example.com/cb?sub={click_id}&status={status}'],
    ]);
    $this->db->execute(
        "INSERT INTO stats.clicks (id, campaign_id, visitor_uuid, ip) VALUES (uuidv7(), '{$this->camp->id}', gen_random_uuid()::uuid, '1.1.1.1')",
    );
    $cid = clickId($this);

    ($this->ctrl)(pbRequest(['subid' => $cid, 'token' => $offer->postbackToken, 'status' => 'approved']), new Response());
    $countAfterFirst = (int)$this->db->fetchScalar('SELECT count(*) FROM core.postback_deliveries');
    expect($countAfterFirst)->toBe(1);

    // Identical status resent — must not fire another outbound delivery row.
    ($this->ctrl)(pbRequest(['subid' => $cid, 'token' => $offer->postbackToken, 'status' => 'approved']), new Response());
    $countAfterRepeat = (int)$this->db->fetchScalar('SELECT count(*) FROM core.postback_deliveries');
    expect($countAfterRepeat)->toBe(1);
});

test('genuine status transition still enqueues a new outbound S2S delivery', function (): void {
    $offer = $this->oRepo->create([
        'name' => 'PB Offer w/ postback 2', 'url' => 'https://example.com/', 'is_active' => '1',
        'postback_urls' => ['https://network.example.com/cb?sub={click_id}&status={status}'],
    ]);
    $this->db->execute(
        "INSERT INTO stats.clicks (id, campaign_id, visitor_uuid, ip) VALUES (uuidv7(), '{$this->camp->id}', gen_random_uuid()::uuid, '1.1.1.1')",
    );
    $cid = clickId($this);

    ($this->ctrl)(pbRequest(['subid' => $cid, 'token' => $offer->postbackToken, 'status' => 'pending']), new Response());
    ($this->ctrl)(pbRequest(['subid' => $cid, 'token' => $offer->postbackToken, 'status' => 'approved']), new Response());

    $count = (int)$this->db->fetchScalar('SELECT count(*) FROM core.postback_deliveries');
    expect($count)->toBe(2);
});

test('concurrent postbacks for the same new click_id are serialized by an advisory lock, not double-processed', function (): void {
    // Regression for a live-confirmed race: PostbackController wraps its
    // read-previousStatus-then-UPSERT sequence in a transaction that opens
    // with pg_advisory_xact_lock(hashtext('conversion:'.click_id)) — see
    // PostbackController.php. Before that fix, 15 real concurrent identical
    // postbacks against a running instance produced 1 conversion row but 4
    // outbound S2S deliveries (each request's read of the "does a row with
    // this status already exist" check raced ahead of the others' writes).
    // This test proves the lock primitive itself actually blocks a second
    // connection while a first one holds it, using two independent raw PDO
    // connections under explicit transactions — no sleep-based timing.
    $cid = clickId($this);
    $key = 'conversion:' . $cid;
    $dsn = $_ENV['DB_DSN'] ?? 'pgsql:host=db;port=5432;dbname=slimtds';
    $user = $_ENV['DB_USER'] ?? 'slimtds';
    $pass = $_ENV['DB_PASSWORD'] ?? 'slimtds';
    $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

    $pdoA = new PDO($dsn, $user, $pass, $opts);
    $pdoB = new PDO($dsn, $user, $pass, $opts);

    // Connection A takes the lock and holds it open (uncommitted transaction) —
    // simulates a request that is mid-way through the critical section.
    $pdoA->beginTransaction();
    $pdoA->query('SELECT pg_advisory_xact_lock(hashtext(' . $pdoA->quote($key) . '))');

    // Connection B tries the same lock non-blockingly — must fail while A holds it.
    $pdoB->beginTransaction();
    $gotLockWhileHeld = (bool)$pdoB->query(
        'SELECT pg_try_advisory_xact_lock(hashtext(' . $pdoB->quote($key) . '))',
    )->fetchColumn();
    expect($gotLockWhileHeld)->toBeFalse();
    $pdoB->rollBack();

    // Connection A commits — releases its advisory lock (xact-scoped).
    $pdoA->commit();

    // Now that A released it, B must be able to acquire it immediately.
    $pdoB->beginTransaction();
    $gotLockAfterRelease = (bool)$pdoB->query(
        'SELECT pg_try_advisory_xact_lock(hashtext(' . $pdoB->quote($key) . '))',
    )->fetchColumn();
    expect($gotLockAfterRelease)->toBeTrue();
    $pdoB->commit();
});

test('every postback outcome is logged to core.postback_requests, success and failure alike', function (): void {
    $cid = clickId($this);

    // Success — first-ever conversion for this click.
    ($this->ctrl)(pbRequest(['subid' => $cid, 'token' => $this->offer->postbackToken, 'status' => 'approved', 'payout' => '5']), new Response());
    // Failure — unknown token, never even resolves subid/offer.
    ($this->ctrl)(pbRequest(['subid' => $cid, 'token' => 'garbage']), new Response());
    // Failure — malformed subid.
    ($this->ctrl)(pbRequest(['subid' => 'not-a-uuid', 'token' => $this->offer->postbackToken]), new Response());

    $rows = $this->db->fetchAll('SELECT processing_status, http_status FROM core.postback_requests ORDER BY id');
    expect($rows)->toHaveCount(3);
    expect($rows[0]['processing_status'])->toBe('OK_NEW');
    expect($rows[0]['http_status'])->toBe(200);
    expect($rows[1]['processing_status'])->toBe('UNKNOWN_TOKEN');
    expect($rows[1]['http_status'])->toBe(404);
    expect($rows[2]['processing_status'])->toBe('INVALID_SUBID');
    expect($rows[2]['http_status'])->toBe(400);
});

test('a repeat identical postback is logged as OK_DUPLICATE, a real transition as OK_TRANSITION', function (): void {
    $cid = clickId($this);
    $token = $this->offer->postbackToken;

    ($this->ctrl)(pbRequest(['subid' => $cid, 'token' => $token, 'status' => 'pending']), new Response());
    ($this->ctrl)(pbRequest(['subid' => $cid, 'token' => $token, 'status' => 'pending']), new Response()); // identical resend
    ($this->ctrl)(pbRequest(['subid' => $cid, 'token' => $token, 'status' => 'approved']), new Response()); // real transition

    $statuses = array_column(
        $this->db->fetchAll('SELECT processing_status FROM core.postback_requests ORDER BY id'),
        'processing_status',
    );
    expect($statuses)->toBe(['OK_NEW', 'OK_DUPLICATE', 'OK_TRANSITION']);
});

test('logging an OFFER_GONE outcome does not fail on the dangling offer_id FK', function (): void {
    // Campaign-token path: click was bound to an offer that has since been
    // deleted. logRequest() must log offer_id=null here (not the click's
    // stale offer_id) since core.postback_requests.offer_id FKs to
    // core.offers — referencing a row that no longer exists would violate
    // the constraint and (via the catch-all try/catch) silently swallow the
    // one log entry that matters most for this failure mode.
    $cRepo = new CampaignRepository($this->db, new CampaignIdGenerator());
    $campaignToken = $this->db->fetchScalar('SELECT postback_token FROM core.campaigns WHERE id = :id', ['id' => $this->camp->id]);

    $goneOfferId = $this->oRepo->create(['name' => 'Soon gone', 'url' => 'https://example.com/', 'is_active' => '1'])->id;
    $cid2 = (string)\Ramsey\Uuid\Uuid::uuid7();
    $this->db->execute(
        "INSERT INTO stats.clicks (id, campaign_id, offer_id, visitor_uuid, ip) VALUES (:id, :cid, :oid, gen_random_uuid()::uuid, '1.1.1.1')",
        ['id' => $cid2, 'cid' => $this->camp->id, 'oid' => $goneOfferId],
    );
    $this->db->execute('DELETE FROM core.offers WHERE id = :id', ['id' => $goneOfferId]);

    $resp = ($this->ctrl)(pbRequest(['subid' => $cid2, 'token' => (string)$campaignToken]), new Response());
    expect($resp->getStatusCode())->toBe(410);

    $row = $this->db->fetchOne('SELECT processing_status, offer_id FROM core.postback_requests ORDER BY id DESC LIMIT 1');
    expect($row)->not->toBeNull();
    expect($row['processing_status'])->toBe('OFFER_GONE');
    expect($row['offer_id'])->toBeNull();
});

test('missing event_type defaults to "conversion" (backward compatible)', function (): void {
    $cid   = clickId($this);
    $token = $this->offer->postbackToken;

    ($this->ctrl)(pbRequest(['subid' => $cid, 'token' => $token, 'status' => 'approved']), new Response());

    $row = $this->db->fetchOne('SELECT event_type FROM core.conversions WHERE click_id = :cid', ['cid' => $cid]);
    expect($row['event_type'])->toBe('conversion');
});

test('REG then FTD on the same click_id are two distinct conversions rows, neither overwrites the other', function (): void {
    $cid   = clickId($this);
    $token = $this->offer->postbackToken;

    ($this->ctrl)(pbRequest(['subid' => $cid, 'token' => $token, 'event_type' => 'reg', 'status' => 'approved', 'payout' => '0']), new Response());
    ($this->ctrl)(pbRequest(['subid' => $cid, 'token' => $token, 'event_type' => 'ftd', 'status' => 'approved', 'payout' => '25.00']), new Response());

    $rows = $this->db->fetchAll(
        'SELECT event_type, payout FROM core.conversions WHERE click_id = :cid ORDER BY event_type',
        ['cid' => $cid],
    );
    expect($rows)->toHaveCount(2);
    expect(array_column($rows, 'event_type'))->toBe(['ftd', 'reg']);

    $ledgerCount = (int)$this->db->fetchScalar('SELECT count(*) FROM core.conversion_events WHERE click_id = :cid', ['cid' => $cid]);
    expect($ledgerCount)->toBe(2);
});

test('two redeposits with distinct external_id are kept as separate conversions rows', function (): void {
    $cid   = clickId($this);
    $token = $this->offer->postbackToken;

    ($this->ctrl)(pbRequest(['subid' => $cid, 'token' => $token, 'event_type' => 'redeposit', 'external_id' => 'txn-1', 'status' => 'approved', 'payout' => '10']), new Response());
    ($this->ctrl)(pbRequest(['subid' => $cid, 'token' => $token, 'event_type' => 'redeposit', 'external_id' => 'txn-2', 'status' => 'approved', 'payout' => '15']), new Response());

    $count = (int)$this->db->fetchScalar(
        "SELECT count(*) FROM core.conversions WHERE click_id = :cid AND event_type = 'redeposit'",
        ['cid' => $cid],
    );
    expect($count)->toBe(2);

    $ledgerCount = (int)$this->db->fetchScalar('SELECT count(*) FROM core.conversion_events WHERE click_id = :cid', ['cid' => $cid]);
    expect($ledgerCount)->toBe(2);
});

test('an identical repostback (same event_type/external_id/status/payout) does not grow the ledger or re-fire outbound S2S', function (): void {
    $offer = $this->oRepo->create([
        'name' => 'PB Offer w/ postback ledger', 'url' => 'https://example.com/', 'is_active' => '1',
        'postback_urls' => ['https://network.example.com/cb?sub={click_id}&status={status}'],
    ]);
    $this->db->execute(
        "INSERT INTO stats.clicks (id, campaign_id, visitor_uuid, ip) VALUES (uuidv7(), '{$this->camp->id}', gen_random_uuid()::uuid, '1.1.1.1')",
    );
    $cid = clickId($this);

    ($this->ctrl)(pbRequest(['subid' => $cid, 'token' => $offer->postbackToken, 'event_type' => 'ftd', 'status' => 'approved', 'payout' => '20']), new Response());
    ($this->ctrl)(pbRequest(['subid' => $cid, 'token' => $offer->postbackToken, 'event_type' => 'ftd', 'status' => 'approved', 'payout' => '20']), new Response());

    $ledgerCount = (int)$this->db->fetchScalar('SELECT count(*) FROM core.conversion_events WHERE click_id = :cid', ['cid' => $cid]);
    expect($ledgerCount)->toBe(1);

    $deliveries = (int)$this->db->fetchScalar('SELECT count(*) FROM core.postback_deliveries');
    expect($deliveries)->toBe(1);
});

test('a same-status repostback with a different payout still lands in the ledger', function (): void {
    $cid   = clickId($this);
    $token = $this->offer->postbackToken;

    ($this->ctrl)(pbRequest(['subid' => $cid, 'token' => $token, 'event_type' => 'redeposit', 'status' => 'approved', 'payout' => '10']), new Response());
    ($this->ctrl)(pbRequest(['subid' => $cid, 'token' => $token, 'event_type' => 'redeposit', 'status' => 'approved', 'payout' => '30']), new Response());

    $ledgerCount = (int)$this->db->fetchScalar('SELECT count(*) FROM core.conversion_events WHERE click_id = :cid', ['cid' => $cid]);
    expect($ledgerCount)->toBe(2);

    $row = $this->db->fetchOne(
        "SELECT payout FROM core.conversions WHERE click_id = :cid AND event_type = 'redeposit'",
        ['cid' => $cid],
    );
    expect((float)$row['payout'])->toBe(30.0);
});

test('anonymous campaign-ping (no subid) writes event_type into both conversions and the ledger', function (): void {
    $campaignToken = $this->db->fetchScalar('SELECT postback_token FROM core.campaigns WHERE id = :id', ['id' => $this->camp->id]);

    $resp = ($this->ctrl)(pbRequest(['token' => (string)$campaignToken, 'event_type' => 'reg', 'status' => 'approved', 'payout' => '0']), new Response());
    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode((string)$resp->getBody(), true);
    expect($body['mode'])->toBe('campaign-ping');

    $row = $this->db->fetchOne(
        "SELECT event_type FROM core.conversions WHERE campaign_id = :cid AND click_id IS NULL",
        ['cid' => $this->camp->id],
    );
    expect($row['event_type'])->toBe('reg');

    $ledgerRow = $this->db->fetchOne(
        "SELECT event_type FROM core.conversion_events WHERE campaign_id = :cid AND click_id IS NULL",
        ['cid' => $this->camp->id],
    );
    expect($ledgerRow['event_type'])->toBe('reg');
});

test('an offer linked to a network with custom param names is parsed correctly', function (): void {
    $network = $this->networksRepo->create([
        'name'              => 'Custom PP',
        'click_param'       => 'clickid',
        'status_param'      => 'event',
        'payout_param'      => 'amount',
        'external_id_param' => 'txn',
        'event_type_param'  => 'etype',
        'status_map'        => ['ok' => 'approved'],
        'is_active'         => '1',
    ]);
    $offer = $this->oRepo->create([
        'name' => 'Custom PP Offer', 'url' => 'https://example.com/', 'is_active' => '1',
        'network_id' => $network->id,
    ]);
    $this->db->execute(
        "INSERT INTO stats.clicks (id, campaign_id, offer_id, visitor_uuid, ip) VALUES (uuidv7(), '{$this->camp->id}', '{$offer->id}', gen_random_uuid()::uuid, '1.1.1.1')",
    );
    $cid = clickId($this);

    // Note: no subid/status/payout/external_id/event_type params — this
    // network doesn't use our default names at all.
    $req  = pbRequest(['clickid' => $cid, 'token' => $offer->postbackToken, 'event' => 'ok', 'amount' => '25.50', 'txn' => 'T1', 'etype' => 'ftd']);
    $resp = ($this->ctrl)($req, new Response());

    expect($resp->getStatusCode())->toBe(200);
    $row = $this->db->fetchOne(
        "SELECT status, payout, external_id, event_type FROM core.conversions WHERE click_id = :cid",
        ['cid' => $cid],
    );
    expect($row)->not->toBeNull();
    expect($row['status'])->toBe('approved');
    expect((float)$row['payout'])->toBe(25.5);
    expect($row['external_id'])->toBe('T1');
    expect($row['event_type'])->toBe('ftd');
});

test('a network status value not covered by its status_map is rejected with 400', function (): void {
    $network = $this->networksRepo->create([
        'name' => 'Strict PP', 'status_param' => 'event', 'status_map' => ['1' => 'approved'], 'is_active' => '1',
    ]);
    $offer = $this->oRepo->create(['name' => 'Strict Offer', 'url' => 'https://example.com/', 'is_active' => '1', 'network_id' => $network->id]);
    $cid = clickId($this);

    $req  = pbRequest(['subid' => $cid, 'token' => $offer->postbackToken, 'event' => '99']);
    $resp = ($this->ctrl)($req, new Response());

    expect($resp->getStatusCode())->toBe(400);
    $body = json_decode((string)$resp->getBody(), true);
    expect($body['error'])->toBe('invalid status');
});

test('an inactive network falls back to default param names', function (): void {
    $network = $this->networksRepo->create([
        'name' => 'Disabled PP', 'click_param' => 'clickid', 'is_active' => '0',
    ]);
    $offer = $this->oRepo->create(['name' => 'Disabled Offer', 'url' => 'https://example.com/', 'is_active' => '1', 'network_id' => $network->id]);
    $this->db->execute(
        "INSERT INTO stats.clicks (id, campaign_id, offer_id, visitor_uuid, ip) VALUES (uuidv7(), '{$this->camp->id}', '{$offer->id}', gen_random_uuid()::uuid, '1.1.1.1')",
    );
    $cid = clickId($this);

    // Sent under the DEFAULT name (subid), not the network's configured
    // (but inactive) clickid — should still resolve since inactive networks
    // don't apply their mapping.
    $resp = ($this->ctrl)(pbRequest(['subid' => $cid, 'token' => $offer->postbackToken, 'status' => 'approved']), new Response());

    expect($resp->getStatusCode())->toBe(200);
    $count = (int)$this->db->fetchScalar('SELECT count(*) FROM core.conversions WHERE click_id = :cid', ['cid' => $cid]);
    expect($count)->toBe(1);
});

test('shared offer accepts postback for click from any campaign', function (): void {
    $cid = clickId($this);

    // Same offer is reused by another campaign's flow; postback for camp1 click + same token works
    $db    = $this->db;
    $cRepo = new CampaignRepository($db, new CampaignIdGenerator());

    $camp2 = $cRepo->create(['name' => 'Other Camp', 'slug' => 'pb02', 'is_active' => '1']);
    // Note: $this->offer is global. We don't need to recreate.

    $req  = pbRequest(['subid' => $cid, 'token' => $this->offer->postbackToken, 'payout' => '4.00']);
    $resp = ($this->ctrl)($req, new Response());

    expect($resp->getStatusCode())->toBe(200);
    $row = $this->db->fetchOne('SELECT campaign_id FROM core.conversions WHERE click_id = :cid', ['cid' => $cid]);
    expect($row['campaign_id'])->toBe($this->camp->id); // conversion attributed to click's campaign
});
