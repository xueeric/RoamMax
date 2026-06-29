<?php

declare(strict_types=1);

/** @var array<string, mixed> $cancelDefaults */
/** @var string $defaultRefundCad */
/** @var string $maxRefundCad */
?>
<label class="admin-cancel-refund-label">
    <span>Refund rental (CAD)</span>
    <input
        type="number"
        step="0.01"
        min="0"
        max="<?= escape($maxRefundCad) ?>"
        name="refund_cents"
        value="<?= escape($defaultRefundCad) ?>"
        class="admin-input-compact"
    >
</label>
<p class="admin-detail-meta">Max refund <?= escape($maxRefundCad) ?><?= $cancelDefaults['fee_cents'] > 0 ? ' after fee' : '' ?>.</p>
<?php if ($cancelDefaults['has_deposit_hold']): ?>
    <label class="admin-cancel-check">
        <input type="checkbox" name="release_deposit" value="1" checked>
        Release deposit hold (<?= escape(\Starlink\Services\PricingService::formatMoney((int) $cancelDefaults['deposit_cents'])) ?>)
    </label>
<?php else: ?>
    <input type="hidden" name="release_deposit" value="0">
<?php endif; ?>
