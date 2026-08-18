<?php
/** @var list<array{hour:string,clicks:int,uniq:int,bot:int}> $timeline */
/** @var array<string,mixed> $summary */
/** @var list<array{offer_id:?string,offer_name:?string,country:?string,clicks:int,uniq:int,conversions:int,approved:int,payout:string}> $byOfferCountry */
/** @var list<array{lander_host:string,clicks:int,clicks_human:int,conversions:int,approved:int,payout:string}> $bySite */
/** @var string $window */
/** @var ?string $campaign_id */
/** @var list<\App\Admin\Repository\Campaign> $campaigns */

// ISO-2 country code → flag emoji via Unicode regional indicator symbols.
$flag = function (?string $cc): string {
    if (!is_string($cc)) return '';
    $cc = strtoupper(trim($cc));
    if (strlen($cc) !== 2 || !ctype_alpha($cc)) return '';
    return mb_chr(0x1F1E6 + ord($cc[0]) - 65, 'UTF-8') . mb_chr(0x1F1E6 + ord($cc[1]) - 65, 'UTF-8');
};
?>
<div style="margin-bottom:24px;display:flex;align-items:baseline;justify-content:space-between;gap:16px;flex-wrap:wrap">
    <h1 class="page-title"><?= e(t('statistics.title')) ?></h1>
    <form method="get" style="display:flex;gap:8px;font-size:0.875rem">
        <select name="campaign_id" class="input" style="width:200px">
            <option value=""><?= e(t('statistics.any')) ?></option>
            <?php foreach ($campaigns as $c): ?>
                <option value="<?= e($c->id) ?>" <?= ($campaign_id === $c->id) ? 'selected' : '' ?>><?= e($c->slug) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="window" class="input" style="width:100px">
            <option value="24h" <?= $window === '24h' ? 'selected' : '' ?>>24h</option>
            <option value="7d"  <?= $window === '7d'  ? 'selected' : '' ?>>7d</option>
            <option value="30d" <?= $window === '30d' ? 'selected' : '' ?>>30d</option>
        </select>
        <button type="submit" class="btn-secondary" style="font-size:0.8rem"><?= e(t('statistics.apply')) ?></button>
    </form>
</div>

<!-- KPIs -->
<div class="adapt-stack" style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:24px">
    <?php foreach ([
        [t('statistics.kpi.clicks'),      (int)$summary['clicks']],
        [t('statistics.kpi.unique'),      (int)$summary['uniq']],
        [t('statistics.kpi.conversions'), (int)$summary['approved']],
        [t('statistics.kpi.payout'),      '$' . $summary['payout']],
    ] as [$label, $val]): ?>
        <div style="padding:12px 16px;border:1px solid var(--color-stone-200);border-radius:6px;background:var(--color-surface)">
            <div style="font-size:0.7rem;letter-spacing:0.08em;text-transform:uppercase;color:var(--color-stone-500);margin-bottom:4px"><?= e($label) ?></div>
            <div style="font-family:var(--font-display);font-size:1.5rem;font-weight:600;font-variant-numeric:tabular-nums"><?= e((string)$val) ?></div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Chart -->
<div style="padding:8px;border:1px solid var(--color-stone-200);border-radius:6px;background:var(--color-surface)">
    <div x-data="statsChart({ points: <?= e(json_encode($timeline, JSON_UNESCAPED_SLASHES)) ?> })" style="width:100%;height:360px"></div>
</div>

<div style="margin-top:16px;font-size:0.8rem;color:var(--color-stone-500);font-family:var(--font-sans)">
    <?= e(t('statistics.cr')) ?> <strong><?= e((string)$summary['cr']) ?>%</strong> &middot; <?= e(t('statistics.epc')) ?> <strong>$<?= e((string)$summary['epc']) ?></strong> &middot; <?= e(t('statistics.bots')) ?> <?= (int)$summary['bots'] ?>
</div>

<!-- Offer × country breakdown -->
<h2 class="section-title" style="margin-top:28px;margin-bottom:12px"><?= e(t('statistics.breakdown.title')) ?></h2>

<?php if (empty($byOfferCountry)): ?>
    <div style="padding:16px;border:1px solid var(--color-stone-200);border-radius:6px;color:var(--color-stone-500);font-size:0.85rem"><?= e(t('statistics.breakdown.empty')) ?></div>
