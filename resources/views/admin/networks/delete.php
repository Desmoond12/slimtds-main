<?php
/** @var \App\Admin\Repository\AffiliateNetwork $network */
/** @var string $csrf_token */
?>
<div style="max-width:460px">
    <h1 class="page-title-sm"><?= e(t('networks.delete')) ?>?</h1>
    <p style="font-size:0.875rem;color:var(--color-stone-600);font-family:var(--font-sans);margin-bottom:16px">
        <strong style="color:var(--color-stone-900)"><?= e($network->name) ?></strong>
    </p>
    <p style="font-size:0.8rem;color:var(--color-stone-500);font-family:var(--font-sans);margin-bottom:24px">
        <?= e(t('networks.delete_warn')) ?>
    </p>
    <form method="post" action="<?= e(url('/admin/networks/' . $network->id . '/delete')) ?>" style="display:flex;gap:10px">
        <?= csrf_field($csrf_token) ?>
        <button type="submit" class="btn" style="background:var(--color-danger);border-color:var(--color-danger-strong)"><?= e(t('networks.delete')) ?></button>
        <a href="<?= e(url('/admin/networks')) ?>" class="btn-secondary"><?= e(t('form.cancel')) ?></a>
    </form>
</div>
