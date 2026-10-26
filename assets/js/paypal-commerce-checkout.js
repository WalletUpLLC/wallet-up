(function ($) {
    'use strict';

    if (typeof walletUpPayPal === 'undefined' || !window.paypal) {
        return;
    }

    var BUTTONS_CONTAINER = 'walletup-paypal-buttons-classic';
    var CARDFIELDS_CONTAINER = 'walletup-paypal-card-fields-classic';
    var paymentField = walletUpPayPal.paymentField || 'walletup_paypal_order_id';
    var capturedOrderId = '';
    var buttonsInstance = null;
    var cardFieldsInstance = null;

    function postForm(url, data) {
        var body = Object.keys(data).map(function (k) {
            return encodeURIComponent(k) + '=' + encodeURIComponent(data[k]);
        }).join('&');
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        }).then(function (r) { return r.json(); });
    }

    function createPayPalOrder() {
        return postForm(walletUpPayPal.createOrderUrl, {
            action: 'walletup_paypal_create_order',
            nonce: walletUpPayPal.createOrderNonce
        }).then(function (res) {
            if (!res || !res.success || !res.data || !res.data.id) {
                var msg = (res && res.data && res.data.message) || 'Could not start PayPal payment.';
                throw new Error(msg);
            }
            return res.data.id;
        });
    }

    function capturePayPalOrder(orderId) {
        return postForm(walletUpPayPal.captureOrderUrl, {
            action: 'walletup_paypal_capture_order',
            nonce: walletUpPayPal.captureOrderNonce,
            order_id: orderId
        }).then(function (res) {
            if (!res || !res.success) {
                var msg = (res && res.data && res.data.message) || 'PayPal capture failed.';
                throw new Error(msg);
            }
            return res.data;
        });
    }

    function setHiddenField(value) {
        capturedOrderId = value || '';
        var input = document.getElementById(paymentField);
        if (input) {
            input.value = capturedOrderId;
        }
    }

    function autoSubmitPlaceOrder() {
        setTimeout(function () {
            var btn = document.querySelector('button#place_order:not([disabled])');
            if (btn) {
                btn.click();
            }
        }, 50);
    }

    function renderButtons() {
        var btnNode = document.getElementById(BUTTONS_CONTAINER);
        if (!btnNode) { return; }
        try { if (buttonsInstance && buttonsInstance.close) { buttonsInstance.close(); } } catch (e) {}
        buttonsInstance = null;
        btnNode.innerHTML = '';

        try {
            buttonsInstance = window.paypal.Buttons({
                style: { layout: 'vertical', shape: 'rect', label: 'paypal' },
                createOrder: function () { return createPayPalOrder(); },
                onApprove: function (data) {
                    return capturePayPalOrder(data.orderID).then(function () {
                        setHiddenField(data.orderID);
                        autoSubmitPlaceOrder();
                    }).catch(function (err) {
                        alert(err && err.message ? err.message : 'PayPal capture failed.');
                    });
                },
                onError: function (err) {
                    if (err && err.message) {
                        if (window.console && window.console.error) {
                            window.console.error('PayPal error:', err.message);
                        }
                    }
                },
                onCancel: function () { setHiddenField(''); }
            });
            if (buttonsInstance.isEligible && !buttonsInstance.isEligible()) {
                btnNode.style.display = 'none';
            } else {
                buttonsInstance.render('#' + BUTTONS_CONTAINER);
            }
        } catch (e) {
        }
    }

    function renderCardFields() {
        var cardNode = document.getElementById(CARDFIELDS_CONTAINER);
        if (!cardNode || !window.paypal.CardFields) { return; }
        try { if (cardFieldsInstance && cardFieldsInstance.close) { cardFieldsInstance.close(); } } catch (e) {}
        cardFieldsInstance = null;
        cardNode.innerHTML = '';

        try {
            cardFieldsInstance = window.paypal.CardFields({
                createOrder: function () { return createPayPalOrder(); },
                onApprove: function (data) {
                    return capturePayPalOrder(data.orderID).then(function () {
                        setHiddenField(data.orderID);
                        var btn = document.querySelector('.walletup-paypal-card-submit');
                        if (btn) { btn.textContent = 'Completing order…'; }
                        autoSubmitPlaceOrder();
                    }).catch(function (err) {
                        alert(err && err.message ? err.message : 'PayPal capture failed.');
                        var btn = document.querySelector('.walletup-paypal-card-submit');
                        if (btn) { btn.disabled = false; btn.textContent = 'Pay with card'; }
                    });
                },
                onError: function (err) {
                    if (err && err.message) {
                        alert(err.message);
                    }
                    var btn = document.querySelector('.walletup-paypal-card-submit');
                    if (btn) { btn.disabled = false; btn.textContent = 'Pay with card'; }
                }
            });

            if (!(cardFieldsInstance.isEligible && cardFieldsInstance.isEligible())) {
                cardNode.style.display = 'none';
                return;
            }

            cardNode.innerHTML =
                '<div class="walletup-paypal-card-name" style="margin-bottom:8px;"></div>' +
                '<div class="walletup-paypal-card-number" style="margin-bottom:8px;"></div>' +
                '<div style="display:flex;gap:8px;">' +
                '<div class="walletup-paypal-card-expiry" style="flex:1;"></div>' +
                '<div class="walletup-paypal-card-cvv" style="flex:1;"></div>' +
                '</div>' +
                '<button type="button" class="walletup-paypal-card-submit" style="margin-top:12px;padding:10px 16px;background:#2c2e2f;color:#fff;border:0;border-radius:4px;cursor:pointer;">Pay with card</button>';

            cardFieldsInstance.NameField({ style: { input: { 'font-size': '16px' } } }).render('.walletup-paypal-card-name');
            cardFieldsInstance.NumberField({ style: { input: { 'font-size': '16px' } } }).render('.walletup-paypal-card-number');
            cardFieldsInstance.ExpiryField({ style: { input: { 'font-size': '16px' } } }).render('.walletup-paypal-card-expiry');
            cardFieldsInstance.CVVField({ style: { input: { 'font-size': '16px' } } }).render('.walletup-paypal-card-cvv');

            var submitBtn = cardNode.querySelector('.walletup-paypal-card-submit');
            if (submitBtn) {
                submitBtn.addEventListener('click', function () {
                    var originalText = submitBtn.textContent || 'Pay with card';
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Processing…';
                    cardFieldsInstance.submit().catch(function (err) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = originalText;
                        alert(err && err.message ? err.message : 'Card submission failed.');
                    });
                });
            }
        } catch (e) {
            cardNode.style.display = 'none';
        }
    }

    function renderAll() {
        renderButtons();
        renderCardFields();
    }

    $(document).ready(function () { renderAll(); });

    $(document.body).on('updated_checkout', function () {
        renderAll();
    });

    var $form = $('form.checkout, form#order_review');
    $form.on('checkout_place_order_wallet_up_paypal_commerce', function () {
        var input = document.getElementById(paymentField);
        if (input && input.value) {
            return true;
        }
        if (cardFieldsInstance && typeof cardFieldsInstance.submit === 'function') {
            cardFieldsInstance.submit().catch(function (err) {
                alert(err && err.message ? err.message : 'Card submission failed.');
            });
            return false;
        }
        alert('Click the PayPal button above to authorize, or fill in your card details to pay by card.');
        return false;
    });
})(jQuery);
