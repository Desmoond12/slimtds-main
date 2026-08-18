<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Repository\AffiliateNetworkRepository;
use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\OfferRepository;
use App\Admin\Repository\PpReportRepository;
use App\Shared\Import\CsvParser;
use App\Shared\Import\DateParser;
use App\Shared\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class PpReportController
{
    /** Hard cap on rows per upload — the parsed content round-trips through
     *  a hidden form field between preview and confirm (see class docblock
     *  below), so an unbounded file would bloat that page indefinitely. */
    private const MAX_ROWS = 20_000;

    /** Canonical fields a report row can carry, beyond the required date. */
    private const OPTIONAL_FIELDS = ['event_type', 'clicks', 'count', 'payout', 'currency'];

    public function __construct(
        private readonly PpReportRepository $reports,
        private readonly AffiliateNetworkRepository $networks,
        private readonly CampaignRepository $campaigns,
        private readonly OfferRepository $offers,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response, View $view, string $id): ResponseInterface
    {
        $network = $this->networks->findById($id);
        if ($network === null) {
            return $response->withHeader('Location', '/admin/networks')->withStatus(302);
        }
        $data = array_merge(
            $view->withRequestContext($request),
            [
                'title' => $view->i18n->t('pp_reports.title') . ' — ' . $network->name,
                '__layout__' => 'layouts/admin',
                'network' => $network,
                'imports' => $this->reports->listImports($id),
            ],
        );
        return $view->respond($response, 'admin/networks/reports/index', $data);
    }

    /**
     * Step 1: parse the uploaded file's headers, render the column-mapping
     * form. The parsed CSV content itself round-trips through the mapping
     * form as a hidden base64 field (no server-side temp file to clean up —
     * report exports are small enough that this is simpler and safer than
     * managing a temp-file lifecycle, see plan for the trade-off).
     */
    public function preview(ServerRequestInterface $request, ResponseInterface $response, View $view, string $id): ResponseInterface
    {
        $network = $this->networks->findById($id);
        if ($network === null) {
            return $response->withHeader('Location', '/admin/networks')->withStatus(302);
        }

        $uploaded = $request->getUploadedFiles()['csv_file'] ?? null;
        if ($uploaded === null || $uploaded->getError() !== UPLOAD_ERR_OK) {
            flash_push('error', $view->i18n->t('pp_reports.error_upload'));
            return $response->withHeader('Location', "/admin/networks/{$id}/reports")->withStatus(302);
        }

        $filename = $uploaded->getClientFilename() ?? 'report.csv';
        $content = (string)$uploaded->getStream()->getContents();
        $parsed = (new CsvParser())->parse($content);

        if ($parsed['headers'] === [] || $parsed['rows'] === []) {
            flash_push('error', $view->i18n->t('pp_reports.error_empty'));
            return $response->withHeader('Location', "/admin/networks/{$id}/reports")->withStatus(302);
        }
        if (count($parsed['rows']) > self::MAX_ROWS) {
            flash_push('error', $view->i18n->t('pp_reports.error_too_many_rows', ['max' => self::MAX_ROWS]));
            return $response->withHeader('Location', "/admin/networks/{$id}/reports")->withStatus(302);
        }

        $data = array_merge(
            $view->withRequestContext($request),
            [
                'title' => $view->i18n->t('pp_reports.map_title'),
                '__layout__' => 'layouts/admin',
                'network' => $network,
                'filename' => $filename,
                'headers' => $parsed['headers'],
                'row_count' => count($parsed['rows']),
                'sample_rows' => array_slice($parsed['rows'], 0, 3),
                'content_b64' => base64_encode($content),
                'campaigns' => $this->campaigns->page(1, 500),
                'offers' => $this->offers->all(),
            ],
        );
        return $view->respond($response, 'admin/networks/reports/map', $data);
    }

    /**
     * Step 2: re-parse the round-tripped content with the chosen column
     * mapping, validate every row's date up front (all-or-nothing — a
     * malformed date anywhere aborts the whole import with a clear row
     * number rather than landing partial, silently-wrong data), then
     * batch-insert and persist the mapping back onto the network so the
     * next upload from this partner pre-fills it.
     */
    public function confirm(ServerRequestInterface $request, ResponseInterface $response, View $view, string $id): ResponseInterface
    {
        $network = $this->networks->findById($id);
        if ($network === null) {
            return $response->withHeader('Location', '/admin/networks')->withStatus(302);
        }

        $body = $request->getParsedBody();
        $data = is_array($body) ? $body : [];

        $content = base64_decode((string)($data['content_b64'] ?? ''), true);
        if ($content === false) {
            flash_push('error', $view->i18n->t('pp_reports.error_upload'));
            return $response->withHeader('Location', "/admin/networks/{$id}/reports")->withStatus(302);
        }
        $filename = (string)($data['filename'] ?? 'report.csv');
        $dateFormat = trim((string)($data['date_format'] ?? 'Y-m-d')) ?: 'Y-m-d';
        $dateColumn = (string)($data['map_date'] ?? '');
        $campaignId = trim((string)($data['campaign_id'] ?? '')) !== '' ? (string)$data['campaign_id'] : null;
        $offerId = trim((string)($data['offer_id'] ?? '')) !== '' ? (string)$data['offer_id'] : null;

        $columnMap = ['date' => $dateColumn];
        foreach (self::OPTIONAL_FIELDS as $field) {
            $mapped = trim((string)($data['map_' . $field] ?? ''));
            if ($mapped !== '') $columnMap[$field] = $mapped;
        }

        $parsed = (new CsvParser())->parse($content);
        $headers = $parsed['headers'];
        $dateIdx = array_search($dateColumn, $headers, true);
        if ($dateColumn === '' || $dateIdx === false) {
            flash_push('error', $view->i18n->t('pp_reports.error_no_date_column'));
            return $response->withHeader('Location', "/admin/networks/{$id}/reports")->withStatus(302);
        }
        $fieldIdx = [];
        foreach (self::OPTIONAL_FIELDS as $field) {
            $header = $columnMap[$field] ?? null;
            $fieldIdx[$field] = $header !== null ? array_search($header, $headers, true) : false;
        }

        $rows = [];
        foreach ($parsed['rows'] as $i => $cols) {
            $rowNum = $i + 2; // +1 for 0-index, +1 for the header row itself
            try {
                $date = DateParser::parse($cols[$dateIdx] ?? '', $dateFormat, $rowNum);
            } catch (\InvalidArgumentException $e) {
                flash_push('error', $e->getMessage());
                return $response->withHeader('Location', "/admin/networks/{$id}/reports")->withStatus(302);
            }

            $rawRow = array_combine($headers, array_pad($cols, count($headers), ''));
            $rows[] = [
                'campaign_id' => $campaignId,
                'offer_id'    => $offerId,
                'report_date' => $date->format('Y-m-d'),
                'event_type'  => $this->cell($cols, $fieldIdx['event_type']) ?: 'conversion',
                'clicks'      => $this->intOrNull($this->cell($cols, $fieldIdx['clicks'])),
                'count'       => $this->intOrNull($this->cell($cols, $fieldIdx['count'])),
                'payout'      => $this->numericOrNull($this->cell($cols, $fieldIdx['payout'])),
                'currency'    => $this->cell($cols, $fieldIdx['currency']) ?: 'USD',
                'raw_row'     => $rawRow,
            ];
        }

        $adminId = $this->adminId();
        $import = $this->reports->createImport($id, $filename, count($rows), $adminId);
        $this->reports->insertRows($import->id, $id, $rows);
        $this->networks->updateReportConfig($id, $columnMap, $dateFormat);

        flash_push('success', $view->i18n->t('pp_reports.imported', ['count' => count($rows)]));
        return $response->withHeader('Location', "/admin/networks/{$id}/reports")->withStatus(302);
    }

    public function deleteImport(ServerRequestInterface $request, ResponseInterface $response, string $id, string $importId): ResponseInterface
    {
        $this->reports->deleteImport($importId);
        flash_push('success', 'Import deleted');
        return $response->withHeader('Location', "/admin/networks/{$id}/reports")->withStatus(302);
    }

    /** @param list<string> $cols */
    private function cell(array $cols, int|false $idx): ?string
    {
        if ($idx === false || !isset($cols[$idx])) return null;
        $v = trim($cols[$idx]);
        return $v === '' ? null : $v;
    }

    private function intOrNull(?string $v): ?int
    {
        return $v !== null && is_numeric($v) ? (int)round((float)$v) : null;
    }

    private function numericOrNull(?string $v): ?string
    {
        return $v !== null && is_numeric($v) ? $v : null;
    }

    private function adminId(): ?int
    {
        $id = $_SESSION['admin_id'] ?? null;
        return is_int($id) ? $id : null;
    }
}
