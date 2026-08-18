<?php

declare(strict_types=1);

namespace App\Shared\RateLimit;

use App\Shared\RealIp;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Per-IP rate limit for public, unauthenticated endpoints — postback,
 * pixel events, and session-recording chunks. Unlike the admin login these
 * routes have no other throttle: campaign slugs and the pixel script are
 * not secret (visible in any lander's page source), so a flood is a plain
 * DoS/DB-fill risk with nothing else standing in the way.
 *
 * Scoped per route path (`postback`, `p/event`, `p/rec` get independent
 * buckets) so one noisy endpoint doesn't exhaust another's budget, using a
 * single `RATE_LIMIT_PUBLIC` env knob to keep configuration simple.
 */
final class PublicRateLimitMiddleware implements MiddlewareInterface
{
    private const WINDOW_SECONDS = 60;

    public function __construct(private readonly RateLimiter $limiter) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $ip = RealIp::from($request);
        if ($ip === '' || $ip === '0.0.0.0') {
            return $handler->handle($request);
        }

        $max = (int)($_ENV['RATE_LIMIT_PUBLIC'] ?? 120);
        $scope = trim($request->getUri()->getPath(), '/') ?: 'public';
        $decision = $this->limiter->hit("pub:{$scope}:{$ip}", $max, self::WINDOW_SECONDS);

        if (!$decision->allowed) {
            $res = (new ResponseFactory())->createResponse(429);
            $res->getBody()->write('Rate limit exceeded. Try again later.');
            return $res
                ->withHeader('Content-Type', 'text/plain')
                ->withHeader('Retry-After', (string)max(1, $decision->resetAt - time()));
        }

        return $handler->handle($request);
    }
}
