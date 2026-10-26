<?php
namespace WalletUpPro\WooCommerce\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use WalletUpPro\Core\Error\ErrorHandler;
use WalletUpPro\Core\PaymentRegistry;
use WalletUpPro\WooCommerce\WooCommerceMethodSettings;
use Exception;

if (!defined('ABSPATH')) {
    exit;
}

final class WalletUpMethodBlocksSupport extends AbstractPaymentMethodType
{
    protected $name;

    private string $method_id;
    private $errorHandler;
    private $registry;

    /** @var string[] Track which methods have been registered to inject the list once */
    private static array $registered_methods = [];
    private static bool $script_registered = false;

    public function __construct(string $method_id)
    {
        $this->method_id = $method_id;
        $this->name = 'wallet_up_' . $method_id;
        $this->errorHandler = ErrorHandler::getInstance();
        $this->registry = PaymentRegistry::getInstance();

        self::$registered_methods[] = $method_id;
    }

    public function initialize(): void
    {
        $this->settings = get_option('woocommerce_wallet_up_' . $this->method_id . '_settings', []);
    }

    public function is_active(): bool
    {
        try {
            $method = $this->registry->getMethod($this->method_id);
            if (!$method) {
                return false;
            }

            $gateway_settings = get_option('woocommerce_wallet_up_' . $this->method_id . '_settings', []);
            if (isset($gateway_settings['enabled']) && $gateway_settings['enabled'] !== 'yes') {
                return false;
            }

            return true;
        } catch (Exception $e) {
            $this->errorHandler->log('Method blocks is_active error for ' . $this->method_id . ': ' . $e->getMessage());
            return false;
        }
    }

    public function get_payment_method_script_handles(): array
    {
        try {
            $script_handle = 'wc-walletup-method-blocks';

            if (!self::$script_registered) {
                $manifest_path = WALLET_UP_PATH . 'walup-react/dist/.vite/manifest.json';

                if (!file_exists($manifest_path)) {
                    throw new Exception(esc_html('Manifest file not found'));
                }

                $manifest = json_decode(file_get_contents($manifest_path), true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception(esc_html('Invalid manifest JSON'));
                }

                $entry_point = 'src/checkoutBlockSimple.js';
                if (!isset($manifest[$entry_point])) {
                    $entry_point = 'src/checkoutBlock.jsx';
                }
                if (!isset($manifest[$entry_point])) {
                    throw new Exception(esc_html('Checkout block entry not found in manifest'));
                }

                $entry = $manifest[$entry_point];
                $file_path = $entry['file'];
                $relative_path = 'walup-react/dist/' . (str_starts_with($file_path, 'assets/') ? $file_path : 'assets/' . $file_path);

                wp_register_script(
                    $script_handle,
                    WALLET_UP_URL . $relative_path,
                    ['wp-element', 'wp-i18n', 'wc-blocks-registry', 'wc-settings', 'wp-data', 'wp-components', 'lodash'],
                    WALLET_UP_VERSION,
                    true
                );

                self::$script_registered = true;
            }

            $data = $this->get_payment_method_data();
            $setting_key = $this->name . '_data';

            wp_add_inline_script(
                $script_handle,
                sprintf(
                    'window.wc = window.wc || {};
                    window.wc.wcSettings = window.wc.wcSettings || {};
                    window.wc.wcSettings.settings = window.wc.wcSettings.settings || {};
                    window.wc.wcSettings.settings[%s] = %s;',
                    wp_json_encode($setting_key),
                    wp_json_encode($data)
                ),
                'before'
            );

            wp_add_inline_script(
                $script_handle,
                sprintf(
                    'window.walletUpIndividualMethods = window.walletUpIndividualMethods || [];
                    window.walletUpIndividualMethods.push(%s);',
                    wp_json_encode([
                        'id' => $this->method_id,
                        'gatewayId' => $this->name,
                        'title' => $data['title'],
                        'description' => $data['description'],
                        'icon' => $data['icon'] ?? '',
                        'ajaxUrl' => $data['ajaxUrl'],
                        'nonce' => $data['nonce'],
                    ])
                ),
                'before'
            );

            if (!wp_script_is($script_handle . '-individual-init', 'registered')) {
                wp_register_script($script_handle . '-individual-init', false, [$script_handle], WALLET_UP_VERSION, true);
                wp_enqueue_script($script_handle . '-individual-init');
                wp_add_inline_script(
                    $script_handle . '-individual-init',
                    $this->getIndividualRegistrationScript(),
                    'after'
                );
            }

            return [$script_handle];

        } catch (Exception $e) {
            $this->errorHandler->log('Method blocks script registration failed for ' . $this->method_id . ': ' . $e->getMessage());
            return [];
        }
    }

