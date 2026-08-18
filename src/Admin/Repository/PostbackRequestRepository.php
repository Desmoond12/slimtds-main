<?php

declare(strict_types=1);

namespace App\Admin\Repository;

use App\Shared\Db\Connection;

/**
 * Read access to core.postback_requests — the raw incoming-postback audit
 * trail written by App\Postback\PostbackController::logRequest() for every
 * /postback hit, success or failure. See
 * migrations/20260809000002_postback_requests_log.php.
 */
final class PostbackRequestRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @param array{processing_status?:?string} $filters
     * @return list<array<string,mixed>>
     */
    public function page(int $page, int $perPage, array $filters = []): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        [$where, $params] = $this->buildWhere($filters);
        $params['limit'] = $perPage;
        $params['offset'] = $offset;

        return $this->db->fetchAll(
            "SELECT pr.*, o.name AS offer_name
             FROM core.postback_requests pr
             LEFT JOIN core.offers o ON o.id = pr.offer_id
             {$where}
             ORDER BY pr.id DESC
             LIMIT :limit OFFSET :offset",
            $params,
        );
    }

    /** @param array<string,mixed> $filters */
    public function count(array $filters = []): int
    {
        [$where, $params] = $this->buildWhere($filters);
        return (int)$this->db->fetchScalar("SELECT count(*) FROM core.postback_requests pr {$where}", $params);
    }

    /**
     * @param array{processing_status?:?string} $filters
     * @return array{0:string, 1:array<string,mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $cond = [];
        $params = [];
        if (!empty($filters['processing_status'])) {
            $cond[] = 'pr.processing_status = :ps';
            $params['ps'] = (string)$filters['processing_status'];
        }
        return [$cond ? 'WHERE ' . implode(' AND ', $cond) : '', $params];
    }
}
