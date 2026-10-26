(function () {
    'use strict';

    var el = window.wp.element.createElement;
    var useEffect = window.wp.element.useEffect;
    var useRef = window.wp.element.useRef;
    var useState = window.wp.element.useState;
    var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
    var getSetting = window.wc.wcSettings.getSetting;

    var settings = getSetting('wallet_up_paypal_commerce_data', {});
    var title = settings.title || 'PayPal';
    var description = settings.description || '';
    var sandboxMode = !!settings.sandboxMode;
    var clientId = settings.clientId || '';
    var createOrderUrl = settings.createOrderUrl || '';
    var captureOrderUrl = settings.captureOrderUrl || '';
    var createOrderNonce = settings.createOrderNonce || '';
    var captureOrderNonce = settings.captureOrderNonce || '';
    var paymentField = settings.paymentField || 'walletup_paypal_order_id';

    var BUTTONS_CONTAINER = 'walletup-paypal-buttons-block';
    var CARDFIELDS_CONTAINER = 'walletup-paypal-card-fields-block';

    var moduleCapturedOrderId = { current: '' };
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
        return postForm(createOrderUrl, {
            action: 'walletup_paypal_create_order',
            nonce: createOrderNonce
        }).then(function (res) {
            if (!res || !res.success || !res.data || !res.data.id) {
                var msg = (res && res.data && res.data.message) || 'Could not start PayPal payment.';
                throw new Error(msg);
            }
            return res.data.id;
        });
    }

    function capturePayPalOrder(orderId) {
        return postForm(captureOrderUrl, {
            action: 'walletup_paypal_capture_order',
            nonce: captureOrderNonce,
            order_id: orderId
        }).then(function (res) {
            if (!res || !res.success) {
                var msg = (res && res.data && res.data.message) || 'PayPal capture failed.';
                throw new Error(msg);
            }
            return res.data;
        });
    }

    function safeClearContainer(id) {
        var node = document.getElementById(id);
        if (node) {
            node.innerHTML = '';
        }
    }

    function autoSubmitPlaceOrder() {
        setTimeout(function () {
            var btn = document.querySelector('button.wc-block-components-checkout-place-order-button');
            if (btn && !btn.disabled) {
                btn.click();
            }
        }, 50);
    }

    function PayPalCommerceContent(props) {
        var eventRegistration = props.eventRegistration;
        var emitResponse = props.emitResponse;
        var capturedOrderId = useRef('');
        var errState = useState('');
        var error = errState[0];
        var setError = errState[1];

        useEffect(function () {
            if (!window.paypal) {
                setError('PayPal SDK failed to load. Please refresh the page.');
                return;
            }

            try { if (buttonsInstance && buttonsInstance.close) { buttonsInstance.close(); } } catch (e) {}
            try { if (cardFieldsInstance && cardFieldsInstance.close) { cardFieldsInstance.close(); } } catch (e) {}
            buttonsInstance = null;
            cardFieldsInstance = null;
            safeClearContainer(BUTTONS_CONTAINER);
            safeClearContainer(CARDFIELDS_CONTAINER);

            var attempts = 0;
            var interval = setInterval(function () {
                attempts++;
                var btnNode = document.getElementById(BUTTONS_CONTAINER);
                if (!btnNode) {
                    if (attempts > 50) { clearInterval(interval); }
                    return;
                }
                clearInterval(interval);

                try {
                    buttonsInstance = window.paypal.Buttons({
                        style: { layout: 'vertical', shape: 'rect', label: 'paypal' },
                        createOrder: function () { return createPayPalOrder(); },
                        onApprove: function (data) {
                            return capturePayPalOrder(data.orderID).then(function () {
                                capturedOrderId.current = data.orderID;
                                moduleCapturedOrderId.current = data.orderID;
                                setError('');
                                autoSubmitPlaceOrder();
                            }).catch(function (err) {
                                setError(err && err.message ? err.message : 'PayPal capture failed.');
                            });
                        },
                        onError: function (err) {
                            setError(err && err.message ? err.message : 'PayPal error.');
                        },
                        onCancel: function () {
                            capturedOrderId.current = '';
                            moduleCapturedOrderId.current = '';
                        }
                    });
                    if (buttonsInstance.isEligible && !buttonsInstance.isEligible()) {
                        setError('PayPal is not available for this configuration.');
                    } else {
                        buttonsInstance.render('#' + BUTTONS_CONTAINER);
                    }
                } catch (e) {
                    setError('Failed to render PayPal buttons: ' + (e && e.message ? e.message : e));
                }

                try {
                    if (window.paypal.CardFields) {
                        cardFieldsInstance = window.paypal.CardFields({
                            createOrder: function () { return createPayPalOrder(); },
                            onApprove: function (data) {
                                return capturePayPalOrder(data.orderID).then(function () {
                                    capturedOrderId.current = data.orderID;
                                    moduleCapturedOrderId.current = data.orderID;
                                    setError('');
                                    var btn = document.querySelector('.walletup-paypal-card-submit');
                                    if (btn) { btn.textContent = 'Completing order…'; }
                                    autoSubmitPlaceOrder();
                                }).catch(function (err) {
                                    setError(err && err.message ? err.message : 'PayPal capture failed.');
                                    var btn = document.querySelector('.walletup-paypal-card-submit');
                                    if (btn) { btn.disabled = false; btn.textContent = 'Pay with card'; }
                                });
                            },
                            onError: function (err) {
                                setError(err && err.message ? err.message : 'Card error.');
                                var btn = document.querySelector('.walletup-paypal-card-submit');
                                if (btn) { btn.disabled = false; btn.textContent = 'Pay with card'; }
                            }
                        });
                        if (cardFieldsInstance.isEligible && cardFieldsInstance.isEligible()) {
                            var cardNode = document.getElementById(CARDFIELDS_CONTAINER);
                            if (cardNode) {
                                cardNode.innerHTML =
                                    '<div class="walletup-paypal-card-name" style="margin-bottom:8px;"></div>' +
                                    '<div class="walletup-paypal-card-number" style="margin-bottom:8px;"></div>' +
                                    '<div style="display:flex;gap:8px;">' +
                                    '<div class="walletup-paypal-card-expiry" style="flex:1;"></div>' +
                                    '<div class="walletup-paypal-card-cvv" style="flex:1;"></div>' +
                                    '</div>' +
                                    '<button type="button" class="walletup-paypal-card-submit" style="margin-top:12px;padding:10px 16px;background:#2c2e2f;color:#fff;border:0;border-radius:4px;cursor:pointer;">Pay with card</button>';

                                var nameField = cardFieldsInstance.NameField({ style: { input: { 'font-size': '16px' } } });
                                nameField.render('.walletup-paypal-card-name');
                                var numberField = cardFieldsInstance.NumberField({ style: { input: { 'font-size': '16px' } } });
                                numberField.render('.walletup-paypal-card-number');
                                var expiryField = cardFieldsInstance.ExpiryField({ style: { input: { 'font-size': '16px' } } });
                                expiryField.render('.walletup-paypal-card-expiry');
                                var cvvField = cardFieldsInstance.CVVField({ style: { input: { 'font-size': '16px' } } });
                                cvvField.render('.walletup-paypal-card-cvv');

                                var submitBtn = cardNode.querySelector('.walletup-paypal-card-submit');
                                if (submitBtn) {
                                    submitBtn.addEventListener('click', function () {
                                        setError('');
                                        var originalText = submitBtn.textContent || 'Pay with card';
                                        submitBtn.disabled = true;
                                        submitBtn.textContent = 'Processing…';

                                        var submitPromise;
                                        try {
                                            submitPromise = cardFieldsInstance.submit();
                                        } catch (syncErr) {
                                            submitBtn.disabled = false;
                                            submitBtn.textContent = originalText;
                                            setError(syncErr && syncErr.message ? syncErr.message : 'Card submit threw synchronously.');
                                            return;
                                        }
                                        if (!submitPromise || typeof submitPromise.then !== 'function') {
                                            submitBtn.disabled = false;
                                            submitBtn.textContent = originalText;
                                            setError('Card submit did not return a Promise.');
                                            return;
                                        }
                                        submitPromise.then(function () {
                                        }).catch(function (err) {
                                            submitBtn.disabled = false;
                                            submitBtn.textContent = originalText;
                                            var msg = err && err.message ? err.message : (typeof err === 'string' ? err : 'Card submission failed.');
                                            setError(msg);
                                        });
                                    });
                                }
                            }
                        } else {
                            var cardWrap = document.getElementById(CARDFIELDS_CONTAINER);
                            if (cardWrap) { cardWrap.style.display = 'none'; }
                        }
                    }
                } catch (e) {
                    var cardWrap2 = document.getElementById(CARDFIELDS_CONTAINER);
                    if (cardWrap2) { cardWrap2.style.display = 'none'; }
                }
            }, 100);

            return function () {
                clearInterval(interval);
                try { if (buttonsInstance && buttonsInstance.close) { buttonsInstance.close(); } } catch (e) {}
                try { if (cardFieldsInstance && cardFieldsInstance.close) { cardFieldsInstance.close(); } } catch (e) {}
                buttonsInstance = null;
                cardFieldsInstance = null;
            };
        }, []);

        useEffect(function () {
            if (!eventRegistration || !emitResponse) { return; }

            function buildSuccess(id) {
                var meta = { paymentMethodData: {} };
                meta.paymentMethodData[paymentField] = id;
                return { type: emitResponse.responseTypes.SUCCESS, meta: meta };
            }
            function buildError(msg) {
                return { type: emitResponse.responseTypes.ERROR, message: msg };
            }

            function waitForCapture() {
                return new Promise(function (resolve) {
                    var attempts = 0;
                    var poll = setInterval(function () {
                        attempts++;
                        var id = capturedOrderId.current || moduleCapturedOrderId.current;
                        if (id) {
                            clearInterval(poll);
                            resolve(buildSuccess(id));
                        } else if (attempts > 100) {
                            clearInterval(poll);
                            resolve(buildError('Payment authorization timed out. Please try again.'));
                        }
                    }, 200);
                });
            }

            var unsubscribe = eventRegistration.onPaymentSetup(function () {
                var captured = capturedOrderId.current || moduleCapturedOrderId.current || '';
                if (captured) {
                    return buildSuccess(captured);
                }
                if (cardFieldsInstance && typeof cardFieldsInstance.submit === 'function'
                  && cardFieldsInstance.isEligible && cardFieldsInstance.isEligible()) {
                    return cardFieldsInstance.submit().then(function () {
                        return waitForCapture();
                    }).catch(function (err) {
                        return buildError(err && err.message ? err.message : 'Card submission failed.');
                    });
                }
                return buildError('Click the PayPal button above to authorize, or fill in your card details to pay by card.');
            });
            return function () { unsubscribe(); };
        }, [eventRegistration, emitResponse]);

        var children = [];

        if (description) {
            children.push(el('p', { key: 'desc', style: { marginBottom: '8px' } }, description));
        }

        if (sandboxMode) {
            children.push(el('p', {
                key: 'sandbox',
                style: {
                    background: '#fff3cd',
                    border: '1px solid #ffc107',
                    padding: '8px 12px',
                    borderRadius: '4px',
                    fontSize: '13px',
                    color: '#856404',
                    margin: '0 0 12px'
                }
            }, 'SANDBOX MODE — no real charges will be made.'));
        }

        children.push(el('div', {
            key: 'buttons',
            id: BUTTONS_CONTAINER,
            style: { minHeight: '48px', margin: '8px 0' }
        }));

        children.push(el('div', {
            key: 'cardfields',
            id: CARDFIELDS_CONTAINER,
            style: { margin: '12px 0' }
        }));

        if (error) {
            children.push(el('div', {
                key: 'err',
                role: 'alert',
                style: { color: '#fa755a', marginTop: '8px', fontSize: '14px' }
            }, error));
        }

        return el('div', { className: 'walletup-paypal-commerce-block' }, children);
    }

    if (!clientId) {
        registerPaymentMethod({
            name: 'wallet_up_paypal_commerce',
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
            content: el('div', null, 'PayPal is not configured.'),
            edit: el('div', null, 'WalletUp PayPal Commerce'),
            canMakePayment: function () { return false; },
            ariaLabel: title,
            supports: { features: settings.supports || ['products'] }
        });
        return;
    }

    registerPaymentMethod({
        name: 'wallet_up_paypal_commerce',
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
        content: el(PayPalCommerceContent),
        edit: el('div', null, 'WalletUp PayPal Commerce'),
        canMakePayment: function () { return true; },
        ariaLabel: title,
        supports: { features: settings.supports || ['products'] }
    });
})();
