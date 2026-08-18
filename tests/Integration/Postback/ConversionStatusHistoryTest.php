<?php

declare(strict_types=1);

use App\Admin\Controller\ConversionController;
use App\Admin\Repository\AffiliateNetworkRepository;
use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\ConversionRepository;
use App\Admin\Repository\OfferRepository;
use App\Engine\MacroExpander;
use App\Postback\PostbackController;
use App\Postback\PostbackOutbox;
use App\Shared\CampaignIdGenerator;
use App\Shared\Db\Connection;
use App\Shared\Notification\NotificationOutbox;
use App\Shared\Telegram\TelegramNotifier;
use App\Admin\Repository\SettingsRepository;
use App\Shared\Notification\NotificationRegistry;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

// Exercises the core.conversion_status_history trigger (see
// migrations/20260809000001_conversion_status_history.php) end-to-end via
// the real postback path, plus the admin history endpoint that reads it.

beforeEach(function (): void {
    $pdo = pdo();
    $pdo->exec('DELETE FROM core.conversion_status_history');
    $pdo->exec('DELETE FROM core.conversion_events');
    $pdo->exec('DELETE FROM core.conversions');
    $pdo->exec('DELETE FROM stats.clicks');
    $pdo->exec('DELETE FROM core.offers');
    $pdo->exec('DELETE FROM core.campaigns');
    $pdo->exec('DELETE FROM core.affiliate_networks');

    $this->db    = new Connection($pdo);
    $cRepo       = new CampaignRepository($this->db, new CampaignIdGenerator());
    $this->oRepo = new OfferRepository($this->db);

    $outbox     = new PostbackOutbox($this->db, $this->oRepo, new MacroExpander());
    $this->ctrl = new PostbackController(
        $this->oRepo,
        $cRepo,
        new AffiliateNetworkRepository($this->db),
        $this->db,
        new NotificationOutbox($this->db, new TelegramNotifier(null, null)),
        $outbox,
        new SettingsRepository($this->db),
        new NotificationRegistry(),
    );
    $this->convRepo = new ConversionRepository($this->db);
    $this->admin    = new ConversionController($this->convRepo, $cRepo);

    $this->camp  = $cRepo->create(['name' => 'CSH Campaign', 'slug' => 'csh01', 'is_active' => '1']);
    $this->offer = $this->oRepo->create(['name' => 'CSH Offer', 'url' => 'https://example.com/', 'is_active' => '1']);

    $pdo->exec(
        "INSERT INTO stats.clicks (id, campaign_id, visitor_uuid, ip)
         VALUES (uuidv7(), '{$this->camp->id}', gen_random_uuid()::uuid, '1.1.1.1')",
    );
});

function cshClickId(object $test): string
{
    /** @var object{db: Connection} $test */
    return (string)$test->db->fetchScalar('SELECT id FROM stats.clicks ORDER BY created_at DESC LIMIT 1');
}

function cshRequest(array $params): \Psr\Http\Message\ServerRequestInterface
{
    return (new ServerRequestFactory())->createServerRequest('GET', '/postback?' . http_build_query($params));
}

test('first postback writes exactly one history row via the trigger', function (): void {
    $cid = cshClickId($this);
    ($this->ctrl)(cshRequest(['subid' => $cid, 'token' => $this->offer->postbackToken, 'status' => 'pending', 'payout' => '0']), new Response());

    $convId = (string)$this->db->fetchScalar('SELECT id FROM core.conversions WHERE click_id = :cid', ['cid' => $cid]);
    $history = $this->convRepo->history($convId);

    expect($history)->toHaveCount(1);
    expect($history[0]['status'])->toBe('pending');
});

test('a real status transition appends a second history row, oldest first', function (): void {
    $cid = cshClickId($this);
    $token = $this->offer->postbackToken;

    ($this->ctrl)(cshRequest(['subid' => $cid, 'token' => $token, 'status' => 'pending', 'payout' => '0']), new Response());
    ($this->ctrl)(cshRequest(['subid' => $cid, 'token' => $token, 'status' => 'approved', 'payout' => '87.30']), new Response());

    $convId = (string)$this->db->fetchScalar('SELECT id FROM core.conversions WHERE click_id = :cid', ['cid' => $cid]);
    $history = $this->convRepo->history($convId);

    expect($history)->toHaveCount(2);
    expect($history[0]['status'])->toBe('pending');
    expect($history[1]['status'])->toBe('approved');
    expect((float)$history[1]['payout'])->toBe(87.3);
});

