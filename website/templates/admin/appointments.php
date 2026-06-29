<?php

declare(strict_types=1);

$pageTitle = 'Home Appointments';
$headerTitle = 'Home appointment pickup times';
$headerLabel = 'Appointment queue';
$headerLead = 'Upcoming home pickups — confirm new requests, then keep track of when customers are arriving.';
require WEBSITE_ROOT . '/templates/admin/_page-header.php';
?>
<?php if ($message = flash('error')): ?>
    <div class="alert alert-error"><?= escape($message) ?></div>
<?php endif; ?>
<?php if ($message = flash('success')): ?>
    <div class="alert alert-success"><?= escape($message) ?></div>
<?php endif; ?>

<div class="card table-wrap">
    <table>
        <thead>
        <tr>
            <th>Booking</th>
            <th>Customer</th>
            <th>Rental dates</th>
            <th>Payment</th>
            <th>Pickup time</th>
            <th>Notes</th>
            <th>Status / actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($appointments === []): ?>
            <tr><td colspan="7">No upcoming home pickup appointments.</td></tr>
        <?php else: ?>
            <?php foreach ($appointments as $booking): ?>
                <?php
                $paymentStatus = booking_payment_status($booking);
                $summary = booking_appointment_summary($booking);
                $isLegacyProposal = in_array($booking['appointment_status'], ['appointment_proposed', 'proposed'], true);
                $isConfirmed = in_array($booking['appointment_status'], ['appointment_confirmed', 'confirmed'], true);
                $isAwaiting = in_array($booking['appointment_status'], ['appointment_awaiting_admin', 'awaiting_admin'], true);
                ?>
                <tr>
                    <td class="mono">
                        <a href="<?= escape(route_path('admin/bookings/view') . '?booking_id=' . (int) $booking['id']) ?>"><?= escape(booking_reference($booking)) ?></a>
                    </td>
                    <td><?= escape($booking['customer_name']) ?><br><span class="mono"><?= escape($booking['customer_email']) ?></span></td>
                    <td class="mono"><?= escape($booking['start_date']) ?> → <?= escape($booking['end_date']) ?></td>
                    <td>
                        <span class="badge badge-active"><?= escape(str_replace('_', ' ', $paymentStatus)) ?></span>
                    </td>
                    <td class="mono">
                        <?php if ($summary !== null && $summary['slot'] !== ''): ?>
                            <?= escape($summary['slot']) ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= escape($summary['customer_notes'] ?? $booking['customer_notes'] ?? '—') ?>
                        <?php if ($isConfirmed && ($summary['message'] ?? '') !== ''): ?>
                            <div class="admin-bookings-meta"><?= escape($summary['message']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($isLegacyProposal): ?>
                            <span class="badge badge-muted">Proposed</span>
                            <div class="admin-bookings-meta">Waiting for customer acceptance</div>
                        <?php elseif ($isConfirmed): ?>
                            <span class="badge badge-active">Confirmed</span>
                            <div class="admin-bookings-meta">Customer notified · disappears after pickup</div>
                        <?php elseif ($isAwaiting): ?>
                            <form method="post" action="<?= escape(route_path('admin/appointments/confirm')) ?>" class="inline-form">
    <?= csrf_field() ?>
                                <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
                                <fieldset class="radio-stack">
                                    <label class="radio-inline">
                                        <input type="radio" name="message_style" value="home" checked>
                                        <span>I will be home — customer can knock on the door</span>
                                    </label>
                                    <label class="radio-inline">
                                        <input type="radio" name="message_style" value="leave_at_door">
                                        <span>Leave Starlink Mini at front door if customer is not home</span>
                                    </label>
                                </fieldset>
                                <label><span>Extra message (optional)</span><textarea name="custom_message" rows="2" placeholder="Any other details for the customer"></textarea></label>
                                <button class="btn btn-primary" type="submit">Send pickup message</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>
