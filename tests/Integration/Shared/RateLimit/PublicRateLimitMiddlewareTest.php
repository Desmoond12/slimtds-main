<?php

declare(strict_types=1);

use App\Shared\Db\Connection;
use App\Shared\RateLimit\PublicRateLimitMiddleware;
use App\Shared\RateLimit\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

beforeEach(function (): void {
    $pdo = pdo();
    $pdo->exec('DELETE FROM core.rate_limits');
    $this->db  = new Connection($pdo);
    $this->mw  = new PublicRateLimitMiddleware(new RateLimiter($this->db));
    $_ENV['RATE_LIMIT_PUBLIC'] = '3';
});

afterEach(function (): void {
    unset($_ENV['RATE_LIMIT_PUBLIC']);
});

function prlRequest(string $path, string $ip): \Psr\Http\Message\ServerRequestInterface
{
    return (new ServerRequestFactory())->createServerRequest('POST', $path, ['REMOTE_ADDR' => $ip]);
}

function prlNext(): RequestHandlerInterface
{
    return new class implements RequestHandlerInterface {
        public function handle(\Psr\Http\Message\ServerRequestInterface $r): ResponseInterface {
            return (new Response())->withStatus(200);
        }
    };
}

test('allows requests under the limit', function (): void {
    for ($i = 0; $i < 3; $i++) {
        $resp = $this->mw->process(prlRequest('/postback', '198.51.100.1'), prlNext());
        expect($resp->getStatusCode())->toBe(200);
    }
});

test('blocks with 429 once the per-IP limit is exceeded', function (): void {
    for ($i = 0; $i < 3; $i++) {
        $this->mw->process(prlRequest('/postback', '198.51.100.2'), prlNext());
    }
    $resp = $this->mw->process(prlRequest('/postback', '198.51.100.2'), prlNext());
    expect($resp->getStatusCode())->toBe(429);
    expect($resp->getHeaderLine('Retry-After'))->not->toBe('');
});

test('different routes get independent buckets', function (): void {
    for ($i = 0; $i < 3; $i++) {
        $this->mw->process(prlRequest('/postback', '198.51.100.3'), prlNext());
    }
    // Same IP, different route — must not be blocked by /postback's bucket.
    $resp = $this->mw->process(prlRequest('/p/event', '198.51.100.3'), prlNext());
    expect($resp->getStatusCode())->toBe(200);
});

test('different IPs get independent buckets', function (): void {
    for ($i = 0; $i < 3; $i++) {
        $this->mw->process(prlRequest('/postback', '198.51.100.4'), prlNext());
    }
    $resp = $this->mw->process(prlRequest('/postback', '198.51.100.5'), prlNext());
    expect($resp->getStatusCode())->toBe(200);
});
