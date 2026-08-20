<?php
/** @var array<string,string> $errors */
/** @var string $csrf_token */
$errors = $errors ?? [];
$title = t('funnel.title');
$eyebrow = t('funnel.eyebrow');
require __DIR__ . '/../../_partials/page-header.php';
?>

<style>
.preset-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:8px 0 26px}
@media(max-width:640px){.preset-grid{grid-template-columns:1fr}}
.preset-card{text-align:left;cursor:pointer;background:var(--color-surface);border:1.5px solid var(--color-border);
  border-radius:10px;padding:14px 15px;font-family:var(--font-sans);color:var(--color-text);transition:border-color .12s,background .12s}
.preset-card:hover{border-color:var(--color-stone-400,var(--color-muted))}
.preset-card.on{border-color:var(--color-accent-500);background:var(--color-surface-2)}
.preset-card .pk{font-family:var(--font-mono);font-size:0.66rem;letter-spacing:.1em;text-transform:uppercase;color:var(--color-faint)}
.preset-card.on .pk{color:var(--color-accent-500)}
.preset-card h3{margin:7px 0 3px;font-size:0.98rem;font-weight:600;font-family:var(--font-sans)}
.preset-card p{margin:0;font-size:0.78rem;color:var(--color-muted);line-height:1.45}
.preset-card .rec{display:inline-block;margin-top:7px;font-size:0.66rem;font-weight:600;font-family:var(--font-mono);
  color:var(--color-success-text,var(--color-success));background:var(--color-success-bg);padding:2px 7px;border-radius:999px}
.wz-hint{display:flex;align-items:center;gap:6px;margin-top:6px;font-size:0.74rem;color:var(--color-success-text,var(--color-success))}
</style>

