<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Repository\AffiliateNetworkRepository;
use App\Admin\Repository\ReconciliationRepository;
use App\Shared\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Tracker-vs-PP reconciliation screen (/admin/networks/{id}/reconciliation).
 * Joins our conversion ledger + clicks against the imported PP report rows
 * and highlights day/event buckets whose delta exceeds the threshold.
 */
final class ReconciliationController
{
    private const DEFAULT_THRESHOLD_PCT = 10.0;

    public function __construct(
        private readonly AffiliateNetworkRepository $networks,
        private readonly ReconciliationRepository $recon,
    ) {}

    public function show(ServerRequestInterface $request, ResponseInterface $response, View $view, string $id): ResponseInterface
    {
        $network = $this->networks->findById($id);
        if ($network === null) {
            return $response->withHeader('Location', '/admin/networks')->withStatus(302);
        }

        $q = $request->getQueryParams();
        [$defFrom, $defTo] = $this->recon->defaultRange($id);
        $from = $this->validDate($q['from'] ?? null) ?? $defFrom;
        $to   = $this->validDate($q['to'] ?? null) ?? $defTo;
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $threshold = self::DEFAULT_THRESHOLD_PCT;
        if (isset($q['threshold']) && is_string($q['threshold']) && is_numeric($q['threshold'])) {
            $threshold = max(0.0, min(100.0, (float)$q['threshold']));
        }

        $data = array_merge(
            $view->withRequestContext($request),
            [
                'title' => $view->i18n->t('recon.title') . ' — ' . $network->name,
                '__layout__' => 'layouts/admin',
                'network'   => $network,
                'from'      => $from,
                'to'        => $to,
                'threshold' => $threshold,
                'events'    => $this->recon->events($id, $from, $to),
                'clicks'    => $this->recon->clicks($id, $from, $to),
            ],
        );
        return $view->respond($response, 'admin/networks/reconciliation', $data);
    }

    private function validDate(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));
        return checkdate($m, $d, $y) ? $value : null;
    }
}
