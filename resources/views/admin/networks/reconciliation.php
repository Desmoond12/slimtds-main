<?php
/** @var \App\Admin\Repository\AffiliateNetwork $network */
/** @var string $from */
/** @var string $to */
/** @var float $threshold */
/** @var list<array{d:string, event_type:string, tracker_count:?int, tracker_payout:?string, pp_count:?int, pp_payout:?string}> $events */
/** @var list<array{d:string, tracker_clicks:?int, pp_clicks:?int}> $clicks */

/**
 * Symmetric relative delta in % — |a−b| against the larger magnitude, so a
 * missing/zero side reads as 100% and the metric never divides by zero.
 */
$deltaPct = static function (float $a, float $b): ?float {
    $base = max(abs($a), abs($b));
    if ($base == 0.0) return null; // both zero — nothing to disagree about
    return abs($a - $b) / $base * 100;
};
$fmtMoney = static fn (?string $v): string => $v === null ? '—' : number_format((float)$v, 2, '.', ' ');
$fmtInt   = static fn (?int $v): string => $v === null ? '—' : (string)$v;
$fmtPct   = static function (?float $p): string {
    if ($p === null) return '';
    return ($p >= 99.95 ? '100' : number_format($p, 1, '.', '')) . '%';
};

$sumT = 0; $sumP = 0; $sumTPay = 0.0; $sumPPay = 0.0;
foreach ($events as $r) {
    $sumT    += (int)($r['tracker_count'] ?? 0);
    $sumP    += (int)($r['pp_count'] ?? 0);
    $sumTPay += (float)($r['tracker_payout'] ?? 0);
    $sumPPay += (float)($r['pp_payout'] ?? 0);
}
$totCntPct = $deltaPct((float)$sumT, (float)$sumP);
$totPayPct = $deltaPct($sumTPay, $sumPPay);
$warnStyle = 'background:color-mix(in srgb, var(--color-terra-400) 12%, transparent)';
?>
<div style="max-width:980px">
    <nav style="font-size:0.8rem;color:var(--color-stone-400);font-family:var(--font-sans);margin-bottom:20px">
        <a href="<?= e(url('/admin/networks')) ?>" style="color:var(--color-stone-400);text-decoration:none"><?= e(t('networks.title')) ?></a>
        <span style="margin:0 6px;color:var(--color-terra-400)">/</span>
        <a href="<?= e(url('/admin/networks/' . $network->id . '/edit')) ?>" style="color:var(--color-stone-400);text-decoration:none"><?= e($network->name) ?></a>
        <span style="margin:0 6px;color:var(--color-terra-400)">/</span>
        <span><?= e(t('recon.title')) ?></span>
    </nav>

    <h1 class="page-title-sm"><?= e(t('recon.title')) ?></h1>
    <p style="font-size:0.85rem;color:var(--color-stone-500);font-family:var(--font-sans);margin:6px 0 18px;line-height:1.5">
        <?= e(t('recon.intro')) ?>
    </p>

    <form method="get" action="<?= e(url('/admin/networks/' . $network->id . '/reconciliation')) ?>"
          style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:22px">
        <div>
            <label class="label-uppercase" for="from"><?= e(t('recon.from')) ?></label>
            <input type="date" id="from" name="from" value="<?= e($from) ?>" class="input" style="max-width:160px">
        </div>
        <div>
            <label class="label-uppercase" for="to"><?= e(t('recon.to')) ?></label>
            <input type="date" id="to" name="to" value="<?= e($to) ?>" class="input" style="max-width:160px">
        </div>
        <div>
            <label class="label-uppercase" for="threshold"><?= e(t('recon.threshold')) ?></label>
            <input type="number" id="threshold" name="threshold" value="<?= e((string)$threshold) ?>" min="0" max="100" step="0.5" class="input" style="max-width:110px">
        </div>
        <button type="submit" class="btn"><?= e(t('recon.apply')) ?></button>
    </form>

    <!-- Totals -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:24px">
        <div class="form-section" style="margin:0">
            <span class="form-section-label"><?= e(t('recon.total_events')) ?></span>
            <div style="font-size:1.25rem;font-variant-numeric:tabular-nums"><?= $sumT ?> <span style="color:var(--color-stone-400)">/</span> <?= $sumP ?></div>
            <p class="form-help" style="margin-top:4px"><?= e(t('recon.ours_vs_pp')) ?><?php if ($totCntPct !== null): ?> · Δ <?= e($fmtPct($totCntPct)) ?><?php endif; ?></p>
        </div>
        <div class="form-section" style="margin:0">
            <span class="form-section-label"><?= e(t('recon.total_payout')) ?></span>
            <div style="font-size:1.25rem;font-variant-numeric:tabular-nums"><?= e(number_format($sumTPay, 2, '.', ' ')) ?> <span style="color:var(--color-stone-400)">/</span> <?= e(number_format($sumPPay, 2, '.', ' ')) ?></div>
            <p class="form-help" style="margin-top:4px"><?= e(t('recon.ours_vs_pp')) ?><?php if ($totPayPct !== null): ?> · Δ <?= e($fmtPct($totPayPct)) ?><?php endif; ?></p>
        </div>
    </div>

    <!-- Events comparison -->
    <div class="form-section">
        <span class="form-section-label"><?= e(t('recon.events_label')) ?></span>
        <?php if ($events === []): ?>
            <p style="font-size:0.85rem;color:var(--color-stone-400);font-family:var(--font-sans)"><?= e(t('recon.no_data')) ?></p>
        <?php else: ?>
            <div class="tbl-wrap"><div class="tbl-scroll">
                <table class="tbl">
                    <thead><tr>
                        <th><?= e(t('recon.col.date')) ?></th>
                        <th><?= e(t('recon.col.event')) ?></th>
                        <th style="text-align:right"><?= e(t('recon.col.ours')) ?></th>
                        <th style="text-align:right"><?= e(t('recon.col.pp')) ?></th>
                        <th style="text-align:right">Δ</th>
                        <th style="text-align:right"><?= e(t('recon.col.ours_payout')) ?></th>
                        <th style="text-align:right"><?= e(t('recon.col.pp_payout')) ?></th>
                        <th style="text-align:right">Δ</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($events as $r): ?>
                        <?php
                            $cntPct = $deltaPct((float)($r['tracker_count'] ?? 0), (float)($r['pp_count'] ?? 0));
                            $payPct = $deltaPct((float)($r['tracker_payout'] ?? 0), (float)($r['pp_payout'] ?? 0));
                            $isWarn = ($cntPct !== null && $cntPct > $threshold) || ($payPct !== null && $payPct > $threshold);
                        ?>
                        <tr<?= $isWarn ? ' style="' . $warnStyle . '"' : '' ?>>
                            <td class="meta-mono" style="white-space:nowrap"><?= e($r['d']) ?></td>
                            <td><span class="meta-mono"><?= e($r['event_type']) ?></span></td>
                            <td style="text-align:right;font-variant-numeric:tabular-nums"><?= e($fmtInt($r['tracker_count'])) ?></td>
                            <td style="text-align:right;font-variant-numeric:tabular-nums"><?= e($fmtInt($r['pp_count'])) ?></td>
                            <td style="text-align:right;font-variant-numeric:tabular-nums;<?= $cntPct !== null && $cntPct > $threshold ? 'color:var(--color-terra-500);font-weight:600' : 'color:var(--color-stone-400)' ?>"><?= e($fmtPct($cntPct)) ?></td>
                            <td style="text-align:right;font-variant-numeric:tabular-nums"><?= e($fmtMoney($r['tracker_payout'])) ?></td>
                            <td style="text-align:right;font-variant-numeric:tabular-nums"><?= e($fmtMoney($r['pp_payout'])) ?></td>
                            <td style="text-align:right;font-variant-numeric:tabular-nums;<?= $payPct !== null && $payPct > $threshold ? 'color:var(--color-terra-500);font-weight:600' : 'color:var(--color-stone-400)' ?>"><?= e($fmtPct($payPct)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div></div>
        <?php endif; ?>
    </div>

    <!-- Clicks comparison -->
    <div class="form-section">
        <span class="form-section-label"><?= e(t('recon.clicks_label')) ?></span>
        <?php if ($clicks === []): ?>
            <p style="font-size:0.85rem;color:var(--color-stone-400);font-family:var(--font-sans)"><?= e(t('recon.no_data')) ?></p>
        <?php else: ?>
            <div class="tbl-wrap"><div class="tbl-scroll">
                <table class="tbl">
                    <thead><tr>
                        <th><?= e(t('recon.col.date')) ?></th>
                        <th style="text-align:right"><?= e(t('recon.col.ours')) ?></th>
                        <th style="text-align:right"><?= e(t('recon.col.pp')) ?></th>
                        <th style="text-align:right">Δ</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($clicks as $r): ?>
                        <?php
                            $pct = $deltaPct((float)($r['tracker_clicks'] ?? 0), (float)($r['pp_clicks'] ?? 0));
                            // Their "clicks" column is optional — a day where the
                            // report simply has no click numbers is not a discrepancy.
                            $isWarn = $r['pp_clicks'] !== null && $pct !== null && $pct > $threshold;
                        ?>
                        <tr<?= $isWarn ? ' style="' . $warnStyle . '"' : '' ?>>
                            <td class="meta-mono" style="white-space:nowrap"><?= e($r['d']) ?></td>
                            <td style="text-align:right;font-variant-numeric:tabular-nums"><?= e($fmtInt($r['tracker_clicks'])) ?></td>
                            <td style="text-align:right;font-variant-numeric:tabular-nums"><?= e($fmtInt($r['pp_clicks'])) ?></td>
                            <td style="text-align:right;font-variant-numeric:tabular-nums;<?= $isWarn ? 'color:var(--color-terra-500);font-weight:600' : 'color:var(--color-stone-400)' ?>"><?= $r['pp_clicks'] === null ? '' : e($fmtPct($pct)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div></div>
        <?php endif; ?>
    </div>

    <p style="font-size:0.78rem;color:var(--color-stone-400);font-family:var(--font-sans);line-height:1.5;margin-top:8px">
        <?= e(t('recon.tz_note')) ?>
    </p>
</div>
