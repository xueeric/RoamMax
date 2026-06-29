<?php

declare(strict_types=1);

use Starlink\Services\PricingService;

$m = static fn (int $c): string => PricingService::formatMoney($c);
?>
<section class="home-section">
    <div class="section-heading" id="faq">
        <h2 class="section-title">FAQ</h2>
        <span class="micro-label">Questions</span>
    </div>

    <div class="faq-list">
        <details class="card faq-item">
            <summary>How does pricing work?</summary>
            <p>Daily rates depend on how long you rent. Shorter trips use the standard tier; longer stays unlock lower daily rates automatically. Special rules (like long-weekend flat pricing) apply when your dates match. Your exact total is calculated at checkout.</p>
        </details>

        <details class="card faq-item">
            <summary>What is the minimum rental period?</summary>
            <p>The minimum self-serve booking is <?= (int) $minimumRentalDays ?> days. Some holiday periods may require a higher minimum — the calendar will tell you when you select dates.</p>
        </details>

        <details class="card faq-item">
            <summary>What about rentals longer than <?= (int) $maxSelfServeDays ?> days?</summary>
            <p>Select your dates on the calendar and submit a quote request. We will follow up with custom commercial rates — you do not need to contact us separately.</p>
        </details>

        <details class="card faq-item">
            <summary>Is a deposit required?</summary>
            <p>Yes. A <?= escape($m((int) $depositCents)) ?> security deposit is collected at booking and refunded within 72 hours of return, subject to inspection.</p>
        </details>

        <details class="card faq-item">
            <summary>Can I cancel my booking?</summary>
            <p>Free cancellation up to 72 hours before your rental start date. Late cancellations forfeit the base 3-day rental fee; the deposit is still refunded.</p>
        </details>

        <details class="card faq-item">
            <summary>Where can I pick up?</summary>
            <p>We serve Alberta with pickup locations in Edmonton, Calgary, and Red Deer. Choose your city when booking, then select pickup or local delivery within that area.</p>
        </details>

        <details class="card faq-item">
            <summary>What if I return the kit late?</summary>
            <p>We know that hauling a trailer back into town and unpacking can take time, and highway traffic is unpredictable. If you can't make it to our drop-off location before we close (typically between 8:00 PM and 9:00 PM), we offer a Next-Morning Grace Period. You can return the equipment the following morning before 11:00 AM without being charged for an extra day.</p>
            <p>If the unit is not returned by 11:00 AM the morning following your scheduled return date, an automatic late fee of <?= escape($m((int) pricing_config('late_fee_cents_per_day', 5000))) ?> per day will apply until the unit is returned.</p>
        </details>
    </div>

    <p class="lead" style="margin-top:1rem;margin-bottom:0;">
        Full terms: <a href="<?= escape(route_path('agreement')) ?>">Rental agreement</a>
    </p>
</section>
