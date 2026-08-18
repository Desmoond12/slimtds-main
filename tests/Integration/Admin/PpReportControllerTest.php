<?php

declare(strict_types=1);

use App\Admin\Controller\PpReportController;
use App\Admin\Repository\AffiliateNetworkRepository;
use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\OfferRepository;
use App\Admin\Repository\PpReportRepository;
use App\Shared\Asset\Manifest;
use App\Shared\CampaignIdGenerator;
use App\Shared\Db\Connection;
use App\Shared\I18n\I18n;
use App\Shared\I18n\TranslatorFactory;
use App\Shared\View\View;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;
use Slim\Psr7\UploadedFile;

beforeEach(function (): void {
    $_SESSION = [];
    $pdo = pdo();
    $pdo->exec('DELETE FROM core.pp_reports');
    $pdo->exec('DELETE FROM core.pp_report_imports');
    $pdo->exec('DELETE FROM core.affiliate_networks');
    $pdo->exec('DELETE FROM core.campaigns');

    $this->db = new Connection($pdo);
    $this->networksRepo = new AffiliateNetworkRepository($this->db);
    $this->reportsRepo  = new PpReportRepository($this->db);
    $this->controller   = new PpReportController(
        $this->reportsRepo,
        $this->networksRepo,
        new CampaignRepository($this->db, new CampaignIdGenerator()),
        new OfferRepository($this->db),
    );

    $root = dirname(__DIR__, 3);
    $assets = new Manifest($root . '/public/assets/manifest.json');
    $i18n   = new I18n((new TranslatorFactory($root . '/resources/translations'))->create());
    $this->view = new View($root . '/resources/views', $assets, $i18n);

    $this->network = $this->networksRepo->create(['name' => 'CSV Test PP', 'is_active' => '1']);
});

function csvUploadedFile(string $content, string $filename = 'report.csv'): UploadedFile
{
    $stream = (new StreamFactory())->createStream($content);
    return new UploadedFile($stream, $filename, 'text/csv', strlen($content), UPLOAD_ERR_OK);
}

test('index renders the network name and an empty-history message with no imports', function (): void {
    $req = (new ServerRequestFactory())->createServerRequest('GET', "/admin/networks/{$this->network->id}/reports");
    $resp = $this->controller->index($req, new Response(), $this->view, $this->network->id);

    expect($resp->getStatusCode())->toBe(200);
    $body = (string)$resp->getBody();
    expect($body)->toContain('CSV Test PP');
});

test('preview parses the uploaded CSV and renders a mapping form with its headers', function (): void {
    $csv = "Date,Leads,Payout\n2026-01-01,5,25.00\n";
    $req = (new ServerRequestFactory())->createServerRequest('POST', "/admin/networks/{$this->network->id}/reports/preview")
        ->withUploadedFiles(['csv_file' => csvUploadedFile($csv)]);

    $resp = $this->controller->preview($req, new Response(), $this->view, $this->network->id);

    expect($resp->getStatusCode())->toBe(200);
    $body = (string)$resp->getBody();
    expect($body)->toContain('Date');
    expect($body)->toContain('Leads');
    expect($body)->toContain(base64_encode($csv));
});

test('preview redirects with an error when no file was uploaded', function (): void {
    $req = (new ServerRequestFactory())->createServerRequest('POST', "/admin/networks/{$this->network->id}/reports/preview");
    $resp = $this->controller->preview($req, new Response(), $this->view, $this->network->id);
    expect($resp->getStatusCode())->toBe(302);
});

