<?php

declare(strict_types=1);

namespace App\Shared\Session;

use App\Shared\Db\Connection;
use SessionHandlerInterface;
use SessionIdInterface;
use SessionUpdateTimestampHandlerInterface;

final class PgSessionHandler implements SessionHandlerInterface, SessionIdInterface, SessionUpdateTimestampHandlerInterface
{
    public function __construct(
        private readonly Connection $db,
        private readonly int $lifetimeSeconds = 1_209_600, // 14 days default — sliding window, extended on every request
        private readonly int $absoluteLifetimeSeconds = 2_592_000, // 30 days default — hard cap regardless of activity
    ) {}

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string
    {
        $row = $this->db->fetchOne(
            'SELECT data FROM core.sessions WHERE id = :id AND expires_at > now()',
            ['id' => $id],
        );
        if ($row === null) {
            return '';
        }
        $data = $row['data'];
        if (is_resource($data)) {
            $data = stream_get_contents($data);
        }
        return (string)$data;
    }

    /**
     * expires_at is capped so the sliding window (extended on every write)
     * can never push a session past `created_at + absoluteLifetimeSeconds`
     * — otherwise a session cookie used at least once per lifetime window
     * would never truly expire. `created_at` is only ever set by the
     * initial INSERT (the ON CONFLICT branch doesn't touch it), so the cap
     * is anchored to when this exact session id was first created —
     * `session_regenerate_id()` on login mints a fresh id/row, so the clock
     * restarts on every login as intended.
     *
     * admin_id is read from $_SESSION directly (populated by LoginController
     * on success) — safe in FrankenPHP worker mode because SessionMiddleware
     * calls session_start()/session_write_close() once per request, so
     * $_SESSION reflects only the current request's session by the time
     * write() runs, never a previous request's.
     */
    public function write(string $id, string $data): bool
    {
        $adminId = isset($_SESSION['admin_id']) && is_int($_SESSION['admin_id']) ? $_SESSION['admin_id'] : null;
        $this->db->execute(
            <<<'SQL'
                INSERT INTO core.sessions (id, admin_id, data, expires_at, created_at, updated_at)
                VALUES (
                    :id, :admin_id, :data,
                    LEAST(now() + make_interval(secs => :lifetime), now() + make_interval(secs => :abs_lifetime)),
                    now(), now()
                )
                ON CONFLICT (id) DO UPDATE
                SET admin_id   = EXCLUDED.admin_id,
                    data       = EXCLUDED.data,
                    expires_at = LEAST(
                        now() + make_interval(secs => :lifetime),
                        core.sessions.created_at + make_interval(secs => :abs_lifetime)
                    ),
                    updated_at = now()
            SQL,
            [
                'id'           => $id,
                'admin_id'     => $adminId,
                'data'         => $data,
                'lifetime'     => $this->lifetimeSeconds,
                'abs_lifetime' => $this->absoluteLifetimeSeconds,
            ],
        );
        return true;
    }

    public function destroy(string $id): bool
    {
        $this->db->execute('DELETE FROM core.sessions WHERE id = :id', ['id' => $id]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return $this->db->execute('DELETE FROM core.sessions WHERE expires_at < now()');
    }

    public function create_sid(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function validateId(string $id): bool
    {
        $count = $this->db->fetchScalar(
            'SELECT count(*) FROM core.sessions WHERE id = :id AND expires_at > now()',
            ['id' => $id],
        );
        return (int)$count > 0;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        return $this->write($id, $data);
    }
}
