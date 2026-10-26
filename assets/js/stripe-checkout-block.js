(function () {
    'use strict';

    var el = window.wp.element.createElement;
    var useState = window.wp.element.useState;
    var useEffect = window.wp.element.useEffect;
    var useRef = window.wp.element.useRef;
    var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
    var getSetting = window.wc.wcSettings.getSetting;

    var settings = getSetting('wallet_up_card_data', {});
    var publishableKey = settings.publishableKey || '';
    var connectedAccount = settings.connectedAccount || '';
    var title = settings.title || 'Credit/Debit Card';
    var description = settings.description || '';
    var testMode = settings.testMode || false;
    var usePaymentElement = !!settings.paymentElement;

    if (!publishableKey) {
        return;
    }

    var stripe = null;
    var elements = null;
    var cardElement = null;
    var cardCreationError = null;
    var pendingClientSecret = '';

    function getCardElement() {
        if (cardCreationError) {
            return null;
        }
        if (!window.Stripe) {
            cardCreationError = 'Stripe.js failed to load. Please refresh the page.';
            return null;
        }
        try {
            if (!stripe) {
                stripe = connectedAccount
                    ? window.Stripe(publishableKey, { stripeAccount: connectedAccount })
                    : window.Stripe(publishableKey);
            }
            if (!elements) {
                elements = usePaymentElement
                    ? stripe.elements({
                        mode: 'payment',
                        amount: Math.max(parseInt(settings.amount, 10) || 0, 1),
                        currency: settings.currency || 'usd'
                    })
                    : stripe.elements();
            }
            if (!cardElement) {
                cardElement = usePaymentElement ? elements.create('payment') : elements.create('card', {
                    style: {
                        base: {
                            fontSize: '16px',
                            color: '#32325d',
                            fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
                            '::placeholder': { color: '#aab7c4' }
                        },
                        invalid: { color: '#fa755a' }
                    }
                });
            }
            return cardElement;
        } catch (e) {
            cardCreationError = e && e.message ? e.message : String(e);
            return null;
        }
    }

    function getStripe() {
        return stripe;
    }

    function StripeCardContent(props) {
        var eventRegistration = props.eventRegistration;
        var emitResponse = props.emitResponse;
        var cardElementRef = useRef(null);
        var errorState = useState('');
        var error = errorState[0];
        var setError = errorState[1];

        useEffect(function () {
            var element = getCardElement();
            if (!element) {
                setError(cardCreationError || 'Card form unavailable. Please refresh the page.');
                return;
            }

            var changeHandler = function (event) {
                setError(event.error ? event.error.message : '');
            };

            var interval = setInterval(function () {
                var container = document.getElementById('walletup-stripe-card-block');
                if (!container) {
                    return;
                }
                clearInterval(interval);
                try {
                    try { element.unmount(); } catch (e) { /* wasn't mounted yet */ }
                    element.mount(container);
                    cardElementRef.current = element;
                    element.on('change', changeHandler);
                } catch (e) {
                    setError('Failed to attach card form: ' + (e && e.message ? e.message : e));
                }
            }, 100);

            return function () {
                clearInterval(interval);
                try { element.off('change', changeHandler); } catch (e) {}
                try { element.unmount(); } catch (e) {}
            };
        }, []);

        useEffect(function () {
            if (!eventRegistration || !emitResponse) return;

            var unsubscribe = eventRegistration.onPaymentSetup(function () {
                var s = getStripe();
                if (!s) {
                    return { type: emitResponse.responseTypes.ERROR, message: 'Payment form not ready. Please refresh and try again.' };
                }
                if (usePaymentElement) {
                    return elements.submit().then(function (sub) {
                        if (sub.error) {
                            return { type: emitResponse.responseTypes.ERROR, message: sub.error.message };
                        }
                        var body = new window.URLSearchParams();
                        body.append('action', 'wallet_up_stripe_block_create_intent');
                        if (settings.confirmNonce) { body.append('nonce', settings.confirmNonce); }
                        return window.fetch(settings.ajaxUrl, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: body.toString()
                        }).then(function (r) { return r.json(); }).then(function (j) {
                            if (!j || !j.success || !j.data || !j.data.client_secret || !j.data.intent_id) {
                                return { type: emitResponse.responseTypes.ERROR, message: (j && j.data && j.data.message) || 'Could not start payment. Please try again.' };
                            }
                            pendingClientSecret = j.data.client_secret;
                            if (j.data.amount) {
                                try { elements.update({ amount: parseInt(j.data.amount, 10), currency: j.data.currency || (settings.currency || 'usd') }); } catch (e) {}
                            }
                            return {
                                type: emitResponse.responseTypes.SUCCESS,
                                meta: { paymentMethodData: { walletup_stripe_intent_id: j.data.intent_id } }
                            };
                        });
                    });
                }
                if (!cardElementRef.current) {
                    return { type: emitResponse.responseTypes.ERROR, message: 'Card form not ready. Please refresh and try again.' };
                }
                return s.createPaymentMethod({ type: 'card', card: cardElementRef.current }).then(function (result) {
                    if (result.error) {
                        return { type: emitResponse.responseTypes.ERROR, message: result.error.message };
                    }
                    return {
                        type: emitResponse.responseTypes.SUCCESS,
                        meta: { paymentMethodData: { walletup_stripe_payment_method_id: result.paymentMethod.id } }
                    };
                });
            });

            var successEvent = eventRegistration.onCheckoutSuccess || eventRegistration.onCheckoutAfterProcessingWithSuccess;
            var unsubscribeSuccess = (usePaymentElement && successEvent)
                ? successEvent(function (data) {
                    if (!pendingClientSecret) {
                        return true;
                    }
                    var container = data && data.processingResponse && data.processingResponse.paymentDetails;
                    var redirectUrl = (container && container.redirect) || (data && data.redirectUrl) || window.location.href;
                    var s = getStripe();
                    if (!s) {
                        return { type: emitResponse.responseTypes.ERROR, message: 'Payment could not be confirmed. Please try again.' };
                    }
                    var cs = pendingClientSecret;
                    return s.confirmPayment({
                        elements: elements,
                        clientSecret: cs,
                        confirmParams: { return_url: redirectUrl },
                        redirect: 'if_required'
                    }).then(function (result) {
                        if (result.error) {
                            return { type: emitResponse.responseTypes.ERROR, message: result.error.message };
                        }
                        pendingClientSecret = '';
                        return { type: emitResponse.responseTypes.SUCCESS };
                    });
                })
                : null;

            return function () {
                unsubscribe();
                if (unsubscribeSuccess) unsubscribeSuccess();
            };
        }, [eventRegistration, emitResponse]);

        var children = [];

        if (description) {
            children.push(el('p', { key: 'desc', style: { marginBottom: '12px' } }, description));
        }

        if (testMode) {
            children.push(el('p', {
                key: 'test',
                style: {
                    background: '#fff3cd',
                    border: '1px solid #ffc107',
                    padding: '8px 12px',
                    borderRadius: '4px',
                    fontSize: '13px',
                    marginBottom: '12px',
                    color: '#856404'
                }
            }, 'TEST MODE — Use card 4242 4242 4242 4242, any future date, any CVC.'));
        }

        children.push(el('div', {
            key: 'card',
            id: 'walletup-stripe-card-block',
            style: {
                padding: '12px',
                border: '1px solid #d9d9d9',
                borderRadius: '4px',
                background: '#fff',
                minHeight: '24px'
            }
        }));

        if (error) {
            children.push(el('div', {
                key: 'error',
                style: { color: '#fa755a', marginTop: '8px', fontSize: '14px' },
                role: 'alert'
            }, error));
        }

        return el('div', { className: 'walletup-stripe-card-block' }, children);
    }

    registerPaymentMethod({
        name: 'wallet_up_card',
        label: el('span', null, [
            settings.icon && el('img', {
                key: 'icon',
                src: settings.icon,
                alt: '',
                className: 'walletupIconLabel',
                style: { width: '24px', height: '24px', marginRight: '8px', verticalAlign: 'middle' }
            }),
            title
        ]),
        content: el(StripeCardContent),
        edit: el('div', null, 'WalletUp Card Payments (Stripe)'),
        canMakePayment: function () { return true; },
        ariaLabel: title,
        supports: {
            features: settings.supports || ['products']
        }
    });
})();
