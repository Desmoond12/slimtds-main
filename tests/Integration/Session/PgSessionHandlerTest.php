<?php

declare(strict_types=1);

use App\Shared\Db\Connection;
use App\Shared\Session\PgSessionHandler;

beforeEach(function (): void {
    $pdo = new PDO(
        $_ENV['DB_DSN'] ?? 'pgsql:host=db;port=5432;dbname=slimtds',
        $_ENV['DB_USER'] ?? 'slimtds',
        $_ENV['DB_PASSWORD'] ?? 'slimtds',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $pdo->exec('DELETE FROM core.sessions');
    $this->db = new Connection($pdo);
    $this->handler = new PgSessionHandler($this->db, 3600);
});

test('write then read returns same data', function (): void {
    $id   = bin2hex(random_bytes(16));
    $data = 'user|a:2:{s:2:"id";i:1;s:4:"name";s:5:"Alice";}';

    expect($this->handler->write($id, $data))->toBeTrue();
    expect($this->handler->read($id))->toBe($data);
});

test('read returns empty string for missing id', function (): void {
    expect($this->handler->read('nonexistent'))->toBe('');
});

test('read returns empty for expired session', function (): void {
    $id = bin2hex(random_bytes(16));
    $this->db->execute(
        'INSERT INTO core.sessions (id, data, expires_at) VALUES (:id, :data, now() - interval \'1 hour\')',
        ['id' => $id, 'data' => 'stale'],
    );
    expect($this->handler->read($id))->toBe('');
});

test('destroy removes session', function (): void {
    $id = bin2hex(random_bytes(16));
    $this->handler->write($id, 'x');
    expect($this->handler->destroy($id))->toBeTrue();
    expect($this->handler->read($id))->toBe('');
});

test('gc removes only expired sessions', function (): void {
    $live = bin2hex(random_bytes(16));
    $dead = bin2hex(random_bytes(16));
    $this->handler->write($live, 'live');
    $this->db->execute(
        'INSERT INTO core.sessions (id, data, expires_at) VALUES (:id, :data, now() - interval \'1 hour\')',
        ['id' => $dead, 'data' => 'dead'],
    );

    $deleted = $this->handler->gc(0);
    expect($deleted)->toBe(1);
    expect($this->handler->read($live))->toBe('live');
    expect($this->handler->read($dead))->toBe('');
});

test('validateId returns true for active and false for unknown', function (): void {
    $id = bin2hex(random_bytes(16));
    $this->handler->write($id, 'x');
    expect($this->handler->validateId($id))->toBeTrue();
    expect($this->handler->validateId('not-there'))->toBeFalse();
});

test('create_sid returns 64 hex chars', function (): void {
    $sid = $this->handler->create_sid();
    expect($sid)->toMatch('/^[0-9a-f]{64}$/');
});

test('write populates admin_id from $_SESSION', function (): void {
    $adminId = (int)$this->db->fetchScalar(
        "INSERT INTO core.admins (login, password_hash) VALUES ('session-fixture-admin', 'x') RETURNING id",
    );

    $id = bin2hex(random_bytes(16));
    $_SESSION['admin_id'] = $adminId;
    $this->handler->write($id, 'x');
    unset($_SESSION['admin_id']);

    $row = $this->db->fetchOne('SELECT admin_id FROM core.sessions WHERE id = :id', ['id' => $id]);
    expect((int)$row['admin_id'])->toBe($adminId);
});

test('write leaves admin_id null when no admin is logged in', function (): void {
    $id = bin2hex(random_bytes(16));
    unset($_SESSION['admin_id']);
    $this->handler->write($id, 'x');

    $row = $this->db->fetchOne('SELECT admin_id FROM core.sessions WHERE id = :id', ['id' => $id]);
    expect($row['admin_id'])->toBeNull();
});

test('absolute lifetime caps expires_at even when the sliding window is larger', function (): void {
    // 1h sliding window, but a 1s absolute cap — the cap must win.
    $handler = new PgSessionHandler($this->db, 3600, 1);
    $id = bin2hex(random_bytes(16));
    $handler->write($id, 'x');

    $row = $this->db->fetchOne(
        'SELECT expires_at, created_at FROM core.sessions WHERE id = :id',
        ['id' => $id],
    );
    $expiresAt = strtotime((string)$row['expires_at']);
    $createdAt = strtotime((string)$row['created_at']);
    // Capped to created_at + 1s, not the 3600s sliding window.
    expect($expiresAt)->toBeLessThan($createdAt + 5);
});

test('absolute lifetime cap is anchored to the original created_at across repeated writes', function (): void {
    $handler = new PgSessionHandler($this->db, 3600, 2);
    $id = bin2hex(random_bytes(16));
    $handler->write($id, 'first');
    $createdAt = (string)$this->db->fetchScalar(
        'SELECT created_at FROM core.sessions WHERE id = :id',
        ['id' => $id],
    );

    // A later write (e.g. next request) must not push created_at forward.
    $handler->write($id, 'second');
    $createdAtAfter = (string)$this->db->fetchScalar(
        'SELECT created_at FROM core.sessions WHERE id = :id',
        ['id' => $id],
    );
    expect($createdAtAfter)->toBe($createdAt);
});
