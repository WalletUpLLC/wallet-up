(function() {
    'use strict';

    const createProcessingOverlay = () => {
        const overlay = document.createElement('div');
        overlay.className = 'processing-overlay';
        overlay.innerHTML = `
            <div class="processing-content">
                <div class="spinner"></div>
                <p class="processing-message">${_.escape(walletUpDonations.i18n.processing)}</p>
            </div>
        `;
        return overlay;
    };

    const validateAmount = (amount, min, max, isUserDefined) => {
        const value = parseFloat(amount);
        if (isNaN(value) || value <= 0) return false;

        if (isUserDefined) {
            return value >= parseFloat(min) && value <= parseFloat(max);
        }
        return value === parseFloat(min);
    };

    const formatAmount = (amount) => {
        const value = parseFloat(amount);
        return isNaN(value) ? '0.00' : value.toFixed(2);
    };

    const disablePaymentMethods = (container, disable = true) => {
        const buttons = container.querySelectorAll('.method-button');
        buttons.forEach(button => {
            button.disabled = disable;
            button.style.opacity = disable ? '0.5' : '1';
            button.style.cursor = disable ? 'not-allowed' : 'pointer';
        });
    };

    const showProcessingState = (container) => {
        disablePaymentMethods(container);
        const existingIndicator = container.querySelector('.processing-indicator');
        if (existingIndicator) return;

        const processingIndicator = document.createElement('div');
        processingIndicator.className = 'processing-indicator';
        processingIndicator.innerHTML = `
            <div class="processing-spinner"></div>
            <p>${_.escape('Processing payment request...')}</p>
        `;
        container.appendChild(processingIndicator);
    };

    const hideProcessingState = (container) => {
        disablePaymentMethods(container, false);
        const processingIndicator = container.querySelector('.processing-indicator');
        if (processingIndicator) processingIndicator.remove();
    };

    const showError = (message) => {
        const errorDiv = document.createElement('div');
        errorDiv.className = 'wallet-up-error';
        errorDiv.textContent = _.escape(message);
        errorDiv.style.cssText = `
            color: #721c24;
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            padding: 10px;
            margin: 10px 0;
            border-radius: 4px;
        `;

        const container = document.querySelector('.wallet-up-donation-page, .wallet-up-donation-container');
        if (container) {
            container.prepend(errorDiv);
            setTimeout(() => errorDiv.remove(), 5000);
        }
    };

    const initDonationPage = () => {
        const container = document.querySelector('.wallet-up-donation-page, .wallet-up-donation-container');
        if (!container) {
            console.warn('Donation container not found');
            return;
        }

        const amountInput = container.querySelector('.wallet-up-amount-input');
        const methodButtons = container.querySelectorAll('.method-button');
        const isUserDefinedAmount = !!amountInput;
        const donationId = container.dataset.donationId || '';

        if (!donationId) {
            console.warn('Missing donation ID');
            return;
        }

        if (amountInput) {
            amountInput.addEventListener('input', (e) => {
                let value = e.target.value.replace(/[^0-9.]/g, '');
                const decimalPoints = value.match(/\./g);
                if (decimalPoints && decimalPoints.length > 1) {
                    value = value.substring(0, value.lastIndexOf('.'));
                }
                if (value.includes('.')) {
                    const parts = value.split('.');
                    value = `${parts[0]}.${parts[1].substring(0, 2)}`;
                }
                e.target.value = value;
            });

            amountInput.addEventListener('blur', (e) => {
                const min = parseFloat(e.target.min) || 0;
                const max = parseFloat(e.target.max) || Infinity;
                const value = parseFloat(e.target.value) || 0;

                if (value < min) {
                    e.target.value = min.toFixed(2);
                } else if (value > max) {
                    e.target.value = max.toFixed(2);
                } else if (value > 0) {
                    e.target.value = value.toFixed(2);
                } else {
                    e.target.value = min.toFixed(2);
                }
            });

            amountInput.addEventListener('keydown', (e) => {
                const allowedKeys = [8, 9, 13, 27, 46, 110, 190]; // Backspace, tab, enter, escape, delete, decimal
                const isNumber = (e.key >= '0' && e.key <= '9');
                const isCtrl = e.ctrlKey || e.metaKey;
                if (!isNumber && !allowedKeys.includes(e.keyCode) && !isCtrl) {
                    e.preventDefault();
                }
            });
        }

        methodButtons.forEach(button => {
            button.addEventListener('click', async (e) => {
                e.preventDefault();

                const method = button.dataset.method;
                if (!method) {
                    console.warn('Missing payment method on button:', button);
                    return;
                }

                let amount;
                if (isUserDefinedAmount) {
                    amount = amountInput.value;
                    const min = amountInput.min || '0';
                    const max = amountInput.max || 'Infinity';
                    if (!validateAmount(amount, min, max, true)) {
                        showError(`Please enter an amount between ${formatAmount(min)} and ${formatAmount(max)}`);
                        return;
                    }
                } else {
                    const fixedAmount = container.dataset.amount;
                    if (!fixedAmount) {
                        showError('No donation amount specified');
                        return;
                    }
                    amount = fixedAmount;
                    if (!validateAmount(amount, fixedAmount, fixedAmount, false)) {
                        showError('Invalid fixed donation amount');
                        return;
                    }
                }

                showProcessingState(container);

                try {
                    const response = await fetch(walletUpDonations.ajaxUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'Accept': 'application/json'
                        },
                        body: new URLSearchParams({
                            action: 'wallet_up_process_donation',
                            nonce: walletUpDonations.nonce,
                            method: method,
                            amount: formatAmount(amount),
                            donation_id: donationId
                        }),
                        credentials: 'same-origin',
                        signal: AbortSignal.timeout(10000) // 10-second timeout
                    });

                    const data = await response.json();

                    if (!response.ok) {
                        throw new Error(data.data?.message || `HTTP error ${response.status}`);
                    }

                    if (data.success && data.data.redirectUrl) {
                        const overlay = createProcessingOverlay();
                        document.body.appendChild(overlay);

                        const processingMessage = overlay.querySelector('.processing-message');
                        processingMessage.textContent = _.escape(walletUpDonations.i18n.redirecting);

                        setTimeout(() => {
                            window.location.href = data.data.redirectUrl;
                        }, 1500);
                    } else {
                        throw new Error(data.data?.message || walletUpDonations.i18n.error);
                    }
                } catch (error) {
                    console.error('Donation error:', error);
                    hideProcessingState(container);
                    showError(error.message || walletUpDonations.i18n.error);
                }
            });
        });
    };

    document.addEventListener('DOMContentLoaded', () => {
        try {
            initDonationPage();
        } catch (error) {
            console.error('Error initializing donation page:', error);
        }
    });

    document.addEventListener('walletup_donation_loaded', () => {
        try {
            initDonationPage();
        } catch (error) {
            console.error('Error re-initializing donation page:', error);
        }
    });

    window.addEventListener('popstate', () => {
        const overlay = document.querySelector('.processing-overlay');
        if (overlay) {
            overlay.remove();
        }
    });

})();