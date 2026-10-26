(function () {
    'use strict';

    var el = window.wp.element.createElement;
    var useState = window.wp.element.useState;
    var useEffect = window.wp.element.useEffect;
    var useRef = window.wp.element.useRef;
    var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
    var getSetting = window.wc.wcSettings.getSetting;

    var settings = getSetting('wallet_up_cashapp_api_data', {});
    var title = settings.title || 'Cash App Pay';
    var description = settings.description || '';
    var sandboxMode = !!settings.sandboxMode;
    var applicationId = settings.applicationId || '';
    var locationId = settings.locationId || '';
    var currency = (settings.currency || 'USD').toUpperCase();

    var ZERO_DECIMAL = ['BIF','CLP','DJF','GNF','JPY','KMF','KRW','MGA','PYG','RWF','UGX','VND','VUV','XAF','XOF','XPF'];

    function toMinorUnits(amount, ccy) {
        var n = parseFloat(amount);
        if (isNaN(n) || n < 0) {
            n = 0;
        }
        if (ZERO_DECIMAL.indexOf((ccy || 'USD').toUpperCase()) !== -1) {
            return Math.round(n);
        }
        return Math.round(n * 100);
    }

    function getCartTotal(props) {
        try {
            if (props && props.billing && props.billing.cartTotal && props.billing.cartTotal.value) {
                var v = parseInt(props.billing.cartTotal.value, 10);
                if (!isNaN(v) && v >= 0) {
                    return v;
                }
            }
            if (props && props.billing && typeof props.billing.cartTotalItems !== 'undefined') {
            }
        } catch (e) {}
        return 0;
    }

    function CashAppContent(props) {
        var eventRegistration = props.eventRegistration;
        var emitResponse = props.emitResponse;
        var sourceIdRef = useRef('');
        var cashAppPayRef = useRef(null);
        var errorState = useState('');
        var error = errorState[0];
        var setError = errorState[1];

        useEffect(function () {
            var cancelled = false;
            var attached = false;

            if (!applicationId || !locationId) {
                setError('Cash App Pay is not configured. Please contact the merchant.');
                return;
            }
            if (!window.Square || typeof window.Square.payments !== 'function') {
                setError('Square SDK failed to load. Please refresh the page.');
                return;
            }

            var amountMinor = getCartTotal(props);
            var amountMajor;
            if (ZERO_DECIMAL.indexOf(currency) !== -1) {
                amountMajor = String(amountMinor);
            } else {
                amountMajor = (amountMinor / 100).toFixed(2);
            }

            (async function () {
                try {
                    var payments = window.Square.payments(applicationId, locationId);
                    var req = payments.paymentRequest({
                        countryCode: 'US',
                        currencyCode: currency,
                        total: { amount: amountMajor, label: 'Total' }
                    });
                    var cashAppPay = await payments.cashAppPay(req, {
                        redirectURL: window.location.href,
                        referenceId: 'wc-order-pending'
                    });
                    if (cancelled) {
                        try { cashAppPay.destroy(); } catch (e) {}
                        return;
                    }

                    cashAppPay.addEventListener('ontokenization', function (event) {
                        var detail = event && event.detail ? event.detail : {};
                        var tokenResult = detail.tokenResult || {};
                        if (tokenResult.status === 'OK' && tokenResult.token) {
                            sourceIdRef.current = tokenResult.token;
                            setError('');
                        } else {
                            sourceIdRef.current = '';
                            var msg = (tokenResult.errors && tokenResult.errors[0] && tokenResult.errors[0].message) || 'Cash App Pay tokenization failed.';
                            setError(msg);
                        }
                    });

                    var poll = setInterval(function () {
                        if (cancelled) {
                            clearInterval(poll);
                            return;
                        }
                        var container = document.getElementById('walletup-cashapp-pay-block');
                        if (!container) {
                            return;
                        }
                        clearInterval(poll);
                        cashAppPay.attach('#walletup-cashapp-pay-block').then(function () {
                            attached = true;
                            cashAppPayRef.current = cashAppPay;
                        }).catch(function (e) {
                            setError('Failed to load Cash App Pay: ' + (e && e.message ? e.message : e));
                        });
                    }, 100);
                } catch (e) {
                    if (!cancelled) {
                        setError('Cash App Pay init error: ' + (e && e.message ? e.message : e));
                    }
                }
            })();

            return function () {
                cancelled = true;
                var cap = cashAppPayRef.current;
                cashAppPayRef.current = null;
                if (cap) {
                    try { cap.destroy(); } catch (e) {}
                }
                attached = false;
            };
        }, []);

        useEffect(function () {
            if (!eventRegistration || !emitResponse) return;
            var unsubscribe = eventRegistration.onPaymentSetup(function () {
                if (!sourceIdRef.current) {
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: 'Please tap "Pay with Cash App" above to authorize the payment.'
                    };
                }
                return {
                    type: emitResponse.responseTypes.SUCCESS,
                    meta: {
                        paymentMethodData: {
                            walletup_cashapp_source_id: sourceIdRef.current
                        }
                    }
                };
            });
            return function () { unsubscribe(); };
        }, [eventRegistration, emitResponse]);

        var children = [];

        if (description) {
            children.push(el('p', { key: 'desc', style: { marginBottom: '12px' } }, description));
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
                    marginBottom: '12px',
                    color: '#856404'
                }
            }, 'SANDBOX MODE — no real charges will be made.'));
        }

        children.push(el('div', {
            key: 'btn',
            id: 'walletup-cashapp-pay-block',
            style: { minHeight: '48px' }
        }));

        if (error) {
            children.push(el('div', {
                key: 'error',
                style: { color: '#fa755a', marginTop: '8px', fontSize: '14px' },
                role: 'alert'
            }, error));
        }

        return el('div', { className: 'walletup-cashapp-api-block' }, children);
    }

    registerPaymentMethod({
        name: 'wallet_up_cashapp_api',
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
        content: el(CashAppContent),
        edit: el('div', null, 'WalletUp Cash App Pay API'),
        canMakePayment: function () {
            return !!(applicationId && locationId && window.Square);
        },
        ariaLabel: title,
        supports: { features: settings.supports || ['products'] }
    });
})();
