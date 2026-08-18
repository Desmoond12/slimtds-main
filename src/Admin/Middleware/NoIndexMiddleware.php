<?php

declare(strict_types=1);

namespace App\Admin\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Stamps every admin response with X-Robots-Tag telling search engines not to
 * index or follow anything under /admin. The panel sits behind login, but the
 * login page URL itself is still fetchable — without this a crawler that
 * discovers it (a leaked link, a referer, certificate-transparency scraping)
 * could list the tracker's admin login in search results. noarchive/nosnippet
 * also keep it out of caches and result snippets. Belt-and-suspenders next to
 * the domain-wide /robots.txt (see config/routes.php): robots.txt asks nicely,
 * this header travels with the response even if robots.txt is ignored.
 */
final class NoIndexMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)
            ->withHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
    }
}
