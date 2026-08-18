<?php

declare(strict_types=1);

use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\FlowRepository;
use App\Admin\Repository\OfferRepository;
use App\Engine\BotDetector;
use App\Engine\ClickHandler;
use App\Engine\DeviceDetector;
use App\Engine\FilterCompiler;
use App\Engine\FlowMatcher;
use App\Engine\GeoLookup;
use App\Engine\MacroExpander;
use App\Engine\OfferPicker;
use App\Engine\Schema\SchemaRegistry;
use App\Engine\VisitorResolver;
use App\Shared\CampaignIdGenerator;
use App\Admin\Repository\SettingsRepository;
use App\Shared\Db\Connection;
use App\Shared\Notification\NotificationRegistry;
use App\Shared\Telegram\TelegramNotifier;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

beforeEach(function (): void {
    $pdo = pdo();
    $pdo->exec('DELETE FROM stats.clicks');
    $pdo->exec('DELETE FROM stats.visitors_fingerprints');
    $pdo->exec('DELETE FROM core.flows');
    $pdo->exec('DELETE FROM core.offers');
    $pdo->exec('DELETE FROM core.campaigns');
    $this->db = new Connection($pdo);
    $cRepo = new CampaignRepository($this->db, new CampaignIdGenerator());
    $oRepo = new OfferRepository($this->db);
    $fRepo = new FlowRepository($this->db);
    $compiler = new FilterCompiler();
    $this->handler = new ClickHandler(
        $cRepo, $oRepo,
        new VisitorResolver($this->db),
        new DeviceDetector(),
        new GeoLookup(),
        new BotDetector($this->db),
        new FlowMatcher($fRepo, $compiler),
        new OfferPicker(),
        new MacroExpander(),
        new SchemaRegistry(),
        $this->db,
        new TelegramNotifier(null, null),
        new SettingsRepository($this->db),
        new NotificationRegistry(),
    );

    $this->camp = $cRepo->create(['name' => 'Click test', 'slug' => 'clkt01', 'is_active' => '1']);
    $this->offer = $oRepo->create(['name' => 'O', 'url' => 'https://example.com/?c={country}&cid={click_id}', 'is_active' => '1']);
    $fRepo->create($this->camp->id, [
        'name' => 'all → offer',
        'filters' => [],
        'target_type' => 'offers',
        'target_offers' => [['offer_id' => $this->offer->id, 'weight' => 100]],
        'schema_id' => 2,
        'is_active' => '1',
    ]);
});

test('valid slug routes to offer with 302 + macros expanded', function (): void {
    $req = (new ServerRequestFactory())->createServerRequest('GET', '/clkt01');
    $req = $req->withParsedBody(null);
    $resp = $this->handler->handle($req, new Response(), 'clkt01');
    expect($resp->getStatusCode())->toBe(302);
    $loc = $resp->getHeaderLine('Location');
    expect($loc)->toStartWith('https://example.com/');
    expect($loc)->toContain('cid=');
});

test('unknown slug returns 404 (not trash 200)', function (): void {
    $req = (new ServerRequestFactory())->createServerRequest('GET', '/nonexistent');
    $resp = $this->handler->handle($req, new Response(), 'nonexistent');
    expect($resp->getStatusCode())->toBe(404);
});

test('inactive campaign returns 404 regardless of trash_mode', function (): void {
    $this->db->execute('UPDATE core.campaigns SET is_active = false WHERE id = :id', ['id' => $this->camp->id]);
    $req = (new ServerRequestFactory())->createServerRequest('GET', '/clkt01');
    $resp = $this->handler->handle($req, new Response(), 'clkt01');
    expect($resp->getStatusCode())->toBe(404);
});

test('trash {offer:name} redirects to the offer url, macro-expanded', function (): void {
    $cRepo = new CampaignRepository($this->db, new CampaignIdGenerator());
    $c2 = $cRepo->create(['name' => 'Trash offer', 'slug' => 'trsh01', 'is_active' => '1']);
    // No flow on this campaign → falls through to trash. Mode 1 = 302; the URL
    // references the offer by name, which must resolve to the offer's own URL.
    $this->db->execute(
        'UPDATE core.campaigns SET trash_mode = 1, trash_url = :u WHERE id = :id',
        ['u' => '{offer:O}', 'id' => $c2->id],
    );
    $resp = $this->handler->handle(
        (new ServerRequestFactory())->createServerRequest('GET', '/trsh01'),
        new Response(),
        'trsh01',
    );
    expect($resp->getStatusCode())->toBe(302);
    $loc = $resp->getHeaderLine('Location');
    expect($loc)->toStartWith('https://example.com/');
    expect($loc)->toContain('cid=');          // offer's {click_id} was expanded
    expect($loc)->not->toContain('{offer:');  // reference resolved, not passed through literally
});

