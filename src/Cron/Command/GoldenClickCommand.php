<?php

declare(strict_types=1);

namespace App\Cron\Command;

use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\OfferRepository;
use App\Engine\Context;
use App\Engine\FlowMatcher;
use App\Engine\OfferPicker;
use App\Shared\Telegram\TelegramNotifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Golden Click — synthetic end-to-end canary for the redirect engine.
 *
 * Runs the real routing pipeline (campaign → flow match → offer pick → offer URL)
 * for a synthetic visitor, entirely IN-PROCESS: FlowMatcher::match and
 * OfferPicker::pick are pure (no DB writes) and we deliberately never call
 * ClickHandler::logClick, so this leaves ZERO rows in stats.clicks / conversions
 * and cannot pollute reports. It catches the failures that only surface under
 * real traffic: DB down, all offers disabled, a flow that matches nothing, a
 * dangling offer id — before real clicks hit them.
 *
 * The routing check is opt-in: set GOLDEN_SLUG to the slug of a dedicated (or
 * any real) campaign you want continuously verified. Without it, the command
 * still confirms DB reachability every run.
 *
 * On failure it pages the operator via Telegram — but only from APP_ENV=prod
 * (dev/test share the same TG channel), matching TelegramAlertsCommand.
 */
#[AsCommand(name: 'monitor:golden-click', description: 'Synthetic canary: verify the click→flow→offer routing chain is alive')]
final class GoldenClickCommand extends Command
{
    public function __construct(
        private readonly CampaignRepository $campaigns,
        private readonly OfferRepository $offers,
        private readonly FlowMatcher $matcher,
        private readonly OfferPicker $picker,
        private readonly TelegramNotifier $tg,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // 1) DB reachability — a harmless indexed lookup. Throws if Postgres is down.
        try {
            $this->campaigns->findBySlug('__golden_probe__');
        } catch (\Throwable $e) {
            return $this->fail($output, ['DB unreachable: ' . $e->getMessage()]);
        }

        // 2) Routing check (opt-in). No GOLDEN_SLUG → liveness/DB only.
        $slug = trim((string)($_ENV['GOLDEN_SLUG'] ?? ''));
        if ($slug === '') {
            $output->writeln('<comment>GOLDEN_SLUG not set — DB/liveness OK, routing check skipped.</comment>');
            return self::SUCCESS;
        }

        $problems = $this->checkRouting($slug, $output);
        if ($problems !== []) {
            return $this->fail($output, $problems);
        }

        $output->writeln('<info>golden-click chain healthy</info>');
        return self::SUCCESS;
    }

    /**
     * Reproduces ClickHandler's routing (minus every side effect) for a synthetic
     * US/desktop visitor and returns a list of human-readable problems (empty = ok).
     *
     * @return list<string>
     */
    private function checkRouting(string $slug, OutputInterface $output): array
    {
        $campaign = $this->campaigns->findBySlug($slug);
        if ($campaign === null) {
            return ["golden campaign '{$slug}' not found"];
        }
        if (!$campaign->isActive) {
            return ["golden campaign '{$slug}' is inactive"];
        }

        $ctx = new Context('8.8.8.8', 'Mozilla/5.0 (golden-click monitor)', $slug, time());
        $ctx->country     = 'us';
        $ctx->device      = 'desktop';
        $ctx->isBot       = false;
        // Fixed uuid so sticky offer selection is deterministic run to run.
        $ctx->visitorUuid = '00000000-0000-7000-8000-000000000001';

        $flow = $this->matcher->match($campaign->id, $ctx);
        if ($flow === null) {
            return ["no flow matched for '{$slug}' (synthetic US/desktop click)"];
        }

        // Non-offer flows (direct schema/URL) are healthy as soon as a flow matches.
        if ($flow->targetType !== 'offers') {
            $output->writeln("<info>OK: {$slug} → flow '{$flow->name}' (target_type={$flow->targetType})</info>");
            return [];
        }

        // Mirror ClickHandler: drop disabled offers before weighted selection.
        $candidateIds = array_values(array_unique(array_filter(
            array_map(static fn (array $t) => $t['offer_id'], $flow->targetOffers),
        )));
        $activeIds = array_flip($this->offers->filterActiveIds($candidateIds));
        $activeTargets = array_values(array_filter(
            $flow->targetOffers,
            static fn (array $t) => isset($activeIds[$t['offer_id']]),
        ));
        if ($activeTargets === []) {
            return ["flow '{$flow->name}' has no active offers (all disabled?)"];
        }

        $offerId = $this->picker->pick($activeTargets, $ctx, true);
        $offer = $offerId !== null ? $this->offers->findById($offerId) : null;
        if ($offer === null || trim($offer->url) === '') {
            return ['offer did not resolve to a URL (offer_id=' . ($offerId ?? 'null') . ')'];
        }

        $output->writeln("<info>OK: {$slug} → flow '{$flow->name}' → offer {$offerId}</info>");
        return [];
    }

    /**
     * @param list<string> $problems
     */
    private function fail(OutputInterface $output, array $problems): int
    {
        foreach ($problems as $p) {
            $output->writeln("<error>{$p}</error>");
        }

        // Page the operator, prod-only (dev/test share the same Telegram channel).
        $env = strtolower((string)($_ENV['APP_ENV'] ?? 'dev'));
        if ($env === 'prod' && $this->tg->isConfigured()) {
            $lines = array_map(
                static fn (string $p) => '• ' . htmlspecialchars($p, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $problems,
            );
            $this->tg->send("🚨 <b>Golden Click FAILED</b>\nThe click→offer routing chain is broken:\n" . implode("\n", $lines));
        }

        return self::FAILURE;
    }
}
