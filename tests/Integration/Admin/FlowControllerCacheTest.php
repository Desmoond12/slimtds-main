<?php

declare(strict_types=1);

use App\Admin\Controller\FlowController;
use App\Admin\Form\FlowForm;
use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\FlowRepository;
use App\Admin\Repository\OfferRepository;
use App\Engine\Context;
use App\Engine\FilterCompiler;
use App\Engine\FlowMatcher;
use App\Shared\CampaignIdGenerator;
use App\Shared\Db\Connection;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

// FlowMatcher caches a campaign's flow list for FlowMatcher::CACHE_TTL
// seconds — necessary because FrankenPHP worker processes are long-lived
// (see src/Engine/FlowMatcher.php). Without FlowController explicitly
// invalidating that cache on every mutation, an operator disabling a bad
// flow (wrong geo, dead offer, compliance issue) would keep seeing the old
// routing decision on the very next click, indistinguishable from the fix
// not having been applied at all.

beforeEach(function (): void {
    $pdo = pdo();
    $pdo->exec('DELETE FROM core.flows');
    $pdo->exec('DELETE FROM core.offers');
    $pdo->exec('DELETE FROM core.campaigns');
    $this->db = new Connection($pdo);

    $cRepo = new CampaignRepository($this->db, new CampaignIdGenerator());
    $this->oRepo = new OfferRepository($this->db);
    $this->fRepo = new FlowRepository($this->db);
    $this->matcher = new FlowMatcher($this->fRepo, new FilterCompiler());
    $this->ctrl = new FlowController($this->fRepo, $cRepo, $this->oRepo, new FlowForm(), $this->matcher);

    $this->campaign = $cRepo->create(['name' => 'Cache test', 'slug' => 'fcache', 'is_active' => '1']);
    $this->offer = $this->oRepo->create(['name' => 'O', 'url' => 'https://example.com/', 'is_active' => '1']);
    $this->flow = $this->fRepo->create($this->campaign->id, [
        'name' => 'F1', 'filters' => [], 'target_type' => 'offers',
        'target_offers' => [['offer_id' => $this->offer->id, 'weight' => 100]],
        'schema_id' => 2, 'weight' => 100, 'is_active' => '1',
    ]);
});

function fcachePostRequest(array $params): \Psr\Http\Message\ServerRequestInterface
{
    return (new ServerRequestFactory())->createServerRequest('POST', '/x')->withParsedBody($params);
}

test('disabling a flow via update() takes effect on the very next match(), not after the cache TTL', function (): void {
    $ctx = new Context('1.1.1.1', 'test', 'fcache', time());

    // Warm the cache — mirrors a real click that ran before the admin edit.
    expect($this->matcher->match($this->campaign->id, $ctx))->not->toBeNull();

    ($this->ctrl)->update(fcachePostRequest([
        'name' => 'F1', 'filters' => '[]', 'target_type' => 'offers',
        'target_offers' => json_encode([['offer_id' => $this->offer->id, 'weight' => 100]]),
        'schema_id' => '2', 'weight' => '100', 'is_active' => '0',
    ]), new Response(), $this->campaign->id, $this->flow->id);

    expect($this->matcher->match($this->campaign->id, $ctx))->toBeNull();
});

test('a flow created via create() is matchable immediately, not after the cache TTL', function (): void {
    $ctx = new Context('1.1.1.1', 'test', 'fcache', time());

    // Warm the cache against a campaign with zero flows.
    $this->db->execute('DELETE FROM core.flows WHERE id = :id', ['id' => $this->flow->id]);
    expect($this->matcher->match($this->campaign->id, $ctx))->toBeNull();

    ($this->ctrl)->create(fcachePostRequest([
        'name' => 'fresh', 'filters' => '[]', 'target_type' => 'offers',
        'target_offers' => json_encode([['offer_id' => $this->offer->id, 'weight' => 100]]),
        'schema_id' => '2', 'weight' => '100', 'is_active' => '1',
    ]), new Response(), $this->campaign->id);

    expect($this->matcher->match($this->campaign->id, $ctx))->not->toBeNull();
});

test('reordering via move() changes match priority immediately', function (): void {
    $ctx = new Context('1.1.1.1', 'test', 'fcache', time());

    // Second flow, narrower (bots only), created after the first — starts
    // at a lower priority (higher position) so the catch-all F1 wins first.
    $urgent = $this->fRepo->create($this->campaign->id, [
        'name' => 'urgent none', 'filters' => [], 'target_type' => 'none',
        'target_offers' => [], 'schema_id' => 13, 'weight' => 100, 'is_active' => '1',
    ]);

    // Warm the cache — F1 (schema_id 2, offer redirect) currently wins.
    $this->matcher->match($this->campaign->id, $ctx);

    ($this->ctrl)->move(fcachePostRequest(['dir' => 'up']), new Response(), $this->campaign->id, $urgent->id);

    $matched = $this->matcher->match($this->campaign->id, $ctx);
    expect($matched->id)->toBe($urgent->id);
});

test('deleting a flow removes it from matching immediately', function (): void {
    $ctx = new Context('1.1.1.1', 'test', 'fcache', time());
    expect($this->matcher->match($this->campaign->id, $ctx))->not->toBeNull();

    ($this->ctrl)->delete(fcachePostRequest([]), new Response(), $this->campaign->id, $this->flow->id);

    expect($this->matcher->match($this->campaign->id, $ctx))->toBeNull();
});
