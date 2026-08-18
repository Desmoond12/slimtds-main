<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\ConversionRepository;
use App\Shared\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Uuid;

final class ConversionController
{
    public function __construct(
        private readonly ConversionRepository $repo,
        private readonly CampaignRepository $campaigns,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response, View $view): ResponseInterface
    {
        $params = $request->getQueryParams();
        $page = max(1, (int)($params['page'] ?? '1'));
        $perPage = 50;

        $filters = [
            'campaign_id' => $params['campaign_id'] ?? null,
            'status'      => $params['status']      ?? null,
            'event_type'  => $params['event_type']  ?? null,
            'since'       => $params['since']       ?? null,
        ];

        $items = $this->repo->page($page, $perPage, $filters);
        $total = $this->repo->count($filters);
        $pages = max(1, (int)ceil($total / $perPage));
        $breakdown = $this->repo->statusBreakdown($filters);

        $data = array_merge(
            $view->withRequestContext($request),
            [
                'title' => 'Conversions',
                '__layout__' => 'layouts/admin',
                'items' => $items,
                'total' => $total,
                'pages' => $pages,
                'page' => $page,
                'filters' => $filters,
                'breakdown' => $breakdown,
                'campaigns' => $this->campaigns->page(1, 100),
            ],
        );
        return $view->respond($response, 'admin/conversions/index', $data);
    }

    public function history(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        // Path param comes straight from the URL — a malformed id (fat-fingered,
        // copy-paste mishap) must not reach Postgres as an untyped uuid param,
        // which would throw a PDOException (22P02) and 500 instead of a clean 404.
        if (!Uuid::isValid($id)) {
            return $this->json($response, ['ok' => false, 'error' => 'invalid id'], 404);
        }

        $rows = $this->repo->history($id);
        return $this->json($response, ['ok' => true, 'history' => $rows]);
    }

    /** @param array<string,mixed> $data */
    private function json(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
