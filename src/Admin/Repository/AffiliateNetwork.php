<?php

declare(strict_types=1);

namespace App\Admin\Repository;

use DateTimeImmutable;

final readonly class AffiliateNetwork
{
    /**
     * @param array<string,string> $statusMap  raw partner status value → canonical (approved/pending/hold/rejected)
     * @param array<string,string> $reportColumnMap  their CSV/report header → our canonical field (date/campaign/offer/event_type/clicks/count/payout/currency)
     * @param array<string,string> $eventMap  raw partner event value → canonical event_type (reg/ftd/redeposit/...)
     * @param list<string> $allowedIps  postback source allowlist, IPs or CIDRs; empty = no restriction
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $clickParam,
        public string $statusParam,
        public string $payoutParam,
        public string $externalIdParam,
        public string $eventTypeParam,
        public array $statusMap,
        public ?string $notes,
        public bool $isActive,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public array $reportColumnMap = [],
        public string $reportDateFormat = 'Y-m-d',
        public array $eventMap = [],
        public array $allowedIps = [],
    ) {}

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        $statusMap = self::decodeMap($row['status_map'] ?? '{}');
        $reportColumnMap = self::decodeMap($row['report_column_map'] ?? '{}');

        return new self(
            id:               (string)$row['id'],
            name:             (string)$row['name'],
            clickParam:       (string)$row['click_param'],
            statusParam:      (string)$row['status_param'],
            payoutParam:      (string)$row['payout_param'],
            externalIdParam:  (string)$row['external_id_param'],
            eventTypeParam:   (string)$row['event_type_param'],
            statusMap:        $statusMap,
            notes:            isset($row['notes']) ? (string)$row['notes'] : null,
            isActive:         (bool)$row['is_active'],
            createdAt:        new DateTimeImmutable((string)$row['created_at']),
            updatedAt:        new DateTimeImmutable((string)$row['updated_at']),
            reportColumnMap:  $reportColumnMap,
            reportDateFormat: (string)($row['report_date_format'] ?? 'Y-m-d'),
            eventMap:         self::decodeMap($row['event_map'] ?? '{}'),
            allowedIps:       self::decodeList($row['allowed_ips'] ?? '[]'),
        );
    }

    /**
     * @return list<string>
     */
    private static function decodeList(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $decoded = is_array($decoded) ? $decoded : [];
        } else {
            $decoded = is_array($raw) ? $raw : [];
        }
        return array_values(array_filter(array_map('strval', $decoded), static fn (string $s) => $s !== ''));
    }

    /**
     * @return array<string,string>
     */
    private static function decodeMap(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $decoded = is_array($decoded) ? $decoded : [];
        } else {
            $decoded = is_array($raw) ? $raw : [];
        }
        return array_map('strval', $decoded);
    }
}
