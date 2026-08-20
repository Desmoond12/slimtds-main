<?php

declare(strict_types=1);

use App\Engine\Context;
use App\Engine\Schema\JsRedirectSchema;
use Slim\Psr7\Response;

test('js redirect emits window.location with the target url', function (): void {
    $schema = new JsRedirectSchema();
    $ctx = new Context('1.1.1.1', 'curl', 'demo', time());
    $resp = $schema->respond($ctx, 'https://example.com/landing', [], new Response());
    expect($resp->getStatusCode())->toBe(200);
    expect($resp->getHeaderLine('Content-Type'))->toContain('text/html');
    $body = (string)$resp->getBody();
    expect($body)->toContain('window.location.replace');
    expect($body)->toContain('https://example.com/landing');
});

test('js redirect neutralises </script> breakout in the JS body (XSS)', function (): void {
    $schema = new JsRedirectSchema();
    $ctx = new Context('1.1.1.1', 'curl', 'demo', time());
    // Simulates a visitor-controlled macro ({utm_source}/{referer}) that reached
    // the target URL in raw mode and tries to break out of the <script> element.
    $payload = 'https://e.com/?u=</script><img src=x onerror=alert(1)>';
    $resp = $schema->respond($ctx, $payload, [], new Response());
    $body = (string)$resp->getBody();

    // The angle brackets of the injected payload must be hex-escaped
    // (< / >) inside the JS string literal, so the raw <img ...>
    // markup cannot appear verbatim and the injected </script> cannot close
    // the <script> element early.
    expect($body)->not->toContain('<img src=x onerror');
    expect($body)->toContain('\\u003C'); // the payload's "<" was hex-escaped in the JS literal
});

test('js redirect returns 204 for a null/empty target', function (): void {
    $schema = new JsRedirectSchema();
    $resp = $schema->respond(new Context('1.1.1.1', 'curl', 'demo', time()), null, [], new Response());
    expect($resp->getStatusCode())->toBe(204);
});
