<?php
/** @var \App\Admin\Repository\AffiliateNetwork $network */
/** @var list<\App\Admin\Repository\PpReportImport> $imports */
/** @var string $csrf_token */
?>
<div style="max-width:760px">
    <nav style="font-size:0.8rem;color:var(--color-stone-400);font-family:var(--font-sans);margin-bottom:20px">
        <a href="<?= e(url('/admin/networks')) ?>" style="color:var(--color-stone-400);text-decoration:none"><?= e(t('networks.title')) ?></a>
        <span style="margin:0 6px;color:var(--color-terra-400)">/</span>
        <a href="<?= e(url('/admin/networks/' . $network->id . '/edit')) ?>" style="color:var(--color-stone-400);text-decoration:none"><?= e($network->name) ?></a>
        <span style="margin:0 6px;color:var(--color-terra-400)">/</span>
        <span><?= e(t('pp_reports.title')) ?></span>
    </nav>

    <h1 class="page-title-sm"><?= e(t('pp_reports.title')) ?></h1>
    <p style="font-size:0.85rem;color:var(--color-stone-500);font-family:var(--font-sans);margin:6px 0 24px;line-height:1.5">
        <?= e(t('pp_reports.intro')) ?>
    </p>

    <div class="form-section">
        <span class="form-section-label"><?= e(t('pp_reports.upload_label')) ?></span>
        <form method="post" action="<?= e(url('/admin/networks/' . $network->id . '/reports/preview')) ?>" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center">
            <?= csrf_field($csrf_token) ?>
            <input type="file" name="csv_file" accept=".csv,text/csv" required class="input" style="max-width:340px">
            <button type="submit" class="btn"><?= e(t('pp_reports.upload_button')) ?></button>
        </form>
        <p class="form-help"><?= e(t('pp_reports.upload_hint')) ?></p>
    </div>

    <div class="form-section">
        <span class="form-section-label"><?= e(t('pp_reports.history_label')) ?></span>
        <?php if (empty($imports)): ?>
            <p style="font-size:0.85rem;color:var(--color-stone-400);font-family:var(--font-sans)"><?= e(t('pp_reports.no_imports')) ?></p>
        <?php else: ?>
            <div class="tbl-wrap">
                <div class="tbl-scroll">
                    <table class="tbl">
                        <thead>
                            <tr>
                                <th><?= e(t('pp_reports.col.time')) ?></th>
                                <th><?= e(t('pp_reports.col.filename')) ?></th>
                                <th style="text-align:right"><?= e(t('pp_reports.col.rows')) ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($imports as $imp): ?>
                                <tr>
                                    <td class="meta-mono" style="white-space:nowrap"><?= e(substr($imp->createdAt->format('Y-m-d H:i:s'), 0, 19)) ?></td>
                                    <td class="meta-mono"><?= e($imp->filename) ?></td>
                                    <td style="text-align:right;font-variant-numeric:tabular-nums"><?= (int)$imp->rowCount ?></td>
                                    <td style="text-align:right">
                                        <form method="post" action="<?= e(url('/admin/networks/' . $network->id . '/reports/imports/' . $imp->id . '/delete')) ?>"
                                              onsubmit="return confirm(<?= htmlspecialchars(json_encode(t('pp_reports.delete_confirm'), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)">
                                            <?= csrf_field($csrf_token) ?>
                                            <button type="submit" class="danger-link" style="background:none;border:0;cursor:pointer;font-size:0.8rem"><?= e(t('pp_reports.delete')) ?></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
