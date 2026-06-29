<?php

declare(strict_types=1);

use Starlink\Services\PricingService;

/** @var array<string, mixed> $booking */
/** @var array<string, mixed> $pay */
/** @var list<array{key: string, label: string, tone: string}> $attention */
/** @var ?array<string, mixed> $stageSummary */
/** @var list<array<string, mixed>> $paymentTimeline */
/** @var array<string, mixed> $cancelDefaults */
/** @var bool $paymentMismatch */
/** @var list<array{key: string, label: string, confirm?: string}> $depositActions */

$pageTitle = 'Booking ' . booking_reference($booking);
$m = static fn (int $c): string => PricingService::formatMoney($c);
$headerTitle = 'Booking ' . booking_reference($booking);
$headerLabel = 'Operations';
$headerLead = booking_is_partner_personal($booking)
    ? 'Partner personal trip — one unit for the full dates; borrow fee applies to conflict days only.'
    : (booking_is_owner_block($booking)
    ? 'Owner personal use — no payment'
    : (string) ($booking['customer_name'] ?? ''));
require WEBSITE_ROOT . '/templates/admin/_page-header.php';

$lifecycle = booking_lifecycle_status($booking);
$fStatus = booking_fulfillment_status($booking);
$payment = booking_payment_status($booking);
$resolvedPay = booking_resolved_payment_context($booking);
$fulfillment = str_replace('_', ' ', (string) $booking['fulfillment_type']);
$canHandOut = (new \Starlink\Services\BookingStateService())->canHandOutHardware($booking);
$total = $pay['rental_cents'] + $pay['deposit_cents'];
$listQuery = $statusFilter !== '' ? '?status=' . rawurlencode($statusFilter) : '';
$defaultRefundCad = number_format($cancelDefaults['default_refund_cents'] / 100, 2, '.', '');
$maxRefundCad = number_format($cancelDefaults['max_refund_cents'] / 100, 2, '.', '');
?>
<p class="lead" style="margin-top:0;">
    <a href="<?= escape(route_path('admin/bookings') . $listQuery) ?>">&larr; All bookings</a>
</p>

<?php if ($message = flash('error')): ?>
    <div class="alert alert-error"><?= escape($message) ?></div>
<?php endif; ?>
<?php if ($message = flash('success')): ?>
    <div class="alert alert-success"><?= escape($message) ?></div>
<?php endif; ?>

