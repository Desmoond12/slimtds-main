<?php
/** @var list<\App\Admin\Repository\AffiliateNetwork> $items */
/** @var int $total */
/** @var int $pages */
/** @var int $page */
/** @var string $q */
?>
<?php
$title = t('networks.title');
$count = (int)$total;
$ctaLabel = t('networks.create');
$ctaHref = url('/admin/networks/new');
$ctaIcon = '<path d="M12 5v14M5 12h14"/>';
require __DIR__ . '/../../_partials/page-header.php';
?>

<form method="get" style="margin-bottom:16px">
    <span class="search-input">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M16 16l5 5"/></svg>
        <input type="text" name="q" value="<?= e($q) ?>"
               placeholder="<?= e(t('campaigns.search')) ?>"
               class="input-sm" style="width:300px">
    </span>
</form>

<?php if (empty($items)): ?>
    <?php
    $title = $q !== '' ? t('campaigns.no_results') : t('networks.empty');
    $text = $q !== '' ? null : t('networks.empty_hint');
    $iconBody = '<circle cx="12" cy="12" r="9"/><path d="M8 12h8M12 8v8"/>';
    $ctaLabel = $q !== '' ? null : t('networks.create');
    $ctaHref = $q !== '' ? null : url('/admin/networks/new');
    $ctaIcon = '<path d="M12 5v14M5 12h14"/>';
    require __DIR__ . '/../../_partials/empty-state.php';
    ?>
<?php else: ?>
    <div class="tbl-wrap">
        <div class="tbl-scroll">
            <table class="tbl">
                <thead>
                    <tr>
                        <th><?= e(t('networks.name')) ?></th>
                        <th><?= e(t('networks.params')) ?></th>
                        <th style="width:80px"><?= e(t('offers.status')) ?></th>
                        <th style="text-align:right;width:140px"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $n): ?>
                        <tr>
                            <td class="tbl-primary">
                                <a href="<?= e(url('/admin/networks/' . $n->id . '/edit')) ?>"
                                   style="color:var(--color-text);text-decoration:none;font-weight:500;border-bottom:1px dashed transparent"
                                   onfocus="this.style.borderBottomColor='var(--color-terra-500)'"
                                   onblur="this.style.borderBottomColor='transparent'"><?= e($n->name) ?></a>
                            </td>
                            <td class="meta-mono" style="font-size:0.78rem;color:var(--color-stone-500)">
                                <?= e($n->clickParam) ?> · <?= e($n->statusParam) ?> · <?= e($n->payoutParam) ?> · <?= e($n->externalIdParam) ?> · <?= e($n->eventTypeParam) ?>
                            </td>
                            <td>
                                <span class="badge <?= $n->isActive ? 'badge-success' : 'badge-ghost' ?>"><?= e($n->isActive ? t('offers.status_active') : t('offers.status_inactive')) ?></span>
                            </td>
                            <td class="row-actions" style="text-align:right;white-space:nowrap">
                                <a href="<?= e(url('/admin/networks/' . $n->id . '/edit')) ?>" class="action-link"><?= e(t('campaigns.edit')) ?></a>
                                <a href="<?= e(url('/admin/networks/' . $n->id . '/delete')) ?>" class="danger-link" style="margin-left:12px"><?= e(t('campaigns.delete')) ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php
    $baseUrl = '/admin/networks';
    $extraQuery = ['q' => $q !== '' ? $q : null];
    require __DIR__ . '/../../_partials/pagination.php';
    ?>
<?php endif; ?>
