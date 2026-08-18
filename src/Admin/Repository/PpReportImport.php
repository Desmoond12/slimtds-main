<?php

declare(strict_types=1);

namespace App\Admin\Repository;

use DateTimeImmutable;

final readonly class PpReportImport
{
    public function __construct(
        public string $id,
        public string $networkId,
        public string $filename,
        public int $rowCount,
        public ?int $adminId,
        public DateTimeImmutable $createdAt,
    ) {}

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:        (string)$row['id'],
            networkId: (string)$row['network_id'],
            filename:  (string)$row['filename'],
            rowCount:  (int)$row['row_count'],
            adminId:   isset($row['admin_id']) && $row['admin_id'] !== null ? (int)$row['admin_id'] : null,
            createdAt: new DateTimeImmutable((string)$row['created_at']),
        );
    }
}
