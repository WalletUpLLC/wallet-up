(function () {
    'use strict';

    var el = window.wp.element.createElement;
    var useEffect = window.wp.element.useEffect;
    var useRef = window.wp.element.useRef;
    var useState = window.wp.element.useState;
    var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
    var getSetting = window.wc.wcSettings.getSetting;

    var settings = getSetting('wallet_up_venmo_api_data', {});
    var title = settings.title || 'Venmo';
    var description = settings.description || '';
    var sandboxMode = !!settings.sandboxMode;
    var clientTokenUrl = settings.clientTokenUrl || '';
    var clientTokenAction = settings.clientTokenAction || 'walletup_venmo_client_token';
    var clientTokenNonce = settings.clientTokenNonce || '';
    var paymentField = settings.paymentField || 'walletup_venmo_nonce';

    function fetchClientToken() {
        var body = new URLSearchParams();
        body.append('action', clientTokenAction);
        body.append('nonce', clientTokenNonce);

        return fetch(clientTokenUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (res) {
            return res.json();
        }).then(function (json) {
            if (!json || !json.success || !json.data || !json.data.clientToken) {
                throw new Error((json && json.data && json.data.message) || 'Failed to fetch Venmo client token');
            }
            return json.data.clientToken;
        });
    }

    function VenmoBlockContent(props) {
        var eventRegistration = props.eventRegistration;
        var emitResponse = props.emitResponse;

        var venmoRef = useRef(null);
        var nonceRef = useRef('');
        var detailsRef = useRef(null);
        var mountedRef = useRef(true);

        var statusState = useState('init');
        var status = statusState[0];
        var setStatus = statusState[1];

        var errorState = useState('');
        var error = errorState[0];
        var setError = errorState[1];

        var authorizedState = useState(false);
        var authorized = authorizedState[0];
        var setAuthorized = authorizedState[1];

        useEffect(function () {
            mountedRef.current = true;

            if (!window.braintree || !window.braintree.client || !window.braintree.venmo) {
                setError('Braintree SDK failed to load. Please refresh the page.');
                setStatus('error');
                return;
            }

            fetchClientToken().then(function (clientToken) {
                if (!mountedRef.current) return;
                window.braintree.client.create({ authorization: clientToken }, function (clientErr, clientInstance) {
                    if (!mountedRef.current) return;
                    if (clientErr) {
                        setError(clientErr.message || 'Failed to initialize Braintree client.');
                        setStatus('error');
                        return;
                    }
                    window.braintree.venmo.create({
                        client: clientInstance,
                        allowDesktop: true,
                        paymentMethodUsage: 'single_use'
                    }, function (venmoErr, venmoInstance) {
                        if (!mountedRef.current) return;
                        if (venmoErr) {
                            setError(venmoErr.message || 'Failed to initialize Venmo.');
                            setStatus('error');
                            return;
                        }
                        if (!venmoInstance.isBrowserSupported()) {
                            setError('This browser does not support Venmo.');
                            setStatus('unsupported');
                            return;
                        }
                        venmoRef.current = venmoInstance;
                        setStatus('ready');
                    });
                });
            }).catch(function (err) {
                if (!mountedRef.current) return;
                setError(err && err.message ? err.message : String(err));
                setStatus('error');
            });

            return function () {
                mountedRef.current = false;
                var instance = venmoRef.current;
                venmoRef.current = null;
                if (instance && typeof instance.teardown === 'function') {
                    try { instance.teardown(function () {}); } catch (e) { /* noop */ }
                }
            };
        }, []);

        useEffect(function () {
            if (!eventRegistration || !emitResponse) return;

            var unsubscribe = eventRegistration.onPaymentSetup(function () {
                if (!nonceRef.current) {
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: 'Please tap "Pay with Venmo" to authorize before placing the order.'
                    };
                }
                var meta = { paymentMethodData: {} };
                meta.paymentMethodData[paymentField] = nonceRef.current;
                return {
                    type: emitResponse.responseTypes.SUCCESS,
                    meta: meta
                };
            });

            return function () { unsubscribe(); };
        }, [eventRegistration, emitResponse]);

        function handleClick() {
            var instance = venmoRef.current;
            if (!instance) return;
            setError('');
            setStatus('tokenizing');
            instance.tokenize(function (err, payload) {
                if (!mountedRef.current) return;
                if (err) {
                    if (err.code === 'VENMO_CANCELED' || err.code === 'VENMO_APP_CANCELED') {
                        setStatus('ready');
                        setError('');
                        return;
                    }
                    setError(err.message || 'Venmo authorization failed.');
                    setStatus('ready');
                    return;
                }
                nonceRef.current = payload.nonce;
                detailsRef.current = payload.details || null;
                setAuthorized(true);
                setStatus('authorized');
            });
        }

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
                    color: '#856404',
                    margin: '0 0 12px 0'
                }
            }, 'SANDBOX MODE — no real charges will be made.'));
        }

        var buttonLabel = 'Pay with Venmo';
        if (status === 'init') buttonLabel = 'Loading…';
        else if (status === 'tokenizing') buttonLabel = 'Authorizing…';
        else if (authorized && detailsRef.current && detailsRef.current.username) buttonLabel = 'Authorized as @' + detailsRef.current.username + ' — tap to change';
        else if (authorized) buttonLabel = 'Authorized — tap to change';

        var disabled = status === 'init' || status === 'tokenizing' || status === 'error' || status === 'unsupported';

        children.push(el('button', {
            key: 'btn',
            type: 'button',
            id: 'walletup-venmo-button-block',
            disabled: disabled,
            onClick: handleClick,
            style: {
                display: 'inline-flex',
                alignItems: 'center',
                justifyContent: 'center',
                width: '100%',
                padding: '12px 16px',
                background: authorized ? '#0a7e35' : '#3D95CE',
                color: '#fff',
                border: 'none',
                borderRadius: '6px',
                fontSize: '15px',
                fontWeight: '600',
                cursor: disabled ? 'not-allowed' : 'pointer',
                opacity: disabled ? 0.6 : 1,
                transition: 'background .15s ease'
            }
        }, buttonLabel));

        if (error) {
            children.push(el('div', {
                key: 'error',
                role: 'alert',
                style: { color: '#e74c3c', marginTop: '8px', fontSize: '13px' }
            }, error));
        }

        return el('div', { className: 'walletup-venmo-api-block' }, children);
    }

    function canMakePayment() {
        return !!clientTokenUrl && !!clientTokenNonce;
    }

    registerPaymentMethod({
        name: 'wallet_up_venmo_api',
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
        content: el(VenmoBlockContent),
        edit: el('div', null, 'WalletUp Venmo (Braintree)'),
        canMakePayment: canMakePayment,
        ariaLabel: title,
        supports: { features: settings.supports || ['products'] }
    });
})();
