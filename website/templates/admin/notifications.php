<?php

declare(strict_types=1);

use Starlink\Services\NotificationRuleService;

$pageTitle = 'Notification rules';
$headerTitle = 'Notification rules';
$headerLabel = 'Alerts';
$headerLead = 'Choose who receives email for each booking event. Click an event name to preview the email. Telegram cards update automatically — no per-event toggle.';
$categoryLabels = NotificationRuleService::categoryLabels();
$notificationsLive = notifications_live();
require WEBSITE_ROOT . '/templates/admin/_page-header.php';
?>
<?php if ($message = flash('error')): ?>
    <div class="alert alert-error"><?= escape($message) ?></div>
<?php endif; ?>
<?php if ($message = flash('success')): ?>
    <div class="alert alert-success"><?= escape($message) ?></div>
<?php endif; ?>

<?php if (!empty($telegramLive)): ?>
    <div class="card" style="margin-bottom:1rem;padding:0.85rem 1rem;font-size:0.8125rem;">
        <strong>Telegram booking cards</strong> — one message per booking, edited as status changes.
        <?php if ($notificationsLive): ?>
            Live in chat <span class="mono"><?= escape((string) config('telegram.admin_chat_id', '')) ?></span>.
        <?php else: ?>
            Configured; goes live with <span class="mono">NOTIFICATIONS_LIVE=true</span> or production.
        <?php endif; ?>
        <span style="display:block;margin-top:0.35rem;color:#6b7280;">
            ⏳ Awaiting payment · 🟢 In progress · 🟠 Late · ⚠️ Cancel pending · 🔴 Deposit failed · ⭕ Cancelled · ✅ Complete
        </span>
    </div>
<?php endif; ?>

<form method="post" action="<?= escape(route_path('admin/notifications')) ?>" class="notifications-rules-form">
    <?= csrf_field() ?>
    <div class="card table-wrap">
        <table class="notifications-rules-table">
            <thead>
            <tr>
                <th>Event</th>
                <th>Customer email</th>
                <th>Admin email</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($groups as $category => $rules): ?>
                <tr class="notifications-group-row">
                    <td colspan="3">
                        <span class="notifications-group-title"><?= escape($categoryLabels[$category] ?? ucfirst($category)) ?></span>
                        <span class="micro-label"><?= count($rules) ?> event<?= count($rules) === 1 ? '' : 's' ?></span>
                    </td>
                </tr>
                <?php foreach ($rules as $rule): ?>
                    <?php $key = (string) $rule['event_key']; ?>
                    <tr>
                        <td>
                            <button type="button" class="notification-preview-trigger" data-event-key="<?= escape($key) ?>">
                                <?= escape((string) $rule['label']) ?>
                            </button>
                        </td>
                        <td><label class="checkbox-inline"><input type="checkbox" name="customer[<?= escape($key) ?>]" value="1" <?= !empty($rule['notify_customer_email']) ? 'checked' : '' ?>></label></td>
                        <td><label class="checkbox-inline"><input type="checkbox" name="admin_email[<?= escape($key) ?>]" value="1" <?= !empty($rule['notify_admin_email']) ? 'checked' : '' ?>></label></td>
                    </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <button class="btn btn-primary" type="submit">Save rules</button>
</form>

<div class="notification-preview-modal" id="notification-preview-modal" hidden>
    <div class="notification-preview-backdrop" data-close-preview></div>
    <div class="notification-preview-dialog" role="dialog" aria-modal="true" aria-labelledby="notification-preview-title">
        <div class="notification-preview-header">
            <div>
                <div class="micro-label" id="notification-preview-event-key"></div>
                <h2 class="section-title" id="notification-preview-title">Email preview</h2>
                <p class="lead" style="font-size:0.8125rem;margin:0.35rem 0 0;" id="notification-preview-subject"></p>
                <p class="lead" style="font-size:0.75rem;margin:0.25rem 0 0;color:#6b7280;" id="notification-preview-recipients"></p>
            </div>
            <button type="button" class="btn btn-secondary" data-close-preview aria-label="Close preview">Close</button>
        </div>
        <div class="notification-preview-body">
            <iframe class="notification-preview-frame" id="notification-preview-frame" title="Email preview"></iframe>
        </div>
    </div>
