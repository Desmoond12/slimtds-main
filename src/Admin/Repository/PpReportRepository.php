<?php

declare(strict_types=1);

namespace App\Admin\Repository;

use App\Shared\Db\Connection;

final class PpReportRepository
{
    public function __construct(private readonly Connection $db) {}

    public function findImport(string $id): ?PpReportImport
    {
        $row = $this->db->fetchOne('SELECT * FROM core.pp_report_imports WHERE id = :id', ['id' => $id]);
        return $row === null ? null : PpReportImport::fromRow($row);
    }

    /** @return list<PpReportImport> */
    public function listImports(string $networkId): array
    {
        return array_map(
            PpReportImport::fromRow(...),
            $this->db->fetchAll(
                'SELECT * FROM core.pp_report_imports WHERE network_id = :nid ORDER BY created_at DESC',
                ['nid' => $networkId],
            ),
        );
    }

    public function createImport(string $networkId, string $filename, int $rowCount, ?int $adminId): PpReportImport
    {
        $row = $this->db->fetchOne(
            <<<'SQL'
                INSERT INTO core.pp_report_imports (network_id, filename, row_count, admin_id)
                VALUES (:network_id, :filename, :row_count, :admin_id)
                RETURNING *
            SQL,
            [
                'network_id' => $networkId,
                'filename'   => $filename,
                'row_count'  => $rowCount,
                'admin_id'   => $adminId,
            ],
        );
        return PpReportImport::fromRow($row);
    }

    /**
     * Batch-insert normalised report rows for an import. Each $rows entry:
     * array{campaign_id:?string, offer_id:?string, report_date:string,
     *       event_type:string, clicks:?int, count:?int, payout:?string,
     *       currency:string, raw_row:array<string,mixed>}
     *
     * @param list<array<string,mixed>> $rows
     */
    public function insertRows(string $importId, string $networkId, array $rows): void
    {
        if ($rows === []) return;

        $this->db->transactional(function () use ($importId, $networkId, $rows) {
            foreach ($rows as $r) {
                $this->db->execute(
                    <<<'SQL'
                        INSERT INTO core.pp_reports
                            (import_id, network_id, campaign_id, offer_id, report_date, event_type, clicks, count, payout, currency, raw_row)
                        VALUES
                            (:import_id, :network_id, :campaign_id, :offer_id, :report_date, :event_type, :clicks, :count, :payout, :currency, :raw_row::jsonb)
                    SQL,
                    [
                        'import_id'   => $importId,
                        'network_id'  => $networkId,
                        'campaign_id' => $r['campaign_id'] ?? null,
                        'offer_id'    => $r['offer_id'] ?? null,
                        'report_date' => $r['report_date'],
                        'event_type'  => $r['event_type'] ?? 'conversion',
                        'clicks'      => $r['clicks'] ?? null,
                        'count'       => $r['count'] ?? null,
                        'payout'      => $r['payout'] ?? null,
                        'currency'    => $r['currency'] ?? 'USD',
                        'raw_row'     => json_encode($r['raw_row'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
                    ],
                );
            }
        });
    }

    /** Deletes the import; core.pp_reports rows cascade via FK. */
    public function deleteImport(string $id): bool
    {
        return $this->db->execute('DELETE FROM core.pp_report_imports WHERE id = :id', ['id' => $id]) > 0;
    }
}