test('a rejected transition still preserves the earlier approved payout in history', function (): void {
    // Regression for the exact "12000 vs 11400, no explanation" gap
    // flagged in the robustness checklist — the current-state row alone
    // can no longer answer this, the history table must.
    $cid = cshClickId($this);
    $token = $this->offer->postbackToken;

    ($this->ctrl)(cshRequest(['subid' => $cid, 'token' => $token, 'status' => 'pending', 'payout' => '0']), new Response());
    ($this->ctrl)(cshRequest(['subid' => $cid, 'token' => $token, 'status' => 'approved', 'payout' => '87.30']), new Response());
    ($this->ctrl)(cshRequest(['subid' => $cid, 'token' => $token, 'status' => 'rejected', 'payout' => '0']), new Response());

    $convId = (string)$this->db->fetchScalar('SELECT id FROM core.conversions WHERE click_id = :cid', ['cid' => $cid]);
    $history = $this->convRepo->history($convId);

    expect($history)->toHaveCount(3);
    $approvedRow = array_values(array_filter($history, fn ($r) => $r['status'] === 'approved'))[0];
    expect((float)$approvedRow['payout'])->toBe(87.3);

    // Current-state row now shows rejected/0 — history is the only place
    // the transient approved/87.30 still exists.
    $current = $this->db->fetchOne('SELECT status, payout FROM core.conversions WHERE id = :id', ['id' => $convId]);
    expect($current['status'])->toBe('rejected');
});

test('identical repeat postback does not duplicate a history row', function (): void {
    $cid = cshClickId($this);
    $token = $this->offer->postbackToken;

    ($this->ctrl)(cshRequest(['subid' => $cid, 'token' => $token, 'status' => 'approved', 'payout' => '10.00']), new Response());
    ($this->ctrl)(cshRequest(['subid' => $cid, 'token' => $token, 'status' => 'approved', 'payout' => '10.00']), new Response());
    ($this->ctrl)(cshRequest(['subid' => $cid, 'token' => $token, 'status' => 'approved', 'payout' => '10.00']), new Response());

    $convId = (string)$this->db->fetchScalar('SELECT id FROM core.conversions WHERE click_id = :cid', ['cid' => $cid]);
    expect($this->convRepo->history($convId))->toHaveCount(1);
});

test('admin history endpoint returns the transition log as JSON', function (): void {
    $cid = cshClickId($this);
    $token = $this->offer->postbackToken;
    ($this->ctrl)(cshRequest(['subid' => $cid, 'token' => $token, 'status' => 'pending', 'payout' => '0']), new Response());
    ($this->ctrl)(cshRequest(['subid' => $cid, 'token' => $token, 'status' => 'approved', 'payout' => '5.00']), new Response());

    $convId = (string)$this->db->fetchScalar('SELECT id FROM core.conversions WHERE click_id = :cid', ['cid' => $cid]);

    $req  = (new ServerRequestFactory())->createServerRequest('GET', '/admin/conversions/' . $convId . '/history');
    $resp = ($this->admin)->history($req, new Response(), $convId);

    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode((string)$resp->getBody(), true);
    expect($body['ok'])->toBeTrue();
    expect($body['history'])->toHaveCount(2);
});

test('admin history endpoint rejects a malformed id with 404, not a PDO crash', function (): void {
    $req  = (new ServerRequestFactory())->createServerRequest('GET', '/admin/conversions/not-a-uuid/history');
    $resp = ($this->admin)->history($req, new Response(), 'not-a-uuid');

    expect($resp->getStatusCode())->toBe(404);
    $body = json_decode((string)$resp->getBody(), true);
    expect($body['ok'])->toBeFalse();
});

test('admin history endpoint returns an empty list for a well-formed but unknown conversion id', function (): void {
    $unknownId = \Ramsey\Uuid\Uuid::uuid7()->toString();
    $req  = (new ServerRequestFactory())->createServerRequest('GET', '/admin/conversions/' . $unknownId . '/history');
    $resp = ($this->admin)->history($req, new Response(), $unknownId);

    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode((string)$resp->getBody(), true);
    expect($body['history'])->toBe([]);
});