<?php else: ?>
    <div class="tbl-wrap">
        <div class="tbl-scroll">
            <table class="tbl">
                <thead>
                    <tr>
                        <th><?= e(t('statistics.breakdown.col.offer')) ?></th>
                        <th><?= e(t('statistics.breakdown.col.country')) ?></th>
                        <th style="text-align:right"><?= e(t('statistics.breakdown.col.clicks')) ?></th>
                        <th style="text-align:right"><?= e(t('statistics.breakdown.col.uniq')) ?></th>
                        <th style="text-align:right"><?= e(t('statistics.breakdown.col.leads')) ?></th>
                        <th style="text-align:right"><?= e(t('statistics.breakdown.col.deps')) ?></th>
                        <th style="text-align:right"><?= e(t('statistics.breakdown.col.cr')) ?></th>
                        <th style="text-align:right"><?= e(t('statistics.breakdown.col.payout')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($byOfferCountry as $r): ?>
                        <?php
                        $cc = (string)($r['country'] ?? '');
                        $flagStr = $flag($cc);
                        $cr = $r['clicks'] > 0 ? round($r['approved'] / $r['clicks'] * 100, 2) : 0.0;
                        ?>
                        <tr>
                            <td><?= e($r['offer_name'] ?? t('statistics.breakdown.no_offer')) ?></td>
                            <td><?= $cc !== '' ? ($flagStr !== '' ? $flagStr . ' ' : '') . '<span style="text-transform:uppercase">' . e($cc) . '</span>' : '<span style="color:var(--color-faint)">—</span>' ?></td>
                            <td style="text-align:right;font-variant-numeric:tabular-nums"><?= (int)$r['clicks'] ?></td>
                            <td style="text-align:right;font-variant-numeric:tabular-nums"><?= (int)$r['uniq'] ?></td>
                            <td style="text-align:right;font-variant-numeric:tabular-nums"><?= (int)$r['conversions'] ?></td>
                            <td style="text-align:right;font-variant-numeric:tabular-nums"><?= (int)$r['approved'] ?></td>
                            <td style="text-align:right;font-variant-numeric:tabular-nums"><?= e((string)$cr) ?>%</td>
                            <td style="text-align:right;font-variant-numeric:tabular-nums">$<?= e($r['payout']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- By site (lander domain) — which SEO site actually earns -->
<h2 class="section-title" style="margin-top:28px;margin-bottom:12px"><?= e(t('statistics.by_site.title')) ?></h2>

<?php if (empty($bySite)): ?>
    <div style="padding:16px;border:1px solid var(--color-stone-200);border-radius:6px;color:var(--color-stone-500);font-size:0.85rem"><?= e(t('statistics.by_site.empty')) ?></div>
<?php else: ?>
    <div class="tbl-wrap">
        <div class="tbl-scroll">
            <table class="tbl">
                <thead>
                    <tr>
                        <th><?= e(t('statistics.by_site.col.site')) ?></th>
                        <th style="text-align:right"><?= e(t('statistics.by_site.col.clicks')) ?></th>
                        <th style="text-align:right"><?= e(t('statistics.by_site.col.human')) ?></th>
                        <th style="text-align:right"><?= e(t('statistics.by_site.col.leads')) ?></th>
                        <th style="text-align:right"><?= e(t('statistics.by_site.col.deps')) ?></th>
                        <th style="text-align:right"><?= e(t('statistics.by_site.col.cr')) ?></th>
                        <th style="text-align:right"><?= e(t('statistics.by_site.col.payout')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bySite as $r): ?>
                        <?php $cr = $r['clicks'] > 0 ? round($r['approved'] / $r['clicks'] * 100, 2) : 0.0; ?>
                        <tr>
                            <td style="font-family:var(--font-mono);font-size:0.82rem"><?= $r['lander_host'] !== '' ? e($r['lander_host']) : '<span style="color:var(--color-faint)">' . e(t('statistics.by_site.direct')) . '</span>' ?></td>
                            <td style="text-align:right;font-variant-numeric:tabular-nums"><?= (int)$r['clicks'] ?></td>
                            <td style="text-align:right;font-variant-numeric:tabular-nums"><?= (int)$r['clicks_human'] ?></td>
                            <td style="text-align:right;font-variant-numeric:tabular-nums"><?= (int)$r['conversions'] ?></td>
                            <td style="text-align:right;font-variant-numeric:tabular-nums"><?= (int)$r['approved'] ?></td>
                            <td style="text-align:right;font-variant-numeric:tabular-nums"><?= e((string)$cr) ?>%</td>
                            <td style="text-align:right;font-variant-numeric:tabular-nums;font-weight:600">$<?= e($r['payout']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
