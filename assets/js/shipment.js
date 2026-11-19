document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('shipment-form');
    if (!form) return;

    const csrf = form.querySelector('[name="csrf_token"]')?.value || '';
    const packageCount = document.getElementById('pieces');
    const packageRows = document.getElementById('package-rows');
    const commodityRows = document.getElementById('commodity-rows');
    const commoditySelect = document.getElementById('commodity-select');
    const addressDialog = document.getElementById('address-dialog');
    const addressPicker = document.getElementById('address-picker');
    const gstDialog = document.getElementById('gst-dialog');
    const toast = document.getElementById('shipment-toast');
    const stateCodes = {
        'andaman and nicobar islands': '35', 'andhra pradesh': '37', 'arunachal pradesh': '12',
        assam: '18', bihar: '10', chandigarh: '04', chhattisgarh: '22', 'dadra and nagar haveli and daman and diu': '26',
        delhi: '07', goa: '30', gujarat: '24', haryana: '06', 'himachal pradesh': '02',
        jharkhand: '20', 'jammu and kashmir': '01', karnataka: '29', kerala: '32',
        ladakh: '38', lakshadweep: '31', 'madhya pradesh': '23', maharashtra: '27',
        manipur: '14', meghalaya: '17', mizoram: '15', nagaland: '13', odisha: '21',
        puducherry: '34', punjab: '03', rajasthan: '08', sikkim: '11', 'tamil nadu': '33',
        telangana: '36', tripura: '16', 'uttar pradesh': '09', uttarakhand: '05', 'west bengal': '19',
        alabama: 'AL', alaska: 'AK', arizona: 'AZ', arkansas: 'AR', california: 'CA',
        colorado: 'CO', connecticut: 'CT', delaware: 'DE', florida: 'FL', georgia: 'GA',
        hawaii: 'HI', idaho: 'ID', illinois: 'IL', indiana: 'IN', iowa: 'IA',
        kansas: 'KS', kentucky: 'KY', louisiana: 'LA', maine: 'ME', maryland: 'MD',
        massachusetts: 'MA', michigan: 'MI', minnesota: 'MN', mississippi: 'MS',
        missouri: 'MO', montana: 'MT', nebraska: 'NE', nevada: 'NV', 'new hampshire': 'NH',
        'new jersey': 'NJ', 'new mexico': 'NM', 'new york': 'NY', 'north carolina': 'NC',
        'north dakota': 'ND', ohio: 'OH', oklahoma: 'OK', oregon: 'OR', pennsylvania: 'PA',
        'rhode island': 'RI', 'south carolina': 'SC', 'south dakota': 'SD', tennessee: 'TN',
        texas: 'TX', utah: 'UT', vermont: 'VT', virginia: 'VA', washington: 'WA',
        'west virginia': 'WV', wisconsin: 'WI', wyoming: 'WY'
    };

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[char]));

    function packageMarkup(index, previous = {}) {
        return `<div class="shipment-package-row" data-package-row>
            <label class="shipment-field">Piece ${index + 1} L
                <input type="number" min="0.01" step="0.01" name="packages[${index}][length]" value="${escapeHtml(previous.length || '')}" required></label>
            <label class="shipment-field">W
                <input type="number" min="0.01" step="0.01" name="packages[${index}][width]" value="${escapeHtml(previous.width || '')}" required></label>
            <label class="shipment-field">H
                <input type="number" min="0.01" step="0.01" name="packages[${index}][height]" value="${escapeHtml(previous.height || '')}" required></label>
            <label class="shipment-field">Unit
                <select name="packages[${index}][unit]"><option value="CM" ${previous.unit !== 'IN' ? 'selected' : ''}>CM</option><option value="IN" ${previous.unit === 'IN' ? 'selected' : ''}>IN</option></select></label>
            <label class="shipment-field">Actual wt (kg)
                <input type="number" min="0.001" step="0.001" name="packages[${index}][actual_wt]" value="${escapeHtml(previous.actual_wt || '')}" required></label>
            <div class="shipment-note" data-package-vol>Vol wt: 0.000 kg</div>
            <button type="button" class="shipment-btn danger" data-remove-package aria-label="Remove piece ${index + 1}">Remove</button>
        </div>`;
    }

    function refreshPackages() {
        const desired = Math.min(50, Math.max(1, Number(packageCount.value) || 1));
        packageCount.value = desired;
        const current = [...packageRows.querySelectorAll('[data-package-row]')].map((row) => ({
            length: row.querySelector('[name$="[length]"]').value,
            width: row.querySelector('[name$="[width]"]').value,
            height: row.querySelector('[name$="[height]"]').value,
            unit: row.querySelector('[name$="[unit]"]').value,
            actual_wt: row.querySelector('[name$="[actual_wt]"]').value
        }));
        packageRows.innerHTML = Array.from({ length: desired }, (_, index) => packageMarkup(index, current[index] || {})).join('');
        calculateWeights();
    }

    function calculateWeights() {
        let actual = 0;
        let volume = 0;
        packageRows.querySelectorAll('[data-package-row]').forEach((row) => {
            const length = Number(row.querySelector('[name$="[length]"]').value) || 0;
            const width = Number(row.querySelector('[name$="[width]"]').value) || 0;
            const height = Number(row.querySelector('[name$="[height]"]').value) || 0;
            const weight = Number(row.querySelector('[name$="[actual_wt]"]').value) || 0;
            const unit = row.querySelector('[name$="[unit]"]').value;
            const cm = unit === 'IN' ? 2.54 : 1;
            const vol = (length * cm * width * cm * height * cm) / 5000;
            actual += weight;
            volume += vol;
            row.querySelector('[data-package-vol]').textContent = `Vol wt: ${vol.toFixed(3)} kg`;
        });
        const chargeable = Math.max(actual, volume);
        document.getElementById('total-actual-wt').textContent = actual.toFixed(3);
        document.getElementById('total-vol-wt').textContent = volume.toFixed(3);
        document.getElementById('total-chg-wt').textContent = chargeable.toFixed(3);
        document.getElementById('actual-wt-hidden').value = actual.toFixed(3);
        document.getElementById('vol-wt-hidden').value = volume.toFixed(3);
        document.getElementById('chg-wt-hidden').value = chargeable.toFixed(3);
    }

    packageCount.addEventListener('change', refreshPackages);
    packageRows.addEventListener('input', calculateWeights);
    packageRows.addEventListener('change', calculateWeights);
    packageRows.addEventListener('click', (event) => {
        if (!event.target.matches('[data-remove-package]')) return;
        if (packageRows.querySelectorAll('[data-package-row]').length <= 1) return;
        packageCount.value = packageRows.querySelectorAll('[data-package-row]').length - 1;
        refreshPackages();
    });
    document.getElementById('copy-dimensions').addEventListener('click', () => {
        const rows = [...packageRows.querySelectorAll('[data-package-row]')];
        const source = rows[0];
        if (!source) return;
        const values = ['length', 'width', 'height', 'unit', 'actual_wt'].map((key) =>
            source.querySelector(`[name$="[${key}]"]`).value
        );
        rows.slice(1).forEach((row) => {
            ['length', 'width', 'height', 'unit', 'actual_wt'].forEach((key, index) => {
                row.querySelector(`[name$="[${key}]"]`).value = values[index];
            });
        });
        calculateWeights();
    });

    function addCommodity(description = '', hsCode = '', unitPrice = '0') {
        const index = commodityRows.querySelectorAll('[data-commodity-row]').length;
        const row = document.createElement('div');
        row.className = 'shipment-commodity-row';
        row.dataset.commodityRow = '';
        row.innerHTML = `
            <label class="shipment-field">Description<input name="commodities[${index}][description]" value="${escapeHtml(description)}" required maxlength="255"></label>
            <label class="shipment-field">HS code<input name="commodities[${index}][hs_code]" value="${escapeHtml(hsCode)}" maxlength="30"></label>
            <label class="shipment-field">Quantity<input type="number" min="0.01" step="0.01" name="commodities[${index}][qty]" value="1" required></label>
            <label class="shipment-field">Unit price<input type="number" min="0" step="0.01" name="commodities[${index}][unit_price]" value="${escapeHtml(unitPrice)}" required></label>
            <label class="shipment-field">Total<input type="number" name="commodities[${index}][total]" readonly value="0.00"></label>
            <button type="button" class="shipment-btn danger" data-remove-commodity aria-label="Remove commodity">Remove</button>`;
        commodityRows.appendChild(row);
        row.addEventListener('input', calculateCommodityTotal);
        calculateCommodityTotal();
    }

    function calculateCommodityTotal() {
        let sum = 0;
        commodityRows.querySelectorAll('[data-commodity-row]').forEach((row) => {
            const qty = Number(row.querySelector('[name$="[qty]"]').value) || 0;
            const price = Number(row.querySelector('[name$="[unit_price]"]').value) || 0;
            const total = qty * price;
            row.querySelector('[name$="[total]"]').value = total.toFixed(2);
            sum += total;
        });
        document.getElementById('commodity-total').textContent = sum.toFixed(2);
        document.getElementById('total-value-hidden').value = sum.toFixed(2);
    }

    document.getElementById('add-commodity').addEventListener('click', () => {
        const description = commoditySelect.value.trim();
        const option = [...document.querySelectorAll('#commodity-options option')]
            .find((item) => item.value.toLowerCase() === description.toLowerCase());
        addCommodity(description, option?.dataset.hs || '', option?.dataset.price || '0');
        commoditySelect.value = '';
    });
    document.getElementById('refresh-commodities').addEventListener('click', () => {
        commoditySelect.value = '';
    });
    commodityRows.addEventListener('click', (event) => {
        if (!event.target.matches('[data-remove-commodity]')) return;
        event.target.closest('[data-commodity-row]').remove();
        [...commodityRows.querySelectorAll('[data-commodity-row]')].forEach((row, index) => {
            row.querySelectorAll('[name]').forEach((input) => {
                input.name = input.name.replace(/commodities\[\d+\]/, `commodities[${index}]`);
            });
        });
        calculateCommodityTotal();
    });

    async function addressRequest(data) {
        const response = await fetch('address-book.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ ...data, csrf_token: csrf })
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Address book request failed.');
        return result;
    }

    document.querySelectorAll('[data-save-address]').forEach((button) => {
        button.addEventListener('click', async () => {
            const type = button.dataset.saveAddress;
            const prefix = type === 'shipper' ? 'shipper' : 'receiver';
            const address = {};
            form.querySelectorAll(`[name^="${prefix}["]`).forEach((input) => {
                const match = input.name.match(/^\w+\[([^\]]+)\]$/);
                if (match) address[match[1]] = input.value;
            });
            address.type = type;
            address.is_default = form.querySelector(`[name="${prefix}[is_default]"]`).checked ? '1' : '0';
            try {
                await addressRequest({ action: 'save', address: JSON.stringify(address) });
                toast.textContent = `${type === 'shipper' ? 'Shipper' : 'Receiver'} address saved.`;
            } catch (error) {
                toast.textContent = error.message;
            }
        });
    });

    document.querySelectorAll('[data-clear-address]').forEach((button) => {
        button.addEventListener('click', () => {
            const prefix = button.dataset.clearAddress;
            form.querySelectorAll(`[name^="${prefix}["]`).forEach((input) => {
                if (input.type === 'checkbox') input.checked = false;
                else if (['country', 'country_code', 'phone_type', 'identification_type'].includes(input.name.match(/^\w+\[([^\]]+)\]$/)?.[1])) return;
                else input.value = '';
            });
        });
    });

    let activeAddressType = 'shipper';
    document.querySelectorAll('[data-open-address-book]').forEach((button) => {
        button.addEventListener('click', async () => {
            activeAddressType = button.dataset.openAddressBook;
            try {
                const result = await addressRequest({ action: 'list', type: activeAddressType });
                addressPicker.innerHTML = '<option value="">Select a saved address</option>' +
                    result.addresses.map((address) => `<option value="${Number(address.id)}">${escapeHtml(address.name)} — ${escapeHtml(address.address_line1)}${Number(address.is_default) ? ' (Default)' : ''}</option>`).join('');
                addressDialog.showModal();
            } catch (error) {
                toast.textContent = error.message;
            }
        });
    });

    document.getElementById('use-saved-address').addEventListener('click', async () => {
        if (!addressPicker.value) return;
        try {
            const result = await addressRequest({ action: 'get', id: addressPicker.value });
            Object.entries(result.address).forEach(([key, value]) => {
                const input = form.querySelector(`[name="${activeAddressType}[${key}]"]`);
                if (input && input.type !== 'checkbox') input.value = value ?? '';
                if (input && input.type === 'checkbox') input.checked = Number(value) === 1;
            });
            addressDialog.close();
        } catch (error) {
            toast.textContent = error.message;
        }
    });

    document.getElementById('delete-saved-address').addEventListener('click', async () => {
        if (!addressPicker.value || !window.confirm('Delete this saved address?')) return;
        try {
            await addressRequest({ action: 'delete', id: addressPicker.value });
            addressPicker.selectedIndex = 0;
            addressPicker.querySelector('option[value=""]')?.remove();
            toast.textContent = 'Saved address deleted.';
            addressDialog.close();
        } catch (error) {
            toast.textContent = error.message;
        }
    });

    document.querySelectorAll('[data-auto-save]').forEach((toggle) => {
        toggle.addEventListener('change', () => {
            const hidden = form.querySelector(`[name="${toggle.dataset.autoSave}[auto_save]"]`);
            hidden.value = toggle.checked ? '1' : '0';
        });
    });

    function updateStateCode(prefix) {
        const state = form.querySelector(`[name="${prefix}[state]"]`);
        const stateCode = form.querySelector(`[name="${prefix}[state_code]"]`);
        const country = form.querySelector(`[name="${prefix}[country]"]`).value;
        const stateName = state.value.trim().toLowerCase();
        const initials = stateName.split(/\s+/).map((part) => part[0] || '').join('').slice(0, 3).toUpperCase();
        stateCode.value = country === 'INDIA' ? (stateCodes[stateName] || '') :
            (stateCodes[stateName] || initials);
    }

    document.querySelectorAll('[data-country-select]').forEach((select) => {
        select.addEventListener('change', function () {
            const prefix = this.name.split('[')[0];
            const postal = form.querySelector(`[name="${prefix}[postal_code]"]`);
            postal.dataset.country = this.value;
            const code = this.selectedOptions[0].dataset.code;
            if (code) form.querySelector(`[name="${prefix}[country_code]"]`).value = code;
            updateStateCode(prefix);
        });
    });
    form.querySelectorAll('[name$="[state]"]').forEach((input) => {
        input.addEventListener('input', () => updateStateCode(input.name.split('[')[0]));
    });

    document.querySelectorAll('[data-postal-lookup]').forEach((input) => {
        input.addEventListener('change', async () => {
            if (input.dataset.country !== 'INDIA' || !/^\d{6}$/.test(input.value)) return;
            const prefix = input.name.split('[')[0];
            const hint = document.getElementById(`${prefix}-postal-hint`);
            try {
                const response = await fetch(`https://api.postalpincode.in/pincode/${encodeURIComponent(input.value)}`);
                if (!response.ok) throw new Error();
                const result = await response.json();
                const place = result?.[0]?.PostOffice?.[0];
                if (!place) throw new Error();
                form.querySelector(`[name="${prefix}[state]"]`).value = place.State;
                form.querySelector(`[name="${prefix}[city]"]`).value = place.District;
                updateStateCode(prefix);
                hint.textContent = 'City, state, and state code filled from postal code.';
            } catch (error) {
                hint.textContent = 'Postal lookup unavailable; enter city and state manually.';
            }
        });
    });

    document.getElementById('manifest-gst').addEventListener('click', () => gstDialog.showModal());

    document.querySelectorAll('details.shipment-collapsible').forEach((section) => {
        section.addEventListener('toggle', () => {
            const heading = section.querySelector('summary');
            heading.setAttribute('aria-expanded', section.open ? 'true' : 'false');
        });
    });

    document.querySelectorAll('input[name="ship_method"]').forEach((radio) => {
        radio.addEventListener('change', () => {
            const showPickupFields = radio.value === 'Schedule a Pick Up' && radio.checked;
            const pickupFields = document.getElementById('pickup-fields');
            pickupFields.hidden = !showPickupFields;
            pickupFields.querySelectorAll('input').forEach((input) => {
                input.required = showPickupFields;
            });
        });
    });

    form.addEventListener('reset', () => setTimeout(() => {
        packageCount.value = '1';
        refreshPackages();
        commodityRows.innerHTML = '';
        calculateCommodityTotal();
        document.querySelectorAll('[data-auto-save]').forEach((toggle) => {
            toggle.checked = toggle.dataset.autoSaveDefault === 'true';
            toggle.dispatchEvent(new Event('change'));
        });
    }, 0));

    form.addEventListener('input', (event) => {
        const field = event.target.closest('.shipment-field');
        if (field && event.target.checkValidity()) field.classList.remove('invalid');
    });

    form.addEventListener('submit', (event) => {
        calculateWeights();
        const errors = [];
        form.querySelectorAll('[required]').forEach((input) => {
            if (!input.checkValidity()) {
                errors.push(input);
                const field = input.closest('.shipment-field');
                if (field) {
                    field.classList.add('invalid');
                    if (!field.querySelector('.shipment-required')) {
                        const message = document.createElement('small');
                        message.className = 'shipment-required';
                        message.textContent = input.validity.valueMissing ? 'Required' : 'Invalid value';
                        field.appendChild(message);
                    }
                }
            } else {
                input.closest('.shipment-field')?.classList.remove('invalid');
            }
        });
        const postalInputs = [...form.querySelectorAll('[data-postal-lookup]')];
        postalInputs.forEach((input) => {
            if (input.dataset.country === 'INDIA' && !/^\d{6}$/.test(input.value)) {
                input.closest('.shipment-field')?.classList.add('invalid');
                errors.push(input);
            }
        });
        if (!commodityRows.querySelector('[data-commodity-row]')) {
            document.getElementById('commodity-required').hidden = false;
            errors.push(commoditySelect);
        } else {
            document.getElementById('commodity-required').hidden = true;
        }
        const invoice = form.querySelector('[name="own_invoice"]');
        if (invoice.files[0] && (invoice.files[0].type !== 'application/pdf' || invoice.files[0].size > 2 * 1024 * 1024)) {
            invoice.setCustomValidity('Choose a PDF file up to 2 MB.');
            errors.push(invoice);
            invoice.reportValidity();
        } else {
            invoice.setCustomValidity('');
        }
        if (errors.length) {
            event.preventDefault();
            errors[0].focus();
            toast.textContent = 'Please correct the required or invalid fields before submitting.';
        }
    });

    refreshPackages();
    calculateCommodityTotal();
});
