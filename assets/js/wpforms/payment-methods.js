(function($) {
    'use strict';
    const debounce = (func, wait, options = { leading: false, trailing: true }) => {
        let timeout;
        return (...args) => {
            const later = () => {
                timeout = null;
                if (options.trailing) func(...args);
            };
            const callNow = options.leading && !timeout;
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
            if (callNow) func(...args);
        };
    };
    const WalletUpProPayments = {
        debug: false,
        currentAmount: null,
        currentMethod: null,
        animationTimeout: null,
        observer: null,
        amountObserver: null,
        debounceTimeout: null,
        formId: null,
        containerSelector: '.wallet-up-wpforms-methods-container',
        displaySelector: '.wallet-up-wpforms-qr-display',
        accountPromptSelector: '.wallet-up-account-prompt',
        paymentTypeFieldSelector: null,
        settings: (typeof window !== 'undefined' && window.walletUpWPForms) ? window.walletUpWPForms : {},

        init: function() {
            $(document).ready(() => {
                this.container = $(this.containerSelector);
                this.display = this.container.find(this.displaySelector).first();
                this.accountPromptContainer = this.container.find(this.accountPromptSelector).first();
                if (!this.display.length) this.display = $(this.displaySelector).first();
                if (!this.accountPromptContainer.length) this.accountPromptContainer = $(this.accountPromptSelector).first();

                if (this.container.length) {
                    this.settings = window.walletUpWPForms || {};

                    if (this.settings.formFields && this.settings.formFields.paymentTypeField) {
                        this.paymentTypeFieldSelector = `input[name="wpforms[fields][${this.settings.formFields.paymentTypeField}]"], select[name="wpforms[fields][${this.settings.formFields.paymentTypeField}]"]`;
                        this.log('Setting payment type selector to:', this.paymentTypeFieldSelector);
                    } else {
                        this.detectPaymentTypeField();
                    }

                    this.bindEvents();
                    this.setupInitialObserver();
                    this.checkLocalization();
                    this.initializeAccountPrompt();

                    const $form = this.container.closest('form.wpforms-form');
                    if ($form.length) {
                        this.formId = $form.data('formid') || 0;
                        this.log('Form ID detected:', this.formId);
                    }

                    this.initializeFromStoredDetails();
                } else {
                    this.log('Payment methods container not found. Will try to detect it later.');
                    this.setupInitialObserver();
                }
            });

            $(document)
                .off('wpformsProcessComplete.wupwpf')
                .on('wpformsProcessComplete.wupwpf', this.handleGlobalFormCompletion.bind(this));

        },
        handleGlobalFormCompletion: function(e, data, form) {
            if (data.success) {
                this.log('Form submitted successfully, clearing stored data');

                const formId = $(form).data('formid');
                const storedDetails = JSON.parse(sessionStorage.getItem('walletup_payment_details') || '{}');

                if (formId && storedDetails.formId === formId) {
                    sessionStorage.removeItem('walletup_payment_details');
                    sessionStorage.removeItem('walletup_gateway_payment');
                    this.log('Cleared payment details for form:', formId);

                    this.currentMethod = null;
                    this.currentAmount = null;

                    if (this.display && this.display.length) {
                        this.display.empty();
                    }
                    
                    if (this.accountPromptContainer && this.accountPromptContainer.length) {
                        this.accountPromptContainer.empty().hide();
                    }
                }
            }
        },

        detectPaymentTypeField: function() {
            const paymentLabels = ['payment method', 'pay with', 'pay via', 'how would you like to pay'];
            let found = false;

            $('form.wpforms-form').find('input[type="radio"], select').each((index, element) => {
                const value = $(element).val();
                const labelText = $(element).closest('.wpforms-field').find('.wpforms-field-label').text().toLowerCase();

                if (value &&
                    (value === '3' ||
                     value.toLowerCase().includes('wallet') ||
                     value.toLowerCase().includes('pay') ||
                     labelText.includes('payment'))) {

                    const fieldId = $(element).closest('.wpforms-field').data('field-id');
                    if (fieldId) {
                        this.paymentTypeFieldSelector = `input[name="wpforms[fields][${fieldId}]"], select[name="wpforms[fields][${fieldId}]"]`;
                        this.log('Detected payment type field (direct match):', fieldId);
                        found = true;
                        return false;
                    }
                }
            });

            if (!found) {
                $('form.wpforms-form').find('.wpforms-field-label').each((index, element) => {
                    const label = $(element).text().toLowerCase();
                    for (const searchText of paymentLabels) {
                        if (label.includes(searchText)) {
                            const fieldId = $(element).closest('.wpforms-field').data('field-id');
                            if (fieldId) {
                                this.paymentTypeFieldSelector = `input[name="wpforms[fields][${fieldId}]"], select[name="wpforms[fields][${fieldId}]"]`;
                                this.log('Detected payment type field (label match):', fieldId);
                                found = true;
                                return false;
                            }
                        }
                    }
                });
            }

            if (!found) {
                this.paymentTypeFieldSelector = 'input[name*="wpforms[fields]"][type="radio"], select[name*="wpforms[fields]"]';
                this.log('Using fallback payment type selector');
            }
        },

        initializeFromStoredDetails: function() {
            try {
                const stored = sessionStorage.getItem('walletup_payment_details');
                if (stored) {
                    const details = JSON.parse(stored);
                    if (details && details.id) {
                        const liveAmount = this.getAmount();
                        if (liveAmount <= 0) {
                            this.log('Stored details found but live amount is $0 — clearing stale session');
                            sessionStorage.removeItem('walletup_payment_details');
                            return;
                        }

                        this.log('Found stored payment details, live amount:', liveAmount);

                        if (this.container.length) {
                            this.selectStoredMethod(details);
                        } else {
                            this.pendingStoredMethod = details;
                        }
                    }
                }
            } catch (e) {
                this.log('Error initializing from stored details:', e);
            }
        },

        selectStoredMethod: function(details) {
            const $method = this.container.find(`.wallet-up-wpforms-method[data-method="${details.id}"]`);
            if ($method.length) {
                setTimeout(() => {
                    const liveAmount = this.getAmount();
                    if (liveAmount <= 0) {
                        $method.addClass('selected');
                        this.currentMethod = details.id;
                        this.currentAmount = null;
                        this.display.empty();
                        this.log('Stored method restored but amount is $0, skipping QR');
                        return;
                    }
                    $method.addClass('selected');
                    this.currentMethod = details.id;
                    this.currentAmount = liveAmount;
                    this.generateQR(details.id, liveAmount);
                    this.updateAccountPrompt({
                        id: details.id,
                        name: details.name,
                        amount: liveAmount
                    });
                }, 500);
            }
        },

        initializeAccountPrompt: function() {
            if (this.accountPromptContainer.length) {
                this.log('Account prompt container found');
            } else {
                this.log('Account prompt container not found');
            }

            this.reactPromptContainer = $('.wallet-up-account-prompt-root');
            if (this.reactPromptContainer.length) {
                this.log('React-based account prompt container found');
            }
        },

        storePaymentDetails: function(methodId, methodName, amount) {
            const paymentData = {
                id: methodId,
                name: methodName,
                amount: amount,
                timestamp: Date.now(),
                formId: this.formId || 0,
                summary: `Payment via ${methodName} for $${parseFloat(amount).toFixed(2)}`
            };

            sessionStorage.setItem('walletup_payment_details', JSON.stringify(paymentData));

            this.addPaymentFieldsToForm(methodId, amount, methodName);

            this.updateAccountPrompt(paymentData);

            window.dispatchEvent(
                new CustomEvent('walletUpPaymentSelected', {
                    detail: paymentData
                })
            );
        },

        addPaymentFieldsToForm: function(methodId, amount, methodName) {
            const $form = $('form.wpforms-form');
            if (!$form.length) return;

            $form.find('input[name^="wallet_up_"]').remove();

            $form.append(`<input type="hidden" name="wallet_up_payment_method" value="${methodId}">`);
            $form.append(`<input type="hidden" name="wallet_up_payment_amount" value="${amount}">`);
            $form.append(`<input type="hidden" name="wallet_up_payment_method_name" value="${methodName}">`);

            const summary = `Payment via ${methodName} for $${parseFloat(amount).toFixed(2)}`;
            $form.append(`<input type="hidden" name="wallet_up_payment_summary" value="${summary}">`);

            $form.append(`<input type="hidden" name="wallet_up_payment_timestamp" value="${Date.now()}">`);

            this.log('Added payment fields to form:', { methodId, amount, methodName, summary });
        },

        log: function(message, data = null) {
            if (this.debug && console && console.log) {
                console.log('WalletUpPro:', message, data || '');
            }
        },

        checkLocalization: function() {
            if (typeof window.walletUpWPForms === 'undefined') {
                console.error('WalletUpProWPForms is not defined. Check PHP script localization.');
            } else {
                this.log('walletUpWPForms object found:', window.walletUpWPForms);
            }
        },

        handleAccountNameChange: function(e) {
            const accountName = $(e.target).val();
            this.updateStoredAccountName(accountName);
        },
        handleAccountNameInput: function(e) {
            const $field = $(e.target);
            const accountName = $field.val();

            if (accountName.trim() !== '') {
                $field.removeClass('wpforms-error');
            }

            this.updateStoredAccountName(accountName);
        },

        updateStoredAccountName: function(accountName) {
            if (accountName && accountName.trim() !== '') {
                const storedDetails = JSON.parse(sessionStorage.getItem('walletup_payment_details') || '{}');
                if (storedDetails.id) {
                    storedDetails.accountHolder = accountName;
                    sessionStorage.setItem('walletup_payment_details', JSON.stringify(storedDetails));
                    this.log('Updated stored payment details with account name:', accountName);

                    this.ensureAccountNameInForm(accountName);
                }
            }
        },

        updateAccountPrompt: function(methodDetails) {
            if (!this.accountPromptContainer.length ||
                (this.settings.accountPrompt && !this.settings.accountPrompt.enabled)) {
                return;
            }

            var _mid = (methodDetails && methodDetails.id) ? methodDetails.id : this.currentMethod;
            if (_mid === 'stripe' || _mid === 'paypal_commerce') {
                this.accountPromptContainer.empty().hide();
                return;
            }

            let promptMessage = 'Please enter the account name used for payment';
            if (this.settings.accountPrompt && this.settings.accountPrompt.message) {
                promptMessage = this.settings.accountPrompt.message
                    .replace('{method}', methodDetails.name);
            }

            const content = `
            <div class="payment-prompt__container">
                <div class="payment-prompt__message">${promptMessage}</div>
                
                <div class="payment-details__card">
                    <h3 class="payment-details__title">Payment Details</h3>
                    <div class="payment-details__row">
                        <span class="payment-details__label">Method</span>
                        <span class="payment-details__value">${methodDetails.name}</span>
                    </div>
                    <div class="payment-details__row">
                        <span class="payment-details__label">Amount</span>
                        <span class="payment-details__value">$${parseFloat(methodDetails.amount).toFixed(2)}</span>
                    </div>
                </div>
        
                <div class="input-group">
                    <label for="wallet_up_account_name" class="input-group__label">
                        Account Name
                        ${this.settings.accountPrompt?.required ? '<span class="input-group__required">*</span>' : ''}
                    </label>
                    <input 
                        type="text"
                        id="wallet_up_account_name"
                        name="wallet_up_account_name"
                        class="input-group__field"
                        placeholder="Enter account name"
                        data-payment-field="account_name"
                        ${this.settings.accountPrompt?.required ? 'required' : ''}
                    >
                </div>
            </div>
            `;
        
            this.accountPromptContainer.html(content).slideDown(300);

            try {
                const storedDetails = JSON.parse(sessionStorage.getItem('walletup_payment_details') || '{}');
                if (storedDetails.accountHolder) {
                    this.accountPromptContainer.find('#wallet_up_account_name').val(storedDetails.accountHolder);
                }
            } catch (e) {
                this.log('Error restoring account name:', e);
            }

            if (this.reactPromptContainer.length) {
                window.dispatchEvent(
                    new CustomEvent('walletUpPromptUpdate', { 
                        detail: methodDetails
                    })
                );
            }
        },

        setupInitialObserver: function() {
            this.observer = new MutationObserver(this.handleInitialContainer.bind(this));

            this.observer.observe(document.body, {
                childList: true,
                subtree: true
            });

            this.log('Initial observer setup complete');

            $(document).on('wpformsValidationFailed', (e, form) => {
                setTimeout(() => {
                    const $form = $(form);
                    const paymentTypeField = $form.find(this.paymentTypeFieldSelector + ':checked');
                    
                    if (paymentTypeField.length && this.isWalletUpProSelected(paymentTypeField.val())) {
                        if (this.settings.accountPrompt?.enabled && !this.accountPromptContainer.is(':visible')) {
                            this.accountPromptContainer.slideDown(300);
                        }
                    }
                }, 100);
            });
        },
        handleFormSubmit: function(event, form) {
            if (window.WalletUpGateways && window.WalletUpGateways.isActiveGateway()) {
                if (!window.WalletUpGateways.paymentCompleted) {
                    event.preventDefault();
                    window.WalletUpGateways.processPayment(form);
                    return false;
                }
            }

            if (this.settings.accountPrompt &&
                this.settings.accountPrompt.enabled &&
                this.settings.accountPrompt.required) {

                const $form = $(form);

                const paymentTypeField = $form.find(this.paymentTypeFieldSelector + ':checked');
                if (paymentTypeField.length && this.isWalletUpProSelected(paymentTypeField.val())) {

                    let $accountNameField = $form.find('#payment_account_name');
                    let accountName = $accountNameField.length ? $accountNameField.val() : '';

                    if ((!accountName || accountName.trim() === '') && $form.find('#wallet_up_account_name').length) {
                        $accountNameField = $form.find('#wallet_up_account_name');
                        accountName = $accountNameField.val();
                    }

                    if ((!accountName || accountName.trim() === '') && $form.find('input[name="wallet_up_account_name"]').length) {
                        accountName = $form.find('input[name="wallet_up_account_name"]').val();
                    }

                    if (!accountName || accountName.trim() === '') {
                        event.preventDefault();

                        const errorMsg = 'Please enter the account name used for payment';

                        if (typeof wpforms !== 'undefined' && wpforms.hasOwnProperty('showAlert')) {
                            wpforms.showAlert(errorMsg);
                        } else {
                            alert(errorMsg);
                        }

                        if (!this.accountPromptContainer.is(':visible')) {
                            this.accountPromptContainer.slideDown(300);
                        }

                        if ($accountNameField.length) {
                            $accountNameField.addClass('wpforms-error').focus();
                        }

                        return false;
                    } else {
                        this.ensureAccountNameInForm(accountName);
                    }
                }
            }
            $(form).on('wpformsComplete', this.handleFormSubmissionComplete.bind(this));

        },

        handleFormSubmissionComplete: function(event, data) {
            if (data.success) {
                this.log('Form submission complete, clearing stored payment details');
                sessionStorage.removeItem('walletup_payment_details');

                const $form = $(event.target);
                $form.find('input[name^="wallet_up_"]').remove();
            }
        },

        ensureAccountNameInForm: function(accountName) {
            const $form = $('form.wpforms-form');
            if (!$form.length) return;

            let $hiddenField = $form.find('input[name="wallet_up_account_name"]');

            if ($hiddenField.length) {
                $hiddenField.val(accountName);
            } else {
                $form.append(`<input type="hidden" name="wallet_up_account_name" value="${accountName}">`);
            }

            this.log('Account name added/updated in form:', accountName);
        },

        bindEvents: function() {
            try {
                const methodSelector = this.containerSelector + ' .wallet-up-wpforms-method';
                $(document)
                    .off('click.wupwpf', methodSelector)
                    .on('click.wupwpf', methodSelector, this.handleMethodSelection.bind(this));

                $(document)
                    .off('change.wupwpf', this.paymentTypeFieldSelector)
                    .on('change.wupwpf', this.paymentTypeFieldSelector, this.handlePaymentTypeChange.bind(this));

                $(document)
                    .off('click.wupwpf', '.wpforms-page-button')
                    .on('click.wupwpf', '.wpforms-page-button', this.onPageChange.bind(this));

                $(document)
                    .off('wpformsBeforeFormSubmit.wupwpf')
                    .on('wpformsBeforeFormSubmit.wupwpf', this.handleFormSubmit.bind(this));

                $(document)
                    .off('change.wupwpf', '#wallet_up_account_name, #payment_account_name')
                    .on('change.wupwpf', '#wallet_up_account_name, #payment_account_name', this.handleAccountNameChange.bind(this));

                $(document)
                    .off('input.wupwpf', '#wallet_up_account_name, #payment_account_name')
                    .on('input.wupwpf', '#wallet_up_account_name, #payment_account_name', this.handleAccountNameInput.bind(this));

                this.log('Events bound successfully');
            } catch (error) {
                this.log('Error binding events:', error);
            }
        },

        addAccountNameToForm: function(accountName) {
            const $form = $('form.wpforms-form');
            if (!$form.length) return;

            $form.find('input[name="wallet_up_account_name"]').remove();

            $form.append(`<input type="hidden" name="wallet_up_account_name" value="${accountName}">`);

            this.log('Added account name to form:', accountName);
        },

        isWalletUpProSelected: function(value) {
            const walletUpValues = ['3', 'wallet_up', 'walletup', 'walupay'];
            return walletUpValues.includes(value) || (value && value.toLowerCase().includes('wallet'));
        },

        handleInitialContainer: function(mutations) {
            for (const mutation of mutations) {
                if (mutation.type === 'childList') {
                    for (const node of mutation.addedNodes) {
                        if (!(node instanceof Element)) continue;

                        if (node.matches(this.containerSelector) || node.querySelector(this.containerSelector)) {
                            this.container = $(this.containerSelector);
                            this.display = $(this.displaySelector);
                            this.accountPromptContainer = $(this.accountPromptSelector); // Get the new account prompt
                            this.setupAmountObserver(); // Set up the observer for amount changes
                            this.handleAmountChange();  // Initialize the UI based on current state
                            
                            if (this.pendingStoredMethod) {
                                this.selectStoredMethod(this.pendingStoredMethod);
                                this.pendingStoredMethod = null;
                            }
                            
                            this.log('WalletUpPro container found and initialized');
                            return; // Exit once found
                        }
                    }
                }
            }
        },

        handleMethodSelection: function(e) {
            e.preventDefault();

            this.log('Method clicked:', $(e.currentTarget).data('method'));

            if (this.container.hasClass('disabled')) {
              this.log('Selection blocked - container disabled');
              return;
            }

            const $method = $(e.currentTarget);
            const methodId = $method.data('method');

            if (!methodId) {
              this.log('Error: No method ID found on element', $method);
              return;
            }

            const methodName = $method.find('.wallet-up-wpforms-method-name').text();
            const amount = this.getAmount();

            this.log('Selected method:', { id: methodId, name: methodName, amount });

            $('.wallet-up-wpforms-method').removeClass('selected');
            $method.addClass('selected');
            this.currentMethod = methodId;

            if (!amount || amount <= 0) {
              this.currentAmount = null;
              this.display.html(
                '<div class="wallet-up-notice" style="text-align:center;padding:16px;color:#6b7280;font-size:14px;">' +
                this.getI18nString('selectProduct', 'Please select a product or service above to see the payment total.') +
                '</div>'
              );
              this.accountPromptContainer.slideUp(200);
              return;
            }

            if (methodId === 'stripe' || methodId === 'paypal_commerce') {
              this.currentAmount = amount;
              this.display.empty().hide();
              if (window.WalletUpGateways) {
                window.WalletUpGateways.showGateway(methodId, amount, this.formId);
              }
              this.storePaymentDetails(methodId, methodName, amount);
              return;
            }

            if (window.WalletUpGateways) {
              window.WalletUpGateways.hideAll();
            }
            this.display.show();

            if (this.currentAmount !== amount) {
              this.currentAmount = amount;

              setTimeout(() => {
                this.generateQR(methodId, amount);

                this.storePaymentDetails(methodId, methodName, amount);
              }, 50);
            } else {
              setTimeout(() => {
                this.generateQR(methodId, amount);
                this.storePaymentDetails(methodId, methodName, amount);
              }, 50);
            }
          },

        setupAmountObserver: function() {
            let $totalDisplay;

            if (this.settings.formFields && this.settings.formFields.totalField) {
                $totalDisplay = $(`.wpforms-field-${this.settings.formFields.totalField} .wpforms-payment-total`);
            }

            if (!$totalDisplay || !$totalDisplay.length) {
                $totalDisplay = $('.wpforms-payment-total');
            }

            if ($totalDisplay.length) {
                if (this.amountObserver) {
                    this.amountObserver.disconnect();
                }

                this.amountObserver = new MutationObserver((mutations) => {
                    mutations.forEach((mutation) => {
                        if (mutation.type === 'characterData' || mutation.type === 'childList') {
                            this.handleAmountChange();
                        }
                    });
                });

                this.amountObserver.observe($totalDisplay[0], {
                    characterData: true,
                    childList: true,
subtree: true,
                   characterDataOldValue: true
               });

               $totalDisplay.on('DOMSubtreeModified', () => {
                   this.handleAmountChange();
               });

               this.log('Enhanced amount observer setup complete');
           } else {
               this.log('Could not find payment total display for observation');
           }
       },

       handleAmountChange: function() {
        if (this.debounceTimeout) {
            clearTimeout(this.debounceTimeout);
        }

        this.debounceTimeout = setTimeout(() => {
            const amount = this.getAmount();
            const previousAmount = this.currentAmount;

            this.log('Amount detected:', amount, 'Previous:', previousAmount);

            const $paymentTypeField = $(this.paymentTypeFieldSelector + ':checked');
            const isWalletUpProSelected = $paymentTypeField.length && this.isWalletUpProSelected($paymentTypeField.val());

            this.log('WalletUpPro selected:', isWalletUpProSelected);

            if (amount <= 0) {
                this.container.addClass('low-amount-warning');
            } else {
                this.container.removeClass('low-amount-warning');
            }

            if (isWalletUpProSelected) {
                if (!this.container.is(':visible')) {
                    this.container.show();
                    requestAnimationFrame(() => {
                        this.container.css('display', 'grid');
                    });
                }

                if (amount <= 0) {
                    if (this.currentAmount !== null) {
                        this.log('Amount is $0, clearing QR display');
                        this.currentAmount = null;
                        this.display.html(
                            this.currentMethod
                                ? '<div class="wallet-up-notice" style="text-align:center;padding:16px;color:#6b7280;font-size:14px;">' +
                                  this.getI18nString('selectProduct', 'Please select a product or service above to see the payment total.') +
                                  '</div>'
                                : ''
                        );
                        this.accountPromptContainer.slideUp(200);
                    }
                    return;
                }

                if (Math.abs(amount - (previousAmount || 0)) > 0.005) {
                    this.log('Amount changed significantly:', amount, 'Previous:', previousAmount);
                    this.currentAmount = amount;

                    this.updateStoredAmount(amount);

                    if (this.currentMethod) {
                        if (window.WalletUpGateways && window.WalletUpGateways.isActiveGateway()) {
                            window.WalletUpGateways.currentAmount = amount;
                            if (this.currentMethod === 'paypal_commerce' && window.WalletUpGateways.paypalSDKLoaded) {
                                window.WalletUpGateways.renderPayPalButton(amount);
                            }
                        } else {
                            this.log('Regenerating QR with new amount:', amount);
                            this.generateQR(this.currentMethod, amount);
                        }
                    }
                }
            } else {
                this.container.hide();
                this.display.empty();
                this.accountPromptContainer.slideUp(200);
            }
        }, 150); // Shorter debounce time (was 300ms)
    },

    updateStoredAmount: function(amount) {
        try {
            const storedDetails = JSON.parse(sessionStorage.getItem('walletup_payment_details') || '{}');
            if (storedDetails.id) {
                storedDetails.amount = amount;
                sessionStorage.setItem('walletup_payment_details', JSON.stringify(storedDetails));

                if (storedDetails.name) {
                    this.updateAccountPrompt({
                        id: storedDetails.id,
                        name: storedDetails.name,
                        amount: amount
                    });
                }

                window.dispatchEvent(
                    new CustomEvent('walletUpAmountChanged', {
                        detail: { amount }
                    })
                );
            }
        } catch (e) {
            this.log('Error updating stored amount:', e);
        }
    },
        
        handlePaymentTypeChange: (e) => {
            const target = $(e.target);
            const value = target.val();
            const isWalletUpProSelected = WalletUpProPayments.isWalletUpProSelected(value);
            
            WalletUpProPayments.log('Payment type changed:', value, 'WalletUpPro selected:', isWalletUpProSelected);
            
            WalletUpProPayments.container.toggleClass('wallet-up-active', isWalletUpProSelected);
            
            if (isWalletUpProSelected) {
                WalletUpProPayments.log('WalletUpPro selected, showing container');
                WalletUpProPayments.container.show();
                setTimeout(() => {
                    WalletUpProPayments.container.css('display', 'grid');
                }, 50);
            } else {
                WalletUpProPayments.log('WalletUpPro not selected, hiding container');
                WalletUpProPayments.container.hide();
            }
            
            WalletUpProPayments.handleAmountChange();
        },
       getAmount: function() {
           let amount = 0;

           let $totalDisplay;
           
           if (this.settings && this.settings.formFields && this.settings.formFields.totalField) {
               $totalDisplay = $(`.wpforms-field-${this.settings.formFields.totalField} .wpforms-payment-total`);
           }
           
           if (!$totalDisplay || !$totalDisplay.length) {
               $totalDisplay = $('.wpforms-payment-total');
           }

           if ($totalDisplay.length) {
               let text = $totalDisplay.text().trim();
               const match = text.match(/[\d.,]+/);
               if (match) {
                   let numStr = match[0].replace(/,/g, '');
                   amount = parseFloat(numStr);
               }

               if (!isNaN(amount)) {
                   amount = parseFloat(amount.toFixed(2)); // Ensure 2 decimal places
               }

               if (isNaN(amount) || amount <= 0) {
                   amount = 0;
               }
           } else {
               const amountInput = $('.wallet-up-wpforms-amount');
               if (amountInput.length) {
                   amount = parseFloat(amountInput.val());
                   if (isNaN(amount) || amount <= 0) {
                       amount = 0;
                   }
               } else {
                   this.log('No payment total display found');
               }
           }

           return amount;
       },

       getI18nString: function(key, defaultValue) {
           return this.settings?.i18n?.[key] || defaultValue;
       },

       generateQR: (methodId, amount) => {
        if (!methodId || amount <= 0) {
            WalletUpProPayments.log('Invalid QR parameters:', { methodId, amount });
            return;
        }
    
        WalletUpProPayments.currentQRRequest = {
            methodId: methodId,
            amount: amount
        };
    
        const generatingMsg = WalletUpProPayments.getI18nString('generating', 'Generating QR code...');
        WalletUpProPayments.display.html(`<div class="wallet-up-loading">${generatingMsg}</div>`);
    
        if (WalletUpProPayments.activeRequest) {
            WalletUpProPayments.activeRequest.abort();
        }
    
        const nonce = WalletUpProPayments.settings?.nonce;
        if (!nonce) {
            WalletUpProPayments.log('Nonce missing for AJAX call');
            WalletUpProPayments.display.html(`<div class="wallet-up-error">${WalletUpProPayments.getI18nString('error', 'Error: Security token not found.')}</div>`);
            return;
        }
    
        const data = {
            action: 'wallet_up_wpforms_generate_qr',
            method: methodId,
            amount: amount,
            nonce: nonce,
            form_id: WalletUpProPayments.formId || 0,
            post_id: WalletUpProPayments.settings?.postId || 0,
            timestamp: Date.now(), // Prevent caching issues

            display_settings: {
            qrCode: WalletUpProPayments.settings?.qrSettings || {},
            showContainer: WalletUpProPayments.settings?.displaySettings?.showContainer,
            messages: WalletUpProPayments.settings?.displaySettings?.messages || {}
        }
        };
    
        WalletUpProPayments.log('Generating QR:', data);
    
        WalletUpProPayments.activeRequest = $.ajax({
            url: WalletUpProPayments.settings?.ajaxUrl || '',
            type: 'POST',
            data: data,
            cache: false,
            success: (response) => {
                if (WalletUpProPayments.currentQRRequest.methodId === methodId &&
                    WalletUpProPayments.currentQRRequest.amount === amount) {
                    
                    if (response.success && response.data.html) {
                        let html = response.data.html;

                        if (WalletUpProPayments.settings.qrSettings) {
                            const qrSettings = WalletUpProPayments.settings.qrSettings;
                            const styleTag = `<style>
                                img[data-walletup-qr] { border-radius: ${qrSettings.style === 'rounded' ? '12px' : (qrSettings.style === 'dot' ? '50%' : '4px')}; }
                                .walletup-qr-link, [data-walletup-link] { 
                                    background-color: ${qrSettings.background}; 
                                    border: ${qrSettings.border ? `2px solid ${qrSettings.color}` : 'none'}; 
                                    border-radius: ${qrSettings.style === 'rounded' ? '16px' : (qrSettings.style === 'dot' ? '50%' : '8px')}; 
                                }
                            </style>`;
                            if (!html.includes('<style>')) html = styleTag + html;
                        }
                        
                        WalletUpProPayments.display.fadeOut(200, () => {
                            WalletUpProPayments.display.html(html).fadeIn(200);

                            if (WalletUpProPayments.settings?.displaySettings?.messages) {
                                const messageEl = WalletUpProPayments.display.find('.walletup-message');

                                if (messageEl.length) {
                                    const messageSettings = WalletUpProPayments.settings.displaySettings.messages;

                                    if (messageSettings.hideMessage === true) {
                                        messageEl.hide();
                                    }
                                    else if (messageSettings.showDefaultMessage === false && messageSettings.customMessage) {
                                        const formattedAmount = parseFloat(amount).toFixed(2);
                                        const methodName = response.data.methodDetails?.name || methodId;

                                        let customMessage = messageSettings.customMessage
                                            .replace(/\$amount/g, '$' + formattedAmount)
                                            .replace(/\$method/g, methodName);

                                        customMessage = customMessage
                                            .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
                                            .replace(/\*(.*?)\*/g, '<em>$1</em>');

                                        messageEl.html(customMessage);
                                    }
                                }
                            }

                            if (WalletUpProPayments.settings?.qrSettings?.showBackground === false) {
                                WalletUpProPayments.display.find('.walletup-qr-link, [data-walletup-link]').css({
                                    'background-color': 'transparent'
                                });
                            }

                                if (WalletUpProPayments.settings?.displaySettings?.showContainer === false) {
                                    WalletUpProPayments.display.addClass('no-container-bg');
                                } else {
                                    WalletUpProPayments.display.removeClass('no-container-bg');
                                }

                            if (WalletUpProPayments.settings?.accountPrompt?.enabled && response.data.methodDetails) {
                                WalletUpProPayments.updateAccountPrompt(response.data.methodDetails);
                            }

                            document.dispatchEvent(new CustomEvent('walletup_qr_generated', {
                                detail: {
                                    methodId: methodId,
                                    amount: amount,
                                    response: response.data
                                }
                            }));
                            
                            WalletUpProPayments.log('QR generated successfully');
                        });
                    } else {
                        const error = response.data?.message || WalletUpProPayments.getI18nString('error', 'Error generating QR code');
                        WalletUpProPayments.display.fadeOut(200, () => {
                            WalletUpProPayments.display.html(`<div class="wallet-up-error">${error}</div>`).fadeIn(200);
                        });
                        WalletUpProPayments.log('QR generation failed:', error);
                    }
                } else {
                    WalletUpProPayments.log('Ignoring outdated QR response');
                }
                WalletUpProPayments.activeRequest = null;
            },
            error: (xhr, status, error) => {
                if (status !== 'abort') {
                    const errorMsg = 'Unable to generate QR code. Please try again.';
                    WalletUpProPayments.display.fadeOut(200, () => {
                        WalletUpProPayments.display.html(`
                            <div class="wallet-up-error">
                                ${errorMsg}
                                <button class="wallet-up-retry-button">Try Again</button>
                            </div>
                        `).fadeIn(200);

                        WalletUpProPayments.display.find('.wallet-up-retry-button').on('click', function() {
                            WalletUpProPayments.generateQR(methodId, amount);
                        });
                    });
                    WalletUpProPayments.log('AJAX error:', { status, error, responseText: xhr.responseText });
                }
                WalletUpProPayments.activeRequest = null;
            }
        });
    },

       onPageChange: function() {
           setTimeout(() => {
               this.container = $(this.containerSelector);
               this.display = $(this.displaySelector);
               this.accountPromptContainer = $(this.accountPromptSelector);

               this.setupAmountObserver();
               this.handleAmountChange();

               const stored = sessionStorage.getItem('walletup_payment_details');
               if (stored) {
                   try {
                       const details = JSON.parse(stored);
                       if (details && details.id && this.container.length) {
                           this.selectStoredMethod(details);
                       }
                   } catch (e) {
                       this.log('Error restoring method on page change:', e);
                   }
               }
           }, 300);
       }
   };

   $(document).ready(function() {
       WalletUpProPayments.init();
   });

   $(document).on('wpformsReady', function() {
       WalletUpProPayments.init();
   });

   const WalletUpGateways = {
       stripe: null,
       stripeElements: null,
       cardElement: null,
       paypalButtonRendered: false,
       paypalSDKLoaded: false,
       activeGateway: null,
       paymentCompleted: false,
       paymentInProgress: false,
       paymentTransactionId: null,
       currentAmount: 0,
       currentFormId: 0,

       $id: function(suffix) {
           return '#wallet-up-wpforms-' + suffix + '-' + this.currentFormId;
       },

       getFormConfig: function(formId) {
           if (window.walletUpWPFormsInstances && window.walletUpWPFormsInstances[formId]) {
               return window.walletUpWPFormsInstances[formId];
           }
           return window.walletUpWPForms || {};
       },

       init: function() {
           this.settings = window.walletUpWPForms || {};
           this.gateways = this.settings.gateways || {};
           this.currentFormId = this.settings.formId || 0;

           try {
               const saved = JSON.parse(sessionStorage.getItem('walletup_gateway_payment') || 'null');
               if (saved && saved.completed && (Date.now() - saved.timestamp) < 600000) {
                   this.paymentCompleted = true;
                   this.paymentTransactionId = saved.transactionId;
                   this.log('Restored payment state from sessionStorage', saved);
               }
           } catch (e) { /* ignore */ }

           if (this.gateways.stripe && typeof Stripe !== 'undefined') {
               try {
                   this.stripe = this.gateways.stripe.connectedAccount
                       ? Stripe(this.gateways.stripe.publishableKey, { stripeAccount: this.gateways.stripe.connectedAccount })
                       : Stripe(this.gateways.stripe.publishableKey);
                   this.stripeElements = this.stripe.elements({
                       mode: 'payment',
                       amount: Math.max(Math.round((WalletUpProPayments.getAmount() || 0) * 100), 50),
                       currency: (this.gateways.stripe.currency || 'usd').toLowerCase(),
                       paymentMethodCreation: 'manual'
                   });
                   this.log('Stripe initialized');
               } catch (e) {
                   this._stripeLoadFailed = true;
                   this.stripe = null;
                   this.stripeElements = null;
                   if (console && console.error) { console.error('WalletUp: Stripe init failed —', (e && e.message) ? e.message : e); }
               }
           } else if (this.gateways.stripe && typeof Stripe === 'undefined') {
               this.log('Stripe.js failed to load — card payments unavailable');
               this._stripeLoadFailed = true;
           }

           $(document).on('change', '.wallet-up-wpforms-recurring-check, .wallet-up-wpforms-paypal-recurring-check', function() {
               const $freq = $(this).closest('.wallet-up-recurring-options').find('.wallet-up-recurring-freq');
               if ($(this).is(':checked')) {
                   $freq.slideDown(200);
               } else {
                   $freq.slideUp(200);
               }
               if ($(this).hasClass('wallet-up-wpforms-paypal-recurring-check') && WalletUpGateways.activeGateway === 'paypal_commerce') {
                   const liveAmount = WalletUpProPayments.getAmount();
                   WalletUpGateways.renderPayPalButton(liveAmount);
               }
           });

           $(document).on('change', '.wallet-up-recurring-freq', function() {
               const $container = $(this).closest('.wallet-up-wpforms-gateway-container');
               if ($container.find('.wallet-up-wpforms-paypal-recurring-check').length &&
                   WalletUpGateways.activeGateway === 'paypal_commerce' &&
                   $container.find('.wallet-up-wpforms-paypal-recurring-check').is(':checked')) {
                   const liveAmount = WalletUpProPayments.getAmount();
                   WalletUpGateways.renderPayPalButton(liveAmount);
               }
           });

           this.log('WalletUpGateways initialized');
       },

       log: function(msg, data) {
           if (WalletUpProPayments.debug && console && console.log) {
               console.log('WalletUpGateways:', msg, data || '');
           }
       },

       isActiveGateway: function() {
           return this.activeGateway === 'stripe' || this.activeGateway === 'paypal_commerce';
       },

       getFormContactInfo: function() {
           const $form = this.currentFormId
               ? $('form.wpforms-form[data-formid="' + this.currentFormId + '"]')
               : $('form.wpforms-form').first();
           let email = '';
           let name = '';

           if ($form.length) {
               const $emailField = $form.find('input[type="email"]').first();
               if ($emailField.length) {
                   email = $.trim($emailField.val());
               }

               const $firstField = $form.find('.wpforms-field-name input.wpforms-field-name-first').first();
               const $lastField = $form.find('.wpforms-field-name input.wpforms-field-name-last').first();
               if ($firstField.length || $lastField.length) {
                   name = $.trim(($firstField.val() || '') + ' ' + ($lastField.val() || ''));
               }

               if (!name) {
                   const $nameInput = $form.find('.wpforms-field-name input[type="text"]').first();
                   if ($nameInput.length) {
                       name = $.trim($nameInput.val());
                   }
               }
           }

           return { email: email, name: name };
       },

       hideAll: function() {
           $(this.$id('stripe-container')).hide();
           $(this.$id('paypal-container')).hide();
           this.activeGateway = null;
           this.paymentCompleted = false;
           this.paymentInProgress = false;
           this.paymentTransactionId = null;
       },

       showGateway: function(methodId, amount, formId) {
           if (formId) {
               this.currentFormId = formId;
               const formConfig = this.getFormConfig(formId);
               this.settings = formConfig;
               this.gateways = formConfig.gateways || {};
           }
           this.hideAll();
           this.currentAmount = amount;
           this.activeGateway = methodId;
           this.paymentCompleted = false;

           if (methodId === 'stripe') {
               this.showStripe(amount);
           } else if (methodId === 'paypal_commerce') {
               this.showPayPal(amount);
           }
       },

       showStripe: function(amount) {
           if (this._stripeLoadFailed) {
               $(this.$id('card-errors')).text('Card payments are currently unavailable. Please refresh or choose another payment method.').show();
           }

           const $container = $(this.$id('stripe-container'));
           $container.show();

           if (this.gateways.stripe?.recurringEnabled) {
               $(this.$id('stripe-recurring')).show();
           }

           if (!this.cardElement && this.stripeElements) {
               try {
                   this.cardElement = this.stripeElements.create('payment');
                   this.cardElement.on('loaderror', (ev) => {
                       if (console && console.error) { console.error('WalletUp: Stripe Payment Element loaderror —', (ev && ev.error) ? ev.error.message : ev); }
                       $(this.$id('card-errors')).text('Card payments are unavailable right now. Please choose another method.').show();
                   });
                   this.cardElement.mount(this.$id('card-element'));
                   this.log('Stripe Payment Element mounted');
               } catch (e) {
                   this.cardElement = null;
                   if (console && console.error) { console.error('WalletUp: Stripe Payment Element mount failed —', (e && e.message) ? e.message : e); }
                   $(this.$id('card-errors')).text('Card payments are unavailable right now. Please choose another method.').show();
               }
           } else if (!this.stripeElements && !this._stripeLoadFailed) {
               if (console && console.error) { console.error('WalletUp: Stripe Elements not initialized; card element cannot mount.'); }
               $(this.$id('card-errors')).text('Card payments are unavailable right now. Please choose another method.').show();
           }
       },

       processStripePayment: function(form) {
           if (this.paymentInProgress || this.paymentCompleted) {
               return;
           }
           if (!this.stripe || !this.stripeElements) {
               this.log('Stripe not initialized');
               return;
           }

           const amount = WalletUpProPayments.getAmount();
           if (amount <= 0) {
               alert('Please select a product or enter an amount first.');
               return;
           }

           const $form = $(form);
           const $submitBtn = $form.find('.wpforms-submit');
           let originalText = $submitBtn.data('wupOriginalText');
           if (typeof originalText === 'undefined') {
               originalText = $submitBtn.text();
               $submitBtn.data('wupOriginalText', originalText);
           }
           const showErr = (msg) => {
               this.paymentInProgress = false;
               $(this.$id('card-errors')).text(msg).show();
               $submitBtn.prop('disabled', false).text(originalText);
           };
           this.paymentInProgress = true;
           $submitBtn.prop('disabled', true).text(this.settings.i18n?.processingPayment || 'Processing payment...');

           const isRecurring = $(this.$id('recurring-check')).is(':checked');
           const frequency = isRecurring ? $(this.$id('recurring-freq')).val() : null;

           try { this.stripeElements.update({ amount: Math.max(Math.round(amount * 100), 50) }); } catch (e) {}

           this.stripeElements.submit().then((sub) => {
               if (sub.error) { showErr(sub.error.message); return; }
               if (isRecurring) {
                   this.stripe.createPaymentMethod({ elements: this.stripeElements }).then((result) => {
                       if (result.error) { showErr(result.error.message); return; }
                       this.createSubscription('stripe', result.paymentMethod.id, amount, frequency, form, $submitBtn, originalText);
                   });
               } else {
                   this.createStripeIntent(amount, form, $submitBtn, originalText);
               }
           });
       },

       createStripeIntent: function(amount, form, $submitBtn, originalText) {
           const contact = this.getFormContactInfo();
           const currency = (this.gateways.stripe?.currency || this.gateways.paypal?.currency || 'usd').toLowerCase();
           const formId = this.settings.formId || WalletUpProPayments.formId || 0;
           const showErr = (msg) => {
               this.paymentInProgress = false;
               $(this.$id('card-errors')).text(msg).show();
               $submitBtn.prop('disabled', false).text(originalText);
           };
           $.ajax({
               url: this.settings.ajaxUrl,
               type: 'POST',
               timeout: 60000,
               data: {
                   action: 'wallet_up_wpforms_create_stripe_intent',
                   nonce: this.settings.gatewayNonce,
                   amount: amount,
                   form_id: formId,
                   currency: currency,
                   email: contact.email,
                   name: contact.name,
               },
               success: (response) => {
                   if (!response.success || !response.data || !response.data.client_secret || !response.data.intent_id) {
                       showErr(response.data?.message || 'Payment failed');
                       return;
                   }
                   const intentId = response.data.intent_id;
                   this.stripe.confirmPayment({
                       elements: this.stripeElements,
                       clientSecret: response.data.client_secret,
                       confirmParams: { return_url: window.location.href.split('#')[0] },
                       redirect: 'if_required'
                   }).then((result) => {
                       if (result.error) { showErr(result.error.message); return; }
                       $.ajax({
                           url: this.settings.ajaxUrl,
                           type: 'POST',
                           timeout: 60000,
                           data: {
                               action: 'wallet_up_wpforms_create_stripe_intent',
                               nonce: this.settings.gatewayNonce,
                               payment_intent_id: intentId,
                               amount: amount,
                               form_id: formId,
                               currency: currency,
                               email: contact.email,
                               name: contact.name,
                           },
                           success: (rec) => {
                               if (!rec.success) { showErr(rec.data?.message || 'Could not record payment.'); return; }
                               const methodName = (rec.data && rec.data.method_label) ? rec.data.method_label : 'Card';
                               this.onPaymentSuccess('stripe', intentId, amount, form, $submitBtn, originalText, methodName);
                           },
                           error: () => { showErr('Connection error. Please try again.'); }
                       });
                   });
               },
               error: (xhr) => {
                   showErr('Connection error. Please try again.');
                   this.log('Stripe intent AJAX error', xhr.responseText);
               }
           });
       },

       showPayPal: function(amount) {
           const $container = $(this.$id('paypal-container'));
           $container.show();

           if (this.gateways.paypal?.recurringEnabled) {
               $(this.$id('paypal-recurring')).show();
           }

           if (!this.paypalSDKLoaded && this.gateways.paypal?.clientId) {
               this.loadPayPalSDK(amount);
           } else if (this.paypalSDKLoaded) {
               this.renderPayPalButton(amount);
           }
       },

       loadPayPalSDK: function(amount) {
           const clientId = this.gateways.paypal.clientId;
           const currency = (this.gateways.paypal.currency || 'USD').toUpperCase();

           if (window.paypal) {
               this.paypalSDKLoaded = true;
               this.renderPayPalButton(amount);
               return;
           }

           if (this._paypalSDKLoading) {
               this._paypalSDKPendingAmount = amount;
               return;
           }
           this._paypalSDKLoading = true;

           const script = document.createElement('script');
           script.src = `https://www.paypal.com/sdk/js?client-id=${clientId}&currency=${currency}&intent=capture`;
           script.onload = () => {
               this.paypalSDKLoaded = true;
               this._paypalSDKLoading = false;
               const renderAmount = this._paypalSDKPendingAmount || amount;
               this._paypalSDKPendingAmount = null;
               this.renderPayPalButton(renderAmount);
               this.log('PayPal SDK loaded');
           };
           script.onerror = () => {
               this._paypalSDKLoading = false;
               this._paypalSDKPendingAmount = null;
               $(this.$id('paypal-message')).text('Failed to load PayPal. Please refresh.').show();
               this.log('PayPal SDK failed to load');
           };
           document.head.appendChild(script);
       },

       renderPayPalButton: function(amount) {
           const $btnContainer = $(this.$id('paypal-button'));
           $btnContainer.empty();
           this.paypalButtonRendered = false;

           if ($(this.$id('paypal-recurring-check')).is(':checked')) {
               this.renderPayPalSubscribeButton($btnContainer);
               return;
           }

           if (!window.paypal) {
               this.log('PayPal SDK not available');
               return;
           }

           const self = this;
           const currency = (this.gateways.paypal?.currency || 'USD').toUpperCase();
           const paypalMsgId = this.$id('paypal-message');
           const paypalBtnId = this.$id('paypal-button');

           window.paypal.Buttons({
               style: {
                   layout: 'vertical',
                   color: 'gold',
                   shape: 'rect',
                   label: 'pay',
                   height: 45,
               },
               createOrder: function(data, actions) {
                   const liveAmount = WalletUpProPayments.getAmount();
                   if (liveAmount <= 0) {
                       self.log('PayPal: amount is 0');
                       return actions.reject();
                   }
                   return actions.order.create({
                       purchase_units: [{
                           amount: {
                               value: liveAmount.toFixed(2),
                               currency_code: currency,
                           },
                           description: 'WPForms Payment - Form #' + (self.settings.formId || WalletUpProPayments.formId || 0),
                       }]
                   });
               },
               onApprove: function(data, actions) {
                   const $msg = $(paypalMsgId);
                   $msg.text(self.settings.i18n?.processingPayment || 'Processing payment...').show();

                   const liveAmount = WalletUpProPayments.getAmount();
                   const contact = self.getFormContactInfo();
                   $.ajax({
                       url: self.settings.ajaxUrl,
                       type: 'POST',
                       timeout: 60000,
                       data: {
                           action: 'wallet_up_wpforms_capture_paypal_order',
                           nonce: self.settings.gatewayNonce,
                           order_id: data.orderID,
                           amount: liveAmount,
                           form_id: self.settings.formId || WalletUpProPayments.formId || 0,
                           email: contact.email,
                           name: contact.name,
                       },
                       success: function(response) {
                           if (response.success) {
                               self.onPaymentSuccess('paypal', response.data.transaction_id, liveAmount);
                               $msg.text(self.settings.i18n?.paymentSuccess || 'Payment successful!').show();
                               const $form = self.currentFormId
                                   ? $('form.wpforms-form[data-formid="' + self.currentFormId + '"]')
                                   : $('form.wpforms-form').first();
                               if ($form.length) {
                                   setTimeout(() => $form.trigger('submit'), 500);
                               }
                           } else {
                               $msg.text(response.data?.message || 'Capture failed').show();
                           }
                       },
                       error: function() {
                           $msg.text('Connection error during capture.').show();
                       }
                   });
               },
               onError: function(err) {
                   $(paypalMsgId).text('PayPal error: ' + err.message).show();
                   self.log('PayPal button error', err);
               },
               onCancel: function() {
                   $(paypalMsgId).text('Payment cancelled.').show();
               }
           }).render(paypalBtnId);

           this.paypalButtonRendered = true;
           this.log('PayPal button rendered');
       },

       renderPayPalSubscribeButton: function($container) {
           const self = this;
           const frequency = $(this.$id('paypal-recurring-freq')).val() || 'monthly';

           $container.html(
               '<button type="button" class="wallet-up-paypal-subscribe-btn" style="' +
               'display:block;width:100%;padding:14px;background:#FFC439;color:#111;border:none;border-radius:8px;' +
               'font-size:15px;font-weight:600;cursor:pointer;text-align:center;">' +
               'Subscribe with PayPal (' + frequency.charAt(0).toUpperCase() + frequency.slice(1) + ')' +
               '</button>'
           );

           $container.find('.wallet-up-paypal-subscribe-btn').on('click', function() {
               const liveAmount = WalletUpProPayments.getAmount();
               if (liveAmount <= 0) {
                   $(self.$id('paypal-message')).text('Please select a product or enter an amount.').show();
                   return;
               }

               $(this).prop('disabled', true).text('Redirecting to PayPal...');
               const freq = $(self.$id('paypal-recurring-freq')).val() || 'monthly';
               self.createSubscription('paypal', null, liveAmount, freq, null, null, null);
           });

           this.paypalButtonRendered = true;
           this.log('PayPal subscribe button rendered');
       },

       createSubscription: function(provider, pmId, amount, frequency, form, $submitBtn, originalText, paypalOrderId) {
           const contact = this.getFormContactInfo();
           const postData = {
               action: 'wallet_up_wpforms_create_subscription',
               nonce: this.settings.gatewayNonce,
               provider: provider,
               amount: amount,
               frequency: frequency,
               form_id: this.settings.formId || WalletUpProPayments.formId || 0,
               currency: provider === 'paypal' ? (this.gateways.paypal?.currency || 'USD') : (this.gateways.stripe?.currency || 'usd').toLowerCase(),
               email: contact.email,
               name: contact.name,
           };

           if (provider === 'stripe' && pmId) {
               postData.payment_method_id = pmId;
           }

           $.ajax({
               url: this.settings.ajaxUrl,
               type: 'POST',
               timeout: 60000,
               data: postData,
               success: (response) => {
                   if (response.success && response.data.requires_action && this.stripe) {
                       this.stripe.confirmCardPayment(response.data.client_secret).then((confirmResult) => {
                           if (confirmResult.error) {
                               this.paymentInProgress = false;
                               $(this.$id('card-errors')).text(confirmResult.error.message).show();
                               if ($submitBtn) $submitBtn.prop('disabled', false).text(originalText);
                           } else {
                               this.onPaymentSuccess('stripe', response.data.subscription_id, amount, form, $submitBtn, originalText);
                           }
                       });
                   } else if (response.success && response.data.requires_redirect) {
                       window.location.href = response.data.approval_url;
                   } else if (response.success) {
                       this.onPaymentSuccess(provider, response.data.subscription_id, amount, form, $submitBtn, originalText);
                   } else {
                       this.paymentInProgress = false;
                       const msg = response.data?.message || 'Subscription creation failed';
                       if (provider === 'stripe') {
                           $(this.$id('card-errors')).text(msg).show();
                       } else {
                           $(this.$id('paypal-message')).text(msg).show();
                       }
                       if ($submitBtn) $submitBtn.prop('disabled', false).text(originalText);
                   }
               },
               error: (xhr) => {
                   this.paymentInProgress = false;
                   const errorEl = provider === 'stripe' ? this.$id('card-errors') : this.$id('paypal-message');
                   $(errorEl).text('Connection error. Please try again.').show();
                   if ($submitBtn) $submitBtn.prop('disabled', false).text(originalText);
                   this.log('Subscription AJAX error', xhr.responseText);
               }
           });
       },

       onPaymentSuccess: function(provider, transactionId, amount, form, $submitBtn, originalText, methodName) {
           this.paymentCompleted = true;
           this.paymentTransactionId = transactionId;

           try {
               sessionStorage.setItem('walletup_gateway_payment', JSON.stringify({
                   completed: true,
                   provider: provider,
                   transactionId: transactionId,
                   amount: amount,
                   timestamp: Date.now(),
               }));
           } catch (e) { /* sessionStorage unavailable */ }

           const $form = form ? $(form) : (this.currentFormId
               ? $('form.wpforms-form[data-formid="' + this.currentFormId + '"]')
               : $('form.wpforms-form').first());
           $form.find('input[name^="wallet_up_"]').remove();
           $form.append(`<input type="hidden" name="wallet_up_payment_method" value="${provider}">`);
           $form.append(`<input type="hidden" name="wallet_up_payment_amount" value="${amount}">`);
           $form.append(`<input type="hidden" name="wallet_up_payment_transaction_id" value="${transactionId}">`);
           $form.append(`<input type="hidden" name="wallet_up_payment_status" value="completed">`);
           const displayMethod = methodName || (provider === 'stripe' ? 'Card' : 'PayPal');
           $form.append(`<input type="hidden" name="wallet_up_payment_method_name" value="${displayMethod}">`);
           $form.append(`<input type="hidden" name="wallet_up_payment_summary" value="Payment via ${displayMethod} for $${parseFloat(amount).toFixed(2)} (Txn: ${transactionId})">`);

           this.log('Payment success', { provider, transactionId, amount });

           if (provider === 'stripe' && form) {
               if ($submitBtn) $submitBtn.prop('disabled', false).text(originalText);
               setTimeout(() => $form.trigger('submit'), 100);
           }
       },

       processPayment: function(form) {
           if (this.activeGateway === 'stripe') {
               this.processStripePayment(form);
           }
       },
   };

   $(document).ready(function() {
       WalletUpGateways.init();
   });

   window.WalletUpGateways = WalletUpGateways;

})(jQuery);