test('trash {offer:missing} falls back to 204 (no leaked macro)', function (): void {
    $cRepo = new CampaignRepository($this->db, new CampaignIdGenerator());
    $c2 = $cRepo->create(['name' => 'Trash miss', 'slug' => 'trsh02', 'is_active' => '1']);
    $this->db->execute(
        'UPDATE core.campaigns SET trash_mode = 1, trash_url = :u WHERE id = :id',
        ['u' => '{offer:NoSuchOffer}', 'id' => $c2->id],
    );
    $resp = $this->handler->handle(
        (new ServerRequestFactory())->createServerRequest('GET', '/trsh02'),
        new Response(),
        'trsh02',
    );
    expect($resp->getStatusCode())->toBe(204);
    expect($resp->getHeaderLine('Location'))->toBe('');
});

test('trash plain url is macro-expanded', function (): void {
    $cRepo = new CampaignRepository($this->db, new CampaignIdGenerator());
    $c2 = $cRepo->create(['name' => 'Trash url', 'slug' => 'trsh03', 'is_active' => '1']);
    $this->db->execute(
        'UPDATE core.campaigns SET trash_mode = 1, trash_url = :u WHERE id = :id',
        ['u' => 'https://fallback.example/?cid={click_id}', 'id' => $c2->id],
    );
    $resp = $this->handler->handle(
        (new ServerRequestFactory())->createServerRequest('GET', '/trsh03'),
        new Response(),
        'trsh03',
    );
    expect($resp->getStatusCode())->toBe(302);
    $loc = $resp->getHeaderLine('Location');
    expect($loc)->toStartWith('https://fallback.example/?cid=');
    expect($loc)->not->toContain('{click_id}');
});

test('click is logged in stats.clicks', function (): void {
    $req = (new ServerRequestFactory())->createServerRequest('GET', '/clkt01');
    $this->handler->handle($req, new Response(), 'clkt01');
    $count = (int)$this->db->fetchScalar('SELECT count(*) FROM stats.clicks WHERE campaign_id = :c', ['c' => $this->camp->id]);
    expect($count)->toBe(1);
});

test('Set-Cookie vu attached on first visit', function (): void {
    $req = (new ServerRequestFactory())->createServerRequest('GET', '/clkt01');
    $resp = $this->handler->handle($req, new Response(), 'clkt01');
    $setCookie = $resp->getHeaderLine('Set-Cookie');
    expect($setCookie)->toContain('vu=');
    expect($setCookie)->toContain('Path=/');
});

test('HtmlPageSchema body HTML-escapes attacker-controlled macros (referer)', function (): void {
    $cRepo = new CampaignRepository($this->db, new CampaignIdGenerator());
    $camp = $cRepo->create(['name' => 'Html echo', 'slug' => 'htmlxs', 'is_active' => '1']);
    $fRepo = new FlowRepository($this->db);
    $fRepo->create($camp->id, [
        'name' => 'html echo referer', 'filters' => [],
        'target_type' => 'none', 'target_offers' => [],
        'schema_id' => 9, // HtmlPageSchema
        'schema_config' => ['body' => 'Welcome from {referer}'],
        'is_active' => '1',
    ]);
    $req = (new ServerRequestFactory())
        ->createServerRequest('GET', '/htmlxs')
        ->withHeader('Referer', '"><script>alert(1)</script>');
    $resp = $this->handler->handle($req, new Response(), 'htmlxs');
    $body = (string)$resp->getBody();
    expect($body)->not->toContain('<script>');
    expect($body)->toContain('&lt;script&gt;');
});

test('FormulaSchema body HTML-escapes macros but keeps operator literal HTML/JS intact', function (): void {
    $cRepo = new CampaignRepository($this->db, new CampaignIdGenerator());
    $camp = $cRepo->create(['name' => 'Formula echo', 'slug' => 'formxs', 'is_active' => '1']);
    $fRepo = new FlowRepository($this->db);
    $fRepo->create($camp->id, [
        'name' => 'formula echo utm', 'filters' => [],
        'target_type' => 'none', 'target_offers' => [],
        'schema_id' => 15, // FormulaSchema
        'schema_config' => ['body' => '<script>var utm="{utm_content}";</script><h1>Hi</h1>'],
        'is_active' => '1',
    ]);
    // Query params are read from the request URI, not a factory constructor arg.
    $req = (new ServerRequestFactory())->createServerRequest('GET', '/formxs?utm_content=%22%3Balert(1)%3B%2F%2F');
    $resp = $this->handler->handle($req, new Response(), 'formxs');
    $body = (string)$resp->getBody();
    expect($body)->toContain('<script>');   // operator's own literal markup untouched
    expect($body)->toContain('<h1>Hi</h1>');
    expect($body)->not->toContain('";alert(1);//'); // macro value escaped
});

