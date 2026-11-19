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
    const invoiceInput = document.getElementById('own-invoice');
    const invoiceHint = document.getElementById('invoice-error');
    let toastTimer;
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
    const oldInput = window.shipmentOldInput || {};
    const oldPackages = Array.isArray(window.shipmentOldPackages) ? window.shipmentOldPackages : [];
    const oldCommodities = Array.isArray(window.shipmentOldCommodities) ? window.shipmentOldCommodities : [];
    const locations = window.shipmentLocations || { states: {}, cities: {} };

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[char]));
    function showToast(message, type = 'error') {
        window.clearTimeout(toastTimer);
        toast.textContent = message;
        toast.className = `shipment-toast${message ? ` shipment-toast--${type}` : ''}`;
        if (message) {
            toastTimer = window.setTimeout(() => {
                toast.textContent = '';
                toast.className = 'shipment-toast';
            }, 6000);
        }
    }

    invoiceInput.addEventListener('change', () => {
        const file = invoiceInput.files[0];
        invoiceHint.textContent = '';
        invoiceHint.style.display = 'none';
        if (!file) return;
        if (!/\.pdf$/i.test(file.name) || file.type !== 'application/pdf') {
            invoiceHint.textContent = 'Only PDF files are allowed.';
        } else if (file.size > 2 * 1024 * 1024) {
            invoiceHint.textContent = 'Invoice file must be no larger than 2 MB.';
        }
        if (invoiceHint.textContent) invoiceHint.style.display = 'block';
    });
    const inputRulePatterns = {
        letters: /^[A-Za-z][A-Za-z .'-]*$/,
        numbers: /^[0-9]+$/,
        decimal: /^[0-9]+(?:\.[0-9]{1,2})?$/,
        alnum: /^[A-Za-z0-9 ]+$/,
        address: /^[A-Za-z0-9 ,.\-\/#()]+$/,
        email: /^[A-Za-z0-9.!#$%&'*+/=?^_`{|}~@-]+$/,
        gstin: /^[0-9A-Z]+$/,
        pan: /^[A-Z0-9]+$/,
        commodity: /^[A-Za-z0-9 ,.\-]+$/,
        'invoice-ref': /^[A-Za-z0-9 /-]+$/
    };

    function sanitizeByRule(input) {
        const rule = input.dataset.rule;
        if (input.matches('[data-postal-lookup]')) {
            const country = input.dataset.country || '';
            const before = input.value;
            if (country === 'UNITED KINGDOM' || country === 'CANADA') {
                input.value = before.replace(/[^A-Za-z0-9 ]/g, '').toUpperCase().slice(0, 10);
            } else {
                input.value = before.replace(/[^0-9]/g, '').slice(0, country === 'UNITED STATES' ? 5 : 10);
            }
            return;
        }
        const filters = {
            letters: /[^A-Za-z .'-]/g,
            numbers: /[^0-9]/g,
            alnum: /[^A-Za-z0-9 ]/g,
            address: /[^A-Za-z0-9 ,.\-\/#()]/g,
            email: /[^A-Za-z0-9.!#$%&'*+/=?^_`{|}~@-]/g,
            gstin: /[^A-Za-z0-9]/g,
            pan: /[^A-Za-z0-9]/g,
            commodity: /[^A-Za-z0-9 ,.\-]/g,
            'invoice-ref': /[^A-Za-z0-9 /-]/g
        };
        const before = input.value;
        if (rule === 'decimal') {
            let value = before.replace(/[^0-9.\-]/g, '');
            const allowNegative = Number(input.dataset.min) < 0;
            value = value.replace(/-/g, '');
            if (allowNegative && before.trimStart().startsWith('-')) value = `-${value}`;
            const negative = value.startsWith('-');
            const unsigned = value.replace(/-/g, '');
            const dot = unsigned.indexOf('.');
            const integer = (dot < 0 ? unsigned : unsigned.slice(0, dot)).slice(0, 6);
            const fraction = dot < 0 ? '' : `.${unsigned.slice(dot + 1).replace(/\./g, '').slice(0, 2)}`;
            input.value = `${negative ? '-' : ''}${integer}${fraction}`;
        } else if (rule === 'id-number') {
            const type = form.querySelector(`[name="${input.name.replace('[identification_number]', '[identification_type]')}"]`)?.value || '';
            input.value = type === 'Aadhaar Number'
                ? before.replace(/[^0-9]/g, '').slice(0, 12)
                : before.replace(/[^A-Za-z0-9]/g, '').toUpperCase().slice(0, 16);
        } else if (filters[rule]) {
            input.value = before.replace(filters[rule], '');
            if (rule === 'gstin' || rule === 'pan') input.value = input.value.toUpperCase();
        }
        if (input.value.length > Number(input.maxLength) && input.maxLength > 0) {
            input.value = input.value.slice(0, input.maxLength);
        }
    }

    function ruleError(input) {
        const value = input.value.trim();
        const rule = input.dataset.rule;
        if (input.required && input.type === 'checkbox' && !input.checked) return 'Required.';
        if (input.required && !value) return 'Required.';
        if (!value) return '';
        if (rule === 'letters' && !inputRulePatterns.letters.test(value)) return 'Use letters, spaces, dots, hyphens, or apostrophes; start with a letter.';
        if (rule === 'numbers' && !inputRulePatterns.numbers.test(value)) return 'Use digits only.';
        if (rule === 'decimal') {
            const coordinate = input.hasAttribute('data-min');
            const regex = coordinate ? /^-?[0-9]+(?:\.[0-9]{1,2})?$/ : inputRulePatterns.decimal;
            const number = Number(value);
            if (!regex.test(value) || number < Number(input.dataset.min ?? 0) || number > Number(input.dataset.max ?? 9999999.99)) return 'Enter a valid decimal within the allowed range.';
        }
        if (rule === 'alnum' && !inputRulePatterns.alnum.test(value)) return 'Use letters, numbers, and spaces only.';
        if (rule === 'address' && !inputRulePatterns.address.test(value)) return 'Use letters, numbers, spaces, and , . - / # ( ) only.';
        if (rule === 'commodity' && !inputRulePatterns.commodity.test(value)) return 'Use letters, numbers, spaces, commas, dots, or hyphens only.';
        if (rule === 'invoice-ref' && !inputRulePatterns['invoice-ref'].test(value)) return 'Use letters, numbers, spaces, hyphens, or slashes only.';
        if (rule === 'email' && (value.length > 150 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value))) return 'Enter a valid email address.';
        if (rule === 'gstin' && !/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/.test(value)) return 'Enter a valid 15-character GSTIN.';
        if (rule === 'pan' && !/^[A-Z]{5}[0-9]{4}[A-Z]$/.test(value)) return 'Enter a PAN in AAAAA9999A format.';
        if (rule === 'id-number') {
            const type = form.querySelector(`[name="${input.name.replace('[identification_number]', '[identification_type]')}"]`)?.value || '';
            if (type === 'Aadhaar Number' && !/^[0-9]{12}$/.test(value)) return 'Aadhaar must contain exactly 12 digits.';
            if (type === 'PAN' && !/^[A-Z]{5}[0-9]{4}[A-Z]$/.test(value)) return 'Enter a PAN in AAAAA9999A format.';
            if (type === 'Passport' && !/^[A-Z][A-Z0-9]{7}$/.test(value)) return 'Passport must be 8 characters and start with a letter.';
            if (type === 'Driving License' && !/^[A-Z0-9]{8,16}$/.test(value)) return 'Driving License must be 8–16 letters or digits.';
            if (type === 'Voter ID' && !/^[A-Z0-9]{10}$/.test(value)) return 'Voter ID must contain exactly 10 letters or digits.';
        }
        if (input.matches('[data-postal-lookup]')) {
            const postalPatterns = {
                INDIA: /^[1-9][0-9]{5}$/,
                'UNITED STATES': /^[0-9]{5}$/,
                'UNITED KINGDOM': /^[A-Z]{1,2}[0-9][A-Z0-9]? ?[0-9][A-Z]{2}$/i,
                AUSTRALIA: /^[0-9]{4}$/,
                CANADA: /^[A-Z][0-9][A-Z] ?[0-9][A-Z][0-9]$/i,
                'UNITED ARAB EMIRATES': /^[0-9]{5}$/
            };
            if (!postalPatterns[input.dataset.country]?.test(value)) {
                return `Enter a valid postal code for ${input.dataset.country}.`;
            }
        }
        if (input.name.endsWith('[mobile]')) {
            const country = form.querySelector(`[name="${input.name.replace('[mobile]', '[country]')}"]`)?.value;
            if (!/^[0-9]{10}$/.test(value) || (country === 'INDIA' && !/^[6-9][0-9]{9}$/.test(value))) return 'Enter a valid 10-digit mobile number.';
        }
        if (input.name === 'pieces' && (!/^[0-9]+$/.test(value) || Number(value) < 1 || Number(value) > 99)) return 'Enter a number from 1 to 99.';
        if (input.name === 'pickup_contact_phone' && !/^[0-9]{10}$/.test(value)) return 'Enter exactly 10 digits.';
        if (input.name.startsWith('packages[')) {
            if (input.name.endsWith('[length]') || input.name.endsWith('[width]') || input.name.endsWith('[height]')) {
                if (!/^[0-9]+(?:\.[0-9]{1,2})?$/.test(value) || Number(value) <= 0 || Number(value) > 999) return 'Enter a dimension greater than 0 and no more than 999.';
            }
            if (input.name.endsWith('[actual_wt]') && (!/^[0-9]+(?:\.[0-9]{1,2})?$/.test(value) || Number(value) <= 0 || Number(value) > 999.99)) return 'Enter a weight greater than 0 and no more than 999.99 kg.';
        }
        if (input.name.endsWith('[hs_code]') && value && !/^[0-9]{6,10}$/.test(value)) return 'HS Code must contain 6–10 digits.';
        if (input.name.endsWith('[qty]') && (!/^[0-9]+$/.test(value) || Number(value) < 1 || Number(value) > 99999)) return 'Quantity must be 1–99999.';
        if (input.name.endsWith('[unit_price]') && (!/^[0-9]+(?:\.[0-9]{1,2})?$/.test(value) || Number(value) <= 0 || Number(value) > 9999999.99)) return 'Unit price must be greater than 0 and no more than 9999999.99.';
        if (input.name.endsWith('[description]') && value.length > 100) return 'Maximum 100 characters.';
        if (input.name === 'contents' && value.length > 100) return 'Maximum 100 characters.';
        if (input.name === 'remarks' && value.length > 250) return 'Maximum 250 characters.';
        if (input.name === 'customs_status' && value.length > 100) return 'Maximum 100 characters.';
        if (input.name === 'pickup_instructions' && value.length > 200) return 'Maximum 200 characters.';
        if (input.name === 'gst_legal_name' && value && (value.length > 80 || !inputRulePatterns.alnum.test(value))) return 'Use letters, numbers, and spaces only (max 80).';
        if (input.name === 'gst_amount' || input.name === 'gst_igst') {
            if (value && (!/^[0-9]+(?:\.[0-9]{1,2})?$/.test(value) || Number(value) < 0 || Number(value) > 100)) return 'Enter a value from 0 to 100.';
        }
        if (input.name === 'pickup_date') {
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            const selected = new Date(`${value}T00:00:00`);
            const max = new Date(today);
            max.setDate(max.getDate() + 30);
            if (!value || selected < today || selected > max) return 'Choose today or a date within the next 30 days.';
        }
        return '';
    }

    function setFieldError(input, message) {
        const field = input.closest('.shipment-field');
        if (!field) return;
        field.classList.toggle('invalid', Boolean(message));
        const hint = field.querySelector('.shipment-required');
        if (hint) {
            hint.textContent = message;
            hint.style.display = message ? 'block' : '';
        } else if (message) {
            const error = document.createElement('small');
            error.className = 'shipment-required';
            error.textContent = message;
            field.appendChild(error);
        }
    }

    function validateField(input) {
        const message = ruleError(input);
        setFieldError(input, message);
        return !message;
    }

    function restoreOldValues() {
        fieldsForValidation().forEach((input) => {
            if (!input.name || input.type === 'hidden') return;
            const match = input.name.match(/^([^\[]+)(?:\[([^\]]+)\])?$/);
            if (!match) return;
            const parent = oldInput[match[1]];
            const value = match[2] && parent && typeof parent === 'object'
                ? parent[match[2]]
                : parent;
            if (value === undefined || value === null || typeof value === 'object') return;
            if (input.type === 'checkbox' || input.type === 'radio') {
                input.checked = Array.isArray(value)
                    ? value.includes(input.value)
                    : String(value) === input.value || (input.type === 'checkbox' && ['1', 'on', 'yes'].includes(String(value)));
            } else if (input.type !== 'file' && !input.readOnly) {
                input.value = String(value);
            }
        });
    }

    function packageMarkup(index, previous = {}) {
        return `<div class="shipment-package-row" data-package-row>
            <label class="shipment-field">Piece ${index + 1} L
                <input type="text" inputmode="decimal" data-rule="decimal" data-min="0.01" data-max="999" maxlength="6" name="packages[${index}][length]" value="${escapeHtml(previous.length || '')}" required><small class="shipment-required">Enter a dimension from 0.01 to 999.</small></label>
            <label class="shipment-field">W
                <input type="text" inputmode="decimal" data-rule="decimal" data-min="0.01" data-max="999" maxlength="6" name="packages[${index}][width]" value="${escapeHtml(previous.width || '')}" required><small class="shipment-required">Enter a dimension from 0.01 to 999.</small></label>
            <label class="shipment-field">H
                <input type="text" inputmode="decimal" data-rule="decimal" data-min="0.01" data-max="999" maxlength="6" name="packages[${index}][height]" value="${escapeHtml(previous.height || '')}" required><small class="shipment-required">Enter a dimension from 0.01 to 999.</small></label>
            <label class="shipment-field">Unit
                <select name="packages[${index}][unit]"><option value="CM" ${previous.unit !== 'IN' ? 'selected' : ''}>CM</option><option value="IN" ${previous.unit === 'IN' ? 'selected' : ''}>IN</option></select></label>
            <label class="shipment-field">Actual wt (kg)
                <input type="text" inputmode="decimal" data-rule="decimal" data-min="0.01" data-max="999.99" maxlength="6" name="packages[${index}][actual_wt]" value="${escapeHtml(previous.actual_wt || '')}" required><small class="shipment-required">Enter a weight from 0.01 to 999.99 kg.</small></label>
            <label class="shipment-field">Vol Wt<input type="text" value="0.000" readonly aria-label="Volumetric weight"></label>
            <div class="shipment-note" data-package-vol>Vol wt: 0.000 kg</div>
            <button type="button" class="shipment-btn danger" data-remove-package aria-label="Remove piece ${index + 1}">Remove</button>
        </div>`;
    }

    function refreshPackages() {
        const desired = Math.min(99, Math.max(1, Number(packageCount.value) || 1));
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
            row.querySelector('[aria-label="Volumetric weight"]').value = vol.toFixed(3);
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
        const values = ['length', 'width', 'height', 'unit'].map((key) =>
            source.querySelector(`[name$="[${key}]"]`).value
        );
        rows.slice(1).forEach((row) => {
            ['length', 'width', 'height', 'unit'].forEach((key, index) => {
                row.querySelector(`[name$="[${key}]"]`).value = values[index];
            });
        });
        calculateWeights();
    });

    function addCommodity(description = '', hsCode = '', unitPrice = '1', previous = {}) {
        const index = commodityRows.querySelectorAll('[data-commodity-row]').length;
        const row = document.createElement('div');
        row.className = 'shipment-commodity-row';
        row.dataset.commodityRow = '';
        row.innerHTML = `
            <label class="shipment-field">Description<input data-rule="commodity" name="commodities[${index}][description]" value="${escapeHtml(previous.description || description)}" required maxlength="100"><small class="shipment-required">Required. Use letters, numbers, spaces, commas, dots, or hyphens.</small></label>
            <label class="shipment-field">HS code<input data-rule="numbers" inputmode="numeric" name="commodities[${index}][hs_code]" value="${escapeHtml(previous.hs_code || hsCode)}" maxlength="10"><small class="shipment-required">HS Code must contain 6–10 digits.</small></label>
            <label class="shipment-field">Quantity<input data-rule="numbers" inputmode="numeric" name="commodities[${index}][qty]" value="${escapeHtml(previous.qty || '1')}" required maxlength="5"><small class="shipment-required">Quantity must be 1–99999.</small></label>
            <label class="shipment-field">Unit price<input data-rule="decimal" inputmode="decimal" data-min="0.01" data-max="9999999.99" name="commodities[${index}][unit_price]" value="${escapeHtml(previous.unit_price || unitPrice)}" required maxlength="10"><small class="shipment-required">Enter a positive price, max 9999999.99.</small></label>
            <label class="shipment-field">Line total<input type="text" name="commodities[${index}][total]" readonly value="0.00"></label>
            <label class="shipment-field">Unit<select name="commodities[${index}][unit]"><option>PCS</option><option>KG</option><option>BOX</option><option>SET</option></select></label>
            <label class="shipment-field">Origin<select name="commodities[${index}][country_of_origin]"><option>INDIA</option><option>UNITED STATES</option><option>UNITED KINGDOM</option><option>AUSTRALIA</option><option>CANADA</option></select></label>
            <button type="button" class="shipment-btn danger" data-remove-commodity aria-label="Remove commodity">Remove</button>`;
        commodityRows.appendChild(row);
        if (previous.unit) row.querySelector(`[name$="[unit]"]`).value = previous.unit;
        if (previous.country_of_origin) row.querySelector(`[name$="[country_of_origin]"]`).value = previous.country_of_origin;
        row.addEventListener('input', calculateCommodityTotal);
        row.querySelectorAll('[data-rule]').forEach((input) => {
            input.addEventListener('input', () => sanitizeByRule(input));
            input.addEventListener('blur', () => validateField(input));
        });
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
        const validRows = [...commodityRows.querySelectorAll('[data-commodity-row]')].filter((row) =>
            [...row.querySelectorAll('[data-rule]')].every((input) => !ruleError(input))
        );
        document.getElementById('preview-invoice').disabled = validRows.length === 0;
        document.getElementById('commodity-required').hidden = validRows.length > 0;
    }

    document.getElementById('add-commodity').addEventListener('click', () => {
        const description = commoditySelect.value.trim();
        const option = [...document.querySelectorAll('#commodity-options option')]
            .find((item) => item.value.toLowerCase() === description.toLowerCase());
        addCommodity(description, option?.dataset.hs || '', option?.dataset.price || '1');
        commoditySelect.value = '';
    });
    document.getElementById('refresh-commodities').addEventListener('click', () => {
        commoditySelect.value = '';
        document.getElementById('commodity-options').replaceChildren(...[
            ...document.querySelectorAll('#commodity-options option')
        ].map((option) => option.cloneNode(true)));
    });
    commodityRows.addEventListener('click', (event) => {
        if (!event.target.matches('[data-remove-commodity]')) return;
        if (commodityRows.querySelectorAll('[data-commodity-row]').length <= 1) {
            document.getElementById('commodity-required').hidden = false;
            return;
        }
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
            const addressInputs = [...form.querySelectorAll(`[name^="${prefix}["]`)]
                .filter((input) => !['hidden', 'checkbox'].includes(input.type));
            const invalidInputs = addressInputs.filter((input) =>
                (input.required || input.value.trim() !== '') && !validateField(input)
            );
            if (invalidInputs.length) {
                showToast('Complete all required address fields and correct any invalid values before saving.', 'error');
                invalidInputs[0].focus();
                return;
            }

            const address = {};
            form.querySelectorAll(`[name^="${prefix}["]`).forEach((input) => {
                const match = input.name.match(/^\w+\[([^\]]+)\]$/);
                if (match) address[match[1]] = input.value;
            });
            address.type = type;
            address.is_default = form.querySelector(`[name="${prefix}[is_default]"]`).checked ? '1' : '0';
            button.disabled = true;
            showToast(`Saving ${type === 'shipper' ? 'sender' : 'recipient'} address...`, 'success');
            try {
                await addressRequest({ action: 'save', address: JSON.stringify(address) });
                showToast(`${type === 'shipper' ? 'Sender' : 'Recipient'} address saved successfully.`, 'success');
            } catch (error) {
                showToast(`Address was not saved: ${error.message}`, 'error');
            } finally {
                button.disabled = false;
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
                showToast(error.message, 'error');
            }
        });
    });

    document.getElementById('use-saved-address').addEventListener('click', async () => {
        if (!addressPicker.value) return;
        try {
            const result = await addressRequest({ action: 'get', id: addressPicker.value });
            Object.entries(result.address).forEach(([key, value]) => {
                const input = form.querySelector(`[name="${activeAddressType}[${key}]"]`);
                if (input && input.type === 'select-one' && input.matches('[data-location-select]')) {
                    populateLocationSelect(input, result.address.country, value ?? '');
                } else if (input && input.type !== 'checkbox') input.value = value ?? '';
                if (input && input.type === 'checkbox') input.checked = Number(value) === 1;
            });
            addressDialog.close();
        } catch (error) {
            showToast(error.message, 'error');
        }
    });

    document.getElementById('delete-saved-address').addEventListener('click', async () => {
        if (!addressPicker.value || !window.confirm('Delete this saved address?')) return;
        try {
            await addressRequest({ action: 'delete', id: addressPicker.value });
            addressPicker.selectedIndex = 0;
            addressPicker.querySelector('option[value=""]')?.remove();
            showToast('Saved address deleted successfully.', 'success');
            addressDialog.close();
        } catch (error) {
            showToast(error.message, 'error');
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

    function populateLocationSelect(select, country, selectedValue = '') {
        const type = select.dataset.locationSelect;
        const values = locations[type]?.[country] || [];
        select.replaceChildren(new Option(type === 'states' ? 'Select a state' : 'Select a city', ''));
        values.forEach((value) => select.add(new Option(value, value)));
        if (selectedValue && !values.includes(selectedValue)) {
            select.add(new Option(selectedValue, selectedValue));
        }
        select.value = selectedValue;
        select.dataset.country = country;
    }

    document.querySelectorAll('[data-country-select]').forEach((select) => {
        select.addEventListener('change', function () {
            const prefix = this.name.split('[')[0];
            const postal = form.querySelector(`[name="${prefix}[postal_code]"]`);
            postal.dataset.country = this.value;
            const postalRules = {
                'UNITED STATES': { maxLength: 5, rule: 'numbers' },
                AUSTRALIA: { maxLength: 4, rule: 'numbers' },
                'UNITED ARAB EMIRATES': { maxLength: 5, rule: 'numbers' },
                'UNITED KINGDOM': { maxLength: 10, rule: 'alnum' },
                CANADA: { maxLength: 7, rule: 'alnum' }
            };
            const postalRule = postalRules[this.value] || { maxLength: 10, rule: 'numbers' };
            postal.maxLength = postalRule.maxLength;
            postal.dataset.rule = postalRule.rule;
            postal.value = '';
            const callingCodes = {
                'UNITED STATES': '+1',
                'UNITED KINGDOM': '+44',
                AUSTRALIA: '+61',
                CANADA: '+1',
                'UNITED ARAB EMIRATES': '+971'
            };
            const code = this.selectedOptions[0].dataset.code;
            const countryCode = callingCodes[this.value] || code;
            if (countryCode) form.querySelector(`[name="${prefix}[country_code]"]`).value = countryCode;
            form.querySelectorAll(`[data-location-select][name^="${prefix}["]`).forEach((locationSelect) => {
                populateLocationSelect(locationSelect, this.value);
                setFieldError(locationSelect, '');
            });
            form.querySelector(`[name="${prefix}[state_code]"]`).value = '';
            validateField(postal);
        });
        document.querySelectorAll('[name$="[identification_type]"]').forEach((select) => {
            const number = form.querySelector(`[name="${select.name.replace('[identification_type]', '[identification_number]')}"]`);
            const updateIdentificationRule = () => {
                const type = select.value;
                number.dataset.rule = type === 'Aadhaar Number' ? 'numbers' : (type === 'PAN' ? 'pan' : 'alnum');
                number.maxLength = type === 'Aadhaar Number' ? 12 : (type === 'Passport' || type === 'Voter ID' ? 10 : 16);
                number.value = type === 'Aadhaar Number'
                    ? number.value.replace(/[^0-9]/g, '').slice(0, 12)
                    : number.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase().slice(0, 16);
            };
            select.addEventListener('change', updateIdentificationRule);
            updateIdentificationRule();
        });
    });
    form.querySelectorAll('[name$="[state]"]').forEach((input) => {
        input.addEventListener('change', () => updateStateCode(input.name.split('[')[0]));
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
                const stateSelect = form.querySelector(`[name="${prefix}[state]"]`);
                const citySelect = form.querySelector(`[name="${prefix}[city]"]`);
                populateLocationSelect(stateSelect, input.dataset.country, place.State);
                populateLocationSelect(citySelect, input.dataset.country, place.District);
                updateStateCode(prefix);
                hint.textContent = 'City, state, and state code filled from postal code.';
            } catch (error) {
                hint.textContent = 'Postal lookup unavailable; enter city and state manually.';
            }
        });
    });

    document.getElementById('manifest-gst').addEventListener('click', () => gstDialog.showModal());
    document.querySelector('[data-open-gst]').addEventListener('click', () => gstDialog.showModal());
    const gstInvoice = document.getElementById('gst-invoice');
    const gstin = document.getElementById('gstin');
    const gstSummary = document.getElementById('gst-summary');
    gstInvoice.addEventListener('change', () => {
        const enabled = gstInvoice.value === '1';
        gstSummary.hidden = !enabled;
        gstin.required = enabled;
        const legalName = document.querySelector('[name="gst_legal_name"]');
        legalName.required = enabled;
    });
    document.getElementById('gstin').addEventListener('input', (event) => {
        document.getElementById('gstin-summary').textContent = event.target.value;
    });
    document.querySelector('[name="shipper[name]"]').addEventListener('input', (event) => {
        const name = document.getElementById('pickup-contact-name');
        if (!name.dataset.edited) name.value = event.target.value;
    });
    document.querySelector('[name="shipper[mobile]"]').addEventListener('input', (event) => {
        const phone = document.getElementById('pickup-contact-phone');
        if (!phone.dataset.edited) phone.value = event.target.value;
    });
    document.getElementById('pickup-contact-name').addEventListener('input', (event) => {
        event.target.dataset.edited = 'true';
    });
    document.getElementById('pickup-contact-phone').addEventListener('input', (event) => {
        event.target.dataset.edited = 'true';
    });
    document.getElementById('pickup-date').addEventListener('focus', (event) => {
        const today = new Date();
        const dateString = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
        const max = new Date(today);
        max.setDate(max.getDate() + 30);
        event.target.min = dateString(today);
        event.target.max = dateString(max);
    });

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
            pickupFields.querySelectorAll('input, select').forEach((input) => {
                input.required = showPickupFields;
            });
        });
    });

    form.addEventListener('reset', () => setTimeout(() => {
        packageCount.value = '1';
        refreshPackages();
        commodityRows.innerHTML = '';
        document.getElementById('pickup-fields').hidden = true;
        document.querySelectorAll('#pickup-fields input, #pickup-fields select').forEach((input) => {
            input.required = false;
        });
        document.getElementById('gst-summary').hidden = true;
        calculateCommodityTotal();
        gstInvoice.dispatchEvent(new Event('change'));
        document.getElementById('own-invoice-toggle').dispatchEvent(new Event('change'));
        document.querySelectorAll('[data-auto-save]').forEach((toggle) => {
            toggle.checked = toggle.dataset.autoSaveDefault === 'true';
            toggle.dispatchEvent(new Event('change'));
        });
    }, 0));

    const fieldsForValidation = () => [
        ...form.querySelectorAll('input, select, textarea'),
        ...document.querySelectorAll('[form="shipment-form"]')
    ].filter((field, index, list) => list.indexOf(field) === index);

    form.addEventListener('input', (event) => {
        const input = event.target;
        if (input.matches('[data-rule]')) sanitizeByRule(input);
        if (input.name.startsWith('commodities[')) calculateCommodityTotal();
        if (input.matches('[data-rule], [required]') && input.closest('.shipment-field')?.classList.contains('invalid')) {
            validateField(input);
        }
        if (input.name === 'gst_legal_name' && input.value.trim() && !inputRulePatterns.alnum.test(input.value.trim())) {
            setFieldError(input, 'Use letters, numbers, and spaces only (max 80).');
        }
        if (input.name === 'pickup_instructions' && input.value.length > 200) input.value = input.value.slice(0, 200);
        if (input.name === 'remarks' && input.value.length > 250) input.value = input.value.slice(0, 250);
        if (input.name === 'customs_status' && input.value.length > 100) input.value = input.value.slice(0, 100);
        if (input.name === 'contents') {
            input.value = input.value.slice(0, 100);
        }
        if (input.matches('[name$="[description]"]')) input.value = input.value.slice(0, 100);
        if (input.matches('[name$="[qty]"]')) input.value = input.value.slice(0, 5);
        if (input.matches('[name$="[unit_price]"]')) input.value = input.value.slice(0, 10);
        if (input.matches('[name$="[hs_code]"]')) input.value = input.value.slice(0, 10);
        if (input.matches('[data-rule="gstin"]')) input.value = input.value.toUpperCase();
        if (input.name === 'gst_legal_name') input.value = input.value.slice(0, 80);
        if (input.name === 'gst_amount' || input.name === 'gst_igst') input.value = input.value.slice(0, 6);
    });

    form.addEventListener('blur', (event) => {
        if (event.target.matches('[data-rule], [required]')) validateField(event.target);
    }, true);

    form.addEventListener('submit', (event) => {
        const isPreview = event.submitter?.id === 'preview-invoice';
        calculateWeights();
        const errors = [];
        if (!isPreview) {
            fieldsForValidation().forEach((input) => {
                if (input.type === 'file' && !input.required && !input.files.length) return;
                if (input.closest('#pickup-fields')?.hidden) return;
                if (input.closest('#gst-dialog') && gstInvoice.value !== '1') return;
                if (input.name === 'own_invoice' && !document.getElementById('own-invoice-toggle').checked) return;
                if (!validateField(input)) errors.push(input);
            });
        }
        const commodityList = [...commodityRows.querySelectorAll('[data-commodity-row]')];
        const invalidCommodityInputs = commodityList.flatMap((row) =>
            [...row.querySelectorAll('[data-rule]')].filter((input) => !validateField(input))
        );
        if (!commodityList.length || invalidCommodityInputs.length) {
            document.getElementById('commodity-required').hidden = false;
            errors.push(invalidCommodityInputs[0] || commoditySelect);
        } else {
            document.getElementById('commodity-required').hidden = true;
        }
        const invoice = form.querySelector('[name="own_invoice"]');
        const invoiceHint = document.getElementById('invoice-error');
        invoiceHint.textContent = '';
        if (document.getElementById('own-invoice-toggle').checked) {
            const file = invoice.files[0];
            const validPdf = file && file.type === 'application/pdf' && /\.pdf$/i.test(file.name);
            if (!file) {
                invoiceHint.textContent = 'Choose a PDF invoice.';
                invoiceHint.style.display = 'block';
                errors.push(invoice);
            } else if (!validPdf || file.size > 2 * 1024 * 1024) {
                invoiceHint.textContent = file.size > 2 * 1024 * 1024
                    ? 'Invoice file must be no larger than 2 MB.'
                    : 'Only PDF files are allowed.';
                invoiceHint.style.display = 'block';
                errors.push(invoice);
            }
        }
        if (gstInvoice.value === '1' && !gstin.value.trim()) {
            setFieldError(gstin, 'GSTIN is required when GST Invoice is Yes.');
            errors.push(gstin);
        }
        if (isPreview && (commodityList.length === 0 || invalidCommodityInputs.length)) {
            event.preventDefault();
        }
        if (errors.length) {
            event.preventDefault();
            errors[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
            errors[0].focus({ preventScroll: true });
            showToast('Please correct the required or invalid fields before submitting.', 'error');
        }
    });

    packageCount.value = oldInput.pieces || packageCount.value || '1';
    refreshPackages();
    oldPackages.forEach((item, index) => {
        const row = packageRows.querySelectorAll('[data-package-row]')[index];
        if (!row) return;
        Object.entries(item).forEach(([key, value]) => {
            const field = row.querySelector(`[name$="[${key}]"]`);
            if (field) field.value = value;
        });
    });
    if (oldCommodities.length) oldCommodities.forEach((item) => addCommodity(item.description, item.hs_code, item.unit_price, item));
    form.querySelectorAll('[data-location-select]').forEach((select) => {
        const prefix = select.name.split('[')[0];
        const oldAddress = oldInput[prefix] || {};
        populateLocationSelect(select, form.querySelector(`[name="${prefix}[country]"]`).value, oldAddress[select.dataset.locationSelect === 'states' ? 'state' : 'city'] || '');
    });
    restoreOldValues();
    document.querySelectorAll('[name$="[identification_type]"]').forEach((select) => select.dispatchEvent(new Event('change')));
    document.getElementById('package-pieces').value = packageCount.value;
    document.getElementById('total-pcs').textContent = packageCount.value;
    calculateWeights();
    calculateCommodityTotal();
    form.querySelectorAll('[data-rule]').forEach((input) => {
        input.addEventListener('blur', () => validateField(input));
    });
    document.querySelectorAll('[form="shipment-form"][data-rule]').forEach((input) => {
        input.addEventListener('input', () => {
            sanitizeByRule(input);
            if (input.closest('.shipment-field')?.classList.contains('invalid')) validateField(input);
        });
        input.addEventListener('blur', () => validateField(input));
    });
    const invoiceDate = document.getElementById('pickup-date');
    invoiceDate.dispatchEvent(new Event('focus'));
    if (gstInvoice.value === '1') gstInvoice.dispatchEvent(new Event('change'));
    const selectedShipMethod = form.querySelector('input[name="ship_method"]:checked');
    if (selectedShipMethod) selectedShipMethod.dispatchEvent(new Event('change'));
    document.querySelectorAll('[data-country-select]').forEach((select) => {
        const postal = form.querySelector(`[name="${select.name.split('[')[0]}[postal_code]"]`);
        const postalRules = {
            'UNITED STATES': { maxLength: 5, rule: 'numbers' },
            AUSTRALIA: { maxLength: 4, rule: 'numbers' },
            'UNITED ARAB EMIRATES': { maxLength: 5, rule: 'numbers' },
            'UNITED KINGDOM': { maxLength: 10, rule: 'alnum' },
            CANADA: { maxLength: 7, rule: 'alnum' }
        };
        const postalRule = postalRules[select.value] || { maxLength: 10, rule: 'numbers' };
        postal.maxLength = postalRule.maxLength;
        postal.dataset.country = select.value;
        postal.dataset.rule = postalRule.rule;
    });
    document.querySelectorAll('[data-auto-save]').forEach((toggle) => toggle.dispatchEvent(new Event('change')));
    document.getElementById('own-invoice-toggle').dispatchEvent(new Event('change'));
});
