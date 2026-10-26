(function($) {
    'use strict';

    const FormFieldMapper = {
        mappings: {},
        formId: null,
        isInitialized: false,
        debugMode: false,

init: function(options = {}) {
    this.debugMode = options.debug || false;

    if (!window.walletUpFieldMapper) {
        window.walletUpFieldMapper = {
            availableForms: [],
            fieldTypeMappings: {
                'name': ['first', 'last'],
                'email': ['email'],
                'phone': ['phone'],
                'radio': ['category', 'tt'],
                'select': ['category', 'tt'],
                'payment-single': ['amt'],
                'payment-total': ['amt'],
                'text': ['bn', 'td', 'rn', 'in', 'street', 'street2', 'city', 'state', 'zip']
            }
        };

        this.log('Created walletUpFieldMapper object');
    }

    this.isInitialized = true;

    this.bindEvents();

    this.detectFormId();

    this.detectWPFormsState();

    if (this.formId) {
        this.loadMappings(this.formId);
    }

    this.log('FormFieldMapper initialized');

    return this;
},

detectWPFormsState: function() {
    if (typeof window.wpforms !== 'undefined') {
        this.log('WPForms detected directly from window.wpforms');
        window.walletUpFieldMapper.wpformsActive = true;

        this.getFormsFromWPForms();
        return;
    }

    const $wpformsForms = document.querySelectorAll('form.wpforms-form');
    if ($wpformsForms.length > 0) {
        this.log('WPForms detected through form elements');
        window.walletUpFieldMapper.wpformsActive = true;

        window.walletUpFieldMapper.availableForms = Array.from($wpformsForms).map(form => {
            const formId = form.getAttribute('data-formid') || form.id.replace('wpforms-form-', '');
            return {
                id: formId,
                title: 'Form #' + formId
            };
        });

        this.log('Extracted forms from DOM', window.walletUpFieldMapper.availableForms);
        return;
    }

            const adminBar = document.getElementById('wpadminbar');
            if (adminBar && adminBar.innerHTML.includes('wpforms')) {
                this.log('WPForms detected through admin bar');
                window.walletUpFieldMapper.wpformsActive = true;
                return;
            }

            const scripts = document.querySelectorAll('script[src*="wpforms"]');
            if (scripts.length > 0) {
                this.log('WPForms detected through script tags');
                window.walletUpFieldMapper.wpformsActive = true;
                return;
            }

            this.log('WPForms not detected on the page');
            window.walletUpFieldMapper.wpformsActive = false;
        },

        getFormsFromWPForms: function() {
            if (typeof window.wpforms === 'undefined' || !window.wpforms.hasOwnProperty('forms')) {
                return;
            }

            try {
                const formIds = Object.keys(window.wpforms.forms || {});

                if (formIds.length > 0) {
                    window.walletUpFieldMapper.availableForms = formIds.map(id => ({
                        id: id,
                        title: 'Form #' + id
                    }));

                    this.log('Extracted forms from WPForms object', window.walletUpFieldMapper.availableForms);
                }
            } catch (e) {
                this.log('Error extracting forms from WPForms object', e);
            }
        },

        log: function(message, data = null) {
            if (!this.debugMode) return;

            if (data) {
                console.log('FormFieldMapper:', message, data);
            } else {
                console.log('FormFieldMapper:', message);
            }
        },

        bindEvents: function() {
            $(document).on('wpformsReady', this.handleFormReady.bind(this));

            window.addEventListener('popstate', this.handleUrlChange.bind(this));

            $(document).on('walletUpFormSelected', this.handleFormSelected.bind(this));

            $(document).ready(this.processUrlParams.bind(this));
        },

        handleFormReady: function() {
            this.detectFormId();

            if (this.formId) {
                this.loadMappings(this.formId);
                this.processUrlParams();
            }
        },

        handleUrlChange: function() {
            this.processUrlParams();
        },

        handleFormSelected: function(e, formId) {
            if (formId && formId !== this.formId) {
                this.formId = formId;
                this.loadMappings(formId);
            }
        },

        detectFormId: function() {
            const urlParams = new URLSearchParams(window.location.search);
            const formIdParam = urlParams.get('form_id');

            if (formIdParam) {
                this.formId = parseInt(formIdParam, 10);
                this.log('Form ID detected from URL', this.formId);
                return;
            }

            const $form = $('form.wpforms-form');
            if ($form.length) {
                const formId = $form.data('formid');
                if (formId) {
                    this.formId = parseInt(formId, 10);
                    this.log('Form ID detected from form attribute', this.formId);
                    return;
                }
            }

            if ($form.length) {
                const formClasses = $form.attr('class').split(' ');
                for (const cls of formClasses) {
                    const match = cls.match(/wpforms-(\d+)/);
                    if (match && match[1]) {
                        this.formId = parseInt(match[1], 10);
                        this.log('Form ID detected from form class', this.formId);
                        return;
                    }
                }
            }

            this.log('Could not detect form ID');
        },

        loadMappings: function(formId) {
            if (this.mappings[formId]) {
                this.log('Using cached mappings for form', formId);
                return Promise.resolve(this.mappings[formId]);
            }

            this.log('Fetching mappings for form', formId);
            
            return new Promise((resolve, reject) => {
                $.ajax({
                    url: window.walletUpFieldMapper.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'wallet_up_get_form_field_mappings',
                        nonce: window.walletUpFieldMapper.nonce,
                        form_id: formId
                    },
                    success: (response) => {
                        if (response.success && response.data && response.data.mappings) {
                            this.mappings[formId] = response.data.mappings;
                            this.log('Mappings loaded for form', formId);
                            resolve(this.mappings[formId]);
                        } else {
                            this.log('Failed to load mappings', response);
                            reject(new Error('Failed to load mappings'));
                        }
                    },
                    error: (xhr, status, error) => {
                        this.log('Error loading mappings', { status, error });
                        reject(new Error(`Failed to load mappings: ${error}`));
                    }
                });
            });
        },

        processUrlParams: function() {
            if (!this.formId || !this.mappings[this.formId]) {
                return;
            }

            const urlParams = new URLSearchParams(window.location.search);
            const mappings = this.mappings[this.formId];

            let prefillCount = 0;

            for (const [key, value] of urlParams.entries()) {
                if (key.startsWith('wup_')) {
                    const paramName = key.substring(4);

                    for (const [wpfKey, wupKey] of Object.entries(mappings)) {
                        if (wupKey === `wup_${paramName}`) {
                            this.prefillField(wpfKey, value);
                            prefillCount++;
                        }
                    }
                }
            }

            this.log(`Prefilled ${prefillCount} fields from URL parameters`);

            const specialParams = ['wup_field_map', 'wup_form_id'];
            for (const param of specialParams) {
                if (urlParams.has(param)) {
                    this.handleSpecialParam(param, urlParams.get(param));
                }
            }
        },

        handleSpecialParam: function(param, value) {
            switch (param) {
                case 'wup_field_map':
                    try {
                        const decodedMap = JSON.parse(atob(value));
                        this.log('Received field mapping in URL', decodedMap);

                        const formId = this.formId || 'default';
                        this.mappings[formId] = decodedMap;

                        this.processUrlParams();
                    } catch (e) {
                        this.log('Error processing field map parameter', e);
                    }
                    break;

                case 'wup_form_id':
                    const formId = parseInt(value, 10);
                    if (formId && formId !== this.formId) {
                        this.formId = formId;
                        this.loadMappings(formId)
                            .then(() => this.processUrlParams())
                            .catch(e => this.log('Error loading mappings for form', e));
                    }
                    break;
            }
        },

        prefillField: function(wpfKey, value) {
            const matches = wpfKey.match(/wpforms\[fields\]\[(\d+)\](?:\[([^\]]+)\])?/);
            if (!matches) {
                return;
            }

            const fieldId = matches[1];
            const subfield = matches[2];

            let $field;

            if (subfield) {
                switch (subfield) {
                    case 'first':
                    case 'last':
                        $field = $(`.wpforms-field-${fieldId} input[name="wpforms[fields][${fieldId}][${subfield}]"]`);
                        break;

                    case 'address1':
                    case 'address2':
                    case 'city':
                    case 'state':
                    case 'postal':
                    case 'country':
                        $field = $(`.wpforms-field-${fieldId} [name="wpforms[fields][${fieldId}][${subfield}]"]`);
                        break;
                }
            } else {
                $field = $(`.wpforms-field-${fieldId} input[name="wpforms[fields][${fieldId}]"],
                           .wpforms-field-${fieldId} select[name="wpforms[fields][${fieldId}]"],
                           .wpforms-field-${fieldId} textarea[name="wpforms[fields][${fieldId}]"]`);
            }

            if (!$field.length) {
                this.log(`Field not found: ${wpfKey}`);
                return;
            }

            this.log(`Prefilling field ${wpfKey} with value: ${value}`);

            const fieldType = $field.attr('type') || $field.prop('tagName').toLowerCase();

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
                    const $option = $field.find(`option[value="${value}"]`);
                    if ($option.length) {
                        $option.prop('selected', true);
                    } else {
                        const $optionByText = $field.find('option').filter(function() {
                            return $(this).text().toLowerCase() === value.toLowerCase();
                        });

                        if ($optionByText.length) {
                            $optionByText.prop('selected', true);
                        } else {
                            const fieldSettings = this.getFieldSettings(fieldId);
                            if (fieldSettings && fieldSettings.allowCustomValue) {
                                $field.append($('<option>', {
                                    value: value,
                                    text: value,
                                    selected: true
                                }));
                            }
                        }
                    }
                    break;
            }

            $field.trigger('change');
        },

        getFieldSettings: function(fieldId) {
            if (typeof wpforms !== 'undefined' && 
                wpforms.hasOwnProperty('form') && 
                wpforms.form.hasOwnProperty('fields')) {
                
                return wpforms.form.fields[fieldId];
            }
            
            return null;
        },

        generatePaymentLink: function(baseUrl, formData) {
            if (!this.isInitialized || !this.formId) {
                this.log('Cannot generate payment link - not initialized or no form ID');
                return baseUrl;
            }

            const params = new URLSearchParams();

            params.append('wup_form_id', this.formId);

            if (this.mappings[this.formId]) {
                const encodedMap = btoa(JSON.stringify(this.mappings[this.formId]));
                params.append('wup_field_map', encodedMap);
            }

            if (formData) {
                for (const [key, value] of Object.entries(formData)) {
                    params.append(`wup_${key}`, value);
                }
            }

            const url = new URL(baseUrl);

            for (const [key, value] of params.entries()) {
                url.searchParams.append(key, value);
            }

            return url.toString();
        }
    };

    window.WalletUpProFormFieldMapper = FormFieldMapper;

})(jQuery);

jQuery(document).ready(function() {
    if (window.WalletUpProFormFieldMapper) {
        window.WalletUpProFormFieldMapper.init({
            debug: false
        });
    }
});