<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Form\UrlMacroSampler;
use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\FlowRepository;
use App\Admin\Repository\OfferRepository;
use App\Engine\FlowMatcher;
use App\Shared\Db\Connection;
use App\Shared\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * "Новая связка" — funnel quick-start wizard.
 *
 * One form creates a complete, working funnel in a single transaction, using
 * the real repositories (same validation / id + token generation as the
 * individual editors — nothing is duplicated). Presets:
 *   - single : all traffic → one offer (worldwide)
 *   - geo    : bot → safe, country ∈ {…} → offer, everything else → blank fallback
 *   - ab     : split traffic between two offers by weight
 *
 * Order is mandatory (offers are global; the campaign↔offer link lives only in
 * flows.target_offers): Campaign → Offer(s) → Flow(s). Flows are inserted in
 * priority order (bot-trap first, catch-all last) because position auto-numbers
 * MAX+1 and the engine matches the first active flow.
 */
final class FunnelWizardController
{
    private const PRESETS = ['single', 'geo', 'ab'];

    public function __construct(
        private readonly Connection $db,
        private readonly CampaignRepository $campaigns,
        private readonly OfferRepository $offers,
        private readonly FlowRepository $flows,
        private readonly FlowMatcher $matcher,
    ) {}

    public function new_(ServerRequestInterface $request, ResponseInterface $response, View $view): ResponseInterface
    {
        $data = array_merge(
            $view->withRequestContext($request),
            [
                'title'      => $view->i18n->t('funnel.title'),
                '__layout__' => 'layouts/admin',
                'errors'     => $_SESSION['_errors'] ?? [],
            ],
        );
        unset($_SESSION['_errors']);
        return $view->respond($response, 'admin/funnels/new', $data);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $d = is_array($body) ? $body : [];

        $preset      = in_array($d['preset'] ?? '', self::PRESETS, true) ? (string)$d['preset'] : 'geo';
        $name        = trim((string)($d['campaign_name'] ?? ''));
        $urlA        = trim((string)($d['offer_url'] ?? ''));
        $urlB        = trim((string)($d['offer_url_b'] ?? ''));
        $geoRaw      = trim((string)($d['geo'] ?? ''));
        $cloak       = !empty($d['cloak']);
        $officialUrl = trim((string)($d['official_url'] ?? ''));
        $weightA     = max(1, (int)($d['weight_a'] ?? 70));
        $weightB     = max(1, (int)($d['weight_b'] ?? 30));

        // ── Validation (mirrors OfferForm's URL check: sample macros, then FILTER_VALIDATE_URL)
        $errors = [];
        if ($name === '') {
            $errors['campaign_name'] = 'validation.required';
        }
        if ($urlA === '') {
            $errors['offer_url'] = 'validation.required';
        } elseif (!filter_var(UrlMacroSampler::sample($urlA), FILTER_VALIDATE_URL)) {
            $errors['offer_url'] = 'validation.pattern';
        }
        if ($preset === 'ab') {
            if ($urlB === '') {
                $errors['offer_url_b'] = 'validation.required';
            } elseif (!filter_var(UrlMacroSampler::sample($urlB), FILTER_VALIDATE_URL)) {
                $errors['offer_url_b'] = 'validation.pattern';
            }
        }
        $geoCodes = [];
        if ($preset === 'geo') {
            $geoCodes = $this->parseGeo($geoRaw);
            if ($geoCodes === []) {
                $errors['geo'] = 'validation.required';
            }
        }
        if ($cloak && $officialUrl !== '' && !filter_var(UrlMacroSampler::sample($officialUrl), FILTER_VALIDATE_URL)) {
            $errors['official_url'] = 'validation.pattern';
        }

        if ($errors !== []) {
            $_SESSION['_old'] = $d;
            $_SESSION['_errors'] = $errors;
            return $response->withHeader('Location', '/admin/funnels/new')->withStatus(302);
        }

        // Smart: make sure the click id reaches the partner so postbacks can attribute.
        $urlA = $this->ensureClickId($urlA);
        if ($preset === 'ab') {
            $urlB = $this->ensureClickId($urlB);
        }

        $campaign = $this->db->transactional(function () use ($preset, $name, $urlA, $urlB, $geoCodes, $cloak, $officialUrl, $weightA, $weightB) {
            $c = $this->campaigns->create(['name' => $name, 'is_active' => '1']);

            $offerA = $this->offers->create([
                'name'          => $preset === 'ab' ? $name . ' — A' : $name,
                'url'           => $urlA,
                'payout_default' => '',
                'currency'      => 'USD',
                'is_active'     => '1',
                'postback_urls' => [],
            ]);

            $targets = [['offer_id' => $offerA->id, 'weight' => $preset === 'ab' ? $weightA : 100]];
            if ($preset === 'ab') {
                $offerB = $this->offers->create([
                    'name'          => $name . ' — B',
                    'url'           => $urlB,
                    'payout_default' => '',
                    'currency'      => 'USD',
                    'is_active'     => '1',
                    'postback_urls' => [],
                ]);
                $targets[] = ['offer_id' => $offerB->id, 'weight' => $weightB];
            }

            // 1) Bot trap FIRST (highest priority) — bots never reach the money offer.
            if ($cloak) {
                $this->flows->create($c->id, [
                    'name'          => 'Боты → безопасно',
                    'filters'       => [[['field' => 'is_bot', 'op' => 'eq', 'value' => '1']]],
                    'target_type'   => 'none',
                    'schema_id'     => $officialUrl !== '' ? 2 : 13, // 2=302 to official site, 13=blank 200
                    'schema_config' => $officialUrl !== '' ? ['url' => $officialUrl] : [],
                    'weight'        => 100,
                    'is_active'     => '1',
                ]);
            }

            // 2) Money flow — geo-filtered or catch-all.
            $this->flows->create($c->id, [
                'name'          => $preset === 'geo'
                    ? 'Гео → оффер (' . strtoupper(implode(', ', $geoCodes)) . ')'
                    : 'Весь трафик → оффер',
                'filters'       => $preset === 'geo'
                    ? [[['field' => 'country', 'op' => 'in', 'value' => implode(',', $geoCodes)]]]
                    : [],
                'target_type'   => 'offers',
                'target_offers' => $targets,
                'schema_id'     => 2, // 302
                'weight'        => 100,
                'is_active'     => '1',
            ]);

            // 3) Fallback (geo preset only) — everything else → blank 200 (no wasted traffic to a geo-locked offer).
            if ($preset === 'geo') {
                $this->flows->create($c->id, [
                    'name'        => 'Фолбэк (остальные гео)',
                    'filters'     => [], // catch-all
                    'target_type' => 'none',
                    'schema_id'   => 13, // blank 200
                    'weight'      => 100,
                    'is_active'   => '1',
                ]);
            }

            return $c;
        });

        // Post-commit side effects only.
        $this->matcher->invalidate($campaign->id);
        flash_push('success', "Связка «{$campaign->slug}» создана");
        return $response->withHeader('Location', '/admin/campaigns/' . $campaign->id . '/flows')->withStatus(302);
    }

    /**
     * Append subid={click_id} if the offer URL has no click macro, so the
     * partner echoes it back and the postback can attribute the conversion.
     */
    private function ensureClickId(string $url): string
    {
        if (str_contains($url, '{click_id}')) {
            return $url;
        }
        $sep = str_contains($url, '?') ? '&' : '?';
        return $url . $sep . 'subid={click_id}';
    }

    /**
     * Parse a free-form country list ("IT, DE at") into unique lowercase ISO-2
     * codes (the country field is stored lowercase; FilterCompiler 'in' splits
     * on comma).
     *
     * @return list<string>
     */
    private function parseGeo(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', strtolower($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $codes = [];
        foreach ($parts as $p) {
            if (preg_match('/^[a-z]{2}$/', $p)) {
                $codes[$p] = true;
            }
        }
        return array_keys($codes);
    }
}
