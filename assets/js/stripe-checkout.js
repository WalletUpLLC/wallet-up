(function($) {
    'use strict';

    if (typeof walletUpStripe === 'undefined' || !walletUpStripe.publishableKey) {
        return;
    }

    var stripe = walletUpStripe.connectedAccount
        ? Stripe(walletUpStripe.publishableKey, { stripeAccount: walletUpStripe.connectedAccount })
        : Stripe(walletUpStripe.publishableKey);
    var usePaymentElement = !!walletUpStripe.paymentElement;
    var MOUNT = usePaymentElement ? '#walletup-stripe-payment-element' : '#walletup-stripe-card-element';

    var elements = usePaymentElement
        ? stripe.elements({
            mode: 'payment',
            amount: Math.max(parseInt(walletUpStripe.amount, 10) || 0, 1),
            currency: walletUpStripe.currency || 'usd',
            paymentMethodCreation: 'manual'
        })
        : stripe.elements();

    var cardElement = usePaymentElement
        ? elements.create('payment')
        : elements.create('card', {
        style: {
            base: {
                fontSize: '16px',
                color: '#32325d',
                fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
                '::placeholder': { color: '#aab7c4' }
            },
            invalid: { color: '#e74c3c' }
        }
    });

    var mounted = false;

    function mountCard() {
        var container = document.querySelector(MOUNT);
        if (container && !mounted) {
            try { cardElement.unmount(); } catch (e) { /* wasn't mounted */ }
            try {
                cardElement.mount(MOUNT);
                mounted = true;
            } catch (e) {
                var errorEl = document.getElementById('walletup-stripe-card-errors');
                if (errorEl) {
                    errorEl.textContent = 'Failed to load card form. Please refresh the page.';
                }
            }
        }
    }

    $(document).ready(function() {
        mountCard();
    });

    function currentAmountMinor() {
        var el = document.querySelector('.order-total .amount, .order-total bdi');
        if (!el) {
            return parseInt(walletUpStripe.amount, 10) || 0;
        }
        var digits = el.textContent.replace(/[^0-9.,]/g, '').replace(/,/g, '');
        var major = parseFloat(digits);
        return isNaN(major) ? (parseInt(walletUpStripe.amount, 10) || 0) : Math.round(major * 100);
    }

    $(document.body).on('updated_checkout', function() {
        if (usePaymentElement) {
            try { elements.update({ amount: Math.max(currentAmountMinor(), 1) }); } catch (err) { /* element not ready */ }
        }
        mounted = false;
        mountCard();
    });

    cardElement.on('change', function(event) {
        var errorEl = document.getElementById('walletup-stripe-card-errors');
        if (errorEl) {
            errorEl.textContent = event.error ? event.error.message : '';
        }
    });

    var form = $('form.checkout, form#order_review');

    function showStripeError(msg) {
        var errorEl = document.getElementById('walletup-stripe-card-errors');
        if (errorEl) { errorEl.textContent = msg; }
        form.removeClass('processing').unblock();
    }

    form.on('checkout_place_order_wallet_up_card', function() {
        var pmField = document.getElementById('walletup_stripe_payment_method_id');
        var intentIdField = document.getElementById('walletup_stripe_intent_id');

        if ((pmField && pmField.value) || (intentIdField && intentIdField.value)) {
            return true;
        }

        if (usePaymentElement) {
            elements.submit().then(function(sub) {
                if (sub.error) { showStripeError(sub.error.message); return; }
                var body = new URLSearchParams();
                body.append('action', 'wallet_up_stripe_block_create_intent');
                if (walletUpStripe.confirmNonce) { body.append('nonce', walletUpStripe.confirmNonce); }
                fetch(walletUpStripe.ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                }).then(function(r){ return r.json(); }).then(function(j){
                    if (!j || !j.success || !j.data || !j.data.client_secret || !j.data.intent_id) {
                        showStripeError((j && j.data && j.data.message) || 'Could not start payment. Please try again.');
                        return;
                    }
                    var intentId = j.data.intent_id;
                    if (j.data.amount) { try { elements.update({ amount: parseInt(j.data.amount, 10), currency: j.data.currency || (walletUpStripe.currency || 'usd') }); } catch (e) {} }
                    stripe.confirmPayment({
                        elements: elements,
                        clientSecret: j.data.client_secret,
                        confirmParams: { return_url: window.location.href.split('#')[0] },
                        redirect: 'if_required'
                    }).then(function(result){
                        if (result.error) { showStripeError(result.error.message); return; }
                        if (intentIdField) { intentIdField.value = intentId; }
                        form.submit();
                    });
                }).catch(function(){ showStripeError('Could not start payment. Please try again.'); });
            });
            return false;
        }

        var billing = {
            name: ($('#billing_first_name').val() + ' ' + $('#billing_last_name').val()).trim(),
            email: $('#billing_email').val(),
            address: {
                line1: $('#billing_address_1').val(),
                city: $('#billing_city').val(),
                state: $('#billing_state').val(),
                postal_code: $('#billing_postcode').val(),
                country: $('#billing_country').val()
            }
        };
        stripe.createPaymentMethod({ type: 'card', card: cardElement, billing_details: billing }).then(function(result) {
            if (result.error) { showStripeError(result.error.message); return; }
            if (pmField) { pmField.value = result.paymentMethod.id; }
            form.submit();
        });

        return false;
    });

    (function() {
        var qp = new URLSearchParams(window.location.search);
        var resumeIntent = qp.get('payment_intent');
        var redirectStatus = qp.get('redirect_status');
        if (usePaymentElement && resumeIntent && (redirectStatus === 'succeeded' || redirectStatus === 'processing' || redirectStatus === 'pending')) {
            var resumeField = document.getElementById('walletup_stripe_intent_id');
            if (resumeField) { resumeField.value = resumeIntent; }
            setTimeout(function(){ form.submit(); }, 50);
        }
    })();

    var urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('walletup_stripe_3ds') === '1') {
        var clientSecret = urlParams.get('client_secret');
        if (clientSecret) {
            var settle = usePaymentElement
                ? stripe.handleNextAction({ clientSecret: clientSecret })
                : stripe.handleCardAction(clientSecret);
            settle.then(function(result) {
                if (result.error) {
                    alert(result.error.message);
                    window.location.href = urlParams.get('return_url') || '/checkout/';
                } else {
                    $.post(walletUpStripe.ajaxUrl, {
                        action: 'wallet_up_stripe_confirm_payment',
                        nonce: walletUpStripe.nonce,
                        payment_intent_id: result.paymentIntent.id,
                        order_id: urlParams.get('order_id')
                    }, function(response) {
                        if (response.success) {
                            window.location.href = response.data.redirect;
                        } else {
                            alert(response.data.message || 'Payment confirmation failed');
                        }
                    });
                }
            });
        }
    }
})(jQuery);
