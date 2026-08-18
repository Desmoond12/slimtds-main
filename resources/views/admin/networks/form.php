<?php
/** @var ?\App\Admin\Repository\AffiliateNetwork $network */
/** @var array<string,string> $errors */
/** @var string $csrf_token */
$action   = $network === null
    ? url('/admin/networks')
    : url('/admin/networks/' . $network->id);
$titleKey = $network === null ? 'networks.create' : 'networks.edit';
?>
<div style="max-width:560px">
    <nav style="font-size:0.8rem;color:var(--color-stone-400);font-family:var(--font-sans);margin-bottom:20px">
        <a href="<?= e(url('/admin/networks')) ?>" style="color:var(--color-stone-400);text-decoration:none"><?= e(t('networks.title')) ?></a>
        <span style="margin:0 6px;color:var(--color-terra-400)">/</span>
        <span><?= e(t($titleKey)) ?></span>
    </nav>

    <div style="display:flex;align-items:baseline;justify-content:space-between;gap:16px">
        <h1 class="page-title-sm"><?= e(t($titleKey)) ?></h1>
        <?php if ($network !== null): ?>
            <a href="<?= e(url('/admin/networks/' . $network->id . '/reports')) ?>" class="btn-secondary" style="font-size:0.8rem"><?= e(t('pp_reports.title')) ?> →</a>
        <?php endif; ?>
    </div>

    <form method="post" action="<?= e($action) ?>">
        <?= csrf_field($csrf_token) ?>

        <!-- Section: identity -->
        <div class="form-section">
            <span class="form-section-label"><?= e(t('offers.section_identity')) ?></span>
            <div>
                <label class="label-uppercase" for="name"><?= e(t('networks.name')) ?></label>
                <input id="name" name="name" class="input" required
                       value="<?= e(old('name', $network?->name ?? '')) ?>">
                <?php if (isset($errors['name'])): ?>
                    <p class="form-error"><?= e(t($errors['name'])) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Section: param mapping -->
        <div class="form-section">
            <span class="form-section-label"><?= e(t('networks.section_params')) ?></span>
            <p class="form-help" style="margin-bottom:12px"><?= e(t('networks.params_hint')) ?></p>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                <?php
                $paramFields = [
                    'click_param'       => ['label' => t('networks.click_param'),       'default' => $network?->clickParam ?? 'subid'],
                    'status_param'      => ['label' => t('networks.status_param'),      'default' => $network?->statusParam ?? 'status'],
                    'payout_param'      => ['label' => t('networks.payout_param'),      'default' => $network?->payoutParam ?? 'payout'],
                    'external_id_param' => ['label' => t('networks.external_id_param'), 'default' => $network?->externalIdParam ?? 'external_id'],
                    'event_type_param'  => ['label' => t('networks.event_type_param'),  'default' => $network?->eventTypeParam ?? 'event_type'],
                ];
                ?>
                <?php foreach ($paramFields as $field => $meta): ?>
                    <div>
                        <label class="label-uppercase" for="<?= $field ?>"><?= e($meta['label']) ?></label>
                        <input id="<?= $field ?>" name="<?= $field ?>" class="input"
                               style="font-family:var(--font-mono);font-size:0.82rem"
                               value="<?= e(old($field, $meta['default'])) ?>">
                        <?php if (isset($errors[$field])): ?>
                            <p class="form-error"><?= e(t($errors[$field])) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Section: status value translation -->
        <div class="form-section">
            <span class="form-section-label"><?= e(t('networks.section_status_map')) ?></span>
            <?php
                // old() always returns a string (never null, even with a null
                // default — see Shared/View/Helpers.php) — pass the real
                // fallback as its default rather than comparing against null.
                $existingMapLines = [];
                if ($network !== null) {
                    foreach ($network->statusMap as $raw => $canonical) {
                        $existingMapLines[] = $raw . '=' . $canonical;
                    }
                }
                $mapTextareaValue = old('status_map_raw', implode("\n", $existingMapLines));
            ?>
            <label class="label-uppercase" for="status_map_raw"><?= e(t('networks.status_map_label')) ?></label>
            <textarea id="status_map_raw" name="status_map_raw" rows="4"
                      class="input"
                      style="font-family:var(--font-mono);font-size:0.78rem;resize:vertical;min-height:80px"
                      placeholder="1=approved&#10;0=rejected&#10;2=pending"><?= e($mapTextareaValue) ?></textarea>
            <p class="form-help"><?= e(t('networks.status_map_help')) ?></p>
            <?php if (isset($errors['status_map'])): ?>
                <p class="form-error"><?= e(t($errors['status_map'])) ?></p>
            <?php endif; ?>
        </div>

        <!-- Section: notes -->
        <div class="form-section">
            <span class="form-section-label"><?= e(t('networks.section_notes')) ?></span>
            <textarea id="notes" name="notes" rows="2" class="input" style="resize:vertical"><?= e(old('notes', $network?->notes ?? '')) ?></textarea>
        </div>

        <!-- Section: state -->
        <div class="form-section">
            <span class="form-section-label"><?= e(t('offers.section_state')) ?></span>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.875rem;font-family:var(--font-sans);color:var(--color-stone-700)">
                <input type="checkbox" id="is_active" name="is_active" value="1"
                       <?= old('is_active', $network?->isActive ?? true) ? 'checked' : '' ?>>
                <?= e(t('offers.is_active')) ?>
            </label>
        </div>

        <!-- Actions -->
        <div style="display:flex;gap:10px;margin-top:28px;padding-top:20px;border-top:1px solid var(--color-stone-100)">
            <button type="submit" class="btn"><?= e(t('campaigns.save')) ?></button>
            <a href="<?= e(url('/admin/networks')) ?>" class="btn-secondary"><?= e(t('form.cancel')) ?></a>
        </div>
    </form>
</div>