<form method="post" action="<?= e(url('/admin/funnels')) ?>" x-data="{ preset: 'geo', cloak: true }" style="max-width:640px">
    <?= csrf_field($csrf_token) ?>
    <input type="hidden" name="preset" :value="preset">

    <p style="font-size:0.86rem;color:var(--color-muted);font-family:var(--font-sans);margin:0 0 18px;line-height:1.5;max-width:60ch">
        <?= e(t('funnel.intro')) ?>
    </p>

    <!-- Preset selector -->
    <div class="preset-grid">
        <button type="button" class="preset-card" :class="{ 'on': preset==='single' }" @click="preset='single'">
            <div class="pk"><?= e(t('funnel.preset')) ?> 01</div>
            <h3><?= e(t('funnel.preset_single')) ?></h3>
            <p><?= e(t('funnel.preset_single_hint')) ?></p>
        </button>
        <button type="button" class="preset-card" :class="{ 'on': preset==='geo' }" @click="preset='geo'">
            <div class="pk"><?= e(t('funnel.preset')) ?> 02</div>
            <h3><?= e(t('funnel.preset_geo')) ?></h3>
            <p><?= e(t('funnel.preset_geo_hint')) ?></p>
            <span class="rec">★ <?= e(t('funnel.preset_geo_rec')) ?></span>
        </button>
        <button type="button" class="preset-card" :class="{ 'on': preset==='ab' }" @click="preset='ab'">
            <div class="pk"><?= e(t('funnel.preset')) ?> 03</div>
            <h3><?= e(t('funnel.preset_ab')) ?></h3>
            <p><?= e(t('funnel.preset_ab_hint')) ?></p>
        </button>
    </div>

    <!-- Campaign -->
    <div class="form-section">
        <span class="form-section-label"><?= e(t('funnel.section_campaign')) ?></span>
        <div class="form-row">
            <label class="label-uppercase" for="campaign_name"><?= e(t('funnel.campaign_name')) ?></label>
            <input id="campaign_name" name="campaign_name" class="input" type="text"
                   placeholder="CasinoX — Tier-1" value="<?= e(old('campaign_name', '')) ?>">
            <?php if (isset($errors['campaign_name'])): ?><p class="form-error"><?= e(t($errors['campaign_name'])) ?></p><?php endif; ?>
            <p class="form-help"><?= e(t('funnel.campaign_name_hint')) ?></p>
        </div>
    </div>

    <!-- Offer(s) -->
    <div class="form-section">
        <span class="form-section-label"><?= e(t('funnel.section_offer')) ?></span>
        <div class="form-row">
            <label class="label-uppercase" for="offer_url">
                <span x-text="preset==='ab' ? '<?= e(t('funnel.offer_url_a')) ?>' : '<?= e(t('funnel.offer_url')) ?>'"></span>
            </label>
            <input id="offer_url" name="offer_url" class="input input-mono" type="text"
                   placeholder="https://track.brand.com/visit?aff=88" value="<?= e(old('offer_url', '')) ?>">
            <?php if (isset($errors['offer_url'])): ?><p class="form-error"><?= e(t($errors['offer_url'])) ?></p><?php endif; ?>
            <div class="wz-hint">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                <?= e(t('funnel.offer_url_macro')) ?>
            </div>
        </div>

        <div class="form-row" x-show="preset==='ab'" x-cloak>
            <label class="label-uppercase" for="offer_url_b"><?= e(t('funnel.offer_url_b')) ?></label>
            <input id="offer_url_b" name="offer_url_b" class="input input-mono" type="text"
                   placeholder="https://go.brand2.com/?ref=k9" value="<?= e(old('offer_url_b', '')) ?>">
            <?php if (isset($errors['offer_url_b'])): ?><p class="form-error"><?= e(t($errors['offer_url_b'])) ?></p><?php endif; ?>
            <div style="display:flex;gap:10px;margin-top:8px;align-items:center">
                <label class="label-uppercase" style="margin:0"><?= e(t('funnel.weights')) ?></label>
                <input name="weight_a" class="input input-sm input-mono" style="max-width:70px" type="number" min="1" max="1000" value="<?= e(old('weight_a', '70')) ?>">
                <span style="color:var(--color-faint)">/</span>
                <input name="weight_b" class="input input-sm input-mono" style="max-width:70px" type="number" min="1" max="1000" value="<?= e(old('weight_b', '30')) ?>">
            </div>
        </div>
    </div>

    <!-- Geo -->
    <div class="form-section" x-show="preset==='geo'" x-cloak>
        <span class="form-section-label"><?= e(t('funnel.section_geo')) ?></span>
        <div class="form-row">
            <label class="label-uppercase" for="geo"><?= e(t('funnel.geo')) ?></label>
            <input id="geo" name="geo" class="input input-mono" type="text"
                   placeholder="IT, DE, AT" value="<?= e(old('geo', '')) ?>">
            <?php if (isset($errors['geo'])): ?><p class="form-error"><?= e(t($errors['geo'])) ?></p><?php endif; ?>
            <p class="form-help"><?= e(t('funnel.geo_hint')) ?></p>
        </div>
    </div>

    <!-- Cloaking -->
    <div class="form-section">
        <span class="form-section-label"><?= e(t('funnel.section_cloak')) ?></span>
        <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;font-size:0.875rem;font-family:var(--font-sans);color:var(--color-stone-700,var(--color-text))">
            <input type="checkbox" name="cloak" value="1" x-model="cloak">
            <?= e(t('funnel.cloak')) ?>
        </label>
        <p style="font-size:0.78rem;color:var(--color-stone-500,var(--color-muted));margin:4px 0 0;font-family:var(--font-sans)"><?= e(t('funnel.cloak_hint')) ?></p>

        <div class="form-row" x-show="cloak" x-cloak style="margin-top:12px">
            <label class="label-uppercase" for="official_url"><?= e(t('funnel.official_url')) ?></label>
            <input id="official_url" name="official_url" class="input input-mono" type="text"
                   placeholder="https://www.brand.com" value="<?= e(old('official_url', '')) ?>">
            <?php if (isset($errors['official_url'])): ?><p class="form-error"><?= e(t($errors['official_url'])) ?></p><?php endif; ?>
            <p class="form-help"><?= e(t('funnel.official_url_hint')) ?></p>
        </div>
    </div>

    <div style="display:flex;gap:10px;align-items:center;margin-top:24px">
        <button type="submit" class="btn"><?= e(t('funnel.submit')) ?></button>
        <a href="<?= e(url('/admin/campaigns')) ?>" class="btn-ghost"><?= e(t('funnel.cancel')) ?></a>
    </div>
</form>
