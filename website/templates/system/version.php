<?php

declare(strict_types=1);

/** @var array<string, array{key: string, label: string, route: string, open_url: ?string, version: string, ahead_of_release: bool, modified: string, template: string}> $catalog */
/** @var string $appVersion */
/** @var string $releasedDate */
?>
<section class="version-registry">
    <header class="version-registry-header">
        <p class="eyebrow">Development only</p>
        <h1>Page versions</h1>
        <p class="muted">
            App release <strong>v<?= escape($appVersion) ?></strong>
            <?php if ($releasedDate !== ''): ?>
                · shipped <?= escape($releasedDate) ?>
            <?php endif; ?>
        </p>
        <?php
        [$releaseMajor, $releaseMinor] = array_map('intval', explode('.', $appVersion, 2));
        $aheadExample = $releaseMajor . '.' . ($releaseMinor + 1);
        $behindExample = $releaseMajor . '.' . max(0, $releaseMinor - 1);
        ?>
        <p class="muted version-registry-legend">
            <span class="version-badge version-badge--current">v<?= escape($appVersion) ?></span>
            at app release.
            <span class="version-badge version-badge--ahead">v<?= escape($aheadExample) ?></span>
            above release — edited since ship.
            <span class="version-badge version-badge--behind">v<?= escape($behindExample) ?></span>
            below release — bump this page to match ship.
        </p>
        <p class="muted version-registry-hint">
            Footer notes on <code>localhost</code> and <code>dev.roammax.ca</code> only.
        </p>
    </header>

    <div class="table-wrap">
        <table class="data-table version-registry-table">
            <thead>
                <tr>
                    <th>Page</th>
                    <th>Route</th>
                    <th></th>
                    <th>Version</th>
                    <th>Last modified</th>
                    <th>Template</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($catalog as $entry): ?>
                    <tr>
                        <td>
                            <?php if (!empty($entry['open_url'])): ?>
                                <a href="<?= escape($entry['open_url']) ?>" target="_blank" rel="noopener noreferrer"><?= escape($entry['label']) ?></a>
                            <?php else: ?>
                                <?= escape($entry['label']) ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($entry['open_url'])): ?>
                                <a href="<?= escape($entry['open_url']) ?>" target="_blank" rel="noopener noreferrer"><code><?= escape($entry['route']) ?></code></a>
                            <?php elseif ($entry['route'] !== '—'): ?>
                                <code><?= escape($entry['route']) ?></code>
                            <?php else: ?>
                                <?= escape($entry['route']) ?>
                            <?php endif; ?>
                        </td>
                        <td class="version-registry-open-cell">
                            <?php if (!empty($entry['open_url'])): ?>
                                <a class="version-registry-open-link" href="<?= escape($entry['open_url']) ?>" target="_blank" rel="noopener noreferrer">Open</a>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="<?= escape(page_version_badge_class($entry['version'])) ?>">v<?= escape($entry['version']) ?></span>
                        </td>
                        <td><?= escape($entry['modified']) ?></td>
                        <td><code><?= escape($entry['template']) ?>.php</code></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
