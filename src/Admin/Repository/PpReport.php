<?php

declare(strict_types=1);

namespace App\Admin\Repository;

use DateTimeImmutable;

final readonly class PpReport
{
    /** @param array<string,mixed> $rawRow */
    public function __construct(
        public string $id,
        public string $importId,
        public string $networkId,
        public ?string $campaignId,
        public ?string $offerId,
        public DateTimeImmutable $reportDate,
        public string $eventType,
        public ?int $clicks,
        public ?int $count,
        public ?string $payout,
        public string $currency,
        public array $rawRow,
        public DateTimeImmutable $createdAt,
    ) {}

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        $rawRow = $row['raw_row'] ?? '{}';
        if (is_string($rawRow)) {
            $decoded = json_decode($rawRow, true);
            $rawRow = is_array($decoded) ? $decoded : [];
        } elseif (!is_array($rawRow)) {
            $rawRow = [];
        }

        return new self(
            id:          (string)$row['id'],
            importId:    (string)$row['import_id'],
            networkId:   (string)$row['network_id'],
            campaignId:  isset($row['campaign_id']) && $row['campaign_id'] !== null ? (string)$row['campaign_id'] : null,
            offerId:     isset($row['offer_id']) && $row['offer_id'] !== null ? (string)$row['offer_id'] : null,
            reportDate:  new DateTimeImmutable((string)$row['report_date']),
            eventType:   (string)$row['event_type'],
            clicks:      isset($row['clicks']) && $row['clicks'] !== null ? (int)$row['clicks'] : null,
            count:       isset($row['count']) && $row['count'] !== null ? (int)$row['count'] : null,
            payout:      isset($row['payout']) && $row['payout'] !== null ? (string)$row['payout'] : null,
            currency:    (string)$row['currency'],
            rawRow:      $rawRow,
            createdAt:   new DateTimeImmutable((string)$row['created_at']),
        );
    }
}
