<?php

declare(strict_types=1);

use App\Admin\Repository\AffiliateNetworkRepository;
use App\Admin\Repository\PpReportRepository;
use App\Shared\Db\Connection;

beforeEach(function (): void {
    $pdo = pdo();
    $pdo->exec('DELETE FROM core.pp_reports');
    $pdo->exec('DELETE FROM core.pp_report_imports');
    $pdo->exec('DELETE FROM core.affiliate_networks');

    $this->db = new Connection($pdo);
    $this->networks = new AffiliateNetworkRepository($this->db);
    $this->repo = new PpReportRepository($this->db);
    $this->network = $this->networks->create(['name' => 'PP Reports Test Network', 'is_active' => '1']);
});

/** @return list<array<string,mixed>> */
function sampleReportRows(): array
{
    return [
        [
            'campaign_id' => null, 'offer_id' => null, 'report_date' => '2026-01-01',
            'event_type' => 'conversion', 'clicks' => 100, 'count' => 5, 'payout' => '25.00',
            'currency' => 'USD', 'raw_row' => ['Date' => '2026-01-01', 'Leads' => '5'],
        ],
        [
            'campaign_id' => null, 'offer_id' => null, 'report_date' => '2026-01-02',
            'event_type' => 'conversion', 'clicks' => 80, 'count' => 3, 'payout' => '15.00',
            'currency' => 'USD', 'raw_row' => ['Date' => '2026-01-02', 'Leads' => '3'],
        ],
    ];
}

test('createImport then insertRows stores normalised rows tied to the import', function (): void {
    // admin_id is nullable (ON DELETE SET NULL) — pass null rather than a
    // fabricated id, since core.admins isn't seeded with a fixture here.
    $import = $this->repo->createImport($this->network->id, 'report.csv', 2, null);
    expect($import->filename)->toBe('report.csv');
    expect($import->rowCount)->toBe(2);

    $this->repo->insertRows($import->id, $this->network->id, sampleReportRows());

    $rows = $this->db->fetchAll('SELECT * FROM core.pp_reports WHERE import_id = :id ORDER BY report_date', ['id' => $import->id]);
    expect($rows)->toHaveCount(2);
    expect($rows[0]['report_date'])->toBe('2026-01-01');
    expect((int)$rows[0]['count'])->toBe(5);
    expect((float)$rows[0]['payout'])->toBe(25.0);
});

test('raw_row is preserved as jsonb for debugging a bad mapping', function (): void {
    $import = $this->repo->createImport($this->network->id, 'report.csv', 1, null);
    $this->repo->insertRows($import->id, $this->network->id, [sampleReportRows()[0]]);

    $raw = $this->db->fetchScalar('SELECT raw_row FROM core.pp_reports WHERE import_id = :id', ['id' => $import->id]);
    $decoded = json_decode((string)$raw, true);
    expect($decoded)->toBe(['Date' => '2026-01-01', 'Leads' => '5']);
});

test('listImports returns imports for a network newest first', function (): void {
    $i1 = $this->repo->createImport($this->network->id, 'first.csv', 1, null);
    $i2 = $this->repo->createImport($this->network->id, 'second.csv', 1, null);

    $list = $this->repo->listImports($this->network->id);
    expect($list)->toHaveCount(2);
    expect($list[0]->id)->toBe($i2->id);
});

test('deleteImport cascades to its pp_reports rows', function (): void {
    $import = $this->repo->createImport($this->network->id, 'report.csv', 2, null);
    $this->repo->insertRows($import->id, $this->network->id, sampleReportRows());

    expect($this->repo->deleteImport($import->id))->toBeTrue();
    $count = (int)$this->db->fetchScalar('SELECT count(*) FROM core.pp_reports WHERE import_id = :id', ['id' => $import->id]);
    expect($count)->toBe(0);
    expect($this->repo->findImport($import->id))->toBeNull();
});

test('deleting the network cascades to its imports and reports', function (): void {
    $import = $this->repo->createImport($this->network->id, 'report.csv', 1, null);
    $this->repo->insertRows($import->id, $this->network->id, [sampleReportRows()[0]]);

    $this->networks->delete($this->network->id);

    expect($this->repo->findImport($import->id))->toBeNull();
    $count = (int)$this->db->fetchScalar('SELECT count(*) FROM core.pp_reports WHERE network_id = :id', ['id' => $this->network->id]);
    expect($count)->toBe(0);
});
