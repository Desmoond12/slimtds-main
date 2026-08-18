<?php

declare(strict_types=1);

namespace App\Engine\Schema;

use App\Engine\Context;
use Psr\Http\Message\ResponseInterface;

final class ShowTextSchema implements Schema
{
    public function respond(Context $ctx, ?string $outUrl, array $config, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write((string)($config['body'] ?? ''));
        // Macros here are intentionally left unescaped (config['body'] can contain
        // literal '<'/'>' as plain text) — nosniff stops a browser from ever
        // MIME-sniffing this as HTML and executing embedded markup/script.
        return $response
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
