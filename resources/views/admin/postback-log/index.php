<?php
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var int $pages */
/** @var int $page */
/** @var array<string,mixed> $filters */
/** @var list<\App\Admin\Repository\Offer> $offers */
?>
<?php
$title = t('postback_log.title');
$count = (int)$total;
require __DIR__ . '/../../_partials/page-header.php';
?>

<p style="color:var(--color-muted);font-size:0.85rem;margin:-8px 0 18px;max-width:70ch"><?= e(t('postback_log.intro')) ?></p>

<!-- Postback tester -->
<div class="form-section" style="margin-bottom:22px"
     x-data="postbackTester(<?= htmlspecialchars(json_encode(
        array_map(fn ($o) => ['id' => $o->id, 'name' => $o->name, 'token' => $o->postbackToken], $offers),
        JSON_UNESCAPED_UNICODE,
     ), ENT_QUOTES) ?>)">
    <span class="form-section-label"><?= e(t('postback_log.tester_title')) ?></span>
    <p style="margin:2px 0 12px;font-size:0.78rem;color:var(--color-stone-400)"><?= e(t('postback_log.tester_hint')) ?></p>

    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
        <div class="filter-field">
            <label class="filter-label"><?= e(t('postback_log.tester_offer')) ?></label>
            <select class="input-sm" style="width:220px" x-model="offerId">
                <option value=""><?= e(t('postback_log.tester_offer_ph')) ?></option>
                <template x-for="o in offers" :key="o.id">
                    <option :value="o.id" x-text="o.name"></option>
                </template>
            </select>
        </div>
        <div class="filter-field">
            <label class="filter-label"><?= e(t('postback_log.tester_subid')) ?></label>
            <input type="text" class="input-sm" style="width:280px;font-family:var(--font-mono)" x-model="subid" placeholder="<?= e(t('postback_log.tester_subid_ph')) ?>">
        </div>
        <div class="filter-field">
            <label class="filter-label"><?= e(t('postback_log.tester_status')) ?></label>
            <select class="input-sm" style="width:130px" x-model="status">
                <option value="approved">approved</option>
                <option value="pending">pending</option>
                <option value="hold">hold</option>
                <option value="rejected">rejected</option>
            </select>
        </div>
        <div class="filter-field">
            <label class="filter-label"><?= e(t('postback_log.tester_payout')) ?></label>
            <input type="text" class="input-sm" style="width:100px;font-family:var(--font-mono)" x-model="payout">
        </div>
        <div class="filter-field">
            <label class="filter-label"><?= e(t('postback_log.tester_external_id')) ?></label>
            <input type="text" class="input-sm" style="width:140px;font-family:var(--font-mono)" x-model="externalId">
        </div>
        <button type="button" class="btn" style="font-size:0.8rem;height:32px" :disabled="!offerId || firing" @click="fire()"><?= e(t('postback_log.tester_fire')) ?></button>
    </div>

    <template x-if="result">
        <div style="margin-top:12px">
            <div class="form-section-label" style="margin-bottom:4px"><?= e(t('postback_log.tester_result')) ?></div>
            <pre style="margin:0;padding:10px 12px;border-radius:6px;background:var(--color-surface-sunken,var(--color-surface));border:1px solid var(--color-border-soft);font-family:var(--font-mono);font-size:0.78rem;white-space:pre-wrap;word-break:break-all" x-text="result"></pre>
            <button type="button" class="btn-ghost" style="font-size:0.78rem;margin-top:6px" @click="location.reload()">↻ refresh log</button>
        </div>
    </template>
</div>

<form method="get" class="filter-bar">
    <div class="filter-field">
        <label class="filter-label"><?= e(t('postback_log.filter.status')) ?></label>
        <select name="processing_status" class="input-sm" style="width:180px">
            <option value=""><?= e(t('postback_log.filter.any_status')) ?></option>
            <?php foreach (['OK_NEW', 'OK_TRANSITION', 'OK_DUPLICATE', 'CAMPAIGN_PING_OK', 'MISSING_TOKEN', 'UNKNOWN_TOKEN', 'INVALID_STATUS', 'INVALID_PAYOUT', 'MISSING_SUBID', 'INVALID_SUBID', 'CLICK_NOT_FOUND', 'CAMPAIGN_MISMATCH', 'TRASH_FALLTHROUGH', 'OFFER_GONE'] as $ps): ?>
                <option value="<?= e($ps) ?>" <?= ($filters['processing_status'] ?? '') === $ps ? 'selected' : '' ?>><?= e($ps) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn-secondary" style="font-size:0.8rem;height:32px;align-self:flex-end"><?= e(t('postback_log.apply')) ?></button>
</form>

<?php if (empty($items)): ?>
    <?php
    $title = t('postback_log.empty_title');
    $text = t('postback_log.empty_text');
    $iconBody = '<path d="M4 4h16v12H8l-4 4z"/><path d="M8 9h8M8 12h5"/>';
    require __DIR__ . '/../../_partials/empty-state.php';
    ?>
<?php else: ?>
    <div class="tbl-wrap">
        <div class="tbl-scroll">
            <table class="tbl">
                <thead>
                    <tr>
                        <th><?= e(t('postback_log.col.time')) ?></th>
                        <th><?= e(t('postback_log.col.method')) ?></th>
                        <th><?= e(t('postback_log.col.offer')) ?></th>
                        <th><?= e(t('postback_log.col.subid')) ?></th>
                        <th><?= e(t('postback_log.col.status')) ?></th>
                        <th style="text-align:right"><?= e(t('postback_log.col.payout')) ?></th>
                        <th><?= e(t('postback_log.col.outcome')) ?></th>
                        <th><?= e(t('postback_log.col.http')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $r): ?>
                        <tr>
                            <td class="meta-mono" style="white-space:nowrap"><?= e(substr((string)$r['received_at'], 0, 19)) ?></td>
                            <td class="meta-mono"><?= e((string)$r['method']) ?></td>
                            <td><?= e((string)($r['offer_name'] ?? '—')) ?></td>
                            <td class="meta-mono" title="<?= e((string)($r['parsed_subid'] ?? '')) ?>"><?= e($r['parsed_subid'] !== null ? substr((string)$r['parsed_subid'], 0, 8) . '…' : '—') ?></td>
                            <td class="meta-mono"><?= e((string)($r['parsed_status'] ?? '')) ?: '—' ?></td>
                            <td style="text-align:right;font-variant-numeric:tabular-nums"><?= e((string)($r['parsed_payout'] ?? '')) ?: '—' ?></td>
                            <td>
                                <?php $ps = (string)$r['processing_status']; ?>
                                <span class="badge <?= str_starts_with($ps, 'OK') ? 'badge-success' : 'badge-danger' ?>"><?= e($ps) ?></span>
                            </td>
                            <td class="meta-mono"><?= (int)$r['http_status'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php
    $baseUrl = '/admin/postback-log';
    $extraQuery = array_filter([
        'processing_status' => $filters['processing_status'] ?? null,
    ], fn ($v) => $v !== null && $v !== '');
    require __DIR__ . '/../../_partials/pagination.php';
    ?>
<?php endif; ?>
