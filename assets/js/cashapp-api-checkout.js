(function ($) {
    'use strict';

    if (typeof walletUpCashAppApi === 'undefined') {
        return;
    }

    var applicationId = walletUpCashAppApi.applicationId || '';
    var locationId = walletUpCashAppApi.locationId || '';
    var currency = (walletUpCashAppApi.currency || 'USD').toUpperCase();

    if (!applicationId || !locationId) {
        return;
    }

    var ZERO_DECIMAL = ['BIF','CLP','DJF','GNF','JPY','KMF','KRW','MGA','PYG','RWF','UGX','VND','VUV','XAF','XOF','XPF'];

    var cashAppPay = null;
    var attached = false;
    var sourceId = '';

    function readCartTotal() {
        var $amt = $('.order-total .woocommerce-Price-amount').last();
        var raw = $amt.text() || '0';
        var n = parseFloat(raw.replace(/[^0-9.\-]/g, ''));
        return isNaN(n) ? 0 : n;
    }

    function totalToString(amount) {
        if (ZERO_DECIMAL.indexOf(currency) !== -1) {
            return String(Math.round(amount));
        }
        return amount.toFixed(2);
    }

    function setSourceId(value) {
        sourceId = value || '';
        $('#walletup_cashapp_source_id').val(sourceId);
    }

    function setError(msg) {
        var $err = $('#walletup-cashapp-pay-errors');
        if (!$err.length) {
            return;
        }
        $err.text(msg || '');
    }

    function destroyButton() {
        attached = false;
        if (cashAppPay) {
            try { cashAppPay.destroy(); } catch (e) {}
            cashAppPay = null;
        }
    }

    function mountButton() {
        var container = document.getElementById('walletup-cashapp-pay-classic');
        if (!container || attached || !window.Square || typeof window.Square.payments !== 'function') {
            return;
        }

        try {
            var payments = window.Square.payments(applicationId, locationId);
            var amount = readCartTotal();
            var req = payments.paymentRequest({
                countryCode: 'US',
                currencyCode: currency,
                total: { amount: totalToString(amount), label: 'Total' }
            });

            payments.cashAppPay(req, {
                redirectURL: window.location.href,
                referenceId: 'wc-order-pending'
            }).then(function (cap) {
                cashAppPay = cap;
                cap.addEventListener('ontokenization', function (event) {
                    var detail = event && event.detail ? event.detail : {};
                    var tokenResult = detail.tokenResult || {};
                    if (tokenResult.status === 'OK' && tokenResult.token) {
                        setSourceId(tokenResult.token);
                        setError('');
                    } else {
                        setSourceId('');
                        var msg = (tokenResult.errors && tokenResult.errors[0] && tokenResult.errors[0].message) || 'Cash App Pay tokenization failed.';
                        setError(msg);
                    }
                });
                return cap.attach('#walletup-cashapp-pay-classic');
            }).then(function () {
                attached = true;
            }).catch(function (e) {
                setError('Failed to load Cash App Pay: ' + (e && e.message ? e.message : e));
            });
        } catch (e) {
            setError('Cash App Pay init error: ' + (e && e.message ? e.message : e));
        }
    }

    $(document).ready(function () {
        mountButton();
    });

    $(document.body).on('updated_checkout', function () {
        destroyButton();
        mountButton();
    });

    var $form = $('form.checkout, form#order_review');
    $form.on('checkout_place_order_wallet_up_cashapp_api', function () {
        if (sourceId) {
            return true;
        }
        setError('Please tap "Pay with Cash App" above to authorize the payment.');
        $form.removeClass('processing').unblock();
        return false;
    });
})(jQuery);
