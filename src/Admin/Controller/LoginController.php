<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Repository\AdminRepository;
use App\Shared\Auth\AuthEventLogger;
use App\Shared\Auth\PasswordHasher;
use App\Shared\RealIp;
use App\Shared\Telegram\TelegramNotifier;
use App\Shared\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handles POST /admin/login and POST /admin/logout.
 * GET /admin/login is a view-only handler in routes.php (no behaviour).
 */
final class LoginController
{
    public function __construct(
        private readonly AdminRepository $admins,
        private readonly PasswordHasher $hasher,
        private readonly AuthEventLogger $audit,
        private readonly TelegramNotifier $tg,
    ) {}

    public function postLogin(ServerRequestInterface $request, ResponseInterface $response, View $view): ResponseInterface
    {
        $body = $request->getParsedBody();
        $login = is_array($body) && isset($body['login']) && is_string($body['login']) ? trim($body['login']) : '';
        $pass  = is_array($body) && isset($body['password']) && is_string($body['password']) ? $body['password'] : '';
        $ip    = $this->resolveIp($request);
        $ua    = $request->getHeaderLine('User-Agent') ?: null;

        if ($login === '' || $pass === '') {
            return $this->fail($response, 'auth.wrong_credentials', $login, $ip, $ua, $view);
        }

        $admin = $this->admins->findByLogin($login);
        if ($admin === null) {
            // Spend the same Argon2id time as a real verify so a non-existent
            // login can't be distinguished from a valid one by response timing.
            $this->hasher->verifyDummy($pass);
            return $this->fail($response, 'auth.wrong_credentials', $login, $ip, $ua, $view);
        }

        // Escalating lockout — independent of RateLimitMiddleware's fixed
        // windows, which reset every 5 minutes with no memory of prior
        // failures and are keyed by IP (bypassable by rotating source IP)
        // as well as by login. While locked, short-circuit before even
        // checking the password and don't touch the counters — a genuine
        // owner retrying too soon shouldn't extend their own lockout, and
        // an attacker gets no extra signal either way.
        if ($admin->isLocked()) {
            $this->hasher->verifyDummy($pass);
            return $this->fail($response, 'auth.wrong_credentials', $login, $ip, $ua, $view);
        }

        if (!$this->hasher->verify($pass, $admin->passwordHash)) {
            $result = $this->admins->recordFailedLogin($admin->id);
            if ($result['justLocked'] && $this->tg->isConfigured()) {
                $until = $result['lockedUntil']?->format('H:i:s T') ?? '?';
                $this->tg->send(sprintf(
                    "🔒 <b>Admin login locked</b>\nAccount: <code>%s</code>\n5 failed attempts — locked until %s (doubles each further attempt)\nIP: %s",
                    htmlspecialchars($admin->login, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    htmlspecialchars($until, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    htmlspecialchars($ip ?? 'unknown', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                ));
            }
            return $this->fail($response, 'auth.wrong_credentials', $login, $ip, $ua, $view);
        }

        // Success: reset lockout, rehash if needed, set session, log
        $this->admins->resetLockout($admin->id);
        if ($this->hasher->needsRehash($admin->passwordHash)) {
            $this->admins->updatePassword($admin->id, $this->hasher->hash($pass));
        }

        // Regenerate session id on privilege elevation (prevents session fixation)
        session_regenerate_id(true);
        $_SESSION['admin_id'] = $admin->id;
        unset($_SESSION['_old']);

        $this->audit->log(
            AuthEventLogger::EVENT_LOGIN_SUCCESS,
            adminLogin: $admin->login,
            ip: $ip,
            userAgent: $ua,
        );

        // If must change password, redirect to /admin/password, else dashboard
        $target = $admin->mustChangePassword ? '/admin/password' : '/admin';
        return $response->withHeader('Location', $target)->withStatus(302);
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $adminLogin = null;
        if (isset($_SESSION['admin_id']) && is_int($_SESSION['admin_id'])) {
            $admin = $this->admins->findById($_SESSION['admin_id']);
            $adminLogin = $admin?->login;
        }

        $_SESSION = [];
        // Use regenerate + destroy (rather than session_destroy alone) to also clear the cookie
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            session_destroy();
        }

        $this->audit->log(
            AuthEventLogger::EVENT_LOGOUT,
            adminLogin: $adminLogin,
            ip: $this->resolveIp($request),
            userAgent: $request->getHeaderLine('User-Agent') ?: null,
        );

        return $response->withHeader('Location', '/admin/login')->withStatus(302);
    }

    private function fail(
        ResponseInterface $response,
        string $messageKey,
        string $login,
        ?string $ip,
        ?string $ua,
        View $view,
    ): ResponseInterface {
        $_SESSION['_old'] = ['login' => $login];
        flash_push('error', $view->i18n->t($messageKey));

        $this->audit->log(
            AuthEventLogger::EVENT_LOGIN_FAIL,
            adminLogin: $login !== '' ? $login : null,
            ip: $ip,
            userAgent: $ua,
            details: ['reason' => $messageKey],
        );

        return $response->withHeader('Location', '/admin/login')->withStatus(302);
    }

    private function resolveIp(ServerRequestInterface $request): ?string
    {
        // Use the same trusted-proxy-aware resolution as RateLimitMiddleware so
        // the audit trail records the real client IP (not the CF/proxy edge)
        // if the admin host is ever placed behind a proxy. Forwarding headers
        // are only honoured from trusted peers, so this can't be spoofed.
        $ip = RealIp::from($request);
        return $ip !== '' && $ip !== '0.0.0.0' ? $ip : null;
    }
}