    public function get_payment_method_data(): array
    {
        $baseTitle = WooCommerceMethodSettings::getEffectiveTitle($this->method_id);
        $title = WooCommerceMethodSettings::appendDiscountBadge($this->method_id, $baseTitle);

        $icon_url = WooCommerceMethodSettings::get($this->method_id, 'hide_icon', false)
            ? ''
            : \WalletUpPro\Core\Display\DisplayHandler::methodLogoUrl($this->method_id);

        return [
            'title' => $title,
            'description' => '',
            'supports' => ['products'],
            'methodId' => $this->method_id,
            'icon' => $icon_url,
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wallet_up_wc_payment_display'),
        ];
    }

    private function getIndividualRegistrationScript(): string
    {
        return <<<'JS'
(function() {
    'use strict';

    function waitForDeps(cb) {
        if (window.wc && window.wc.wcBlocksRegistry && window.wp && window.wp.element) {
            cb();
        } else {
            setTimeout(function() { waitForDeps(cb); }, 100);
        }
    }

    waitForDeps(function() {
        var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
        var createElement = window.wp.element.createElement;
        var useState = window.wp.element.useState;
        var useEffect = window.wp.element.useEffect;
        var methods = window.walletUpIndividualMethods || [];

        function sanitizeHTML(html) {
            if (!html) return '';
            var doc = new DOMParser().parseFromString(html, 'text/html');
            doc.querySelectorAll('script').forEach(function(el) { el.remove(); });
            doc.querySelectorAll('*').forEach(function(el) {
                Array.from(el.attributes).forEach(function(attr) {
                    if (attr.name.toLowerCase().startsWith('on')) {
                        el.removeAttribute(attr.name);
                    }
                });
            });
            return doc.body.innerHTML;
        }

        methods.forEach(function(method) {
            try {
                var PaymentContent = function(props) {
                    var eventRegistration = props.eventRegistration;
                    var emitResponse = props.emitResponse;
                    var loading = useState(false);
                    var isLoading = loading[0];
                    var setLoading = loading[1];
                    var qrState = useState(null);
                    var qrDisplay = qrState[0];
                    var setQrDisplay = qrState[1];
                    var errorState = useState(null);
                    var error = errorState[0];
                    var setError = errorState[1];

                    var currentAmount = props.billing && props.billing.cartTotal && props.billing.cartTotal.value
                        ? (props.billing.cartTotal.value / 100).toFixed(2) : '0.00';

                    useEffect(function() {
                        var mounted = { on: true };
                        var amount = currentAmount;
                        var g = window.__walletUpQrState = window.__walletUpQrState || { qr: {}, feeMethod: null, inflight: {} };
                        var wantKey = method.id + '|' + amount;
                        var cachedHtml = g.qr[wantKey];

                        if (cachedHtml) {
                            setQrDisplay(cachedHtml);
                            setError(null);
                            setLoading(false);
                        } else {
                            setQrDisplay(null);
                            setError(null);
                            setLoading(true);
                        }

                        if (g.feeMethod !== method.id) {
                            g.feeMethod = method.id;
                            try {
                                if (window.wc && window.wc.blocksCheckout && window.wc.blocksCheckout.extensionCartUpdate) {
                                    window.wc.blocksCheckout.extensionCartUpdate({
                                        namespace: 'walletup',
                                        data: { selected_method: method.id, payment_method: method.gatewayId }
                                    });
                                }
                            } catch (e) {}
                        }

                        if (!cachedHtml) {
                            var reqKey = method.id + '|' + amount;
                            if (!g.inflight[reqKey]) {
                                var fd = new FormData();
                                fd.append('action', 'wallet_up_wc_payment_display');
                                fd.append('method', method.id);
                                fd.append('amount', amount);
                                fd.append('nonce', method.nonce);
                                g.inflight[reqKey] = fetch(method.ajaxUrl || '/wp-admin/admin-ajax.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                                    .then(function(res) { return res.json(); })
                                    .then(function(result) {
                                        delete g.inflight[reqKey];
                                        if (result && result.success && result.data && result.data.html) {
                                            var serverAmount = (result.data.amount != null) ? String(result.data.amount) : amount;
                                            g.qr[method.id + '|' + serverAmount] = result.data.html;
                                            return { ok: true, html: result.data.html, amount: serverAmount };
                                        }
                                        return { ok: false, message: (result && result.data && result.data.message) || 'Failed to load payment display' };
                                    }, function(err) {
                                        delete g.inflight[reqKey];
                                        return { ok: false, message: (err && err.message) || 'Network error' };
                                    });
                            }
                            g.inflight[reqKey].then(function(outcome) {
                                if (!mounted.on) return;
                                if (outcome && outcome.ok) {
                                    if (outcome.amount === amount) {
                                        setQrDisplay(outcome.html);
                                        setError(null);
                                        setLoading(false);
                                    }
                                } else {
                                    setError((outcome && outcome.message) || 'Failed to load payment display');
                                    setLoading(false);
                                }
                            });
                        }

                        return function() { mounted.on = false; };
                    }, [currentAmount]);

                    useEffect(function() {
                        if (!eventRegistration || !emitResponse) return;
                        var unsubscribe = eventRegistration.onPaymentSetup(function() {
                            return {
                                type: emitResponse.responseTypes.SUCCESS,
                                meta: {
                                    paymentMethodData: {
                                        wallet_up_payment_method: method.id,
                                        wallet_up_payment_nonce: method.nonce
                                    }
                                }
                            };
                        });
                        return function() { unsubscribe(); };
                    }, [eventRegistration, emitResponse]);

                    return createElement('div', { className: 'wallet-up-individual-method' }, [
                        method.description && createElement('p', { key: 'desc', style: { marginBottom: '10px', color: '#6b7280', fontSize: '14px' } }, method.description),
                        isLoading && createElement('div', { key: 'loading', style: { textAlign: 'center', padding: '24px' } }, [
                            createElement('div', { key: 'spinner', style: {
                                width: '28px', height: '28px', border: '3px solid #e5e7eb',
                                borderTopColor: '#7c3aed', borderRadius: '50%',
                                animation: 'walletup-spin 0.8s linear infinite',
                                margin: '0 auto 10px'
                            } }),
                            createElement('style', { key: 'spin-style' }, '@keyframes walletup-spin { to { transform: rotate(360deg) } }'),
                            createElement('span', { key: 'text', style: { color: '#6b7280', fontSize: '13px' } }, 'Loading payment details...')
                        ]),
                        error && createElement('div', { key: 'error', style: { color: '#dc2626', padding: '12px', background: '#fef2f2', borderRadius: '8px', border: '1px solid #fecaca', fontSize: '13px' } }, error),
                        !isLoading && !error && !qrDisplay && createElement('div', { key: 'empty', style: { textAlign: 'center', padding: '20px', color: '#9ca3af', fontSize: '13px' } }, 'Payment display will appear here.'),
                        qrDisplay && createElement('div', {
                            key: 'qr',
                            dangerouslySetInnerHTML: { __html: sanitizeHTML(qrDisplay) }
                        })
                    ]);
                };

                registerPaymentMethod({
                    name: method.gatewayId,
                    label: createElement('span', {}, [
                        method.icon && createElement('img', {
                            key: 'icon',
                            src: method.icon,
                            alt: '',
                            style: { width: '24px', height: '24px', marginRight: '8px', verticalAlign: 'middle' }
                        }),
                        method.title
                    ]),
                    content: createElement(PaymentContent),
                    edit: createElement(PaymentContent),
                    canMakePayment: function() { return true; },
                    ariaLabel: method.title,
                    supports: {
                        features: ['products'],
                        showSavedCards: false,
                        showSaveOption: false
                    }
                });
            } catch (e) {
                console.error('WalletUp: Failed to register blocks method ' + method.gatewayId, e);
            }
        });
    });
})();
JS;
    }

    public static function resetRegistration(): void
    {
        self::$registered_methods = [];
        self::$script_registered = false;
    }
}