</div>

<p class="lead" style="font-size:0.8125rem;margin-top:1rem;">
    <?php if ($notificationsLive && !empty($mailLive)): ?>
        Customer and admin emails are sent via <strong>Brevo</strong> from <span class="mono"><?= escape((string) config('mail.from_email', 'noreply@roammax.ca')) ?></span>.
    <?php elseif (!empty($mailLive)): ?>
        Brevo is configured but app events are <strong>log-only</strong> until <span class="mono">NOTIFICATIONS_LIVE=true</span> or <span class="mono">APP_ENV=production</span>.
    <?php else: ?>
        Email delivery is log-only until <span class="mono">BREVO_API_KEY</span> is set in <span class="mono">.env</span>.
    <?php endif; ?>
    Preview uses sample booking data. Every attempt is logged to <span class="mono">data/notifications.log</span> and <span class="mono">notification_log</span>.
    Admin email uses <span class="mono">ADMIN_EMAIL</span>.
</p>

<script>
(function () {
    const modal = document.getElementById('notification-preview-modal');
    const frame = document.getElementById('notification-preview-frame');
    const titleEl = document.getElementById('notification-preview-title');
    const subjectEl = document.getElementById('notification-preview-subject');
    const keyEl = document.getElementById('notification-preview-event-key');
    const recipientsEl = document.getElementById('notification-preview-recipients');
    const previewUrl = <?= json_encode($previewUrl ?? route_path('admin/notifications/preview'), JSON_THROW_ON_ERROR) ?>;

    function closePreview() {
        modal.hidden = true;
        frame.srcdoc = '';
        document.body.style.overflow = '';
    }

    function openPreview(data) {
        titleEl.textContent = data.label || 'Email preview';
        keyEl.textContent = data.event_key || '';
        subjectEl.textContent = data.subject ? ('Subject: ' + data.subject) : '';
        const recipients = [];
        if (data.customer_enabled) recipients.push('customer');
        if (data.admin_enabled) recipients.push('admin');
        recipientsEl.textContent = recipients.length
            ? ('Sent to: ' + recipients.join(' + ') + ' when toggles are on')
            : 'Not sent by email — both toggles are off for this event';

        const doc = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>body{margin:0;padding:0;}</style></head><body>'
            + (data.html || '') + '</body></html>';
        frame.srcdoc = doc;
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
    }

    document.querySelectorAll('.notification-preview-trigger').forEach(function (button) {
        button.addEventListener('click', function () {
            const eventKey = button.getAttribute('data-event-key');
            if (!eventKey) return;

            titleEl.textContent = 'Loading preview…';
            subjectEl.textContent = '';
            recipientsEl.textContent = '';
            frame.srcdoc = '<p style="font-family:sans-serif;padding:1rem;">Loading…</p>';
            modal.hidden = false;
            document.body.style.overflow = 'hidden';

            fetch(previewUrl + '?event_key=' + encodeURIComponent(eventKey), {
                headers: { 'Accept': 'application/json' },
            })
                .then(function (response) {
                    if (!response.ok) throw new Error('Preview failed');
                    return response.json();
                })
                .then(openPreview)
                .catch(function () {
                    titleEl.textContent = 'Preview unavailable';
                    subjectEl.textContent = '';
                    recipientsEl.textContent = '';
                    frame.srcdoc = '<p style="font-family:sans-serif;padding:1rem;color:#b91c1c;">Could not load preview.</p>';
                });
        });
    });

    modal.querySelectorAll('[data-close-preview]').forEach(function (el) {
        el.addEventListener('click', closePreview);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.hidden) {
            closePreview();
        }
    });
})();
</script>