test('X-Lander-Host is ignored from an untrusted peer', function (): void {
    $cRepo = new CampaignRepository($this->db, new CampaignIdGenerator());
    $camp = $cRepo->create(['name' => 'Lander untrusted', 'slug' => 'landr01', 'is_active' => '1']);
    $fRepo = new FlowRepository($this->db);
    $fRepo->create($camp->id, [
        'name' => 'lander echo', 'filters' => [],
        'target_type' => 'none', 'target_offers' => [],
        'schema_id' => 9,
        'schema_config' => ['body' => 'Lander: {lander_host}'],
        'is_active' => '1',
    ]);
    $req = (new ServerRequestFactory())
        ->createServerRequest('GET', '/landr01', ['REMOTE_ADDR' => '198.51.100.7'])
        ->withHeader('X-Lander-Host', 'evil-attribution.example');
    $resp = $this->handler->handle($req, new Response(), 'landr01');
    $body = (string)$resp->getBody();
    expect($body)->toBe('Lander: '); // known macro, null value from an untrusted peer — degrades to empty, not spoofed and not leaked literally
});

test('a disabled offer left in target_offers no longer receives traffic', function (): void {
    // Regression: OfferPicker::pick() only ever checked weight, never
    // is_active — disabling an offer without also editing every flow that
    // still referenced it silently kept sending real clicks to its (possibly
    // dead / paused / no-longer-contracted) URL. See ClickHandler.php +
    // OfferRepository::filterActiveIds().
    $this->db->execute('UPDATE core.offers SET is_active = false WHERE id = :id', ['id' => $this->offer->id]);

    $resp = $this->handler->handle(
        (new ServerRequestFactory())->createServerRequest('GET', '/clkt01'),
        new Response(),
        'clkt01',
    );

    // Sole offer in the flow is disabled → picker has nothing to pick →
    // HttpRedirectSchema gets a null outUrl and degrades to 204, same as
    // the pre-existing "all weights zero" case — never a Location header
    // pointing at the disabled offer's URL.
    expect($resp->getStatusCode())->toBe(204);
    expect($resp->getHeaderLine('Location'))->toBe('');
});

test('weighted split redistributes to the remaining active offer when one leg is disabled', function (): void {
    $cRepo = new CampaignRepository($this->db, new CampaignIdGenerator());
    $oRepo = new OfferRepository($this->db);
    $fRepo = new FlowRepository($this->db);

    $camp = $cRepo->create(['name' => 'Split test', 'slug' => 'split01', 'is_active' => '1']);
    $live = $oRepo->create(['name' => 'Live leg', 'url' => 'https://live.example/', 'is_active' => '1']);
    $dead = $oRepo->create(['name' => 'Disabled leg', 'url' => 'https://dead.example/', 'is_active' => '0']);
    $fRepo->create($camp->id, [
        'name' => '50/50 split', 'filters' => [],
        'target_type' => 'offers',
        'target_offers' => [
            ['offer_id' => $live->id, 'weight' => 50],
            ['offer_id' => $dead->id, 'weight' => 50],
        ],
        'schema_id' => 2,
        'is_active' => '1',
    ]);

    for ($i = 0; $i < 10; $i++) {
        $resp = $this->handler->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/split01'),
            new Response(),
            'split01',
        );
        expect($resp->getHeaderLine('Location'))->toStartWith('https://live.example/');
    }
});

test('X-Lander-Host is honored from a trusted proxy (TRUSTED_PROXIES)', function (): void {
    $_ENV['TRUSTED_PROXIES'] = '203.0.113.10';
    $cRepo = new CampaignRepository($this->db, new CampaignIdGenerator());
    $camp = $cRepo->create(['name' => 'Lander trusted', 'slug' => 'landr02', 'is_active' => '1']);
    $fRepo = new FlowRepository($this->db);
    $fRepo->create($camp->id, [
        'name' => 'lander echo trusted', 'filters' => [],
        'target_type' => 'none', 'target_offers' => [],
        'schema_id' => 9,
        'schema_config' => ['body' => 'Lander: {lander_host}'],
        'is_active' => '1',
    ]);
    $req = (new ServerRequestFactory())
        ->createServerRequest('GET', '/landr02', ['REMOTE_ADDR' => '203.0.113.10'])
        ->withHeader('X-Lander-Host', 'trusted-lander.example');
    $resp = $this->handler->handle($req, new Response(), 'landr02');
    $body = (string)$resp->getBody();
    expect($body)->toBe('Lander: trusted-lander.example');
    unset($_ENV['TRUSTED_PROXIES']);
});
