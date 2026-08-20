<?php

declare(strict_types=1);

use App\Admin\Controller\FunnelWizardController;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/** Resolve the wizard through the real DI container (autowires all repos + Connection). */
function wizardController(): FunnelWizardController
{
    $container = (require dirname(__DIR__, 3) . '/config/di.php')();
    return $container->get(FunnelWizardController::class);
}

/** @param array<string,mixed> $body */
function postFunnel(array $body): \Psr\Http\Message\ResponseInterface
{
    $_SESSION = [];
    $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/funnels')->withParsedBody($body);
    return wizardController()->create($req, new Response());
}

test('geo preset creates campaign + offer + 3 ordered flows in one transaction', function (): void {
    $name = 'WZ Geo ' . bin2hex(random_bytes(3));

    $resp = postFunnel([
        'preset'        => 'geo',
        'campaign_name' => $name,
        'offer_url'     => 'https://track.brand.com/visit?aff=88',
        'geo'           => 'IT, DE, at',
        'cloak'         => '1',
        'official_url'  => 'https://www.brand.com',
    ]);

    expect($resp->getStatusCode())->toBe(302);
    $loc = $resp->getHeaderLine('Location');
    expect($loc)->toContain('/admin/campaigns/');
    expect($loc)->toContain('/flows');

    expect(preg_match('#/admin/campaigns/([0-9a-f-]+)/flows#', $loc, $m))->toBe(1);
    $cid = $m[1];

    $stmt = pdo()->prepare(
        'SELECT name, filters::text AS filters, target_type, schema_id, target_offers::text AS target_offers
         FROM core.flows WHERE campaign_id = :cid ORDER BY position',
    );
    $stmt->execute(['cid' => $cid]);
    $flows = $stmt->fetchAll();

    expect(count($flows))->toBe(3);

    // 1) bot trap first → official site (target_type none, schema 302, url in config)
    expect($flows[0]['target_type'])->toBe('none');
    expect((int)$flows[0]['schema_id'])->toBe(2);
    expect($flows[0]['filters'])->toContain('is_bot');

    // 2) geo money flow → offers, country filter, offer id present
    expect($flows[1]['target_type'])->toBe('offers');
    expect($flows[1]['filters'])->toContain('country');
    expect($flows[1]['target_offers'])->toContain('offer_id');

    // 3) fallback catch-all → blank 200
    expect($flows[2]['target_type'])->toBe('none');
    expect((int)$flows[2]['schema_id'])->toBe(13);
    expect($flows[2]['filters'])->toBe('[]');

    // offer created, {click_id} macro auto-appended (was absent in the input URL)
    $o = pdo()->prepare('SELECT url FROM core.offers WHERE name = :n');
    $o->execute(['n' => $name]);
    expect((string)$o->fetch()['url'])->toContain('{click_id}');
});

test('single preset without cloak creates exactly one catch-all flow to the offer', function (): void {
    $name = 'WZ Single ' . bin2hex(random_bytes(3));

    $resp = postFunnel([
        'preset'        => 'single',
        'campaign_name' => $name,
        'offer_url'     => 'https://track.brand.com/go?a=1&subid={click_id}',
    ]);

    expect($resp->getStatusCode())->toBe(302);
    preg_match('#/admin/campaigns/([0-9a-f-]+)/flows#', $resp->getHeaderLine('Location'), $m);

    $stmt = pdo()->prepare('SELECT target_type, filters::text AS filters FROM core.flows WHERE campaign_id = :cid');
    $stmt->execute(['cid' => $m[1]]);
    $flows = $stmt->fetchAll();

    expect(count($flows))->toBe(1);
    expect($flows[0]['target_type'])->toBe('offers');
    expect($flows[0]['filters'])->toBe('[]'); // catch-all
});

test('invalid input creates nothing (all-or-nothing, no partial funnel)', function (): void {
    $before = (int)pdo()->query('SELECT count(*) AS c FROM core.campaigns')->fetch()['c'];

    $resp = postFunnel(['preset' => 'geo', 'campaign_name' => '', 'offer_url' => '', 'geo' => '']);

    expect($resp->getStatusCode())->toBe(302);
    expect($resp->getHeaderLine('Location'))->toContain('/admin/funnels/new');

    $after = (int)pdo()->query('SELECT count(*) AS c FROM core.campaigns')->fetch()['c'];
    expect($after)->toBe($before);
});
