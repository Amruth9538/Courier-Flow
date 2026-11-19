(() => {
    'use strict';

    const form = document.querySelector('#parcel-form');
    if (!form) return;

    const rates = Array.isArray(window.domesticRateSlabs) ? window.domesticRateSlabs : [];
    const inputFilters = {
        letters: /[^A-Za-z .'-]/g,
        numbers: /[^0-9]/g,
        decimal: /[^0-9.]/g,
        alnum: /[^A-Za-z0-9 ]/g,
        address: /[^A-Za-z0-9 ,.\-/#()]/g,
        gstin: /[^A-Za-z0-9]/g
    };

    function filterValue(input) {
        const rule = input.dataset.rule;
        const filter = inputFilters[rule];
        if (filter) {
            const start = input.selectionStart;
            const before = input.value;
            input.value = before.replace(filter, '');

            if (rule === 'decimal') {
                const firstDot = input.value.indexOf('.');
                if (firstDot !== -1) {
                    input.value = input.value.slice(0, firstDot + 1)
                        + input.value.slice(firstDot + 1).replace(/\./g, '').slice(0, 2);
                }
            }

            if (rule === 'gstin') input.value = input.value.toUpperCase();
            if (start !== null && before !== input.value) {
                const removed = before.slice(0, start).length - before.slice(0, start)
                    .replace(filter, '').length;
                const position = Math.max(0, start - removed);
                input.setSelectionRange(position, position);
            }
        }
    }

    function fieldError(input, message) {
        const wrapper = input.closest('.shipment-field');
        if (!wrapper) return;
        wrapper.classList.toggle('invalid', Boolean(message));
        const error = wrapper.querySelector('.shipment-required');
        if (error && message) error.textContent = message;
        if (error && !message) error.removeAttribute('data-current-error');
        if (error && message) error.dataset.currentError = message;
        if (error) error.setAttribute('aria-live', 'polite');
        input.setAttribute('aria-invalid', message ? 'true' : 'false');
    }

    function validateField(input) {
        const value = input.value.trim();
        const name = input.name || '';
        let error = '';

        if (input.hasAttribute('required') && !value) {
            error = 'This field is required.';
        } else if (value) {
            const rule = input.dataset.rule;
            if (rule === 'letters' && !/^[A-Za-z][A-Za-z .'-]*$/.test(value)) {
                error = 'Use letters, spaces, dots, hyphens, or apostrophes only.';
            } else if (rule === 'numbers' && !/^[0-9]+$/.test(value)) {
                error = 'Use digits only.';
            } else if (rule === 'decimal' && !/^[0-9]+(?:\.[0-9]{1,2})?$/.test(value)) {
                error = 'Enter a number with up to 2 decimal places.';
            } else if (rule === 'alnum' && !/^[A-Za-z0-9 ]+$/.test(value)) {
                error = 'Use letters, numbers, and spaces only.';
            } else if (rule === 'address' && !/^[A-Za-z0-9 ,.\-/#()]+$/.test(value)) {
                error = 'Use letters, numbers, spaces, and common address punctuation.';
            } else if (rule === 'gstin'
                && !/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/.test(value)) {
                error = 'Enter a valid 15-character GSTIN.';
            } else if (input.dataset.indianMobile !== undefined
                && !/^[6-9][0-9]{9}$/.test(value)) {
                error = 'Enter 10 digits; the first digit must be 6, 7, 8, or 9.';
            } else if (input.dataset.pincodeLookup !== undefined
                && !/^[1-9][0-9]{5}$/.test(value)) {
                error = 'Enter a valid 6-digit Indian pincode.';
            } else if (input.type === 'email' && !input.validity.valid) {
                error = 'Enter a valid email address.';
            } else if (name === 'sender[name]' || name === 'receiver[name]') {
                if (value.length < 2 || value.length > 50) error = 'Enter 2–50 valid characters.';
            } else if (name === 'pieces' && (+value < 1 || +value > 99)) {
                error = 'Enter a number from 1 to 99.';
            } else if (['actual_wt', 'length', 'width', 'height', 'declared_value', 'cod_amount'].includes(name)) {
                if (!/^[0-9]+(?:\.[0-9]{1,2})?$/.test(value) || Number(value) <= 0) {
                    error = 'Enter a positive number with up to 2 decimal places.';
                }
            } else if (name === 'gstin' && value.length !== 15) {
                error = 'GSTIN must be exactly 15 characters.';
            } else if (name === 'delivery_instructions' && value.length > 200) {
                error = 'Maximum 200 characters.';
            } else if (name === 'remarks' && value.length > 250) {
                error = 'Maximum 250 characters.';
            }
        }

        fieldError(input, error);
        return !error;
    }

    function updateCodVisibility() {
        const mode = form.querySelector('[data-payment-mode]');
        const field = form.querySelector('[data-cod-field]');
        const amount = form.querySelector('[name="cod_amount"]');
        const required = mode && mode.value === 'COD';
        if (!field || !amount) return;
        field.hidden = !required;
        field.style.display = required ? '' : 'none';
        amount.required = Boolean(required);
        if (!required) fieldError(amount, '');
    }

    function numericValue(name) {
        const input = form.elements.namedItem(name);
        const value = input ? Number(input.value) : 0;
        return Number.isFinite(value) ? value : 0;
    }

    function updateWeightsAndRate() {
        const actual = numericValue('actual_wt');
        const length = numericValue('length');
        const width = numericValue('width');
        const height = numericValue('height');
        const volumetric = length > 0 && width > 0 && height > 0
            ? length * width * height / 5000
            : 0;
        const chargeable = Math.max(actual, volumetric);
        const volField = form.querySelector('[data-vol-weight]');
        const chargeField = form.querySelector('[data-charge-weight]');
        if (volField) volField.value = volumetric > 0 ? volumetric.toFixed(3) : '';
        if (chargeField) chargeField.value = chargeable > 0 ? chargeable.toFixed(3) : '';

        const service = form.querySelector('[data-rate-service]')?.value || '';
        const slab = rates.find((rate) => rate.service === service
            && chargeable > Number(rate.min_wt)
            && chargeable <= Number(rate.max_wt));
        const output = document.querySelector('#domestic-rate-estimate');
        if (!output) return;
        if (chargeable <= 0) {
            output.textContent = 'Enter parcel weight and dimensions.';
        } else if (!slab) {
            output.textContent = `No ${service} rate slab is configured for ${chargeable.toFixed(3)} kg.`;
        } else {
            output.textContent = `₹${Number(slab.rate).toFixed(2)} (${service}, ${Number(slab.min_wt).toFixed(3)}–${Number(slab.max_wt).toFixed(3)} kg slab)`;
        }
    }

    const lookupControllers = new WeakMap();
    async function lookupPincode(input) {
        const value = input.value.trim();
        if (!/^[1-9][0-9]{5}$/.test(value)) return;
        const group = input.name.split('[')[0];
        const state = form.querySelector(`[name="${group}[state]"]`);
        const city = form.querySelector(`[name="${group}[city]"]`);
        const status = input.closest('.shipment-field')?.querySelector('.shipment-required');
        const previous = lookupControllers.get(input);
        if (previous) previous.abort();
        const controller = new AbortController();
        lookupControllers.set(input, controller);

        try {
            const response = await fetch(`https://api.postalpincode.in/pincode/${encodeURIComponent(value)}`, {
                headers: { Accept: 'application/json' },
                signal: controller.signal
            });
            if (!response.ok) throw new Error(`Pincode service returned HTTP ${response.status}.`);
            const result = await response.json();
            const place = result?.[0]?.PostOffice?.[0];
            if (!place) {
                if (status) status.textContent = 'Pincode lookup found no matching location; enter state and city manually.';
                return;
            }
            if (state && place.State) state.value = place.State;
            if (city && (place.District || place.Block)) city.value = place.District || place.Block;
            if (status) status.textContent = 'State and city filled from pincode.';
            if (state) validateField(state);
            if (city) validateField(city);
        } catch (error) {
            if (error.name !== 'AbortError') {
                if (status) status.textContent = 'Pincode lookup is unavailable; enter state and city manually.';
                console.error('Domestic pincode lookup failed:', error);
            }
        }
    }

    form.addEventListener('input', (event) => {
        const input = event.target;
        if (!(input instanceof HTMLInputElement || input instanceof HTMLTextAreaElement)) return;
        filterValue(input);
        if (input.closest('.shipment-field')?.classList.contains('invalid')) validateField(input);
        if (['actual_wt', 'length', 'width', 'height'].includes(input.name)) updateWeightsAndRate();
        if (input.dataset.pincodeLookup !== undefined && input.value.length === 6) lookupPincode(input);
    });

    form.addEventListener('change', (event) => {
        const input = event.target;
        if (input.matches('[data-payment-mode]')) updateCodVisibility();
        if (input.matches('[data-rate-service]')) updateWeightsAndRate();
        if (input.matches('select')) validateField(input);
    });

    form.addEventListener('focusout', (event) => {
        const input = event.target;
        if (input instanceof HTMLInputElement || input instanceof HTMLTextAreaElement || input instanceof HTMLSelectElement) {
            validateField(input);
        }
    });

    form.addEventListener('submit', (event) => {
        let valid = true;
        form.querySelectorAll('input:not([type="hidden"]), select, textarea').forEach((input) => {
            if (input.disabled || input.readOnly || input.closest('[hidden]')) return;
            if (!validateField(input)) valid = false;
        });
        updateCodVisibility();
        if (!valid) {
            event.preventDefault();
            form.querySelector('.shipment-field.invalid input, .shipment-field.invalid select, .shipment-field.invalid textarea')?.focus();
        }
    });

    form.addEventListener('reset', () => {
        window.setTimeout(() => {
            form.querySelectorAll('.shipment-field.invalid').forEach((field) => field.classList.remove('invalid'));
            form.querySelectorAll('[aria-invalid]').forEach((input) => input.removeAttribute('aria-invalid'));
            updateCodVisibility();
            updateWeightsAndRate();
        }, 0);
    });

    updateCodVisibility();
    updateWeightsAndRate();
})();
