(() => {
    const config = window.STARLINK_CHECKOUT || {};
    const form = document.getElementById('checkout-form');
    if (!form || !config.quoteUrl) {
        return;
    }

    const panel = document.getElementById('checkout-quote-panel');
    let refreshTimer = null;

    const getTaxProvince = () => {
        if (!config.needsShipping) {
            return '';
        }

        return form.querySelector('[name="shipping_province"]')?.value?.trim() || '';
    };

    const getPaymentMethod = () => {
        const selected = form.querySelector('input[name="payment_method"]:checked');
        return selected?.value || '';
    };

    const collectAddOns = () => {
        const addons = {};
        form.querySelectorAll('[data-addon-input]').forEach((input) => {
            const match = input.name.match(/addons\[(\d+)\]/);
            if (match) {
                addons[match[1]] = input.value;
            }
        });
        return addons;
    };

    const refreshQuote = async () => {
        const url = new URL(config.quoteUrl, window.location.origin);
        url.searchParams.set('location_id', String(config.locationId));
        url.searchParams.set('fulfillment_type', config.fulfillmentType);
        url.searchParams.set('start_date', config.startDate);
        url.searchParams.set('end_date', config.endDate);

        const taxProvince = getTaxProvince();
        if (taxProvince) {
            url.searchParams.set('tax_province', taxProvince);
        }

        const paymentMethod = getPaymentMethod();
        if (paymentMethod) {
            url.searchParams.set('payment_method', paymentMethod);
        }

        Object.entries(collectAddOns()).forEach(([id, qty]) => {
            url.searchParams.set(`addons[${id}]`, qty);
        });

        const response = await fetch(url);
        let quote;
        try {
            quote = await response.json();
        } catch (error) {
            return;
        }

        if (!response.ok) {
            if (panel) {
                const errorNode = panel.querySelector('[data-quote="error"]');
                if (errorNode) {
                    errorNode.textContent = quote?.error || 'Unable to refresh price.';
                }
            }
            return;
        }

        updatePanel(quote);
    };

    const updatePanel = (quote) => {
        if (!panel) {
            return;
        }

        const formatted = quote.formatted || {};
        const set = (key, value) => {
            const node = panel.querySelector(`[data-quote="${key}"]`);
            if (node) {
                node.textContent = value;
            }
        };

        set('rental', formatted.rental_total || '');
        set('shipping', formatted.shipping_fee || '');
        set('addons', formatted.add_ons_total || '');
        set('deposit', formatted.deposit || '');
        set('total', formatted.total_due || '');

        const depositNote = panel.querySelector('[data-quote="deposit-note"]');
        if (depositNote) {
            depositNote.style.display = quote.deposit_charged_at_checkout === false ? 'block' : 'none';
        }

        const taxLabel = panel.querySelector('[data-quote="tax-label"]');
        const taxNote = panel.querySelector('.quote-tax-note');

        if (quote.tax_determined) {
            const rate = quote.tax_rate_percent != null
                ? ` (${String(quote.tax_rate_percent).replace(/\.?0+$/, '')}%)`
                : '';
            if (taxLabel) {
                taxLabel.textContent = `${quote.tax_label}${rate}`;
            }
            set('tax', formatted.tax || '');
            if (taxNote) {
                taxNote.remove();
            }
        } else {
            if (taxLabel) {
                taxLabel.textContent = 'Tax';
            }
            set('tax', 'To be determined');
            if (!taxNote) {
                const note = document.createElement('p');
                note.className = 'lead quote-tax-note';
                note.style.cssText = 'font-size:0.75rem;margin-top:0.75rem;margin-bottom:0;';
                note.textContent = 'Enter your shipping province to calculate tax.';
                panel.appendChild(note);
            }
        }
    };

    const scheduleRefresh = () => {
        clearTimeout(refreshTimer);
        refreshTimer = setTimeout(refreshQuote, 250);
    };

    const copyAddress = (sourcePrefix, targetPrefix) => {
        ['name', 'line1', 'line2', 'city', 'province', 'postal_code'].forEach((field) => {
            const source = form.querySelector(`[name="${sourcePrefix}${field}"]`);
            const target = form.querySelector(`[name="${targetPrefix}${field}"]`);
            if (source && target) {
                target.value = source.value;
                target.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    };

    const setAddressFieldsetVisible = (fieldset, visible) => {
        if (!fieldset) {
            return;
        }

        fieldset.hidden = !visible;
        fieldset.querySelectorAll('input:not([type="hidden"]), select, textarea').forEach((input) => {
            if (visible) {
                if (input.dataset.wasRequired === '1') {
                    input.setAttribute('required', 'required');
                }
            } else if (input.hasAttribute('required')) {
                input.dataset.wasRequired = '1';
                input.removeAttribute('required');
            }
        });
    };

    const bindSameAsToggle = (checkbox) => {
        const sourcePrefix = checkbox.dataset.copySource;
        const targetPrefix = checkbox.dataset.copyTarget;
        const fieldset = checkbox.dataset.toggleTarget
            ? document.getElementById(checkbox.dataset.toggleTarget)
            : null;

        const sync = () => {
            if (!checkbox.checked) {
                setAddressFieldsetVisible(fieldset, true);
                return;
            }

            copyAddress(sourcePrefix, targetPrefix);
            setAddressFieldsetVisible(fieldset, false);
            scheduleRefresh();
        };

        checkbox.addEventListener('change', sync);

        ['name', 'line1', 'line2', 'city', 'province', 'postal_code'].forEach((field) => {
            const source = form.querySelector(`[name="${sourcePrefix}${field}"]`);
            if (!source) {
                return;
            }

            const recopy = () => {
                if (checkbox.checked) {
                    copyAddress(sourcePrefix, targetPrefix);
                    scheduleRefresh();
                }
            };

            source.addEventListener('change', recopy);
            source.addEventListener('input', recopy);
        });

        sync();
    };

    form.querySelectorAll('[data-copy-source]').forEach((checkbox) => {
        if (checkbox.dataset.toggleTarget) {
            bindSameAsToggle(checkbox);
            return;
        }

        checkbox.addEventListener('change', () => {
            if (!checkbox.checked) {
                return;
            }
            copyAddress(checkbox.dataset.copySource, checkbox.dataset.copyTarget);
            scheduleRefresh();
        });
    });

    form.querySelectorAll('[data-tax-province], [data-addon-input]').forEach((input) => {
        input.addEventListener('change', scheduleRefresh);
        input.addEventListener('input', scheduleRefresh);
    });

    form.querySelectorAll('input[name="payment_method"]').forEach((input) => {
        input.addEventListener('change', scheduleRefresh);
    });

    form.querySelectorAll('[data-address-field="province"]').forEach((select) => {
        select.addEventListener('change', () => {
            const sameAs = form.querySelector(`[data-copy-target="${select.dataset.addressPrefix}"]`);
            if (sameAs?.checked) {
                return;
            }
            scheduleRefresh();
        });
    });

    const agreementModal = document.getElementById('checkout-agreement-modal');
    if (agreementModal) {
        const openAgreementModal = () => {
            agreementModal.hidden = false;
            document.body.style.overflow = 'hidden';
            agreementModal.querySelector('.agreement-modal-close')?.focus();
        };

        const closeAgreementModal = () => {
            agreementModal.hidden = true;
            document.body.style.overflow = '';
        };

        form.querySelectorAll('[data-agreement-open]').forEach((link) => {
            link.addEventListener('click', (event) => {
                if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) {
                    return;
                }

                event.preventDefault();
                openAgreementModal();
            });
        });

        agreementModal.querySelectorAll('[data-agreement-close]').forEach((el) => {
            el.addEventListener('click', closeAgreementModal);
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !agreementModal.hidden) {
                closeAgreementModal();
            }
        });
    }
})();