<?php if ($attention !== []): ?>
    <div class="admin-booking-attention-bar">
        <?php foreach ($attention as $item): ?>
            <span class="admin-attention-chip admin-attention-chip--<?= escape($item['tone']) ?>"><?= escape($item['label']) ?></span>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="admin-booking-detail-grid">
    <div class="card">
        <div class="micro-label">Booking</div>
        <p class="admin-detail-line"><strong class="mono"><?= escape(booking_reference($booking)) ?></strong> · #<?= (int) $booking['id'] ?></p>
        <p class="admin-detail-line"><?= escape((string) ($booking['customer_name'] ?? '')) ?></p>
        <p class="admin-detail-line mono"><?= escape((string) $booking['start_date']) ?> → <?= escape((string) $booking['end_date']) ?></p>
        <p class="admin-detail-meta"><?= (int) $pay['rental_days'] ?> days · <?= escape($fulfillment) ?> · <?= escape((string) ($booking['location_name'] ?? '')) ?></p>
        <?php
        $appointment = booking_appointment_summary($booking);
        if ($appointment !== null): ?>
            <p class="admin-detail-meta">
                Home pickup · <span class="badge badge-active"><?= escape($appointment['status']) ?></span>
            </p>
            <?php if ($appointment['slot'] !== ''): ?>
                <p class="admin-detail-line"><?= escape($appointment['slot']) ?></p>
            <?php endif; ?>
            <?php if ($appointment['message'] !== ''): ?>
                <p class="admin-detail-meta"><?= escape($appointment['message']) ?></p>
            <?php endif; ?>
            <?php if ($appointment['customer_notes'] !== ''): ?>
                <p class="admin-detail-meta">Customer notes: <?= escape($appointment['customer_notes']) ?></p>
            <?php endif; ?>
        <?php endif; ?>
        <?php if (!empty($booking['equipment_name'])): ?>
            <p class="admin-detail-meta">Unit: <span class="mono"><?= escape((string) $booking['equipment_name']) ?></span></p>
        <?php endif; ?>
        <p class="admin-detail-meta">Created <?= escape((string) ($booking['created_at'] ?? '')) ?></p>
        <?php if (!empty($booking['agreement_accepted_at'])): ?>
            <p class="admin-detail-meta">
                Agreement v<?= escape((string) ($booking['agreement_version'] ?? 'unknown')) ?>
                accepted <span class="mono"><?= escape((string) $booking['agreement_accepted_at']) ?></span>
                <?php if (!empty($booking['agreement_ip'])): ?>
                    · IP <span class="mono"><?= escape((string) $booking['agreement_ip']) ?></span>
                <?php endif; ?>
                <?php if (!empty($booking['agreement_content_snapshot'])): ?>
                    · snapshot saved
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="micro-label">Status</div>
        <p class="admin-detail-line"><span class="badge <?= escape($pay['status_class']) ?>"><?= escape(booking_customer_status_label($booking)) ?></span></p>
        <p class="admin-detail-meta mono">System: <?= escape($payment) ?></p>
        <p class="admin-detail-meta mono"><?= escape($fStatus) ?></p>
    </div>

    <div class="card admin-booking-detail-span">
        <?php if (booking_is_owner_block($booking)): ?>
        <div class="micro-label">Payment</div>
        <p class="admin-detail-meta">No charge — personal-use reservation (<?= escape((string) ($booking['owner_block_requested_by'] ?? 'owner')) ?>).</p>
        <?php else: ?>
        <div class="section-heading" style="margin-bottom:0.75rem;">
            <h2 class="section-title" style="font-size:1rem;">Payment</h2>
            <?php if (!empty($paymentMismatch)): ?>
                <form method="post" action="<?= escape(route_path('admin/bookings/action')) ?>" style="margin:0;"
                      onsubmit="return confirm('Reset payment for this booking? Square IDs will be cleared and the customer will see Pay now again.');">
    <?= csrf_field() ?>
                    <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
                    <input type="hidden" name="status_filter" value="<?= escape($statusFilter) ?>">
                    <input type="hidden" name="return_to" value="detail">
                    <button class="btn btn-primary btn-compact" name="action" value="reset_payment" type="submit">Reset</button>
                </form>
            <?php endif; ?>
        </div>

        <p class="admin-detail-meta"><?= escape($pay['flow']) ?></p>
        <ul class="admin-pay-lines" style="margin-top:0.5rem;">
            <li>
                <span class="admin-pay-label">Rental</span>
                <span class="admin-pay-value">
                    <span class="mono"><?= escape($m($pay['rental_cents'])) ?></span>
                    <span class="admin-pay-state"><?= escape($pay['rental_state']) ?></span>
                </span>
            </li>
            <?php if ($pay['deposit_cents'] > 0): ?>
                <li>
                    <span class="admin-pay-label">Deposit</span>
                    <span class="admin-pay-value">
                        <span class="mono"><?= escape($m($pay['deposit_cents'])) ?></span>
                        <span class="admin-pay-state"><?= escape($pay['deposit_state']) ?></span>
                    </span>
                </li>
            <?php endif; ?>
        </ul>
        <p class="admin-detail-line mono" style="margin-top:0.75rem;"><strong>Total <?= escape($m($total)) ?></strong></p>
        <?php if ($pay['release_blocked']): ?>
            <p class="admin-pay-alert">Do not release hardware</p>
        <?php endif; ?>
        <?php if (!empty($pay['rental_mismatch'])): ?>
            <p class="admin-pay-alert">Rental payment not verified in Square. Reset so the customer can pay again from their booking page.</p>
        <?php elseif (!empty($pay['deposit_mismatch'])): ?>
            <p class="admin-pay-alert">Deposit record is inconsistent — use deposit actions below or reset payment.</p>
        <?php endif; ?>

        <?php if (!empty($pay['deposit_hold_release'])): ?>
            <?php $holdRelease = $pay['deposit_hold_release']; ?>
            <div class="admin-deposit-hold-meta" style="margin-top:0.75rem;">
                <?php if (!empty($holdRelease['placed_label'])): ?>
                    <p class="admin-detail-meta">Hold placed <?= escape((string) $holdRelease['placed_label']) ?></p>
                <?php endif; ?>
                <p class="admin-detail-meta">Scheduled release · <?= escape((string) $holdRelease['release_label']) ?></p>
                <?php if (!empty($holdRelease['square_expires_label'])): ?>
                    <p class="admin-detail-meta"><?= escape((string) $holdRelease['square_expires_label']) ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($depositActions !== []): ?>
            <div class="admin-deposit-actions">
                <?php foreach ($depositActions as $depositAction): ?>
                    <?php $confirm = trim((string) ($depositAction['confirm'] ?? '')); ?>
                    <form method="post" action="<?= escape(route_path('admin/bookings/action')) ?>" style="margin:0;"
                          <?php if ($confirm !== ''): ?>onsubmit="return confirm(<?= escape(json_encode($confirm, JSON_THROW_ON_ERROR)) ?>);"<?php endif; ?>>
                        <?= csrf_field() ?>
                        <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
                        <input type="hidden" name="status_filter" value="<?= escape($statusFilter) ?>">
                        <input type="hidden" name="return_to" value="detail">
                        <button
                            class="btn btn-secondary btn-compact"
                            name="action"
                            value="<?= escape($depositAction['key']) ?>"
                            type="submit"
                        ><?= escape($depositAction['label']) ?></button>
                    </form>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if (!booking_is_owner_block($booking)): ?>
    <div class="card admin-booking-detail-span">
        <div class="micro-label">Transaction history</div>
        <?php if ($paymentTimeline === []): ?>
            <p class="admin-detail-meta" style="margin-top:0.5rem;">No payment events recorded yet.</p>
        <?php else: ?>
            <table class="admin-ledger-table">
                <thead>
                <tr>
                    <th>Date & time (UTC)</th>
                    <th>Event</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Square</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($paymentTimeline as $row): ?>
                    <tr>
                        <td class="mono admin-ledger-at"><?= escape((string) $row['at']) ?></td>
                        <td>
                            <?= escape((string) $row['label']) ?>
                            <?php if (!empty($row['notes'])): ?>
                                <div class="admin-detail-meta"><?= escape((string) $row['notes']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($row['square_id'])): ?>
                                <div class="admin-detail-meta mono"><?= escape((string) $row['square_id']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="mono admin-ledger-amount admin-ledger-amount--<?= escape((string) $row['direction']) ?>">
                            <?php
                            $prefix = match ($row['direction']) {
                                'credit' => '−',
                                'hold' => '⏸ ',
                                default => '+',
                            };
                            echo escape($prefix . $m((int) $row['amount_cents']));
                            ?>
                        </td>
                        <td><?= escape((string) $row['status']) ?></td>
                        <td class="admin-ledger-square">
                            <?php if (!empty($row['square_verified'])): ?>
                                <span class="admin-square-verified" title="<?= escape((string) ($row['square_verified_status'] ?? 'Verified')) ?>">✓</span>
                            <?php elseif (!empty($row['square_can_verify']) && !empty($row['square_id'])): ?>
                                <form method="post" action="<?= escape(route_path('admin/bookings/action')) ?>" style="margin:0;">
    <?= csrf_field() ?>
                                    <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
                                    <input type="hidden" name="status_filter" value="<?= escape($statusFilter) ?>">
                                    <input type="hidden" name="return_to" value="detail">
                                    <input type="hidden" name="square_payment_id" value="<?= escape((string) $row['square_id']) ?>">
                                    <button class="btn btn-secondary btn-compact" name="action" value="verify_square" type="submit">Verify</button>
                                </form>
                            <?php else: ?>
                                <span class="admin-detail-meta">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card admin-booking-detail-span" id="actions">
        <div class="micro-label">Actions</div>
        <form method="post" action="<?= escape(route_path('admin/bookings/action')) ?>" class="admin-detail-actions">
    <?= csrf_field() ?>
        <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
        <input type="hidden" name="status_filter" value="<?= escape($statusFilter) ?>">
        <input type="hidden" name="return_to" value="detail">

        <?php if (empty($booking['equipment_id']) && !in_array($lifecycle, ['booking_cancelled', 'booking_closed', 'booking_pending_payment'], true)): ?>
            <div class="admin-detail-action-block" id="assign">
                <p class="admin-detail-meta"><strong>Assign unit</strong> — choose hardware for this booking. Staging due date is set when you assign.</p>
                <?php if ($eligibleUnits === []): ?>
                    <p class="admin-detail-meta">No units available for these dates. Check blocks or overlapping bookings.</p>
                <?php else: ?>
                    <select name="equipment_id" class="admin-input-compact" required>
                        <option value="">Select unit…</option>
                        <?php foreach ($eligibleUnits as $unit): ?>
                            <option value="<?= (int) $unit['id'] ?>">
                                <?= escape($unit['nickname']) ?> · <?= escape($unit['location_name'] ?? '') ?> · <?= escape(str_replace('_', ' ', (string) $unit['status'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-primary" name="action" value="assign_equipment" type="submit">Assign unit</button>
                <?php endif; ?>
            </div>
        <?php elseif (!empty($booking['staging_date'])): ?>
            <p class="admin-detail-meta"><strong>Stage by</strong> <span class="mono"><?= escape($booking['staging_date']) ?></span> · rental starts <?= escape($booking['start_date']) ?></p>
        <?php endif; ?>

        <?php if ($resolvedPay['method'] === 'etransfer' && !booking_is_owner_block($booking) && in_array($payment, ['payment_pending_etransfer', 'payment_etransfer_sent', 'payment_etransfer_partial_confirmed'], true)): ?>
            <div class="admin-detail-action-block">
                <p class="admin-detail-meta">Record e-Transfer received</p>
                <input type="number" step="0.01" min="0" name="etransfer_amount" placeholder="Amount received (CAD)" class="admin-input-compact">
                <button class="btn btn-primary" name="action" value="confirm_etransfer" type="submit">Confirm e-Transfer</button>
            </div>
        <?php endif; ?>

        <?php if ($stageSummary !== null && empty($stageSummary['needs_assign'])): ?>
            <div class="admin-detail-action-block">
                <p class="admin-detail-meta"><strong>Staging</strong> — <?= escape($stageSummary['move_line']) ?></p>
                <?php if ($stageSummary['current_name'] !== ''): ?>
                    <p class="admin-detail-meta">Listed now: <?= escape($stageSummary['current_name']) ?></p>
                <?php endif; ?>
                <button
                    class="btn btn-secondary"
                    name="action"
                    value="stage"
                    type="submit"
                    onclick="return confirm(<?= json_encode($stageSummary['confirm_message'], JSON_THROW_ON_ERROR) ?>);"
                >Mark staged</button>
            </div>
        <?php endif; ?>

        <?php if ($canHandOut && $lifecycle === 'booking_confirmed' && in_array($fStatus, ['fulfillment_pending', 'fulfillment_staged'], true) && $booking['fulfillment_type'] !== 'mail_ship'): ?>
            <div class="admin-detail-action-block">
                <button class="btn btn-primary" name="action" value="pickup" type="submit" onclick="return confirm('Confirm customer picked up the unit?');">Picked up</button>
            </div>
        <?php endif; ?>

        <?php if ($canHandOut && $lifecycle === 'booking_confirmed' && in_array($fStatus, ['fulfillment_pending', 'fulfillment_staged'], true) && $booking['fulfillment_type'] === 'mail_ship'): ?>
            <div class="admin-detail-action-block">
                <button class="btn btn-primary" name="action" value="ship" type="submit" onclick="return confirm('Confirm shipment dispatched?');">Shipped</button>
            </div>
        <?php endif; ?>

        <?php if (in_array($lifecycle, ['booking_active', 'booking_late'], true) && in_array($fStatus, ['fulfillment_with_customer', 'shipping_received'], true)): ?>
            <div class="admin-detail-action-block">
                <button class="btn btn-secondary" name="action" value="return_received" type="submit">Return received</button>
                <button class="btn btn-secondary" name="action" value="late_fee" type="submit">Apply late fee</button>
            </div>
        <?php endif; ?>

        <?php if ($fStatus === 'fulfillment_return_received'): ?>
            <div class="admin-detail-action-block">
                <button class="btn btn-primary" name="action" value="confirm_qc" type="submit">Confirm equipment OK</button>
                <input type="number" step="0.01" min="0" name="damage_cents" placeholder="Damage CAD (blank = deposit)" class="admin-input-compact">
                <button class="btn btn-secondary" name="action" value="qc_failed" type="submit">QC failed</button>
            </div>
        <?php endif; ?>

        <?php if ($lifecycle === 'booking_cancellation_pending'): ?>
            <div class="admin-detail-action-block">
                <p class="admin-pay-alert">Customer requested cancellation</p>
                <?php require WEBSITE_ROOT . '/templates/admin/_cancel-refund-fields.php' ?>
                <button class="btn btn-primary" name="action" value="approve_cancellation" type="submit">Approve cancel</button>
                <input type="text" name="cancellation_reason" placeholder="Decline note (optional)" class="admin-input-compact">
                <button class="btn btn-secondary" name="action" value="reject_cancellation" type="submit">Decline request</button>
            </div>
        <?php elseif (!in_array($lifecycle, ['booking_cancelled', 'booking_closed'], true)): ?>
            <div class="admin-detail-action-block admin-detail-action-block--muted">
                <p class="admin-detail-meta">Admin cancel</p>
                <input type="text" name="cancellation_reason" placeholder="Cancel reason" class="admin-input-compact">
                <?php require WEBSITE_ROOT . '/templates/admin/_cancel-refund-fields.php' ?>
                <button class="btn btn-secondary" name="action" value="cancel" type="submit">Cancel booking</button>
            </div>
        <?php endif; ?>
    </form>
    </div>
</div>
