(function($) {
    'use strict';

    /**
     * Simple debounce implementation to remove the Lodash dependency
     * @param {Function} func - The function to debounce
     * @param {number} wait - The wait time in milliseconds
     * @returns {Function} - The debounced function
     */
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    class WalletUpProQRPayer {
        constructor(container) {
            this.container = $(container);
            this.qrContainers = this.container.find('.qr-method-container');
            this.amountInput = this.container.find('.wallet-up-amount-input');
            this.presetButtons = this.container.find('.preset-amount');
            this.fixedAmountDisplay = this.container.find('.amount-value');
            this.isFixedAmount = this.fixedAmountDisplay.length > 0; // Detect if using fixed amount
            
            if (this.isFixedAmount) {
                const displayAmount = this.fixedAmountDisplay.text().replace(/[^0-9.]/g, '');
                this.currentAmount = displayAmount || this.container.data('amount') || '0.00';
            } else {
                this.currentAmount = this.amountInput.val() || this.container.data('amount') || '0.00';
            }
            
            this.loadingTimers = new Map();
            this.qrCache = new Map();
            
            this.init();
        }

        init() {
            this.initEventListeners();
            this.loadInitialQRCodes();
        }

        initEventListeners() {
            if (!this.isFixedAmount) {
                this.presetButtons.on('click', (e) => {
                    e.preventDefault(); // Prevent default to ensure consistency
                    const amount = $(e.currentTarget).data('amount');
                    if (!amount && amount !== 0) { // Check for null/undefined but allow 0
                        console.warn('Invalid preset amount:', amount);
                        return;
                    }
                    this.handleAmountChange(amount);
                    this.presetButtons.removeClass('selected');
                    $(e.currentTarget).addClass('selected');
                });

                if (this.amountInput.length) {
                    this.amountInput
                        .on('input', debounce((e) => this.handleAmountInput(e), 500))
                        .on('blur', (e) => this.validateAmount(e));
                }
            }
        }

        handleAmountInput(e) {
            let value = e.target.value.replace(/[^0-9.]/g, '');
            if (value.match(/\./g)?.length > 1) {
                value = value.substring(0, value.lastIndexOf('.'));
            }
            if (value.includes('.')) {
                const parts = value.split('.');
                value = `${parts[0]}.${parts[1].substring(0, 2)}`;
            }
            e.target.value = value || '0.00'; // Fallback to '0.00' if empty
            this.presetButtons.removeClass('selected');
            this.handleAmountChange(e.target.value);
        }

        validateAmount(e) {
            const value = parseFloat(e.target.value) || 0;
            const min = parseFloat(e.target.min) || 0;
            const max = parseFloat(e.target.max) || Infinity;
            
            let finalValue = value;
            if (isNaN(value) || value < min) finalValue = min;
            else if (value > max) finalValue = max;
            
            e.target.value = finalValue.toFixed(2);
            this.handleAmountChange(finalValue);
        }

        async handleAmountChange(newAmount) {
            const stringAmount = String(parseFloat(newAmount).toFixed(2));
            if (this.currentAmount === stringAmount) return;

            this.currentAmount = stringAmount;
            
            if (this.amountInput.length) {
                this.amountInput.val(newAmount);
            }

            await this.loadQRCodes(newAmount);
        }

        loadInitialQRCodes() {
            let initialAmount;
            
            if (this.isFixedAmount) {
                initialAmount = this.fixedAmountDisplay.length ? 
                    this.fixedAmountDisplay.text().replace(/[^0-9.]/g, '') : 
                    this.container.data('amount');
            } else {
                initialAmount = this.amountInput.length ? 
                    this.amountInput.val() : 
                    this.container.data('amount');
            }
            
            if (initialAmount === undefined || initialAmount === '') {
                initialAmount = '0.00';
            }
            
            const numAmount = parseFloat(initialAmount);
            if (!isNaN(numAmount) && numAmount >= 0) {
                this.currentAmount = String(numAmount.toFixed(2));
                this.loadQRCodes(this.currentAmount);
            } else {
                console.error('Invalid initial amount:', initialAmount);
                this.currentAmount = '0.00';
                this.loadQRCodes('0.00');
            }
        }

        async loadQRCodes(amount) {
            this.qrContainers.each(async (_, container) => {
                const $container = $(container);
                const method = $container.data('method');
                const $placeholder = $container.find('.qr-placeholder');
                const cacheKey = `${method}-${amount}`;

                if (!method) {
                    console.warn('Missing payment method for container:', $container);
                    this.showError($placeholder, new Error('No payment method specified'));
                    return;
                }

                if (this.qrCache.has(cacheKey)) {
                    $placeholder.html(this.qrCache.get(cacheKey));
                    this.attachQRClickHandler($placeholder);
                    return;
                }

                this.showLoading($placeholder);

                try {
                    const response = await $.ajax({
                        url: walletUpData.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'wallet_up_process_payer',
                            nonce: walletUpData.nonce || this.container.data('nonce'),
                            method: method,
                            amount: parseFloat(amount).toFixed(2),
                            payer_id: this.container.data('payerId'),
                            is_fixed_amount: this.isFixedAmount // Send flag to indicate if this is fixed amount
                        },
                        dataType: 'json' // Ensure JSON response
                    });

                    if (response && response.success) {
                        const responseData = response.data || {};
                        const qrHtml = responseData.qrHtml || '';
                        
                        if (!qrHtml) {
                            throw new Error('No QR code data returned');
                        }
                        
                        this.qrCache.set(cacheKey, qrHtml);
                        $placeholder.html(qrHtml);

                        const redirectUrl = responseData.redirectUrl || '';
                        this.attachQRClickHandler($placeholder, redirectUrl);
                    } else {
                        const errorMsg = response?.data?.message || response?.message || 'Failed to generate QR code';
                        throw new Error(errorMsg === 'Payment method not configured' 
                            ? `${method} account is not set up. Please contact support.` 
                            : errorMsg);
                    }
                } catch (error) {
                    console.error('QR code loading error for method ' + method + ':', error);
                    this.showError($placeholder, error);
                }
            });
        }

        attachQRClickHandler($placeholder, redirectUrl = '') {
            const $img = $placeholder.find('img');
            const $link = $placeholder.find('a');
            
            if (redirectUrl) {
                if ($link.length) {
                    $link.attr('href', redirectUrl);
                } else if ($img.length) {
                    $img.css('cursor', 'pointer').on('click', () => {
                        const $overlay = $('#redirect-overlay');
                        if ($overlay.length) {
                            const methodName = $img.closest('.qr-method-container').find('.method-title').text().trim();
                            $overlay.find('.redirect-method').text(methodName);
                            $overlay.css('display', 'flex');
                            setTimeout(() => {
                                window.open(redirectUrl, '_blank');
                            }, 500);
                        } else {
                            window.open(redirectUrl, '_blank');
                        }
                    });
                }
            }
        }

        showLoading($placeholder) {
            $placeholder.html(`
                <div class="qr-loading">
                    <div class="qr-spinner"></div>
                    <p>Loading QR Code...</p>
                </div>
            `);
        }

        showError($placeholder, error) {
            const errorMessage = error.message || 'Error loading QR code';
            $placeholder.html(`
                <div class="qr-error">
                    <p>${errorMessage}</p>
                    <button class="retry-button">Retry</button>
                </div>
            `);

            $placeholder.find('.retry-button').on('click', (e) => {
                e.preventDefault();
                this.loadQRCodes(this.currentAmount);
            });
        }
    }

    $(document).ready(function() {
        try {
            const $containers = $('.wallet-up-payer-container.qr-only-mode');
            if ($containers.length === 0) {
                return;
            }

            const globalNonce = window.walletUpData ? window.walletUpData.nonce : null;
            
            if (!window.walletUpData || !window.walletUpData.ajaxUrl) {
                console.error('Missing required global walletUpData. Make sure it\'s properly initialized.');
            }

            $containers.each(function(index, element) {
                try {
                    new WalletUpProQRPayer(element);
                } catch (error) {
                    console.error(`Failed to initialize QR payer container ${index}:`, error);
                }
            });
        } catch (generalError) {
            console.error('General initialization error:', generalError);
        }
    });

})(jQuery);