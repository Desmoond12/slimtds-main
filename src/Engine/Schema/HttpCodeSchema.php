<?php

declare(strict_types=1);

namespace App\Engine\Schema;

use App\Engine\Context;
use Psr\Http\Message\ResponseInterface;

final class HttpCodeSchema implements Schema
{
    public function respond(Context $ctx, ?string $outUrl, array $config, ResponseInterface $response): ResponseInterface
    {
        $code = (int)($config['status_code'] ?? 200);
        if ($code < 100 || $code > 599) $code = 200;
        $body = (string)($config['body'] ?? '');
        if ($body === '') {
            return $response->withStatus($code);
        }
        $response->getBody()->write($body);
        // Explicit Content-Type so an arbitrary status+body response can't
        // fall back to a framework default that gets HTML-rendered/sniffed
        // by the browser — ClickHandler HTML-escapes macro substitutions in
        // this body (schema_id 14), consistent with declaring it as HTML here.
        return $response->withStatus($code)->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
