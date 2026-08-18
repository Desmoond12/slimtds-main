<?php

declare(strict_types=1);

namespace App\Postback;

use App\Admin\Repository\AffiliateNetwork;

/**
 * Pulls the five postback fields out of a request's query/body params using
 * a specific network's configured param names instead of our own hardcoded
 * subid/status/payout/external_id/event_type — see
 * migrations/20260811000001_affiliate_networks.php for why this exists.
 * Pure functions, no DB/IO — kept out of PostbackController so the mapping
 * logic is unit-testable in isolation from the rest of the (already long)
 * postback flow.
 */
final class AffiliateNetworkAdapter
{
    /**
     * @param array<string,mixed> $params
     * @return array{subid:string, statusPresent:bool, status:string, payout:mixed, externalId:?string, eventType:string}
     */
    public static function extract(AffiliateNetwork $net, array $params): array
    {
        $statusPresent = isset($params[$net->statusParam]);
        $eventType = isset($params[$net->eventTypeParam]) ? strtolower(trim((string)$params[$net->eventTypeParam])) : '';

        return [
            'subid'         => isset($params[$net->clickParam]) ? trim((string)$params[$net->clickParam]) : '',
            'statusPresent' => $statusPresent,
            'status'        => $statusPresent ? trim((string)$params[$net->statusParam]) : 'approved',
            'payout'        => $params[$net->payoutParam] ?? '0',
            'externalId'    => isset($params[$net->externalIdParam]) ? trim((string)$params[$net->externalIdParam]) : null,
            'eventType'     => $eventType !== '' ? $eventType : 'conversion',
        ];
    }

    /**
     * Translate a raw partner status value to our canonical vocabulary.
     * An empty status_map means the network already sends our own words
     * (approved/pending/hold/rejected) — pass the value through unchanged,
     * same as the no-network default path. A non-empty map that doesn't
     * contain the raw value is a genuine mapping gap (typo, new partner
     * status we haven't configured) — returns null so the caller can treat
     * it exactly like today's "invalid status" case (400, not a silent
     * guess).
     */
    public static function translateStatus(AffiliateNetwork $net, string $raw): ?string
    {
        if ($net->statusMap === []) {
            return $raw;
        }
        return $net->statusMap[$raw] ?? $net->statusMap[strtolower($raw)] ?? null;
    }

    /**
     * Translate a raw partner event value ("firstDeposit", "goal=2"-style
     * codes) to the operator's canonical event vocabulary (reg/ftd/
     * redeposit/...), so per-event stats don't fork into synonym duplicates
     * across networks. Unlike translateStatus, an unmapped value passes
     * through UNCHANGED (lowercased) rather than failing: event_type is an
     * open taxonomy, an unconfigured value is not a protocol violation, and
     * a money postback must never be rejected over cosmetic naming — it
     * stays visible in stats/postback-log under its raw name instead.
     */
    public static function translateEvent(AffiliateNetwork $net, string $raw): string
    {
        if ($net->eventMap === []) {
            return $raw;
        }
        $mapped = $net->eventMap[$raw] ?? $net->eventMap[strtolower($raw)] ?? null;
        $mapped = $mapped !== null ? strtolower(trim($mapped)) : '';
        return $mapped !== '' ? $mapped : $raw;
    }
}
