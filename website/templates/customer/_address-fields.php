<?php

declare(strict_types=1);

use Starlink\Services\AddressService;

/** @var string $prefix e.g. home_, shipping_, billing_ */
/** @var ?array<string, string> $address */
/** @var bool $includeName */
/** @var bool $required */
/** @var string $sectionLabel */

$prefix = $prefix ?? 'home_';
$address = $address ?? [];
$includeName = $includeName ?? false;
$required = $required ?? true;
$sectionLabel = $sectionLabel ?? 'Address';
$req = $required ? ' required' : '';
$fieldsetId = $fieldsetId ?? null;
?>
<fieldset class="address-fieldset"<?= $fieldsetId !== null ? ' id="' . escape($fieldsetId) . '"' : '' ?>>
    <legend class="micro-label"><?= escape($sectionLabel) ?></legend>
    <?php if ($includeName): ?>
        <label>
            <span>Recipient name</span>
            <input type="text" name="<?= escape($prefix) ?>name" value="<?= escape($address['name'] ?? '') ?>"<?= $req ?>>
        </label>
    <?php endif; ?>
    <div class="form-row">
        <label>
            <span>Address line 1</span>
            <input type="text" name="<?= escape($prefix) ?>line1" value="<?= escape($address['line1'] ?? '') ?>" data-address-prefix="<?= escape($prefix) ?>" data-address-field="line1"<?= $req ?>>
        </label>
        <label>
            <span>Address line 2</span>
            <input type="text" name="<?= escape($prefix) ?>line2" value="<?= escape($address['line2'] ?? '') ?>" data-address-prefix="<?= escape($prefix) ?>" data-address-field="line2">
        </label>
    </div>
    <div class="form-row">
        <label>
            <span>City</span>
            <input type="text" name="<?= escape($prefix) ?>city" value="<?= escape($address['city'] ?? '') ?>" data-address-prefix="<?= escape($prefix) ?>" data-address-field="city"<?= $req ?>>
        </label>
        <label>
            <span>Province</span>
            <select name="<?= escape($prefix) ?>province" data-address-prefix="<?= escape($prefix) ?>" data-address-field="province" data-tax-province<?= $req ?>>
                <option value="">Select province</option>
                <?php foreach (AddressService::provinces() as $code => $label): ?>
                    <option value="<?= escape($code) ?>" <?= ($address['province'] ?? '') === $code ? 'selected' : '' ?>><?= escape($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span>Postal code</span>
            <input type="text" name="<?= escape($prefix) ?>postal_code" value="<?= escape($address['postal_code'] ?? '') ?>" data-address-prefix="<?= escape($prefix) ?>" data-address-field="postal_code"<?= $req ?>>
        </label>
    </div>
    <input type="hidden" name="<?= escape($prefix) ?>country" value="CA">
</fieldset>
