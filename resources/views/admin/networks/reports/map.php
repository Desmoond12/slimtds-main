<?php
/** @var \App\Admin\Repository\AffiliateNetwork $network */
/** @var string $filename */
/** @var list<string> $headers */
/** @var int $row_count */
/** @var list<list<string>> $sample_rows */
/** @var string $content_b64 */
/** @var list<\App\Admin\Repository\Campaign> $campaigns */
/** @var list<\App\Admin\Repository\Offer> $offers */
/** @var string $csrf_token */

$savedMap = $network->reportColumnMap;
$fieldLabels = [
    'event_type' => t('pp_reports.map.event_type'),
    'clicks'     => t('pp_reports.map.clicks'),
    'count'      => t('pp_reports.map.count'),
    'payout'     => t('pp_reports.map.payout'),
    'currency'   => t('pp_reports.map.currency'),
];

/** Pick the saved mapping for $field if that header still exists in this file, else ''. */
$preselect = function (string $field) use ($savedMap, $headers): string {
    $saved = $savedMap[$field] ?? '';
    return $saved !== '' && in_array($saved, $headers, true) ? $saved : '';
};
?>
<div style="max-width:720px">
    <nav style="font-size:0.8rem;color:var(--color-stone-400);font-family:var(--font-sans);margin-bottom:20px">
        <a href="<?= e(url('/admin/networks')) ?>" style="color:var(--color-stone-400);text-decoration:none"><?= e(t('networks.title')) ?></a>
        <span style="margin:0 6px;color:var(--color-terra-400)">/</span>
        <a href="<?= e(url('/admin/networks/' . $network->id . '/reports')) ?>" style="color:var(--color-stone-400);text-decoration:none"><?= e($network->name) ?></a>
        <span style="margin:0 6px;color:var(--color-terra-400)">/</span>
        <span><?= e(t('pp_reports.map_title')) ?></span>
    </nav>

    <h1 class="page-title-sm"><?= e(t('pp_reports.map_title')) ?></h1>
    <p style="font-size:0.85rem;color:var(--color-stone-500);font-family:var(--font-sans);margin:6px 0 20px">
        <?= e(t('pp_reports.map_hint', ['filename' => $filename, 'count' => $row_count])) ?>
    </p>

    <?php if (!empty($sample_rows)): ?>
        <div class="tbl-wrap" style="margin-bottom:24px">
            <div class="tbl-scroll">
                <table class="tbl" style="font-size:0.78rem">
                    <thead><tr><?php foreach ($headers as $h): ?><th><?= e($h) ?></th><?php endforeach; ?></tr></thead>
                    <tbody>
                        <?php foreach ($sample_rows as $row): ?>
                            <tr><?php foreach ($headers as $i => $h): ?><td class="meta-mono"><?= e($row[$i] ?? '') ?></td><?php endforeach; ?></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/admin/networks/' . $network->id . '/reports/confirm')) ?>">
        <?= csrf_field($csrf_token) ?>
        <input type="hidden" name="filename" value="<?= e($filename) ?>">
        <input type="hidden" name="content_b64" value="<?= e($content_b64) ?>">

        <div class="form-section">
            <span class="form-section-label"><?= e(t('pp_reports.section_date')) ?></span>
            <div class="adapt-stack" style="display:grid;grid-template-columns:1fr 160px;gap:16px">
                <div>
                    <label class="label-uppercase" for="map_date"><?= e(t('pp_reports.map.date')) ?></label>
                    <select id="map_date" name="map_date" class="input" required>
                        <option value=""><?= e(t('pp_reports.select_column')) ?></option>
                        <?php foreach ($headers as $h): ?>
                            <option value="<?= e($h) ?>" <?= $preselect('date') === $h ? 'selected' : '' ?>><?= e($h) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="label-uppercase" for="date_format"><?= e(t('pp_reports.date_format')) ?></label>
                    <input id="date_format" name="date_format" class="input" style="font-family:var(--font-mono)"
                           value="<?= e($network->reportDateFormat) ?>">
                </div>
            </div>
            <p class="form-help"><?= e(t('pp_reports.date_format_hint')) ?></p>
        </div>

        <div class="form-section">
            <span class="form-section-label"><?= e(t('pp_reports.section_metrics')) ?></span>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                <?php foreach ($fieldLabels as $field => $label): ?>
                    <div>
                        <label class="label-uppercase" for="map_<?= $field ?>"><?= e($label) ?></label>
                        <select id="map_<?= $field ?>" name="map_<?= $field ?>" class="input">
                            <option value=""><?= e(t('pp_reports.not_present')) ?></option>
                            <?php foreach ($headers as $h): ?>
                                <option value="<?= e($h) ?>" <?= $preselect($field) === $h ? 'selected' : '' ?>><?= e($h) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="form-section">
            <span class="form-section-label"><?= e(t('pp_reports.section_scope')) ?></span>
            <p class="form-help" style="margin-bottom:12px"><?= e(t('pp_reports.scope_hint')) ?></p>
            <div class="adapt-stack" style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                <div>
                    <label class="label-uppercase" for="campaign_id"><?= e(t('campaigns.title')) ?></label>
                    <select id="campaign_id" name="campaign_id" class="input">
                        <option value=""><?= e(t('form.default')) ?></option>
                        <?php foreach ($campaigns as $c): ?>
                            <option value="<?= e($c->id) ?>"><?= e($c->slug) ?> · <?= e($c->name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="label-uppercase" for="offer_id"><?= e(t('offers.title')) ?></label>
                    <select id="offer_id" name="offer_id" class="input">
                        <option value=""><?= e(t('form.default')) ?></option>
                        <?php foreach ($offers as $o): ?>
                            <option value="<?= e($o->id) ?>"><?= e($o->name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div style="display:flex;gap:10px;margin-top:24px;padding-top:18px;border-top:1px solid var(--color-stone-100)">
            <button type="submit" class="btn"><?= e(t('pp_reports.confirm_button')) ?></button>
            <a href="<?= e(url('/admin/networks/' . $network->id . '/reports')) ?>" class="btn-secondary"><?= e(t('form.cancel')) ?></a>
        </div>
    </form>
</div>
