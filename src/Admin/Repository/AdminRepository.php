<?php

declare(strict_types=1);

namespace App\Admin\Repository;

use App\Shared\Db\Connection;

final class AdminRepository
{
    public function __construct(private readonly Connection $db) {}

    public function findById(int $id): ?Admin
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM core.admins WHERE id = :id',
            ['id' => $id],
        );
        return $row === null ? null : Admin::fromRow($row);
    }

    public function findByLogin(string $login): ?Admin
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM core.admins WHERE login = :login',
            ['login' => $login],
        );
        return $row === null ? null : Admin::fromRow($row);
    }

    /** @return list<Admin> */
    public function all(): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM core.admins ORDER BY id');
        return array_map(Admin::fromRow(...), $rows);
    }

    /**
     * Update the admin password hash and clear must_change_password.
     * Returns true if a row was updated.
     */
    public function updatePassword(int $adminId, string $newHash): bool
    {
        $n = $this->db->execute(
            <<<'SQL'
                UPDATE core.admins
                SET password_hash = :h, must_change_password = false, updated_at = now()
                WHERE id = :id
            SQL,
            ['h' => $newHash, 'id' => $adminId],
        );
        return $n > 0;
    }

    /**
     * Update the admin's UI language preference.
     */
    public function updateUiLang(int $adminId, string $lang): bool
    {
        if (!in_array($lang, ['ru', 'en'], true)) {
            throw new \InvalidArgumentException("unsupported lang: {$lang}");
        }
        $n = $this->db->execute(
            'UPDATE core.admins SET ui_lang = :l, updated_at = now() WHERE id = :id',
            ['l' => $lang, 'id' => $adminId],
        );
        return $n > 0;
    }

    /**
     * Force must_change_password flag to true (e.g. after CLI admin:set-password by sysadmin).
     */
    public function flagPasswordChange(int $adminId): bool
    {
        $n = $this->db->execute(
            'UPDATE core.admins SET must_change_password = true, updated_at = now() WHERE id = :id',
            ['id' => $adminId],
        );
        return $n > 0;
    }

    /**
     * Record one failed login attempt and, once the count reaches 5,
     * (re-)lock the account for an exponentially growing duration: 1, 2, 4,
     * 8… minutes, capped at 24h. Independent of RateLimitMiddleware's fixed
     * windows — this has memory across windows, so it isn't reset just by
     * waiting out a 5-minute bucket, and it isn't keyed on IP, so rotating
     * source IPs doesn't reset it either.
     *
     * @return array{count:int, lockedUntil:?\DateTimeImmutable, justLocked:bool}
     */
    public function recordFailedLogin(int $adminId): array
    {
        $row = $this->db->fetchOne(
            <<<'SQL'
                UPDATE core.admins
                SET failed_login_count = failed_login_count + 1,
                    locked_until = CASE
                        WHEN failed_login_count + 1 >= 5
                        THEN now() + (LEAST(1440, POWER(2, GREATEST(0, failed_login_count + 1 - 5)))::int * interval '1 minute')
                        ELSE locked_until
                    END,
                    updated_at = now()
                WHERE id = :id
                RETURNING failed_login_count, locked_until
            SQL,
            ['id' => $adminId],
        );
        $count = (int)($row['failed_login_count'] ?? 0);
        $lockedUntil = isset($row['locked_until']) && $row['locked_until'] !== null
            ? new \DateTimeImmutable((string)$row['locked_until'])
            : null;

        return ['count' => $count, 'lockedUntil' => $lockedUntil, 'justLocked' => $count === 5];
    }

    /** Clear failed-attempt count and any lock — called on successful login. */
    public function resetLockout(int $adminId): void
    {
        $this->db->execute(
            'UPDATE core.admins SET failed_login_count = 0, locked_until = NULL, updated_at = now() WHERE id = :id',
            ['id' => $adminId],
        );
    }
}
