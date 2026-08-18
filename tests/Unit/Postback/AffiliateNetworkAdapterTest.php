<?php

declare(strict_types=1);

use App\Admin\Repository\AffiliateNetwork;
use App\Postback\AffiliateNetworkAdapter;

/** @param array<string,string> $statusMap */
function makeNetwork(
    string $clickParam = 'subid',
    string $statusParam = 'status',
    string $payoutParam = 'payout',
    string $externalIdParam = 'external_id',
    string $eventTypeParam = 'event_type',
    array $statusMap = [],
): AffiliateNetwork {
    return new AffiliateNetwork(
        id: '019fe000-0000-7000-8000-000000000001',
        name: 'Test Network',
        clickParam: $clickParam,
        statusParam: $statusParam,
        payoutParam: $payoutParam,
        externalIdParam: $externalIdParam,
        eventTypeParam: $eventTypeParam,
        statusMap: $statusMap,
        notes: null,
        isActive: true,
        createdAt: new DateTimeImmutable(),
        updatedAt: new DateTimeImmutable(),
    );
}

test('extract pulls fields by the network custom param names', function (): void {
    $net = makeNetwork(clickParam: 'clickid', statusParam: 'event', payoutParam: 'amount', externalIdParam: 'txn', eventTypeParam: 'etype');
    $params = ['clickid' => 'abc-123', 'event' => 'ok', 'amount' => '25.50', 'txn' => 'T1', 'etype' => 'FTD'];

    $r = AffiliateNetworkAdapter::extract($net, $params);

    expect($r['subid'])->toBe('abc-123');
    expect($r['statusPresent'])->toBeTrue();
    expect($r['status'])->toBe('ok');
    expect($r['payout'])->toBe('25.50');
    expect($r['externalId'])->toBe('T1');
    expect($r['eventType'])->toBe('ftd');
});

test('extract defaults status to approved and statusPresent=false when the param is absent', function (): void {
    $net = makeNetwork(statusParam: 'event');
    $r = AffiliateNetworkAdapter::extract($net, ['subid' => 'x']);

    expect($r['statusPresent'])->toBeFalse();
    expect($r['status'])->toBe('approved');
});

test('extract defaults eventType to conversion when the param is absent or blank', function (): void {
    $net = makeNetwork();
    $r = AffiliateNetworkAdapter::extract($net, []);
    expect($r['eventType'])->toBe('conversion');
});

test('translateStatus passes raw value through unchanged when status_map is empty', function (): void {
    $net = makeNetwork();
    expect(AffiliateNetworkAdapter::translateStatus($net, 'approved'))->toBe('approved');
    // Pass-through means garbage also passes through — validation against
    // the canonical set happens in the caller (PostbackController), not here.
    expect(AffiliateNetworkAdapter::translateStatus($net, 'garbage'))->toBe('garbage');
});

test('translateStatus maps a raw partner value to canonical status', function (): void {
    $net = makeNetwork(statusMap: ['1' => 'approved', '0' => 'rejected', '2' => 'pending']);
    expect(AffiliateNetworkAdapter::translateStatus($net, '1'))->toBe('approved');
    expect(AffiliateNetworkAdapter::translateStatus($net, '0'))->toBe('rejected');
});

test('translateStatus is case-insensitive on the raw key as a fallback', function (): void {
    $net = makeNetwork(statusMap: ['ok' => 'approved']);
    expect(AffiliateNetworkAdapter::translateStatus($net, 'OK'))->toBe('approved');
});

test('translateStatus returns null for a raw value not present in a non-empty map', function (): void {
    $net = makeNetwork(statusMap: ['1' => 'approved']);
    expect(AffiliateNetworkAdapter::translateStatus($net, '99'))->toBeNull();
});
