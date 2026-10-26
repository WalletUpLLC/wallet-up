(function($) {
    'use strict';
    
    const FormAdapter = {
        settings: {},
        isInitialized: false,
        formId: null,
        formFields: [],
        fieldMappings: {},
        debug: false,

        init: function() {
            if (!window.walletUpFormAdapter) {
                console.warn('WalletUpPro Form Adapter: Missing configuration data');
                return;
            }

            this.debug = (typeof WP_DEBUG !== 'undefined' && WP_DEBUG === true);

            this.settings = window.walletUpFormAdapter.settings || {};

            if (!this.settings.enableFormIntegration) {
                this.log('Form integration is disabled in settings');
                return;
            }

            this.isInitialized = true;

            this.detectForm();

            this.bindEvents();

            this.log('Form adapter initialized');

            return this;
        },

        log: function(message, data) {
            if (!this.debug) return;
            
            if (data) {
                console.log('WalletUpPro Form Adapter:', message, data);
            } else {
                console.log('WalletUpPro Form Adapter:', message);
            }
        },

        detectForm: function() {
            const urlParams = new URLSearchParams(window.location.search);
            const formIdParam = urlParams.get('form_id');

            if (formIdParam) {
                this.formId = parseInt(formIdParam, 10);
                this.log('Form ID detected from URL', this.formId);
                return;
            }

            const $form = $('form.wpforms-form');
            if (!$form.length) {
                this.log('No WPForms form found on page');
                return;
            }

            let formId = $form.data('formid');
            if (formId) {
                this.formId = parseInt(formId, 10);
                this.log('Form ID detected from form attribute', this.formId);
                return;
            }

            const formClasses = $form.attr('class').split(' ');
            for (const cls of formClasses) {
                const match = cls.match(/wpforms-form-(\d+)/);
                if (match && match[1]) {
                    this.formId = parseInt(match[1], 10);
                    this.log('Form ID detected from form class', this.formId);
                    return;
                }
            }

            this.log('Could not detect form ID');
        },

        bindEvents: function() {
            $(document).on('wpformsReady', this.handleFormReady.bind(this));

            $(document).ready(this.processUrlParams.bind(this));

            $(document).on('walletUpGenerateLink', this.handleGenerateLink.bind(this));

            $(document).on('walletUpFieldMappingChanged', this.handleFieldMappingChanged.bind(this));

            $('form.wpforms-form').on('submit', this.handleFormSubmit.bind(this));

            $(document).on('click', '.wallet-up-wpforms-method', this.handlePaymentMethodSelection.bind(this));
        },

        handleFormReady: function() {
            if (!this.formId) {
                this.detectForm();
            }

            if (this.formId) {
                this.loadFormData(this.formId);

                this.processUrlParams();
            }
        },

        loadFormData: function(formId) {
            this.log('Loading form data for form', formId);

            this.fetchFormFields(formId)
                .then(fields => {
                    this.formFields = fields;
                    this.log('Loaded form fields', fields);

                    return this.fetchFormMappings(formId);
                })
                .then(mappings => {
                    this.fieldMappings = mappings;
                    this.log('Loaded field mappings', mappings);

                    $(document).trigger('walletUpFormDataLoaded', [formId, this.formFields, this.fieldMappings]);
                })
                .catch(error => {
                    this.log('Error loading form data', error);
                });
        },

        fetchFormFields: function(formId) {
            return new Promise((resolve, reject) => {
                if (!window.walletUpFormAdapter || !window.walletUpFormAdapter.restUrl) {
                    reject(new Error('REST API configuration not available'));
                    return;
                }
                
                wp.apiFetch({
                    path: `wallet-up-pro/v1/form-fields/${formId}`,
                    method: 'GET'
                })
                .then(response => {
                    if (response.success && response.fields) {
                        resolve(response.fields);
                    } else {
                        reject(new Error(response.message || 'Failed to fetch form fields'));
                    }
                })
                .catch(error => {
                    reject(error);
                });
            });
        },

        fetchFormMappings: function(formId) {
            return new Promise((resolve, reject) => {
                if (!window.walletUpFormAdapter || !window.walletUpFormAdapter.restUrl) {
                    reject(new Error('REST API configuration not available'));
                    return;
                }
                
                wp.apiFetch({
                    path: `wallet-up-pro/v1/form-mappings/${formId}`,
                    method: 'GET'
                })
                .then(response => {
                    if (response.success && response.mappings) {
                        resolve(response.mappings);
                    } else {
                        reject(new Error(response.message || 'Failed to fetch form mappings'));
                    }
                })
                .catch(error => {
                    reject(error);
                });
            });
        },

        processUrlParams: function() {
            if (!this.formId) {
                this.log('Cannot process URL parameters - no form ID');
                return;
            }

            const urlParams = new URLSearchParams(window.location.search);
            const wupParams = {};

            for (const [key, value] of urlParams.entries()) {
                if (key.startsWith('wup_')) {
                    const paramName = key.substring(4);
                    wupParams[paramName] = value;
                }
            }

            if (Object.keys(wupParams).length === 0) {
                this.log('No WalletUpPro parameters found in URL');
                return;
            }

            this.log('Found WalletUpPro parameters', wupParams);

            if (urlParams.has('wup_field_map')) {
                try {
                    const fieldMap = JSON.parse(atob(urlParams.get('wup_field_map')));
                    this.fieldMappings = fieldMap;
                    this.log('Using field mapping from URL', fieldMap);
                } catch (e) {
                    this.log('Error parsing field map from URL', e);
                }
            }

            this.waitForMappings()
                .then(() => {
                    this.prefillFormFields(wupParams);
                })
                .catch(error => {
                    this.log('Timed out waiting for mappings', error);
                    this.prefillFormFields(wupParams);
                });
        },

        waitForMappings: function() {
            return new Promise((resolve, reject) => {
                if (Object.keys(this.fieldMappings).length > 0) {
                    resolve();
                    return;
                }

                let attempts = 0;
                const checkInterval = setInterval(() => {
                    if (Object.keys(this.fieldMappings).length > 0) {
                        clearInterval(checkInterval);
                        resolve();
                        return;
                    }

                    attempts++;
                    if (attempts >= 50) {
                        clearInterval(checkInterval);
                        reject(new Error('Timed out waiting for field mappings'));
                    }
                }, 100);
            });
        },

        prefillFormFields: function(wupParams) {
            if (!this.formId || Object.keys(wupParams).length === 0) {
                return;
            }

            let prefillCount = 0;

            if (Object.keys(this.fieldMappings).length > 0) {
                for (const param in wupParams) {
                    const value = wupParams[param];

                    for (const [wpfKey, wupKey] of Object.entries(this.fieldMappings)) {
                        if (wupKey === `wup_${param}`) {
                            this.prefillField(wpfKey, value);
                            prefillCount++;
                        }
                    }
                }
            }

            if (prefillCount === 0 && this.settings.enableAutoDetection) {
                this.autoPrefillFields(wupParams);
            } else {
                this.log(`Prefilled ${prefillCount} fields from URL parameters`);
            }
        },

        autoPrefillFields: function(wupParams) {
            if (!this.formFields || this.formFields.length === 0) {
                this.log('Cannot auto-prefill - no form fields available');
                return;
            }
            
            const prefillMap = {
                'first': { types: ['name', 'text'], keywords: ['first', 'first name', 'firstname'] },
                'last': { types: ['name', 'text'], keywords: ['last', 'last name', 'lastname', 'surname'] },
                'email': { types: ['email', 'text'], keywords: ['email', 'e-mail'] },
                'phone': { types: ['phone', 'text'], keywords: ['phone', 'telephone', 'mobile'] },
                'category': { types: ['radio', 'select'], keywords: ['category', 'user type', 'user category'] },
                'bn': { types: ['text'], keywords: ['business name', 'company', 'organization'] },
                'tt': { types: ['radio', 'select'], keywords: ['transaction type', 'payment type', 'transaction'] },
                'td': { types: ['text', 'textarea'], keywords: ['transaction details', 'details', 'notes'] },
                'rn': { types: ['text'], keywords: ['recipient name', 'receiver', 'payee'] },
                'in': { types: ['text'], keywords: ['invoice', 'invoice number', 'bill number', 'reference'] },
                'amt': { types: ['payment-single', 'payment-total', 'text'], keywords: ['amount', 'total', 'price'] },
                'street': { types: ['address', 'text'], keywords: ['street', 'address', 'street address', 'address line 1'] },
                'street2': { types: ['address', 'text'], keywords: ['street2', 'address 2', 'address line 2', 'apartment'] },
                'city': { types: ['address', 'text'], keywords: ['city', 'town'] },
                'state': { types: ['address', 'text', 'select'], keywords: ['state', 'province', 'region'] },
                'zip': { types: ['address', 'text'], keywords: ['zip', 'postal', 'postal code', 'zip code'] },
                'country': { types: ['address', 'text', 'select'], keywords: ['country', 'nation'] }
            };
            
            let prefillCount = 0;

            for (const [param, value] of Object.entries(wupParams)) {
                if (!prefillMap[param]) continue;

                const { types, keywords } = prefillMap[param];

                const matchedField = this.formFields.find(field =>
                    types.includes(field.type) &&
                    keywords.some(keyword =>
                        field.label.toLowerCase().includes(keyword)
                    )
                );

                if (matchedField) {
                    const wpfKey = `wpforms[fields][${matchedField.id}]`;

                    this.fieldMappings[wpfKey] = `wup_${param}`;

                    this.prefillField(wpfKey, value);
                    prefillCount++;

                    this.log(`Auto-mapped ${param} to field ${matchedField.id} (${matchedField.label})`);
                }
            }

            if (prefillCount > 0) {
                this.log(`Auto-prefilled ${prefillCount} fields from URL parameters`);

                if (this.formId) {
                    this.saveFieldMappings(this.formId, this.fieldMappings);
                }
            } else {
                this.log('Could not auto-prefill any fields');
            }
        },

        saveFieldMappings: function(formId, mappings) {
            if (!window.walletUpFormAdapter || !window.walletUpFormAdapter.ajaxUrl) {
                this.log('Cannot save field mappings - AJAX URL not available');
                return;
            }
            
            $.ajax({
                url: window.walletUpFormAdapter.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wallet_up_save_field_mappings',
                    nonce: window.walletUpFormAdapter.ajaxNonce,
                    form_id: formId,
                    mappings: JSON.stringify(mappings)
                },
                success: (response) => {
                    if (response.success) {
                        this.log('Field mappings saved successfully');
                    } else {
                        this.log('Failed to save field mappings', response);
                    }
                },
                error: (xhr, status, error) => {
                    this.log('Error saving field mappings', { status, error });
                }
            });
        },

        prefillField: function(wpfKey, value) {
            const match = wpfKey.match(/wpforms\[fields\]\[(\d+)\](?:\[([^\]]+)\])?/);
            if (!match) {
                this.log(`Invalid field key format: ${wpfKey}`);
                return;
            }

            const fieldId = match[1];
            const subfield = match[2];

            let $field;

            if (subfield) {
                $field = $(`.wpforms-field-${fieldId} [name="wpforms[fields][${fieldId}][${subfield}]"]`);
            } else {
                $field = $(`.wpforms-field-${fieldId} [name="wpforms[fields][${fieldId}]"]`);
            }

            if (!$field.length) {
                this.log(`Field not found: ${wpfKey}`);
                return;
            }

            this.log(`Prefilling field ${wpfKey} with value: ${value}`);

            const fieldType = $field.prop('type') || $field.prop('tagName').toLowerCase();

            switch (fieldType) {
                case 'text':
                case 'email':
                case 'tel':
                case 'url':
                case 'number':
                case 'hidden':
                case 'textarea':
                    $field.val(value);
                    break;

                case 'radio':
                    $(`.wpforms-field-${fieldId} input[type="radio"][value="${value}"]`).prop('checked', true);
                    break;

                case 'checkbox':
                    const values = value.split(',');
                    values.forEach(val => {
                        $(`.wpforms-field-${fieldId} input[type="checkbox"][value="${val.trim()}"]`).prop('checked', true);
                    });
                    break;

                case 'select':
                    let $option = $field.find(`option[value="${value}"]`);

                    if (!$option.length) {
                        $option = $field.find('option').filter(function() {
                            return $(this).text().toLowerCase() === value.toLowerCase();
                        });
                    }

                    if ($option.length) {
                        $option.prop('selected', true);
                    }
                    break;
            }

            $field.trigger('change');
        },

        handlePaymentMethodSelection: function(e) {
            const $method = $(e.currentTarget);
            const methodId = $method.data('method');

            if (!methodId) {
                return;
            }

            const $totalDisplay = $('.wpforms-payment-total');
            let amount = 0;

            if ($totalDisplay.length) {
                const displayText = $totalDisplay.text();
                const amountMatch = displayText.match(/[\d.,]+/);

                if (amountMatch) {
                    amount = parseFloat(amountMatch[0].replace(/,/g, ''));
                }
            }

            const paymentData = {
                id: methodId,
                name: $method.find('.wallet-up-wpforms-method-name').text().trim(),
                amount: amount,
                timestamp: Date.now(),
                formId: this.formId || 0
            };

            sessionStorage.setItem('walletup_payment_details', JSON.stringify(paymentData));

            window.dispatchEvent(
                new CustomEvent('walletUpPaymentSelected', {
                    detail: paymentData
                })
            );

            this.log('Payment method selected', paymentData);
        },

        handleFormSubmit: function(e) {
            const paymentData = sessionStorage.getItem('walletup_payment_details');

            if (!paymentData) {
                return;
            }

            try {
                const data = JSON.parse(paymentData);
                const $form = $(e.currentTarget);

                if (!$form.find('input[name="wallet_up_payment_method"]').length) {
                    $('<input>')
                        .attr('type', 'hidden')
                        .attr('name', 'wallet_up_payment_method')
                        .val(data.id)
                        .appendTo($form);
                }

                if (!$form.find('input[name="wallet_up_payment_amount"]').length) {
                    $('<input>')
                        .attr('type', 'hidden')
                        .attr('name', 'wallet_up_payment_amount')
                        .val(data.amount)
                        .appendTo($form);
                }

                if (data.accountHolder && !$form.find('input[name="wallet_up_account_name"]').length) {
                    $('<input>')
                        .attr('type', 'hidden')
                        .attr('name', 'wallet_up_account_name')
                        .val(data.accountHolder)
                        .appendTo($form);
                }

                this.log('Added payment data to form submission', data);
            } catch (error) {
                this.log('Error processing payment data for form submission', error);
            }
        },

        handleGenerateLink: function(e, params) {
            if (!params || !params.formId) {
                this.log('Cannot generate link - missing form ID');
                return;
            }

            this.formId = params.formId;

            if (params.fieldMappings) {
                this.fieldMappings = {};

                for (const [param, fieldId] of Object.entries(params.fieldMappings)) {
                    if (fieldId) {
                        this.fieldMappings[`wpforms[fields][${fieldId}]`] = `wup_${param}`;
                    }
                }

                this.log('Using field mappings from link generator', this.fieldMappings);
            }

            if (Object.keys(this.fieldMappings).length > 0) {
                this.saveFieldMappings(this.formId, this.fieldMappings);
            }

            $(document).trigger('walletUpLinkMappingsReady', [this.formId, this.fieldMappings]);
        },

        handleFieldMappingChanged: function(e, mappings) {
            if (!mappings) {
                return;
            }

            this.fieldMappings = mappings;
            this.log('Field mappings updated', mappings);

            if (this.formId) {
                this.saveFieldMappings(this.formId, mappings);
            }
        }
    };

    $(document).ready(function() {
        FormAdapter.init();
    });

    window.WalletUpProFormAdapter = FormAdapter;
    
})(jQuery);