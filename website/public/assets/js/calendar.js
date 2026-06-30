(() => {
    const config = window.STARLINK_CALENDAR || {};
    const minimumDays = config.minimumRentalDays || 3;
    const maxSelfServeDays = config.maxSelfServeDays || 30;
    const shippingArrivalLeadDays = config.shippingArrivalLeadDays || 7;
    const cityDeliveryRadiusKm = config.cityDeliveryRadiusKm || 50;
    const cityDeliveryFeeCents = config.cityDeliveryFeeCents || 2500;
    const horizonDays = 120;
    const visibleWeeks = 5;
    const visibleDays = visibleWeeks * 7;

    const locationSelect = document.getElementById('location-id');
    const fulfillmentSelect = document.getElementById('fulfillment-type');
    const defaultLocationId = String(config.defaultLocationId || locationSelect?.value || '0');
    const grid = document.getElementById('calendar-grid');
    const periodLabel = document.getElementById('calendar-month-label');
    const summary = document.getElementById('selection-summary');
    const result = document.getElementById('availability-result');
    const pickupInfo = document.getElementById('location-pickup-info');
    const locationDetails = config.locations || {};

    let viewWeekStart = startOfWeek(new Date());
    let startDate = null;
    let endDate = null;
    let dayMap = {};
    let lastRangeCheck = null;

    const parseJsonResponse = async (response) => {
        const raw = await response.text();
        try {
            return JSON.parse(raw);
        } catch {
            throw new Error('Server returned an invalid response. Refresh the page and try again.');
        }
    };

    const isMailShipping = () => fulfillmentSelect.value === 'mail_ship';
    const isCityDelivery = () => fulfillmentSelect.value === 'city_delivery';
    const isPickup = () => fulfillmentSelect.value === 'pickup';

    const currentPostFields = () => ({
        location_id: getCheckoutLocationId(),
        fulfillment_type: resolveFulfillmentType(),
        start_date: startDate,
        end_date: endDate,
    });

    const buildFormFromTemplate = (templateId, fields, options = {}) => {
        const template = document.getElementById(templateId);
        if (!template?.content) {
            return null;
        }

        const sourceForm = template.content.querySelector('form');
        if (!sourceForm) {
            return null;
        }

        const form = sourceForm.cloneNode(true);
        Object.entries(fields).forEach(([key, value]) => {
            const input = form.querySelector(`[data-cal-field="${key}"]`);
            if (input) {
                input.value = value;
            }
        });

        const notes = form.querySelector('[data-cal-notes]');
        if (notes && options.notesPlaceholder) {
            notes.placeholder = options.notesPlaceholder;
        }

        const submit = form.querySelector('[data-cal-submit]');
        if (submit && options.submitLabel) {
            submit.textContent = options.submitLabel;
        }

        return form;
    };

    const setResultContent = (html, form = null) => {
        result.innerHTML = html;
        if (form) {
            result.appendChild(form);
        }
    };

    const buildReserveForm = (options) => buildFormFromTemplate(
        'starlink-calendar-reserve-template',
        currentPostFields(),
        options,
    );

    const buildLongTermForm = () => buildFormFromTemplate(
        'starlink-calendar-long-term-template',
        currentPostFields(),
        { submitLabel: 'Submit a quote' },
    );

    const getApiLocationId = () => locationSelect.value;

    const getCheckoutLocationId = () => {
        if (isMailShipping()) {
            return String(lastRangeCheck?.effective_location_id || defaultLocationId);
        }
        return locationSelect.value;
    };

    const fulfillmentLabel = () => {
        if (isMailShipping()) {
            return 'Mail shipping';
        }
        if (isCityDelivery()) {
            return 'Local delivery';
        }
        return 'Pickup';
    };

    const formatMoney = (cents) => `$${(cents / 100).toFixed(2)}`;

    const fmt = (date) => {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    };

    const parseDate = (value) => {
        const [year, month, day] = value.split('-').map(Number);
        return new Date(year, month - 1, day);
    };

    const startOfToday = () => {
        const today = new Date();
        return new Date(today.getFullYear(), today.getMonth(), today.getDate());
    };

    function startOfWeek(date) {
        const weekStart = new Date(date.getFullYear(), date.getMonth(), date.getDate());
        weekStart.setDate(weekStart.getDate() - weekStart.getDay());
        return weekStart;
    }

    const addDays = (date, days) => {
        const next = new Date(date.getFullYear(), date.getMonth(), date.getDate());
        next.setDate(next.getDate() + days);
        return next;
    };

    const visibleRange = (weekStart) => ({
        start: fmt(weekStart),
        end: fmt(addDays(weekStart, visibleDays - 1)),
    });

    const formatPeriodLabel = (weekStart) => {
        const periodEnd = addDays(weekStart, visibleDays - 1);
        const startOpts = { month: 'short', day: 'numeric' };
        const endOpts = { month: 'short', day: 'numeric', year: 'numeric' };

        if (weekStart.getFullYear() === periodEnd.getFullYear()) {
            return `${weekStart.toLocaleDateString(undefined, startOpts)} – ${periodEnd.toLocaleDateString(undefined, endOpts)}`;
        }

        return `${weekStart.toLocaleDateString(undefined, { ...startOpts, year: 'numeric' })} – ${periodEnd.toLocaleDateString(undefined, endOpts)}`;
    };

    const dayCount = () => {
        if (!startDate || !endDate) {
            return 0;
        }
        return Math.round((parseDate(endDate) - parseDate(startDate)) / 86400000) + 1;
    };

    const mergeDays = (days) => {
        dayMap = { ...dayMap, ...(days || {}) };
    };

    const resolveFulfillmentType = () => {
        if (isMailShipping() || isCityDelivery()) {
            return fulfillmentSelect.value;
        }

        const location = locationDetails[locationSelect.value];
        if (location?.location_type === 'home') {
            return 'pickup_appointment';
        }

        return 'pickup';
    };

    const daysUntilStart = (dateKey) => {
        if (!dateKey) {
            return null;
        }
        return Math.round((parseDate(dateKey) - startOfToday()) / 86400000);
    };

    const mailShippingWarning = () => {
        if (fulfillmentSelect.value !== 'mail_ship' || !startDate) {
            return '';
        }

        const leadDays = daysUntilStart(startDate);
        if (leadDays === null || leadDays >= shippingArrivalLeadDays) {
            return '';
        }

        return `<div class="alert alert-error" style="margin-top:0.75rem;">
            Your rental starts in ${leadDays} day${leadDays === 1 ? '' : 's'}, but mail delivery usually needs about ${shippingArrivalLeadDays} days.
            The kit may arrive after your booking start date.
        </div>`;
    };

    const renderPickupInfo = () => {
        if (!pickupInfo) {
            return;
        }

        const location = locationDetails[locationSelect.value];

        pickupInfo.replaceChildren();

        if (!location) {
            pickupInfo.hidden = true;
            return;
        }

        const appendBlock = (className, text) => {
            if (!text) {
                return;
            }
            const block = document.createElement('div');
            block.className = className;
            block.textContent = text;
            pickupInfo.appendChild(block);
        };

        const locationName = location.name || location.city || 'Your area';
        const preCheckoutNote = 'The exact address and pickup instructions are shared after you complete your booking.';

        if (isPickup()) {
            const isHome = location.location_type === 'home';
            if (config.hideLocationDetails) {
                appendBlock('location-pickup-info-label', isHome ? 'Pickup (appointment)' : 'Pickup location');
                appendBlock('location-pickup-info-name', locationName);
                appendBlock(
                    'location-pickup-info-instructions',
                    isHome
                        ? `Choose your preferred pickup time at checkout. ${preCheckoutNote}`
                        : preCheckoutNote,
                );
            } else {
                appendBlock('location-pickup-info-label', isHome ? 'Pickup (appointment)' : 'Pickup location');
                appendBlock('location-pickup-info-name', location.name || '');
                appendBlock('location-pickup-info-address', location.address || '');
                appendBlock(
                    'location-pickup-info-instructions',
                    location.pickup_instructions || (isHome ? 'At checkout, choose your preferred pickup time. We will try to accommodate at our best.' : ''),
                );
            }
        } else if (isCityDelivery()) {
            appendBlock('location-pickup-info-label', 'Local delivery area');
            appendBlock('location-pickup-info-name', `${locationName} · within ${cityDeliveryRadiusKm} km`);
            if (!config.hideLocationDetails) {
                appendBlock('location-pickup-info-address', location.address || '');
            }
            appendBlock(
                'location-pickup-info-instructions',
                config.hideLocationDetails
                    ? `Delivery fee ${formatMoney(cityDeliveryFeeCents)}. We deliver from units staged in ${location.city || locationName}.`
                    : `Delivery fee ${formatMoney(cityDeliveryFeeCents)}. We deliver from units staged in ${location.city || 'this city'}.`,
            );
        } else if (isMailShipping()) {
            appendBlock('location-pickup-info-label', 'Mail shipping');
            appendBlock(
                'location-pickup-info-name',
                'Ships from the first available unit in our fleet',
            );
            appendBlock(
                'location-pickup-info-instructions',
                config.hideLocationDetails
                    ? `Your selected area (${locationName}) is for reference only. Availability is checked across all hubs.`
                    : `Your selected location (${location.name}) is for reference only. Availability is checked across all hubs.`,
            );
        } else {
            pickupInfo.hidden = true;
            return;
        }

        pickupInfo.hidden = false;
    };

    const findFirstAvailable = () => {
        const keys = Object.keys(dayMap).sort();
        for (const key of keys) {
            if (dayMap[key]?.available) {
                return key;
            }
        }
        return null;
    };

    const fetchDays = async (rangeStart, rangeEnd) => {
        const url = new URL(config.availabilityUrl, window.location.origin);
        url.searchParams.set('location_id', getApiLocationId());
        url.searchParams.set('fulfillment_type', resolveFulfillmentType());
        url.searchParams.set('start', rangeStart);
        url.searchParams.set('end', rangeEnd);

        const response = await fetch(url);
        const data = await parseJsonResponse(response);
        mergeDays(data.days);
        return data;
    };

    const jumpToFirstAvailableWeek = (data) => {
        const firstAvailable = findFirstAvailable();
        const anchor = firstAvailable || data.earliest_start || fmt(new Date());
        viewWeekStart = startOfWeek(parseDate(anchor));

        if (startDate) {
            const selectedWeek = startOfWeek(parseDate(startDate));
            if (endDate) {
                viewWeekStart = selectedWeek;
            } else if (selectedWeek > viewWeekStart) {
                viewWeekStart = selectedWeek;
            }
        }
    };

    const fetchAvailability = async ({ jumpToFirstAvailable = false, clearDays = false } = {}) => {
        grid.innerHTML = '<p class="calendar-loading">Loading availability…</p>';

        if (clearDays || jumpToFirstAvailable) {
            dayMap = {};
        }

        if (jumpToFirstAvailable) {
            const today = fmt(new Date());
            const horizon = fmt(addDays(new Date(), horizonDays));
            const data = await fetchDays(today, horizon);
            jumpToFirstAvailableWeek(data);
        }

        const range = visibleRange(viewWeekStart);
        const data = await fetchDays(range.start, range.end);
        renderCalendar(data);
    };

    const formatDayLabel = (date) => {
        const day = date.getDate();
        if (date.getDate() === 1 || date.getDay() === 0) {
            return `${date.toLocaleDateString(undefined, { month: 'short' })} ${day}`;
        }
        return String(day);
    };

    const renderCalendar = (data) => {
        periodLabel.textContent = formatPeriodLabel(viewWeekStart);

        const labels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        let html = '<div class="calendar-weekdays">';
        labels.forEach((label) => {
            html += `<div class="calendar-weekday">${label}</div>`;
        });
        html += '</div><div class="calendar-days">';

        for (let offset = 0; offset < visibleDays; offset += 1) {
            const date = addDays(viewWeekStart, offset);
            const key = fmt(date);
            const info = dayMap[key] || { available: false, reason: 'unknown' };
            const classes = ['calendar-day'];

            if (!info.available) classes.push('is-blocked');
            else classes.push('is-available');

            if (startDate === key) classes.push('is-start');
            if (endDate === key) classes.push('is-end');
            if (startDate && endDate && key > startDate && key < endDate) classes.push('is-range');

            const disabled = info.available ? '' : ' disabled';
            html += `<button type="button" class="${classes.join(' ')}" data-date="${key}"${disabled} title="${key}">${formatDayLabel(date)}</button>`;
        }

        html += '</div>';
        grid.innerHTML = html;

        grid.querySelectorAll('.calendar-day.is-available, .calendar-day.is-start, .calendar-day.is-end').forEach((button) => {
            button.addEventListener('click', () => selectDate(button.dataset.date));
        });

        if (data.earliest_start) {
            result.dataset.earliest = data.earliest_start;
        }
    };

    const ensureDateVisible = (dateKey) => {
        const date = parseDate(dateKey);
        const visibleStart = viewWeekStart;
        const visibleEnd = addDays(viewWeekStart, visibleDays - 1);

        if (date < visibleStart || date > visibleEnd) {
            viewWeekStart = startOfWeek(date);
        }
    };

    const selectDate = async (date) => {
        if (!startDate || (startDate && endDate)) {
            startDate = date;
            endDate = null;
        } else if (date < startDate) {
            startDate = date;
            endDate = null;
        } else {
            endDate = date;
        }

        ensureDateVisible(date);
        await fetchAvailability();

        if (startDate && endDate) {
            await validateRange();
        } else {
            renderSelectionSummary();
        }
    };

    const fetchQuote = async () => {
        const url = new URL(config.quoteUrl, window.location.origin);
        url.searchParams.set('location_id', isMailShipping() ? getCheckoutLocationId() : locationSelect.value);
        url.searchParams.set('fulfillment_type', resolveFulfillmentType());
        url.searchParams.set('start_date', startDate);
        url.searchParams.set('end_date', endDate);

        if (isMailShipping() || isCityDelivery()) {
            const savedProvince = config.customerShippingProvince;
            if (savedProvince) {
                url.searchParams.set('tax_province', savedProvince);
            }
        }

        const response = await fetch(url);
        const data = await parseJsonResponse(response);
        if (!response.ok) {
            throw new Error(data.error || 'Unable to calculate price.');
        }

        return data;
    };

    const renderQuoteBlock = (quote) => {
        const formatted = quote.formatted || {};
        const rentalLabel = quote.pricing_label
            ? `${quote.days} days · ${quote.pricing_label}`
            : `${quote.days} days`;
        const taxDetermined = quote.tax_determined === true;
        const taxLabel = taxDetermined
            ? `${quote.tax_label || 'Tax'}${quote.tax_rate_percent != null ? ` (${String(quote.tax_rate_percent).replace(/\.?0+$/, '')}%)` : ''}`
            : 'Tax';
        const taxAmount = taxDetermined ? (formatted.tax || '') : 'To be determined';

        const depositChargedAtCheckout = quote.deposit_charged_at_checkout !== false;
        const depositNote = depositChargedAtCheckout
            ? ''
            : '<span class="quote-deposit-note" style="display:block;font-size:0.7rem;opacity:0.7;">Held on card 24h before pickup</span>';

        return `<div class="summary-quote">
            <div class="summary-quote-label">${rentalLabel}</div>
            <div class="quote-lines">
                <div><span>Rental</span><strong class="mono">${formatted.rental_total || ''}</strong></div>
                <div><span>${isCityDelivery() ? 'Delivery' : 'Shipping'}</span><strong class="mono">${formatted.shipping_fee || ''}</strong></div>
                <div><span>${taxLabel}</span><strong class="mono">${taxAmount}</strong></div>
                <div><span>Deposit (refundable)${depositNote}</span><strong class="mono">${formatted.deposit || ''}</strong></div>
            </div>
            <div class="quote-total">
                <span>Total due now</span>
                <strong class="mono">${formatted.total_due || ''}</strong>
            </div>
            ${taxDetermined ? '' : '<p class="summary-empty" style="margin-top:0.75rem;margin-bottom:0;">Tax depends on your shipping province and is finalized at checkout.</p>'}
        </div>`;
    };

    const renderSelectionSummary = (options = {}) => {
        const { quote = null, quoteError = null, loading = false, longTerm = false, partnerQuote = null } = options;
        const days = dayCount();

        if (!startDate) {
            summary.innerHTML = `<p class="summary-empty">Select a start date (minimum ${minimumDays} days).</p>`;
            renderPickupInfo();
            return;
        }

        if (!endDate) {
            summary.innerHTML = `<div class="summary-dates">
                <div><span class="mono">Start</span> ${startDate}</div>
                <p class="summary-empty">Select an end date.</p>
            </div>`;
            renderPickupInfo();
            return;
        }

        let html = `<div class="summary-dates">
            <div><span class="mono">Start</span> ${startDate}</div>
            <div><span class="mono">End</span> ${endDate}</div>
            <div><span class="mono">Duration</span> ${days} day${days === 1 ? '' : 's'}</div>
            <div><span class="mono">Fulfillment</span> ${fulfillmentLabel()}</div>
        </div>`;

        if (days < minimumDays) {
            html += `<p class="summary-empty">Minimum rental is ${minimumDays} days.</p>`;
        } else if (partnerQuote) {
            html += `<div class="summary-quote summary-quote-custom">
                <div class="summary-quote-label">${partnerQuote.uses_own_unit ? 'Your unit' : 'Partner substitute'}</div>
                <div class="quote-lines">
                    <div><span>Unit</span><strong>${partnerQuote.assigned_equipment_nickname || 'Assigned'}</strong></div>
                    <div><span>Conflict days</span><strong class="mono">${partnerQuote.conflict_days || 0}</strong></div>
                    <div><span>Borrow fee</span><strong class="mono">${partnerQuote.borrow_fee_formatted || '$0.00'}</strong></div>
                </div>
            </div>`;
        } else if (longTerm) {
            html += `<div class="summary-quote summary-quote-custom">
                <div class="summary-quote-label">Custom commercial quote</div>
            </div>`;
        } else if (loading) {
            html += `<p class="summary-empty">Calculating price…</p>`;
        } else if (quoteError) {
            html += `<p class="summary-empty">${quoteError}</p>`;
        } else if (quote) {
            html += renderQuoteBlock(quote);
        }

        summary.innerHTML = html;
        renderPickupInfo();
    };

    const buildRangeCheckUrl = () => {
        const url = new URL(config.availabilityUrl, window.location.origin);
        url.searchParams.set('location_id', getApiLocationId());
        url.searchParams.set('fulfillment_type', resolveFulfillmentType());
        url.searchParams.set('start', startDate);
        url.searchParams.set('end', endDate);
        url.searchParams.set('start_date', startDate);
        url.searchParams.set('end_date', endDate);
        return url;
    };

    const isOwnerBlockMode = () => config.mode === 'owner_block';
    const isPartnerPersonalMode = () => config.mode === 'partner_personal';
    const isPersonalBookingMode = () => isOwnerBlockMode() || isPartnerPersonalMode();

    const buildCheckoutUrl = () => {
        const checkoutUrl = new URL(config.checkoutUrl, window.location.origin);
        checkoutUrl.searchParams.set('location_id', getCheckoutLocationId());
        checkoutUrl.searchParams.set('fulfillment_type', resolveFulfillmentType());
        checkoutUrl.searchParams.set('start_date', startDate);
        checkoutUrl.searchParams.set('end_date', endDate);
        return checkoutUrl;
    };

    const fetchPartnerQuote = async () => {
        const url = new URL(config.partnerQuoteUrl, window.location.origin);
        url.searchParams.set('location_id', getCheckoutLocationId());
        url.searchParams.set('fulfillment_type', resolveFulfillmentType());
        url.searchParams.set('start_date', startDate);
        url.searchParams.set('end_date', endDate);
        const response = await fetch(url);
        const data = await parseJsonResponse(response);
        if (data.error) {
            throw new Error(data.error);
        }
        return data;
    };

    const renderPartnerPersonalForm = () => buildReserveForm({
        submitLabel: 'Confirm personal trip',
        notesPlaceholder: 'Trip notes, pickup details, etc.',
    });

    const renderOwnerBlockForm = () => buildReserveForm({
        submitLabel: 'Confirm personal-use reservation',
        notesPlaceholder: 'Why you need the kit, pickup details, etc.',
    });

    const renderCheckoutAction = () => {
        if (isPartnerPersonalMode()) {
            return { html: '', form: renderPartnerPersonalForm() };
        }

        if (isOwnerBlockMode()) {
            return { html: '', form: renderOwnerBlockForm() };
        }

        const checkoutUrl = buildCheckoutUrl();
        const href = `${checkoutUrl.pathname}${checkoutUrl.search}`;

        if (config.isAuthenticated && !config.canCheckout) {
            const registerUrl = config.registerUrl || '/register';
            return {
                html: `<div class="alert alert-error" style="margin-top:0.75rem;">
                Customer checkout requires a customer account. Sign out and sign in with a customer account, or
                <a href="${registerUrl}">register</a> a new one.
            </div>`,
                form: null,
            };
        }

        return {
            html: `<a class="btn btn-primary" href="${href}" style="margin-top:0.75rem;display:inline-flex;">Continue to checkout</a>`,
            form: null,
        };
    };

    const validateRange = async () => {
        const days = dayCount();
        result.textContent = '';

        if (days < minimumDays) {
            renderSelectionSummary();
            result.innerHTML = `<div class="alert alert-error">Minimum rental is ${minimumDays} days.</div>`;
            return;
        }

        if (days > maxSelfServeDays && !(isPersonalBookingMode() && config.allowLongTerm)) {
            renderSelectionSummary({ longTerm: true });
            await renderLongTermAction();
            return;
        }

        if (isPartnerPersonalMode()) {
            renderSelectionSummary({ loading: true });
            try {
                const partnerQuote = await fetchPartnerQuote();
                renderSelectionSummary({ partnerQuote });
                const policy = partnerQuote.policy_message || config.borrowPolicyMessage || '';
                let html = `<div class="alert alert-info">${policy}</div>`;
                html += `<div class="alert alert-success">${partnerQuote.summary || 'Trip available.'}</div>`;
                if (partnerQuote.borrow_fee_cents > 0) {
                    html += `<p class="lead" style="font-size:0.875rem;margin:0.5rem 0 0;">Borrow fee due before pickup: <strong>${partnerQuote.borrow_fee_formatted || ''}</strong> (${partnerQuote.conflict_days} conflict day(s) at customer daily rate).</p>`;
                }
                const action = renderCheckoutAction();
                setResultContent(html, action.form);
            } catch (error) {
                renderSelectionSummary();
                result.innerHTML = `<div class="alert alert-error">${error.message}</div>`;
            }
            return;
        }

        renderSelectionSummary(isOwnerBlockMode() ? { longTerm: days > maxSelfServeDays } : { loading: true });

        let quote = null;
        if (!isOwnerBlockMode()) {
            try {
                quote = await fetchQuote();
            } catch (error) {
                renderSelectionSummary({ quoteError: error.message });
                result.innerHTML = `<div class="alert alert-error">${error.message}</div>`;
                return;
            }
        }

        let data;
        try {
            const response = await fetch(buildRangeCheckUrl());
            data = await parseJsonResponse(response);
        } catch (error) {
            renderSelectionSummary(isOwnerBlockMode() ? { longTerm: days > maxSelfServeDays } : { quote });
            result.innerHTML = `<div class="alert alert-error">${error.message}</div>`;
            return;
        }
        lastRangeCheck = data;

        renderSelectionSummary(isOwnerBlockMode() ? { longTerm: days > maxSelfServeDays } : { quote });

        const shippingWarning = mailShippingWarning();

        if (data.range_available) {
            if (isOwnerBlockMode()) {
                setResultContent(
                    `${shippingWarning}<div class="alert alert-success">Available for your dates. No payment — unit assigned before pickup.</div>`,
                    renderOwnerBlockForm(),
                );
            } else {
                const action = renderCheckoutAction();
                setResultContent(`${shippingWarning}${action.html}`, action.form);
            }
            return;
        }

        result.innerHTML = `${shippingWarning}<div class="alert alert-error">Selected range is not available for this fulfillment method.</div>`;
    };

    const renderLongTermAction = async () => {
        const loginUrl = new URL(config.loginUrl || '/login', window.location.origin);
        loginUrl.searchParams.set('next', window.location.pathname + window.location.search);

        let data;
        try {
            const response = await fetch(buildRangeCheckUrl());
            data = await parseJsonResponse(response);
        } catch (error) {
            result.innerHTML = `<div class="alert alert-error">${error.message}</div>`;
            return;
        }
        lastRangeCheck = data;
        const shippingWarning = mailShippingWarning();

        let html = shippingWarning;

        if (!config.isLoggedIn) {
            html += `<a class="btn btn-primary" href="${loginUrl.pathname}${loginUrl.search}" style="margin-top:0.75rem;display:inline-flex;">Sign in to submit quote</a>`;
            result.innerHTML = html;
            return;
        }

        setResultContent(html, buildLongTermForm());
    };

    document.getElementById('prev-month').addEventListener('click', () => {
        viewWeekStart = addDays(viewWeekStart, -visibleDays);
        fetchAvailability();
    });

    document.getElementById('next-month').addEventListener('click', () => {
        viewWeekStart = addDays(viewWeekStart, visibleDays);
        fetchAvailability();
    });

    locationSelect.addEventListener('change', async () => {
        lastRangeCheck = null;
        if (isMailShipping()) {
            renderSelectionSummary();
            return;
        }
        startDate = null;
        endDate = null;
        renderSelectionSummary();
        result.textContent = '';
        await fetchAvailability({ jumpToFirstAvailable: true, clearDays: true });
    });

    fulfillmentSelect.addEventListener('change', async () => {
        result.textContent = '';
        lastRangeCheck = null;
        if (startDate) {
            ensureDateVisible(startDate);
        }
        await fetchAvailability({ jumpToFirstAvailable: !startDate, clearDays: true });
        if (startDate && endDate) {
            await validateRange();
        } else {
            renderSelectionSummary();
        }
    });

    renderSelectionSummary();

    const prefill = config.prefill || {};
    if (prefill.location_id) {
        locationSelect.value = String(prefill.location_id);
    }
    if (prefill.fulfillment_type === 'mail_ship') {
        fulfillmentSelect.value = 'mail_ship';
    } else if (prefill.fulfillment_type === 'city_delivery') {
        fulfillmentSelect.value = 'city_delivery';
    } else if (prefill.fulfillment_type) {
        fulfillmentSelect.value = 'pickup';
    }

    const initializeCalendar = async () => {
        if (prefill.start_date && prefill.end_date) {
            startDate = prefill.start_date;
            endDate = prefill.end_date;
            viewWeekStart = startOfWeek(parseDate(prefill.start_date));
            await fetchAvailability({ jumpToFirstAvailable: true });
            if (prefill.long_term) {
                renderSelectionSummary({ longTerm: true });
                renderLongTermAction();
            } else {
                await validateRange();
            }
            return;
        }

        await fetchAvailability({ jumpToFirstAvailable: true });
    };

    initializeCalendar();
})();
