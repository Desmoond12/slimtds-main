<?php

declare(strict_types=1);

namespace App\Shared\Auth;

final class PasswordHasher
{
    /**
     * A real Argon2id hash (default params, matching hash()) of a throwaway
     * string. Used by verifyDummy() to spend the same CPU time as a genuine
     * verify when the target account doesn't exist / is locked, so login
     * response time can't be used to enumerate valid admin usernames.
     */
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$MThmb01TOHAwNHNlenZCZw$Dr+8I989kNUuJA5YZBuSFKgat+FETZE9MGYd6Y7qeSE';

    public function hash(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID);
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Run a verify against a fixed dummy hash to equalise timing on the
     * account-not-found / locked paths. The result is meaningless and must
     * be discarded — call it purely for its constant-time side effect.
     */
    public function verifyDummy(string $password): void
    {
        password_verify($password, self::DUMMY_HASH);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID);
    }
}
