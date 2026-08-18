<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Repository\OfferRepository;
use App\Admin\Repository\PostbackRequestRepository;
use App\Shared\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Raw postback audit trail + a "fire a test postback" helper — Tools →
 * Postback log. The tester form doesn't call PostbackController directly;
 * it points the browser at the real /postback endpoint (see
 * resources/js/app.js postbackTester), so a test hit is indistinguishable
 * from a real partner hit and always lands in the same log this page reads.
 */
final class PostbackLogController
{
    public function __construct(
        private readonly PostbackRequestRepository $repo,
        private readonly OfferRepository $offers,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response, View $view): ResponseInterface
    {
        $params = $request->getQueryParams();
        $page = max(1, (int)($params['page'] ?? '1'));
        $perPage = 50;

        $filters = ['processing_status' => $params['processing_status'] ?? null];

        $items = $this->repo->page($page, $perPage, $filters);
        $total = $this->repo->count($filters);
        $pages = max(1, (int)ceil($total / $perPage));

        $data = array_merge(
            $view->withRequestContext($request),
            [
                'title' => 'Postback log',
                '__layout__' => 'layouts/admin',
                'items' => $items,
                'total' => $total,
                'pages' => $pages,
                'page' => $page,
                'filters' => $filters,
                'offers' => $this->offers->all(200),
            ],
        );
        return $view->respond($response, 'admin/postback-log/index', $data);
    }
}
