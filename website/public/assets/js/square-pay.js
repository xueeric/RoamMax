(() => {
    const config = window.STARLINK_SQUARE_PAY || {};
    const cardContainer = document.getElementById('square-card-container');
    const payButton = document.getElementById('square-pay-button');
    const errorBox = document.getElementById('square-pay-error');

    if (!cardContainer || !payButton || !config.applicationId || !config.locationId) {
        return;
    }

    let card;

    const showError = (message) => {
        if (!errorBox) {
            return;
        }
        errorBox.textContent = message;
        errorBox.hidden = false;
    };

    const clearError = () => {
        if (errorBox) {
            errorBox.hidden = true;
            errorBox.textContent = '';
        }
    };

    const init = async () => {
        if (!window.Square) {
            showError('Square payments failed to load. Refresh and try again.');
            return;
        }

        const payments = window.Square.payments(config.applicationId, config.locationId);

        if (typeof payments.setLocale === 'function' && config.locale) {
            await payments.setLocale(config.locale);
        }

        const cardOptions = {};
        const billingPostal = (config.billingPostalCode || '').replace(/\s/g, '').toUpperCase();
        // Sandbox test cards (4111…) validate as US ZIP; use 94103 instead of CA postal.
        if (config.sandboxDefaultPostal) {
            cardOptions.postalCode = config.sandboxDefaultPostal;
        } else if (billingPostal) {
            cardOptions.postalCode = billingPostal;
        }

        card = await payments.card(cardOptions);
        await card.attach('#square-card-container');
        payButton.disabled = false;

        if (config.buttonLabel) {
            payButton.textContent = config.buttonLabel;
        }
    };

    payButton.addEventListener('click', async () => {
        clearError();
        payButton.disabled = true;

        try {
            const tokenResult = await card.tokenize();
            if (tokenResult.status !== 'OK') {
                throw new Error(tokenResult.errors?.[0]?.message || 'Unable to tokenize card.');
            }

            const body = new URLSearchParams();
            body.set('booking_id', String(config.bookingId));
            body.set('source_id', tokenResult.token);
            if (config.csrfToken) {
                body.set('_csrf', config.csrfToken);
            }

            const response = await fetch(config.submitUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    Accept: 'application/json',
                },
                body: body.toString(),
            });

            const raw = await response.text();
            let payload;
            try {
                payload = JSON.parse(raw);
            } catch {
                throw new Error('Server returned an invalid response. Refresh and try again, or contact support.');
            }

            if (!response.ok || !payload.ok) {
                throw new Error(payload.message || 'Payment failed.');
            }

            window.location.href = payload.redirect;
        } catch (error) {
            showError(error.message || 'Payment failed.');
            payButton.disabled = false;
        }
    });

    init().catch((error) => {
        showError(error.message || 'Unable to initialize Square checkout.');
    });
})();
