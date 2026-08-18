<?php

declare(strict_types=1);

use App\Admin\Repository\AffiliateNetworkRepository;
use App\Admin\Repository\OfferRepository;
use App\Shared\Db\Connection;

beforeEach(function (): void {
    $pdo = pdo();
    $pdo->exec('DELETE FROM core.offers');
    $pdo->exec('DELETE FROM core.affiliate_networks');
    $this->db = new Connection($pdo);
    $this->repo = new AffiliateNetworkRepository($this->db);
});

test('create applies defaults for param names when not given', function (): void {
    // is_active mirrors OfferRepository's convention: an omitted key means
    // false (unchecked-checkbox semantics) — pass it explicitly to test the
    // "active" path, same as OfferRepositoryTest does throughout.
    $n = $this->repo->create(['name' => 'Default PP', 'is_active' => '1']);
    expect($n->clickParam)->toBe('subid');
    expect($n->statusParam)->toBe('status');
    expect($n->payoutParam)->toBe('payout');
    expect($n->externalIdParam)->toBe('external_id');
    expect($n->eventTypeParam)->toBe('event_type');
    expect($n->statusMap)->toBe([]);
    expect($n->isActive)->toBeTrue();
});

test('create stores custom param names and status_map', function (): void {
    $n = $this->repo->create([
        'name' => 'Custom PP',
        'click_param' => 'clickid',
        'status_param' => 'event',
        'payout_param' => 'amount',
        'external_id_param' => 'txn',
        'event_type_param' => 'etype',
        'status_map' => ['1' => 'approved', '0' => 'rejected'],
    ]);
    expect($n->clickParam)->toBe('clickid');
    // toEqual, not toBe — jsonb round-trips a numeric-string-keyed map back
    // in a different key order (Postgres doesn't preserve jsonb key order),
    // which is irrelevant here; only the key=>value content matters.
    expect($n->statusMap)->toEqual(['1' => 'approved', '0' => 'rejected']);
});

test('update roundtrips all fields', function (): void {
    $n = $this->repo->create(['name' => 'Original']);
    $updated = $this->repo->update($n->id, [
        'name' => 'Renamed',
        'click_param' => 'cid',
        'status_map' => ['ok' => 'approved'],
        'is_active' => '0',
    ]);
    expect($updated)->not->toBeNull();
    expect($updated->name)->toBe('Renamed');
    expect($updated->clickParam)->toBe('cid');
    expect($updated->statusMap)->toBe(['ok' => 'approved']);
    expect($updated->isActive)->toBeFalse();
});

test('a fresh network defaults report_column_map empty and report_date_format to Y-m-d', function (): void {
    $n = $this->repo->create(['name' => 'Report Defaults']);
    expect($n->reportColumnMap)->toBe([]);
    expect($n->reportDateFormat)->toBe('Y-m-d');
});

test('updateReportConfig persists the column map and date format for reuse on the next upload', function (): void {
    $n = $this->repo->create(['name' => 'Report Config']);
    $this->repo->updateReportConfig($n->id, ['date' => 'Report Date', 'payout' => 'Revenue'], 'd/m/Y');

    $reloaded = $this->repo->findById($n->id);
    expect($reloaded->reportColumnMap)->toEqual(['date' => 'Report Date', 'payout' => 'Revenue']);
    expect($reloaded->reportDateFormat)->toBe('d/m/Y');
});

test('findById returns null for unknown id', function (): void {
    expect($this->repo->findById('019fe000-0000-7000-8000-000000000099'))->toBeNull();
});

test('delete removes the network and nulls network_id on referencing offers', function (): void {
    $n = $this->repo->create(['name' => 'To delete']);
    $offers = new OfferRepository($this->db);
    $o = $offers->create(['name' => 'Linked', 'url' => 'https://example.com/', 'network_id' => $n->id]);
    expect($o->networkId)->toBe($n->id);

    expect($this->repo->delete($n->id))->toBeTrue();
    expect($this->repo->findById($n->id))->toBeNull();

    $reloaded = $offers->findById($o->id);
    expect($reloaded)->not->toBeNull();
    expect($reloaded->networkId)->toBeNull();
});

test('pageAll + countAll filter by name', function (): void {
    $this->repo->create(['name' => 'Alpha Network']);
    $this->repo->create(['name' => 'Beta Network']);

    expect($this->repo->countAll())->toBe(2);
    expect($this->repo->countAll('Alpha'))->toBe(1);
    $page = $this->repo->pageAll(1, 25, 'Beta');
    expect($page)->toHaveCount(1);
    expect($page[0]->name)->toBe('Beta Network');
});
