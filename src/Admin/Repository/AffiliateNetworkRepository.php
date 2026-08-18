<?php

declare(strict_types=1);

namespace App\Admin\Repository;

use App\Shared\Db\Connection;

final class AffiliateNetworkRepository
{
    public function __construct(private readonly Connection $db) {}

    public function findById(string $id): ?AffiliateNetwork
    {
        $row = $this->db->fetchOne('SELECT * FROM core.affiliate_networks WHERE id = :id', ['id' => $id]);
        return $row === null ? null : AffiliateNetwork::fromRow($row);
    }

    /** @return list<AffiliateNetwork> */
    public function all(): array
    {
        return array_map(
            AffiliateNetwork::fromRow(...),
            $this->db->fetchAll('SELECT * FROM core.affiliate_networks ORDER BY name'),
        );
    }

    /** @return list<AffiliateNetwork> */
    public function pageAll(int $page, int $perPage, ?string $q = null): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $params = ['limit' => $perPage, 'offset' => $offset];
        $where = '';
        if ($q !== null && trim($q) !== '') {
            $where = 'WHERE name ILIKE :q';
            $params['q'] = '%' . trim($q) . '%';
        }
        $rows = $this->db->fetchAll(
            "SELECT * FROM core.affiliate_networks {$where} ORDER BY created_at DESC LIMIT :limit OFFSET :offset",
            $params,
        );
        return array_map(AffiliateNetwork::fromRow(...), $rows);
    }

    public function countAll(?string $q = null): int
    {
        $params = [];
        $where = '';
        if ($q !== null && trim($q) !== '') {
            $where = 'WHERE name ILIKE :q';
            $params['q'] = '%' . trim($q) . '%';
        }
        return (int)$this->db->fetchScalar("SELECT count(*) FROM core.affiliate_networks {$where}", $params);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): AffiliateNetwork
    {
        $row = $this->db->fetchOne(
            <<<'SQL'
                INSERT INTO core.affiliate_networks
                    (name, click_param, status_param, payout_param, external_id_param, event_type_param, status_map, event_map, allowed_ips, notes, is_active)
                VALUES
                    (:name, :click_param, :status_param, :payout_param, :external_id_param, :event_type_param, :status_map::jsonb, :event_map::jsonb, :allowed_ips::jsonb, :notes, :is_active)
                RETURNING *
            SQL,
            $this->params($data),
        );
        return AffiliateNetwork::fromRow($row);
    }

    /** @param array<string,mixed> $data */
    public function update(string $id, array $data): ?AffiliateNetwork
    {
        $params = $this->params($data);
        $params['id'] = $id;
        $row = $this->db->fetchOne(
            <<<'SQL'
                UPDATE core.affiliate_networks
                SET name = :name,
                    click_param = :click_param,
                    status_param = :status_param,
                    payout_param = :payout_param,
                    external_id_param = :external_id_param,
                    event_type_param = :event_type_param,
                    status_map = :status_map::jsonb,
                    event_map = :event_map::jsonb,
                    allowed_ips = :allowed_ips::jsonb,
                    notes = :notes,
                    is_active = :is_active,
                    updated_at = now()
                WHERE id = :id
                RETURNING *
            SQL,
            $params,
        );
        return $row === null ? null : AffiliateNetwork::fromRow($row);
    }

    public function delete(string $id): bool
    {
        return $this->db->execute('DELETE FROM core.affiliate_networks WHERE id = :id', ['id' => $id]) > 0;
    }

    /**
     * Persists the report column mapping chosen on a PP report upload, so
     * the next upload from this network pre-fills it — same "configure
     * once, reuse forever" UX as the postback param names. Deliberately
     * separate from update(): report config isn't part of the network edit
     * form, it's set by PpReportController::confirm() on the import flow.
     *
     * @param array<string,string> $columnMap
     */
    public function updateReportConfig(string $id, array $columnMap, string $dateFormat): void
    {
        $this->db->execute(
            'UPDATE core.affiliate_networks
             SET report_column_map = :map::jsonb, report_date_format = :fmt, updated_at = now()
             WHERE id = :id',
            [
                'id'  => $id,
                'map' => json_encode($columnMap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
                'fmt' => $dateFormat,
            ],
        );
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function params(array $data): array
    {
        return [
            'name'              => (string)($data['name'] ?? ''),
            'click_param'       => (string)($data['click_param'] ?? 'subid'),
            'status_param'      => (string)($data['status_param'] ?? 'status'),
            'payout_param'      => (string)($data['payout_param'] ?? 'payout'),
            'external_id_param' => (string)($data['external_id_param'] ?? 'external_id'),
            'event_type_param'  => (string)($data['event_type_param'] ?? 'event_type'),
            'status_map'        => json_encode($data['status_map'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            'event_map'         => json_encode($data['event_map'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            'allowed_ips'       => json_encode(array_values((array)($data['allowed_ips'] ?? [])), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
            'notes'             => trim((string)($data['notes'] ?? '')) !== '' ? trim((string)$data['notes']) : null,
            // Same convention as OfferRepository: an unchecked checkbox
            // simply isn't submitted by HTML forms, so absent here means
            // false on both create and update. The create form itself
            // defaults the checkbox to checked (see networks/form.php), so
            // this only bites callers (tests, scripts) that omit the key
            // outright — mirror it explicitly rather than relying on this.
            'is_active'         => !empty($data['is_active']) ? 'true' : 'false',
        ];
    }
}