test('confirm imports rows with the chosen column mapping and saves it on the network', function (): void {
    $csv = "Date,Leads,Payout\n2026-01-01,5,25.00\n2026-01-02,3,15.00\n";
    $req = (new ServerRequestFactory())->createServerRequest('POST', "/admin/networks/{$this->network->id}/reports/confirm")
        ->withParsedBody([
            'content_b64' => base64_encode($csv),
            'filename'    => 'report.csv',
            'date_format' => 'Y-m-d',
            'map_date'    => 'Date',
            'map_count'   => 'Leads',
            'map_payout'  => 'Payout',
        ]);

    $resp = $this->controller->confirm($req, new Response(), $this->view, $this->network->id);
    expect($resp->getStatusCode())->toBe(302);

    $rows = $this->db->fetchAll(
        'SELECT * FROM core.pp_reports WHERE network_id = :id ORDER BY report_date',
        ['id' => $this->network->id],
    );
    expect($rows)->toHaveCount(2);
    expect($rows[0]['report_date'])->toBe('2026-01-01');
    expect((int)$rows[0]['count'])->toBe(5);
    expect((float)$rows[0]['payout'])->toBe(25.0);
    expect($rows[0]['event_type'])->toBe('conversion');

    $reloaded = $this->networksRepo->findById($this->network->id);
    expect($reloaded->reportColumnMap)->toEqual(['date' => 'Date', 'count' => 'Leads', 'payout' => 'Payout']);
    expect($reloaded->reportDateFormat)->toBe('Y-m-d');

    $imports = $this->reportsRepo->listImports($this->network->id);
    expect($imports)->toHaveCount(1);
    expect($imports[0]->rowCount)->toBe(2);
});

test('confirm rejects a malformed date and inserts nothing (all-or-nothing)', function (): void {
    $csv = "Date,Leads\n2026-01-01,5\nnot-a-date,3\n";
    $req = (new ServerRequestFactory())->createServerRequest('POST', "/admin/networks/{$this->network->id}/reports/confirm")
        ->withParsedBody([
            'content_b64' => base64_encode($csv),
            'filename'    => 'report.csv',
            'date_format' => 'Y-m-d',
            'map_date'    => 'Date',
            'map_count'   => 'Leads',
        ]);

    $resp = $this->controller->confirm($req, new Response(), $this->view, $this->network->id);
    expect($resp->getStatusCode())->toBe(302);

    $count = (int)$this->db->fetchScalar('SELECT count(*) FROM core.pp_reports WHERE network_id = :id', ['id' => $this->network->id]);
    expect($count)->toBe(0);
});

test('confirm rejects when no date column was chosen', function (): void {
    $csv = "Date,Leads\n2026-01-01,5\n";
    $req = (new ServerRequestFactory())->createServerRequest('POST', "/admin/networks/{$this->network->id}/reports/confirm")
        ->withParsedBody([
            'content_b64' => base64_encode($csv),
            'filename'    => 'report.csv',
            'date_format' => 'Y-m-d',
            'map_date'    => '',
        ]);

    $resp = $this->controller->confirm($req, new Response(), $this->view, $this->network->id);
    expect($resp->getStatusCode())->toBe(302);
    $count = (int)$this->db->fetchScalar('SELECT count(*) FROM core.pp_reports WHERE network_id = :id', ['id' => $this->network->id]);
    expect($count)->toBe(0);
});

test('deleteImport removes the import and its rows', function (): void {
    $import = $this->reportsRepo->createImport($this->network->id, 'r.csv', 1, null);
    $this->reportsRepo->insertRows($import->id, $this->network->id, [[
        'campaign_id' => null, 'offer_id' => null, 'report_date' => '2026-01-01',
        'event_type' => 'conversion', 'clicks' => null, 'count' => 1, 'payout' => '10.00',
        'currency' => 'USD', 'raw_row' => ['Date' => '2026-01-01'],
    ]]);

    $req = (new ServerRequestFactory())->createServerRequest('POST', "/admin/networks/{$this->network->id}/reports/imports/{$import->id}/delete");
    $resp = $this->controller->deleteImport($req, new Response(), $this->network->id, $import->id);

    expect($resp->getStatusCode())->toBe(302);
    expect($this->reportsRepo->findImport($import->id))->toBeNull();
    $count = (int)$this->db->fetchScalar('SELECT count(*) FROM core.pp_reports WHERE import_id = :id', ['id' => $import->id]);
    expect($count)->toBe(0);
});
