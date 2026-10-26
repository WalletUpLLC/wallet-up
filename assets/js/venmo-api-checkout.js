(function ($) {
    'use strict';

    if (typeof walletUpVenmo === 'undefined') {
        return;
    }

    var clientTokenUrl = walletUpVenmo.clientTokenUrl || '';
    var clientTokenAction = walletUpVenmo.clientTokenAction || 'walletup_venmo_client_token';
    var clientTokenNonce = walletUpVenmo.clientTokenNonce || '';

    var venmoInstance = null;
    var nonce = '';
    var initializing = false;

    function setNonce(value) {
        nonce = value || '';
        $('#walletup_venmo_nonce').val(nonce);
    }

    function setError(msg) {
        $('#walletup-venmo-error-classic').text(msg || '');
    }

    function fetchClientToken() {
        return $.post(clientTokenUrl, {
            action: clientTokenAction,
            nonce: clientTokenNonce
        }).then(function (json) {
            if (!json || !json.success || !json.data || !json.data.clientToken) {
                throw new Error((json && json.data && json.data.message) || 'Failed to fetch Venmo client token');
            }
            return json.data.clientToken;
        });
    }

    function teardown() {
        var inst = venmoInstance;
        venmoInstance = null;
        if (inst && typeof inst.teardown === 'function') {
            try { inst.teardown(function () {}); } catch (e) {}
        }
    }

    function renderButton() {
        var $container = $('#walletup-venmo-button-classic');
        if (!$container.length) {
            return;
        }
        $container.empty();

        var $btn = $('<button>', {
            type: 'button',
            class: 'walletup-venmo-pay-button',
            text: 'Pay with Venmo'
        }).css({
            background: '#3D95CE',
            color: '#fff',
            border: 'none',
            padding: '10px 20px',
            'border-radius': '4px',
            cursor: 'pointer',
            'font-size': '14px',
            'font-weight': '600'
        });

        $btn.on('click', function (e) {
            e.preventDefault();
            if (!venmoInstance) {
                return;
            }
            setError('');
            $btn.prop('disabled', true).text('Authorizing…');
            venmoInstance.tokenize(function (err, payload) {
                $btn.prop('disabled', false);
                if (err) {
                    if (err.code === 'VENMO_CANCELED' || err.code === 'VENMO_APP_CANCELED') {
                        $btn.text('Pay with Venmo');
                        return;
                    }
                    setError(err.message || 'Venmo authorization failed.');
                    $btn.text('Pay with Venmo');
                    return;
                }
                setNonce(payload.nonce);
                $btn.text('✓ Authorized — place order to complete');
            });
        });

        $container.append($btn);
    }

    function mountButton() {
        if (initializing || venmoInstance) {
            return;
        }
        if (!window.braintree || !window.braintree.client || !window.braintree.venmo) {
            setError('Braintree SDK failed to load. Please refresh the page.');
            return;
        }

        initializing = true;
        fetchClientToken().then(function (clientToken) {
            window.braintree.client.create({ authorization: clientToken }, function (clientErr, clientInstance) {
                if (clientErr) {
                    initializing = false;
                    setError(clientErr.message || 'Failed to initialize Braintree client.');
                    return;
                }
                window.braintree.venmo.create({
                    client: clientInstance,
                    allowDesktop: true,
                    paymentMethodUsage: 'single_use'
                }, function (venmoErr, instance) {
                    initializing = false;
                    if (venmoErr) {
                        setError(venmoErr.message || 'Failed to initialize Venmo.');
                        return;
                    }
                    if (!instance.isBrowserSupported()) {
                        setError('This browser does not support Venmo.');
                        return;
                    }
                    venmoInstance = instance;
                    renderButton();
                });
            });
        }).catch(function (err) {
            initializing = false;
            setError(err && err.message ? err.message : String(err));
        });
    }

    $(document).ready(function () {
        mountButton();
    });

    $(document.body).on('updated_checkout', function () {
        teardown();
        setNonce('');
        mountButton();
    });

    var $form = $('form.checkout, form#order_review');
    $form.on('checkout_place_order_wallet_up_venmo_api', function () {
        if (nonce) {
            return true;
        }
        setError('Please tap "Pay with Venmo" above to authorize before placing the order.');
        $form.removeClass('processing').unblock();
        return false;
    });
})(jQuery);
