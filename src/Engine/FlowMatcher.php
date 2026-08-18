<?php

declare(strict_types=1);

namespace App\Engine;

use App\Admin\Repository\Flow;
use App\Admin\Repository\FlowRepository;

final class FlowMatcher
{
    /** @var array<string, array{ts:int, flows: list<Flow>}> */
    private array $cache = [];
    // FrankenPHP runs multiple long-lived worker processes (num 8 in prod —
    // see config/frankenphp/Caddyfile.cf), each with its own independent
    // instance of this cache. invalidate() (called by FlowController on every
    // create/update/delete/move) only clears the one worker that served the
    // admin request — the other 7 keep serving whatever they last cached
    // until their own TTL expires. 60s meant an operator disabling a bad
    // flow (compliance issue, wrong-geo leak, dead offer) could still see it
    // fire for up to a minute on 7/8 of live traffic. Kept short rather than
    // eliminated: a real cross-process invalidation channel (Postgres
    // LISTEN/NOTIFY, etc.) is more machinery than a single-tenant internal
    // tool needs — a few seconds of staleness is an acceptable trade for
    // not adding a DB round-trip to the hot path on every request.
    private const CACHE_TTL = 5; // seconds

    public function __construct(
        private readonly FlowRepository $repo,
        private readonly FilterCompiler $compiler,
    ) {}

    public function match(string $campaignId, Context $ctx): ?Flow
    {
        $flows = $this->cachedFlows($campaignId);
        foreach ($flows as $flow) {
            if (!$flow->isActive) continue;
            $predicate = $this->compiler->compile($flow->filters);
            if ($predicate($ctx)) {
                $ctx->matchedFlowId = $flow->id;
                return $flow;
            }
        }
        return null;
    }

    /** @return list<Flow> */
    private function cachedFlows(string $campaignId): array
    {
        $now = time();
        $entry = $this->cache[$campaignId] ?? null;
        if ($entry !== null && $now - $entry['ts'] < self::CACHE_TTL) {
            return $entry['flows'];
        }
        $flows = $this->repo->forCampaign($campaignId);
        $this->cache[$campaignId] = ['ts' => $now, 'flows' => $flows];
        return $flows;
    }

    public function invalidate(string $campaignId): void
    {
        unset($this->cache[$campaignId]);
    }
}
