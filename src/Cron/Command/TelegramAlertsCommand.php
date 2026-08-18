<?php

declare(strict_types=1);

namespace App\Cron\Command;

use App\Shared\Db\Connection;
use App\Shared\Telegram\TelegramNotifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'telegram:alerts', description: 'Send hourly system alerts to Telegram')]
final class TelegramAlertsCommand extends Command
{
    private const MAX_AGE_SECONDS = 14 * 86400; // 14 days
    private const MIN_DISK_BYTES  = 500 * 1024 * 1024; // 500 MB

    /** @var list<string> */
    private const MMDB_PATHS = [
        '/usr/share/GeoIP/GeoLite2-City.mmdb',
        '/usr/share/GeoIP/GeoLite2-ASN.mmdb',
    ];

    // Postback-health alert: minimum sample size before the ratio is
    // meaningful, and the CLICK_NOT_FOUND / UNKNOWN_TOKEN share of the last
    // hour's postbacks that pages the operator. A rising unknown-click rate
    // is the classic silent-revenue-loss signal (partner mangling subid,
    // clicks dropped by retention, misconfigured postback URL).
    private const PB_MIN_SAMPLE = 10;
    private const PB_UNKNOWN_RATE = 0.20;

    public function __construct(
        private readonly TelegramNotifier $tg,
        private readonly Connection $db,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Alerts are an ops signal — only meaningful in prod. Dev / test stacks
        // share the same .env (and thus the same Telegram channel), so without
        // this guard a local `make up` 14+ days ago would page the operator
        // about stale GeoIP files that don't apply to production.
        $env = strtolower((string)($_ENV['APP_ENV'] ?? 'dev'));
        if ($env !== 'prod') {
            $output->writeln("telegram:alerts skipped (APP_ENV={$env})");
            return self::SUCCESS;
        }

        $alerts = [];

        // GeoIP MMDB checks
        foreach (self::MMDB_PATHS as $path) {
            $name = basename($path);
            if (!file_exists($path)) {
                $alerts[] = "⚠️ GeoIP missing: <code>{$name}</code>";
                continue;
            }
            $age = time() - (int)filemtime($path);
            if ($age > self::MAX_AGE_SECONDS) {
                $days = (int)($age / 86400);
                $alerts[] = "⚠️ GeoIP stale ({$days}d): <code>{$name}</code>";
            }
        }

        // Disk space check
        $free = disk_free_space('/app');
        if ($free !== false && $free < self::MIN_DISK_BYTES) {
            $mb = (int)($free / 1024 / 1024);
            $alerts[] = "🔴 Low disk: <b>{$mb} MB</b> free on /app";
        }

        // Postback health — share of last-hour postbacks that failed click /
        // token resolution. Robustness-checklist item #13 (unknown_click_id
        // alert): a partner-side subid loss or an expired/rotated token
        // otherwise bleeds revenue with zero operator-visible signal.
        try {
            $row = $this->db->fetchOne(
                <<<'SQL'
                    SELECT count(*) AS total,
                           count(*) FILTER (WHERE processing_status = 'CLICK_NOT_FOUND') AS unknown_click,
                           count(*) FILTER (WHERE processing_status = 'UNKNOWN_TOKEN')  AS unknown_token
                    FROM core.postback_requests
                    WHERE created_at >= now() - interval '1 hour'
                SQL,
            );
            $total = (int)($row['total'] ?? 0);
            $unknownClick = (int)($row['unknown_click'] ?? 0);
            $unknownToken = (int)($row['unknown_token'] ?? 0);
            if ($total >= self::PB_MIN_SAMPLE) {
                if ($unknownClick / $total >= self::PB_UNKNOWN_RATE) {
                    $pct = (int)round(100 * $unknownClick / $total);
                    $alerts[] = "🟠 Postbacks: <b>{$pct}%</b> unknown click_id last hour ({$unknownClick}/{$total}) — check partner subid macro / clicks retention";
                }
                if ($unknownToken / $total >= self::PB_UNKNOWN_RATE) {
                    $pct = (int)round(100 * $unknownToken / $total);
                    $alerts[] = "🟠 Postbacks: <b>{$pct}%</b> unknown token last hour ({$unknownToken}/{$total}) — check postback URLs configured at the network";
                }
            }
        } catch (\Throwable $e) {
            $output->writeln('postback-health check failed: ' . $e->getMessage());
        }

        if ($alerts === []) {
            $output->writeln('telegram:alerts no alerts');
            return self::SUCCESS;
        }

        $output->writeln('telegram:alerts — ' . count($alerts) . ' alert(s)');

        if (!$this->tg->isConfigured()) {
            foreach ($alerts as $a) {
                $output->writeln('  [NOT SENT] ' . strip_tags($a));
            }
            return self::SUCCESS;
        }

        $msg = "🚨 <b>slimTDS alerts</b>\n\n" . implode("\n", $alerts);
        $this->tg->send($msg);

        return self::SUCCESS;
    }
}
