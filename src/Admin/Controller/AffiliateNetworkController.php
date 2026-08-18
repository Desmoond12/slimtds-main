<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Form\AffiliateNetworkForm;
use App\Admin\Repository\AffiliateNetworkRepository;
use App\Shared\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AffiliateNetworkController
{
    public function __construct(
        private readonly AffiliateNetworkRepository $repo,
        private readonly AffiliateNetworkForm $form,
    ) {}

    public function all(ServerRequestInterface $request, ResponseInterface $response, View $view): ResponseInterface
    {
        $params = $request->getQueryParams();
        $q = isset($params['q']) && is_string($params['q']) ? $params['q'] : null;
        $page = max(1, (int)($params['page'] ?? '1'));
        $perPage = 25;

        $items = $this->repo->pageAll($page, $perPage, $q);
        $total = $this->repo->countAll($q);
        $pages = max(1, (int)ceil($total / $perPage));

        $data = array_merge(
            $view->withRequestContext($request),
            [
                'title' => $view->i18n->t('networks.title'),
                '__layout__' => 'layouts/admin',
                'items' => $items,
                'total' => $total,
                'pages' => $pages,
                'page' => $page,
                'q' => $q ?? '',
            ],
        );
        return $view->respond($response, 'admin/networks/all', $data);
    }

    public function new_(ServerRequestInterface $request, ResponseInterface $response, View $view): ResponseInterface
    {
        $data = array_merge(
            $view->withRequestContext($request),
            [
                'title' => $view->i18n->t('networks.create'),
                '__layout__' => 'layouts/admin',
                'network' => null,
                'errors' => $_SESSION['_errors'] ?? [],
            ],
        );
        unset($_SESSION['_errors']);
        return $view->respond($response, 'admin/networks/form', $data);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $data = is_array($body) ? $body : [];

        $errors = $this->form->validate($data);
        if (!empty($errors)) {
            $_SESSION['_old'] = $data;
            $_SESSION['_errors'] = $errors;
            return $response->withHeader('Location', '/admin/networks/new')->withStatus(302);
        }

        $data['status_map'] = $this->form->extractStatusMap($data);
        $data['event_map'] = $this->form->extractEventMap($data);
        $data['allowed_ips'] = $this->form->extractAllowedIps($data);
        $network = $this->repo->create($data);
        flash_push('success', "Network {$network->name} created");
        return $response->withHeader('Location', "/admin/networks/{$network->id}/edit")->withStatus(302);
    }

    public function edit(ServerRequestInterface $request, ResponseInterface $response, View $view, string $id): ResponseInterface
    {
        $network = $this->repo->findById($id);
        if ($network === null) {
            return $response->withHeader('Location', '/admin/networks')->withStatus(302);
        }
        $data = array_merge(
            $view->withRequestContext($request),
            [
                'title' => $view->i18n->t('networks.edit'),
                '__layout__' => 'layouts/admin',
                'network' => $network,
                'errors' => $_SESSION['_errors'] ?? [],
            ],
        );
        unset($_SESSION['_errors']);
        return $view->respond($response, 'admin/networks/form', $data);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $network = $this->repo->findById($id);
        if ($network === null) {
            return $response->withHeader('Location', '/admin/networks')->withStatus(302);
        }

        $body = $request->getParsedBody();
        $data = is_array($body) ? $body : [];
        $errors = $this->form->validate($data);
        if (!empty($errors)) {
            $_SESSION['_old'] = $data;
            $_SESSION['_errors'] = $errors;
            return $response->withHeader('Location', "/admin/networks/{$id}/edit")->withStatus(302);
        }

        $data['status_map'] = $this->form->extractStatusMap($data);
        $data['event_map'] = $this->form->extractEventMap($data);
        $data['allowed_ips'] = $this->form->extractAllowedIps($data);
        $this->repo->update($id, $data);
        flash_push('success', 'Network updated');
        return $response->withHeader('Location', "/admin/networks/{$id}/edit")->withStatus(302);
    }

    public function deleteConfirm(ServerRequestInterface $request, ResponseInterface $response, View $view, string $id): ResponseInterface
    {
        $network = $this->repo->findById($id);
        if ($network === null) {
            return $response->withHeader('Location', '/admin/networks')->withStatus(302);
        }
        $data = array_merge(
            $view->withRequestContext($request),
            [
                'title' => $view->i18n->t('networks.delete'),
                '__layout__' => 'layouts/admin',
                'network' => $network,
            ],
        );
        return $view->respond($response, 'admin/networks/delete', $data);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $this->repo->delete($id);
        flash_push('success', 'Network deleted');
        return $response->withHeader('Location', '/admin/networks')->withStatus(302);
    }
}
