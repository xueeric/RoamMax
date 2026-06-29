<?php

declare(strict_types=1);

/** @var string $agreementHtml */
/** @var string $agreementVersion */
?>
<div class="agreement-body">
    <?= $agreementHtml ?>
</div>
<footer class="agreement-version-footer">
    Agreement version <span class="mono"><?= escape($agreementVersion) ?></span>
</footer>
