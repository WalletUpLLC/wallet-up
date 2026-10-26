<?php
namespace WalletUpPro\WooCommerce;

use WalletUpPro\Core\PaymentRegistry;
use WalletUpPro\Core\Config\PaymentMethodsConfig;
use WalletUpPro\Core\Display\DisplayHandler;
use WalletUpPro\Core\Analytics\AnalyticsHandler;
use WalletUpPro\Core\Error\ErrorHandler;
use WalletUpPro\Services\QRCodeService;
use WalletUpPro\WooCommerce\WooCommerceConfig;
use WalletUpPro\Services\PaymentProcessor; // Assuming this class is namespaced correctly in your project
use Exception;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
class WalletUpProGateway extends \WC_Payment_Gateway
{
    private $registry;
    private $displayHandler;
    private $analytics;
    private $errorHandler;
    private $qrCodeService;
    private $instructions;
    private static $instance = null;

    private $initialized = false;

    public static function getInstance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public $supports = [
        'products',
        'refunds',
        'pre-orders',
        'manual_orders',
        'subscriptions',
        'subscription_cancellation',
        'subscription_suspension',
        'subscription_reactivation',
        'subscription_amount_changes',
        'subscription_date_changes',
        'subscription_payment_method_change',
        'subscription_payment_method_change_customer',
        'subscription_payment_method_change_admin',
        'multiple_subscriptions',
    ];
    private const VALID_EVENTS = [
        'order_created',
        'payment_initiated',
        'payment_pending',
        'payment_processing',
        'payment_completed',
        'payment_failed',
        'payment_refunded',
        'wc_order_complete',
        'wc_order_refund',
    ];
    private const QR_DIMENSIONS = [
        'small' => 150,
        'medium' => 180,
        'large' => 250
    ];

    public function __construct()
    {
        $this->id = 'wallet_up';
        $this->method_title = __('WalletUp', 'walletup');
        $this->icon = file_exists(WALLET_UP_PATH . 'assets/images/walup-logo.png')
            ? WALLET_UP_URL . 'assets/images/walup-logo.png'
            : '';
        $this->method_description = __('Accept payments via PayPal, QR codes, mobile money, crypto, and subscriptions.', 'walletup');
        $this->has_fields = true;
        $this->supports[] = 'custom_order_tables';

        try {
            $this->initializeServices();
            $this->init_form_fields();
            $this->initializeSettings();

            $this->registerHooks();

            $this->initialized = true;
        } catch (\Exception $e) {
            if (isset($this->errorHandler)) {
                $this->errorHandler->log('Gateway initialization failed: ' . $e->getMessage());
            }
        }
    }
    private function initializeServices(): void
    {
        try {
            $this->errorHandler = ErrorHandler::getInstance();
            $this->registry = PaymentRegistry::getInstance();
            $this->displayHandler = DisplayHandler::getInstance();
            $this->analytics = AnalyticsHandler::getInstance();
            $this->qrCodeService = QRCodeService::getInstance();

        } catch (Exception $e) {
            if (isset($this->errorHandler)) {
                $this->errorHandler->log('Gateway services initialization failed: ' . $e->getMessage());
            }
        }
    }
    private function registerHooks(): void
    {
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options'], 20);
        add_action('woocommerce_receipt_' . $this->id, [$this, 'receipt_page'], 20);
        add_action('woocommerce_api_wallet_up', [$this, 'check_payment_response'], 20);
        add_action('woocommerce_email_before_order_table', [$this, 'email_instructions'], 10, 3);

        add_action('woocommerce_scheduled_subscription_payment_' . $this->id, [$this, 'processScheduledRenewalPayment'], 10, 2);

        add_action('woocommerce_subscription_cancelled_' . $this->id, [$this, 'cancelWcSubscription']);

        add_action('woocommerce_subscription_status_on-hold_to_active_' . $this->id, [$this, 'reactivateWcSubscription']);
        add_action('woocommerce_subscription_status_pending_to_active_' . $this->id, [$this, 'reactivateWcSubscription']);

        add_action('template_redirect', [$this, 'handleStripe3DSSubscriptionReturn']);

        add_action('template_redirect', [$this, 'handlePayPalOrderReturn']);

    }

    public static function ajaxSetSelectedMethod(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Cart session, not a state-changing action
        $methodId = isset($_POST['method_id']) ? sanitize_key(wp_unslash($_POST['method_id'])) : '';
        if ($methodId && WC()->session) {
            WC()->session->set('wallet_up_selected_method', $methodId); // zi2-dry: ignore
        }
        wp_send_json_success();
    }

    public static function applyPaymentMethodDiscountStatic(\WC_Cart $cart): void
    {
        if (is_admin() && !defined('DOING_AJAX') && !defined('REST_REQUEST')) {
            return;
        }

        if (!WC()->session) {
            return;
        }

        $chosenGateway = '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only; discount calc is not a state-mutation endpoint.
        if (!empty($_POST['payment_method'])) {
            $chosenGateway = sanitize_text_field(wp_unslash($_POST['payment_method']));
        }
        if (empty($chosenGateway)) {
            $chosenGateway = (string) WC()->session->get('chosen_payment_method', '');
        }

        if ($chosenGateway === 'wallet_up_card') {
            return;
        }
        if ($chosenGateway !== 'wallet_up' && strpos($chosenGateway, 'wallet_up_') !== 0) {
            return;
        }

        $methodId = '';
        if ($chosenGateway === 'wallet_up') {
            $methodId = (string) WC()->session->get('wallet_up_selected_method', '');
        } else {
            $methodId = substr($chosenGateway, strlen('wallet_up_'));
        }

        if ($methodId === '' || $methodId === 'card' || !in_array($methodId, \WalletUpPro\Core\Config\PaymentMethodsConfig::getAllMethodIds(), true)) {
            return;
        }

        $discountType = WooCommerceMethodSettings::get($methodId, 'discount_type');
        $discountValue = (float) WooCommerceMethodSettings::get($methodId, 'discount_value', 0);

        if (empty($discountType) || $discountValue <= 0) {
            return;
        }

        if (!WooCommerceMethodSettings::isAmountInRange($methodId, (float) $cart->get_subtotal())) {
            return;
        }

        $methodTitle = WooCommerceMethodSettings::getEffectiveTitle($methodId);

        if ($discountType === 'percentage') {
            $amount = -($cart->get_subtotal() * ($discountValue / 100));
            /* translators: 1: discount percentage, 2: payment method name */
            $label = sprintf(__('%1$s%% off - %2$s', 'walletup'), $discountValue, $methodTitle);
        } elseif ($discountType === 'fixed') {
            $amount = -$discountValue;
            $currency = get_woocommerce_currency_symbol();
            /* translators: 1: currency symbol, 2: discount amount, 3: payment method name */
            $label = sprintf(__('%1$s%2$s off - %3$s', 'walletup'), $currency, number_format($discountValue, 2), $methodTitle);
        } else {
            return;
        }

        $cart->add_fee($label, $amount);
    }

    public function email_instructions($order, $sent_to_admin, $plain_text = false): void
    {
        if ($order->get_payment_method() !== $this->id) {
            return;
        }

        $methodId = $order->get_meta('_wallet_up_payment_method');
        if (empty($methodId)) {
            return;
        }

        $email_instructions = WooCommerceMethodSettings::get($methodId, 'email_instructions', '');
        if (!empty($email_instructions)) {
            $email_instructions = WooCommerceMethodSettings::processVariables($email_instructions, $methodId, [
                'amount'   => $order->get_total(),
                'currency' => $order->get_currency(),
                'order'    => $order,
            ]);
            if ($plain_text) {
                echo "\n" . esc_html(wp_strip_all_tags($email_instructions)) . "\n\n";
            } else {
                echo wp_kses_post(wpautop(wptexturize($email_instructions)));
            }
        }
    }

    private function checkDependencies(): bool
    {
        if (!class_exists('WC_Payment_Gateway')) {
            return false;
        }

        $requiredFiles = [
            WALLET_UP_PATH . 'src/Core/PaymentRegistry.php',
            WALLET_UP_PATH . 'src/Core/Display/DisplayHandler.php',
            WALLET_UP_PATH . 'src/Core/Analytics/AnalyticsHandler.php',
            WALLET_UP_PATH . 'src/Core/Error/ErrorHandler.php',
            WALLET_UP_PATH . 'src/Services/QrCodeService.php'
        ];

        foreach ($requiredFiles as $file) {
            if (!file_exists($file)) {
                return false;
            }
        }

        return true;
    }

    private function initializeSettings(): void
    {
        $this->init_form_fields();

        $this->enabled = $this->get_option('enabled', 'yes');
        $this->title = $this->get_option('title', __('WalletUpPro Pay', 'walletup'));
        $this->description = $this->get_option('description', __('Pay with your preferred mobile payment app', 'walletup'));
        $this->instructions = $this->get_option('instructions', __('Please ensure you have completed your payment using the QR code above.', 'walletup'));

    }

    public function init_form_fields(): void
    {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'walletup'),
                'type' => 'checkbox',
                'label' => __('Enable Wallet Up Payment', 'walletup'),
                'default' => 'yes'
            ],
            'checkout_csp_strict' => [
                'title' => __('Checkout script policy', 'walletup'),
                'type' => 'checkbox',
                'label' => __('Restrict checkout scripts to known payment providers (advanced)', 'walletup'),
                'description' => __('Off by default. When on, only payment-provider scripts (and any extra domains below) may load on the checkout page. Enable only if you understand Content Security Policy, as it can block other scripts your checkout relies on.', 'walletup'),
                'default' => 'no',
                'desc_tip' => true
            ],
            'checkout_csp_domains' => [
                'title' => __('Extra checkout script domains', 'walletup'),
                'type' => 'text',
                'description' => __('Space-separated https:// hosts to also allow on the checkout when the strict policy is on.', 'walletup'),
                'default' => '',
                'desc_tip' => true
            ],
            'title' => [
                'title' => __('Title', 'walletup'),
                'type' => 'text',
                'description' => __('Payment method title that customers see.', 'walletup'),
                'default' => __('WalletUpPro Pay', 'walletup'),
                'desc_tip' => true
            ],
            'description' => [
                'title' => __('Description', 'walletup'),
                'type' => 'textarea',
                'description' => __('Payment method description that customers see.', 'walletup'),
                'default' => __('Pay securely using your preferred mobile payment app', 'walletup'),
                'desc_tip' => true
            ],
            'instructions' => [
                'title' => __('Payment Instructions', 'walletup'),
                'type' => 'textarea',
                'description' => __('Instructions added to the thank you page.', 'walletup'),
                'default' => __('Please ensure you have completed your payment using the QR code above.', 'walletup'),
                'desc_tip' => true
            ],
            'payment_display_timing' => [
                'title' => __('When to show payment details', 'walletup'),
                'type' => 'select',
                'description' => __('Choose when the customer sees the QR code and payment details. Showing them only after the order is placed prevents customers from paying before their order is recorded.', 'walletup'),
                'default' => 'checkout',
                'options' => [
                    'checkout' => __('On the checkout page (before the order is placed)', 'walletup'),
                    'order_received' => __('After the order is placed (on the order confirmation page)', 'walletup'),
                ],
                'desc_tip' => true,
            ]
        ];

        if ($this->registry) {
            foreach ($this->registry->getAllMethods() as $methodId => $method) {
                $this->form_fields["enable_{$methodId}"] = [
                    /* translators: %s: Payment method name */
                    'title' => sprintf(__('Enable %s', 'walletup'), ucfirst($methodId)),
                    'type' => 'checkbox',
                    /* translators: %s: Payment method name */
                    'label' => sprintf(__('Enable %s payments', 'walletup'), ucfirst($methodId)),
                    'default' => 'yes'
                ];
            }
        }
    }

    public function is_available(): bool
    {
        try {
            if ($this->enabled !== 'yes') {
                return false;
            }

            if (!$this->registry || !$this->registry->isInitialized()) {
                return false;
            }

            if (isset($_SERVER['REQUEST_URI']) && strpos(sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '')), '/wc/store/') !== false) {
                return true;
            }
            
            $valid_contexts = [
                is_checkout(),
                is_wc_endpoint_url('order-pay'),
                is_admin(),
                wp_doing_ajax()
            ];

            if (!array_filter($valid_contexts)) {
                return false;
            }

            $availableMethods = $this->registry->getAllMethods();
            
            if (!empty($availableMethods)) {
                $this->errorHandler->log('Gateway available with ' . count($availableMethods) . ' methods from registry');
                return true;
            }
            
            $hasEnabledMethod = false;
            foreach ($this->get_form_fields() as $key => $field) {
                if (strpos($key, 'enable_') === 0 && $this->get_option($key) === 'yes') {
                    $hasEnabledMethod = true;
                    break;
                }
            }
            
            if (!$hasEnabledMethod) {
                return false;
            }

            return true;

        } catch (Exception $e) {
            $this->errorHandler->log('Gateway availability error: ' . $e->getMessage());
            return false;
        }
    }

    public function supports_hpos(): bool
    {
        return true;
    }

    public function payment_fields(): void
    {
        $is_blocks = $this->isBlocksCheckout();
        $this->errorHandler->log('payment_fields() called - isBlocksCheckout: ' . ($is_blocks ? 'true' : 'false'));
        
        if ($is_blocks) {
            $this->errorHandler->log('Skipping payment_fields() for blocks checkout - handled by blocks registration');
            return;
        }
        
        try {
            if ($description = $this->get_description()) {
                echo wp_kses_post(wpautop(wptexturize($description)));
            }

            $config = WooCommerceConfig::getInstance();
            $settings = $config->getSettings();

            $methods = [];
            $valid_methods = PaymentMethodsConfig::getAllMethodIds();
            $cart_total = WC()->cart ? (float) WC()->cart->get_total('') : 0;

            $display_mode = get_option('wallet_up_wc_display_mode', 'grouped');
            $mixed_individual = [];
            if ($display_mode === 'mixed') {
                $mixed_config = get_option('wallet_up_wc_mixed_methods', []);
                $mixed_individual = $mixed_config['individual'] ?? [];
            }

            $walletSettings = get_option('wallet_up_transfer_call', []);
            $mmSettings = get_option('wallet_up_mobile_money_settings', []);

            foreach ($settings['methodSettings'] as $methodId => $methodStatus) {
                if (!in_array($methodId, $valid_methods)) {
                    continue;
                }

                if ($display_mode === 'mixed' && in_array($methodId, $mixed_individual, true)) {
                    continue;
                }

                if ($cart_total > 0 && !WooCommerceMethodSettings::isAmountInRange($methodId, $cart_total)) {
                    continue;
                }

                if ($methodStatus) {
                    if (!\WalletUpPro\Core\Payments\MethodStatusResolver::storeShows((string) $methodId)) {
                        continue;
                    }

                    if (!\WalletUpPro\Core\Payments\MethodStatusResolver::isReady($methodId)) {
                        continue;
                    }
                    if (!\WalletUpPro\Core\Payments\Availability\MethodAvailability::shows((string) $methodId, 'store')) {
                        continue;
                    }

                    $method = $this->registry->getMethod($methodId);
                    if ($method) {
                        $desc = WooCommerceMethodSettings::get($methodId, 'description');
                        if ($desc) {
                            $desc = WooCommerceMethodSettings::processVariables($desc, $methodId);
                        }
                        $methods[$methodId] = [
                            'name' => WooCommerceMethodSettings::getEffectiveTitle($methodId),
                            'icon' => $this->get_method_icon($methodId),
                            'description' => $desc ?: '',
                        ];
                    }
                }
            }

            if (empty($methods)) {
                throw new Exception(esc_html(__('No payment methods are currently enabled.', 'walletup')));
            }

            $initialMethod = array_key_first($methods);
            $sessionMethod = (WC()->session && is_callable([WC()->session, 'get']))
                ? (string) WC()->session->get('wallet_up_selected_method', '')
                : '';
            if ($sessionMethod !== '' && isset($methods[$sessionMethod])) {
                $initialMethod = $sessionMethod;
            }

            $displayAmount = WC()->cart && is_callable([WC()->cart, 'get_total'])
                ? (float) WC()->cart->get_total('edit')
                : 0.0;

            $initialHtml = '';
            try {
                $initialHtml = WooCommerceIntegration::getInstance()->buildCheckoutDisplayHtml($initialMethod, $displayAmount, false);
            } catch (\Throwable $e) {
                $this->errorHandler->log('Initial checkout display render failed for ' . $initialMethod . ': ' . $e->getMessage());
            }
            $displayAmountFmt = number_format($displayAmount, 2, '.', '');

            echo '<div class="wallet-up-modern-wrapper">';
            echo '<div class="wallet-up-methods-container">';
            foreach ($methods as $id => $method) {
                echo sprintf(
                    '<div class="wallet-up-method-item%s" data-method="%s">
                         <img src="%s" alt="%s" class="wallet-up-method-icon" />
                         <span class="wallet-up-method-name">%s</span>
                     </div>',
                    $id === $initialMethod ? ' selected' : '',
                    esc_attr($id),
                    esc_url($method['icon']),
                    esc_attr($method['name']),
                    esc_html($method['name'])
                );
            }
            echo '</div>';

            echo '<select name="wallet_up_payment_method" id="wallet_up_payment_method" style="display: none;">';
            foreach ($methods as $id => $method) {
                echo '<option value="' . esc_attr($id) . '"' . selected($id, $initialMethod, false) . '>' . esc_html($method['name']) . '</option>';
            }
            echo '</select>';

            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated by DisplayHandler::generateDisplay
            echo '<div id="wallet-up-payment-display" class="wallet-up-payment-display" data-wu-method="' . esc_attr($initialMethod) . '" data-wu-amount="' . esc_attr($displayAmountFmt) . '">' . $initialHtml . '</div>';

            $cashappApiSettings = get_option('wallet_up_cashapp_api_settings', []);
            $venmoApiSettings = get_option('wallet_up_venmo_api_settings', []);

            $merchant_profile = get_option('wallet_up_transfer_call', []);

            $nonceDisplay = wp_create_nonce('wallet_up_wc_payment_display'); // zi2-dry: ignore
            $checkout_data = [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonces' => [
                    'payment' => $nonceDisplay,
                    'display' => $nonceDisplay,
                    'wcPaymentDisplay' => $nonceDisplay,
                ],
                'amount' => $displayAmount,
                'separators' => [
                    'decimal' => wc_get_price_decimal_separator(),
                    'thousand' => wc_get_price_thousand_separator(),
                ],
                'initialMethod' => $initialMethod,
                'methods' => $methods,
                'i18n' => [
                    'loadError' => __('We could not load the payment details. Please try again.', 'walletup'),
                ],
                'settings' => [
                    'display' => $settings['wcSettings']['display_mode'] ?? 'qr',
                    'qr' => $settings['wcQRSettings']
                ],
                'automatedApis' => [
                    'cashapp' => [
                        'enabled' => !empty($cashappApiSettings['enabled']),
                        'clientId' => $cashappApiSettings['client_id'] ?? '',
                        'sandbox' => ($cashappApiSettings['environment'] ?? 'sandbox') === 'sandbox',
                    ],
                    'venmo' => [
                        'enabled' => !empty($venmoApiSettings['enabled']),
                        'sandbox' => ($venmoApiSettings['environment'] ?? 'sandbox') === 'sandbox',
                    ],
                ],
                'merchant' => [
                    'name'  => sanitize_text_field($merchant_profile['holder_owner'] ?? ''),
                    'email' => sanitize_email($merchant_profile['holder_email'] ?? ''),
                    'phone' => sanitize_text_field($merchant_profile['holder_no'] ?? ''),
                ],
            ];

            echo '<script>window.walletUpCheckout = ' . wp_json_encode($checkout_data) . ';'
                . 'window.walletUpPro = window.walletUpPro || {};'
                . 'window.walletUpPro.cache = window.walletUpPro.cache || {};'
                . 'window.walletUpPro.selectedMethod = window.walletUpPro.selectedMethod || ' . wp_json_encode($initialMethod) . ';'
                . '</script>';

            echo '<input type="hidden" name="wallet_up_payment_nonce" value="' . esc_attr($nonceDisplay) . '">';

            ?>
            <style>
                .wallet-up-modern-wrapper {
                    margin: 6px 0 0;
                    padding: 20px;
                    background: #ffffff;
                    border-radius: 16px;
                    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
                    box-sizing: border-box;
                    width: 100%;
                    max-width: 100%;
                }

                .wallet-up-modern-wrapper * {
                    box-sizing: border-box;
                }

                .wallet-up-methods-container {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
                    gap: 12px;
                    margin-bottom: 20px;
                }

                .woocommerce #payment .wallet-up-modern-wrapper .wallet-up-method-item {
                    display: flex !important;
                    flex-direction: column !important;
                    align-items: center !important;
                    justify-content: center !important;
                    gap: 6px !important;
                    min-height: 0 !important;
                    height: auto !important;
                    padding: 14px !important;
                    border-radius: 12px;
                    background: #ffffff; /* zi2-dry: ignore */
                    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
                    cursor: pointer;
                    text-align: center !important;
                    float: none !important;
                    transition: border-color 0.2s ease, background 0.2s ease, box-shadow 0.2s ease;
                    border: 2px solid transparent;
                }

                .woocommerce #payment .wallet-up-modern-wrapper .wallet-up-method-item:hover {
                    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
                }

                .woocommerce #payment .wallet-up-modern-wrapper .wallet-up-method-item.selected {
                    border-color: #674FBF; /* zi2-dry: ignore */
                    background: #F4F4FF; /* zi2-dry: ignore */
                }

                .woocommerce #payment .wallet-up-modern-wrapper .wallet-up-method-name {
                    display: block !important;
                    width: 100% !important;
                    order: 1 !important;
                    float: none !important;
                    margin: 0 !important;
                    font-size: 14px;
                    font-weight: 600;
                    color: #333333; /* zi2-dry: ignore */
                    text-align: center !important;
                }

                .woocommerce #payment .wallet-up-modern-wrapper .wallet-up-method-icon {
                    order: 2 !important;
                    float: none !important;
                    display: block !important;
                    width: 40px !important;
                    height: 40px !important;
                    max-height: 40px !important;
                    object-fit: contain;
                    margin: 0 auto !important;
                }

                .wallet-up-payment-display {
                    padding: 20px;
                    margin-top: 20px;
                    border-radius: 12px;
                    background: #F4F4FF;
                    min-height: 120px;
                }

                .wallet-up-payment-display img {
                    max-width: 100%;
                    height: auto;
                }

                .wallet-up-payment-display .loading {
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    padding: 20px;
                    min-height: 120px;
                }

                .wallet-up-payment-display .loading:after {
                    content: '';
                    width: 28px;
                    height: 28px;
                    border: 3px solid #674FBF; /* zi2-dry: ignore */
                    border-radius: 50%;
                    border-top-color: transparent;
                    animation: walletUpSpin 0.8s linear infinite;
                }

                @keyframes walletUpSpin {
                    to {
                        transform: rotate(360deg);
                    }
                }

                .wallet-up-modern-wrapper .woocommerce-error {
                    border-radius: 8px;
                    padding: 12px;
                    margin: 10px 0;
                    background: #fff2f2;
                    color: #d63031;
                    border-left: 4px solid #d63031;
                }

                .woocommerce #payment .payment_methods li img {
                    float: none !important;
                    max-height: none !important;
                }

                @media (max-width: 600px) {
                    .wallet-up-modern-wrapper { padding: 14px; }
                    .wallet-up-methods-container { gap: 10px; }
                    .wallet-up-method-icon { width: 38px; height: 38px; }
                    .wallet-up-payment-display { padding: 14px; }
                }
            </style>

            <script type="text/javascript">
                (function () {
                    window.walletUpPro = window.walletUpPro || {};
                    window.walletUpPro.cache = window.walletUpPro.cache || {};
                    window.walletUpPro.epoch = window.walletUpPro.epoch || 0;
                })();
                jQuery(function ($) {
                    var cfg = window.walletUpCheckout;
                    if (!cfg || !cfg.ajaxurl) { return; }

                    var SEL_DISPLAY = '#wallet-up-payment-display';
                    var SEL_ITEM = '.wallet-up-method-item';

                    function currentTotal() {
                        var $t = $('.order-total').find('.woocommerce-Price-amount, .amount, bdi').last();
                        var raw = $t.length ? $t.text() : '';
                        var sep = (cfg && cfg.separators) || { decimal: '.', thousand: ',' };
                        if (sep.thousand) { raw = raw.split(sep.thousand).join(''); }
                        if (sep.decimal && sep.decimal !== '.') { raw = raw.split(sep.decimal).join('.'); }
                        var n = parseFloat(raw.replace(/[^0-9.\-]/g, ''));
                        if (isNaN(n)) { n = parseFloat(cfg.amount); }
                        return isNaN(n) ? '' : n.toFixed(2);
                    }

                    function keyFor(method) { return method + '|' + window.walletUpPro.epoch; }

                    function errorHtml() {
                        return '<div class="woocommerce-error">' + ((cfg.i18n && cfg.i18n.loadError) || '') + '</div>';
                    }

                    function fetchDisplay(method, key) {
                        return $.ajax({
                            url: cfg.ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'wallet_up_wc_payment_display', // zi2-dry: ignore
                                method: method,
                                amount: currentTotal(),
                                nonce: cfg.nonces.display,
                                record: 0
                            }
                        }).done(function (response) {
                            if (response && response.success && response.data && response.data.html) {
                                window.walletUpPro.cache[key] = response.data.html;
                                var cur = window.walletUpPro.selectedMethod || method;
                                if (cur === method && keyFor(cur) === key) {
                                    $(SEL_DISPLAY).css('min-height', '').html(response.data.html).data('wuKey', key);
                                }
                            } else if ($(SEL_DISPLAY).data('wuKey') !== key) {
                                $(SEL_DISPLAY).css('min-height', '').html(errorHtml());
                            }
                        }).fail(function () {
                            if ($(SEL_DISPLAY).data('wuKey') !== key) {
                                $(SEL_DISPLAY).css('min-height', '').html(errorHtml());
                            }
                        });
                    }

                    function render(method) {
                        if (!method) { return; }
                        var $c = $(SEL_DISPLAY);
                        var key = keyFor(method);
                        if ($c.data('wuKey') === key) { return; }
                        if (window.walletUpPro.cache[key]) {
                            $c.css('min-height', '').html(window.walletUpPro.cache[key]).data('wuKey', key);
                        } else {
                            var h = $c.outerHeight();
                            $c.html('<div class="loading"></div>').data('wuKey', null);
                            if (h && h > 60) { $c.css('min-height', h + 'px'); }
                            fetchDisplay(method, key);
                        }
                    }

                    function selectMethod(method) {
                        if (!method) { return; }
                        window.walletUpPro.selectedMethod = method;
                        $(SEL_ITEM).removeClass('selected')
                            .filter('[data-method="' + method + '"]').addClass('selected');
                        $('#wallet_up_payment_method').val(method);
                        render(method);
                    }

                    function adoptInline(method) {
                        var d = document.querySelector(SEL_DISPLAY);
                        if (!d || !d.querySelector('img')) { return false; }
                        if (d.getAttribute('data-wu-method') === method && d.getAttribute('data-wu-amount') === currentTotal()) {
                            var key = keyFor(method);
                            window.walletUpPro.cache[key] = d.innerHTML;
                            $(SEL_DISPLAY).data('wuKey', key);
                            return true;
                        }
                        return false;
                    }

                    function bindItems() {
                        $(SEL_ITEM).off('click.walletUpPro').on('click.walletUpPro', function () {
                            var method = $(this).data('method');
                            selectMethod(method);
                            var $radio = $(this).closest('li.wc_payment_method').find('input[name=payment_method]');
                            if (!$radio.length) { $radio = $('input[name=payment_method][value="wallet_up"]'); }
                            if ($radio.length && !$radio.prop('checked')) {
                                $radio.prop('checked', true).trigger('change');
                            }
                            $('#wallet_up_payment_method').trigger('change');
                        });
                    }

                    function currentMethod() {
                        return window.walletUpPro.selectedMethod
                            || $('#wallet_up_payment_method').val()
                            || $(SEL_ITEM).first().data('method')
                            || cfg.initialMethod;
                    }

                    function sync() {
                        var method = currentMethod();
                        window.walletUpPro.selectedMethod = method;
                        $(SEL_ITEM).removeClass('selected')
                            .filter('[data-method="' + method + '"]').addClass('selected');
                        $('#wallet_up_payment_method').val(method);
                        bindItems();
                        if (adoptInline(method)) { return; }
                        render(method);
                    }

                    $(document.body).off('updated_checkout.walletUpPro').on('updated_checkout.walletUpPro', function () {
                        var t = currentTotal();
                        if (t !== window.walletUpPro.lastTotal) {
                            window.walletUpPro.lastTotal = t;
                            window.walletUpPro.epoch++;
                            window.walletUpPro.cache = {};
                        }
                        sync();
                    });
                    sync();
                });
            </script>

            <?php

        } catch (\Throwable $e) {
            $this->errorHandler->log('Payment fields error: ' . $e->getMessage());
            echo '<p class="woocommerce-error">' . esc_html($e->getMessage()) . '</p>';
        }
    }

    public function process_payment($order_id): array
    {
        \WalletUpPro\Core\Tenancy\Tenant::enterOrder($order_id);
        try {
            $order = wc_get_order($order_id);
            if (!$order) {
                throw new Exception(esc_html(__('Invalid order ID', 'walletup')));
            }

            $methodId = null;
            $nonce = null;
            
            // phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce process_payment callback; WC verifies the checkout nonce.
            if (isset($_POST['wallet_up_payment_method'])) {
                $methodId = sanitize_text_field(wp_unslash($_POST['wallet_up_payment_method']));
                $nonce = isset($_POST['wallet_up_payment_nonce']) ? sanitize_text_field(wp_unslash($_POST['wallet_up_payment_nonce'])) : null;
            }

            if (!$methodId && isset($_POST['payment_method_data'])) {
                $payment_data = json_decode(sanitize_text_field(wp_unslash($_POST['payment_method_data'])), true);
                if (isset($payment_data['wallet_up_payment_method'])) {
                    $methodId = sanitize_text_field($payment_data['wallet_up_payment_method']);
                    $nonce = isset($payment_data['wallet_up_payment_nonce']) ? sanitize_text_field($payment_data['wallet_up_payment_nonce']) : null;
                }
            }
            // phpcs:enable WordPress.Security.NonceVerification.Missing
            
            if (!$methodId) {
                $temp_method = $order->get_meta('_wallet_up_selected_method');
                if ($temp_method) {
                    $methodId = $temp_method;
                    $nonce = 'blocks_checkout';
                }
            }
            
            if (!$methodId) {
                throw new Exception(esc_html(__('No payment method selected', 'walletup')));
            }
            
            if ($nonce && $nonce !== 'blocks_checkout' && !wp_verify_nonce($nonce, 'wallet_up_wc_payment_display')) {
                $this->errorHandler->log('Nonce validation failed. Expected: wallet_up_wc_payment_display, Received: ' . $nonce);
                if (isset($_POST['wallet_up_payment_method'])) {
                    throw new Exception(esc_html(__('Invalid security token', 'walletup')));
                }
            }
            $method = $this->registry->getMethod($methodId);
            if (!$method) {
                throw new Exception(esc_html(__('Invalid payment method selected', 'walletup')));
            }
            $resolver = '\\WalletUpPro\\Core\\Payments\\MethodStatusResolver';
            $startsNewPayment = $order->get_meta('_wallet_up_payment_method') !== $methodId;
            if ($startsNewPayment
                && in_array($methodId, PaymentMethodsConfig::getAllMethodIds(), true)
                && (!$resolver::isReady($methodId) || !$resolver::storeShows($methodId))) {
                throw new Exception(esc_html(__('That payment method isn’t available right now. Please choose another one.', 'walletup')));
            }
            if ($startsNewPayment && !\WalletUpPro\Core\Payments\Availability\MethodAvailability::canStart($methodId, 'store')) {
                throw new Exception(esc_html(\WalletUpPro\Core\Payments\Availability\MethodAvailability::refusal()));
            }

            $order->update_meta_data('_wallet_up_payment_method', $methodId);
            $order->update_meta_data('_wallet_up_payment_initiated', current_time('mysql'));
            $order->set_payment_method($this->id);
            $order->set_payment_method_title(WooCommerceMethodSettings::getEffectiveTitle($methodId));

            $providerMap = ['stripe' => 'stripe', 'paypal_commerce' => 'paypal', 'paypal' => 'paypal'];
            if (isset($providerMap[$methodId])) {
                $order->update_meta_data('_walletup_provider', $providerMap[$methodId]);
            }

            $merchant_profile = get_option('wallet_up_transfer_call', []);
            if (!empty($merchant_profile['holder_owner'])) {
                $order->update_meta_data('_wallet_up_merchant_name', sanitize_text_field($merchant_profile['holder_owner']));
            }
            if (!empty($merchant_profile['holder_email'])) {
                $order->update_meta_data('_wallet_up_merchant_email', sanitize_email($merchant_profile['holder_email']));
            }
            if (!empty($merchant_profile['holder_no'])) {
                $order->update_meta_data('_wallet_up_merchant_phone', sanitize_text_field($merchant_profile['holder_no']));
            }

            $alreadyHasDiscount = false;
            foreach ($order->get_fees() as $fee) {
                if (strpos($fee->get_name(), 'off -') !== false || strpos($fee->get_name(), 'off -') !== false) {
                    $alreadyHasDiscount = true;
                    break;
                }
            }
            $discountType = WooCommerceMethodSettings::get($methodId, 'discount_type');
            $discountValue = (float) WooCommerceMethodSettings::get($methodId, 'discount_value', 0);
            if (!$alreadyHasDiscount && !empty($discountType) && $discountValue > 0 && WooCommerceMethodSettings::isAmountInRange($methodId, (float) $order->get_subtotal())) {
                $methodTitle = WooCommerceMethodSettings::getEffectiveTitle($methodId);
                $feeAmount = 0;
                $feeLabel = '';

                if ($discountType === 'percentage') {
                    $feeAmount = -($order->get_subtotal() * ($discountValue / 100));
                    /* translators: 1: discount percentage, 2: payment method name */
                    $feeLabel = sprintf(__('%1$s%% off - %2$s', 'walletup'), $discountValue, $methodTitle);
                } elseif ($discountType === 'fixed') {
                    $feeAmount = -$discountValue;
                    $currency = get_woocommerce_currency_symbol();
                    /* translators: 1: currency symbol, 2: discount amount, 3: payment method name */
                    $feeLabel = sprintf(__('%1$s%2$s off - %3$s', 'walletup'), $currency, number_format($discountValue, 2), $methodTitle);
                }

                if ($feeAmount < 0) {
                    $fee = new \WC_Order_Item_Fee();
                    $fee->set_name($feeLabel);
                    $fee->set_total($feeAmount);
                    $fee->set_tax_status('none');
                    $order->add_item($fee);
                    $order->calculate_totals();
                    $order->add_order_note(sprintf(
                        /* translators: 1: discount label, 2: formatted discount amount */
                        __('Payment discount applied: %1$s (%2$s)', 'walletup'),
                        $feeLabel,
                        wc_price($feeAmount)
                    ));
                }
            }

            $order->save();

            if (PaymentMethodsConfig::isMobileMoney($methodId)) {
                if ($this->ensureTransactionTableReady()) try {
                    $paymentProcessor = PaymentProcessor::getInstance();
                    
                    $paymentData = [
                        'email' => $order->get_billing_email(),
                        'first' => $order->get_billing_first_name(),
                        'last' => $order->get_billing_last_name(),
                        'phone' => $order->get_billing_phone(),
                        'amt' => $order->get_total(),
                        'currency' => $order->get_currency(),
                        /* translators: %s: Order number */
                        'description' => sprintf(__('Order #%s', 'walletup'), $order->get_order_number()),
                        'wc_order_id' => $order->get_id(),
                        'metadata' => [
                            'payment_method' => $methodId,
                            'order_key' => $order->get_order_key(),
                            'return_url' => $this->get_return_url($order),
                            'cancel_url' => wc_get_cart_url()
                        ]
                    ];

                    $paymentSession = $paymentProcessor->initializePayment($paymentData);

                    $paymentProcessor->updatePaymentStatus($paymentSession['payment_id'], 'pending', [
                        'payment_method' => $methodId,
                    ]);

                    $order->update_meta_data('_wallet_up_payment_id', $paymentSession['payment_id']);
                    $order->update_meta_data('_wallet_up_session_id', $paymentSession['session_id']);
                    $order->save();
                    
                    return [
                        'result' => 'success',
                        'redirect' => $paymentSession['payment_url'] . '?method=' . $methodId
                    ];
                    
                } catch (Exception $e) {
                    $this->errorHandler->log('Mobile money payment initialization failed: ' . $e->getMessage());
                    wc_add_notice(__('Unable to initialize mobile money payment. Please try again.', 'walletup'), 'error');
                    return ['result' => 'failure', 'redirect' => ''];
                }
            }

            if (function_exists('wcs_order_contains_subscription') && wcs_order_contains_subscription($order)) {
                return $this->processSubscriptionOrder($order, $methodId);
            }

            if ($methodId === 'paypal_commerce') {
                return $this->processPayPalOneTimeOrder($order);
            }

            if ($this->ensureTransactionTableReady()) try {
                $paymentProcessor = PaymentProcessor::getInstance();
                $paymentData = [
                    'email' => $order->get_billing_email(),
                    'first' => $order->get_billing_first_name(),
                    'last' => $order->get_billing_last_name(),
                    'phone' => $order->get_billing_phone(),
                    'amt' => $order->get_total(),
                    'currency' => $order->get_currency(),
                    /* translators: %s: Order number */
                    'description' => sprintf(__('Order #%s', 'walletup'), $order->get_order_number()),
                    'wc_order_id' => $order->get_id(),
                    'metadata' => [
                        'payment_method' => $methodId,
                        'order_id' => $order->get_id(),
                        'order_key' => $order->get_order_key(),
                        'return_url' => $this->get_return_url($order),
                    ]
                ];

                $paymentSession = $paymentProcessor->initializePayment($paymentData);

                $order->update_meta_data('_wallet_up_payment_id', $paymentSession['payment_id']);
                $order->update_meta_data('_wallet_up_session_id', $paymentSession['session_id']);

                $paymentProcessor->updatePaymentStatus($paymentSession['payment_id'], 'pending', [
                    'payment_method' => $methodId,
                ]);

                $order->save();
            } catch (Exception $e) {
                $this->errorHandler->log('Transaction record creation failed (non-fatal): ' . $e->getMessage());
            }

            $orderStatus = WooCommerceMethodSettings::getEffectiveOrderStatus($methodId);
            /* translators: %s: Payment method name */
            $order->update_status($orderStatus, sprintf(
                __('Awaiting %s payment', 'walletup'),
                WooCommerceMethodSettings::getEffectiveTitle($methodId)
            ));

            return [
                'result' => 'success',
                'redirect' => add_query_arg([
                    'order-received' => $order->get_id(),
                    'key' => $order->get_order_key()
                ], $this->get_return_url($order))
            ];

        } catch (Exception $e) {
            $this->errorHandler->log('Payment processing error: ' . $e->getMessage());
            wc_add_notice($e->getMessage(), 'error');
            return ['result' => 'failure', 'redirect' => ''];
        }
    }

    public function custom_payment_complete_redirect($order_id)
    {
        try {
            $order = wc_get_order($order_id);
            if (!$order) {
                throw new Exception(esc_html(__('Order not found', 'walletup')));
            }

            // phpcs:disable WordPress.Security.NonceVerification.Recommended -- WooCommerce payment redirect handler; WC manages order key verification.
            $is_pay_for_order = isset($_GET['pay_for_order']) && sanitize_text_field(wp_unslash($_GET['pay_for_order'] ?? ''));

            if ($is_pay_for_order || (isset($_GET['key']) && sanitize_text_field(wp_unslash($_GET['key'] ?? '')) === $order->get_order_key())) {
            // phpcs:enable WordPress.Security.NonceVerification.Recommended
                wp_safe_redirect(add_query_arg([
                    'order-received' => $order_id,
                    'key' => $order->get_order_key(),
                    'paid_from_history' => $is_pay_for_order ? '1' : '0'
                ], wc_get_endpoint_url('order-received', '', wc_get_checkout_url())));
                exit;
            }
        } catch (Exception $e) {
            $this->errorHandler->log('Redirect error: ' . $e->getMessage());
        }
    }

    public function show_method_id_in_order_details($order)
    {
        try {
            $this->errorHandler->log('Attempting to show method ID for order ' . $order->get_id());
            $methodId = $order->get_meta('_wallet_up_payment_method');
            if ($methodId) {
                echo '<p>' . esc_html__('Payment Method ID:', 'walletup') . ' ' . esc_html($methodId) . '</p>';
            } else {
                $this->errorHandler->log('No method ID found for order ' . $order->get_id());
            }
        } catch (Exception $e) {
            $this->errorHandler->log('Error showing method ID in admin: ' . $e->getMessage());
        }
    }
    private function generateMessage($amount, $method, $settings)
    {
        if (!empty($settings['hide_message'])) {
            return '';
        }

        $show_default = isset($settings['show_default_message']) ? $settings['show_default_message'] : true;
        $custom_message = $settings['custom_message'] ?? '';

        $qr_size = self::QR_DIMENSIONS[$settings['size'] ?? 'medium'] ?? 180;

        if (empty($amount) && !$show_default && empty($custom_message)) {
            return '';
        }

        if ($show_default || empty($custom_message)) {
            $formatted_amount = empty($amount) ? 'an amount' : sprintf('$%s', esc_html(number_format(floatval($amount), 2)));
            return sprintf(
                '<div class="walletup-message" style="font-size: 14px; text-align: center; width: %spx; margin: 0 auto 10px;">Please <strong>Scan or Click</strong> to Pay <strong>%s</strong> with %s</div>',
                $qr_size,
                $formatted_amount,
                esc_html(ucfirst($method))
            );
        } else {
            $formatted_amount = empty($amount) ? 'an amount' : sprintf('$%s', esc_html(number_format(floatval($amount), 2)));
            $message = str_replace(
                ['$amount', '$method'],
                [strpos($custom_message, '$amount') !== false ? $formatted_amount : esc_html(number_format(floatval($amount), 2)), ucfirst($method)],
                $custom_message
            );
            return sprintf(
                '<div class="walletup-message" style="font-size: 14px; text-align: center; width: %spx; margin: 0 auto 10px;">%s</div>',
                $qr_size,
                wp_kses($message, array('strong' => array()))
            );
        }
    }

    public function getDisplaySettings(): array
    {
        $config = WooCommerceConfig::getInstance();
        $settings = $config->getSettings();

        return array_merge([
            'defaultMode' => $settings['wcSettings']['display_mode'] ?? 'qr',
        ], $settings['wcQRSettings']);
    }
    private function generateGenericLink($url, $amount, $method)
    {
        return sprintf(
            '<a href="%s" target="_blank" rel="noopener">Pay %s with %s</a>',
            esc_url($url),
            esc_html($amount),
            esc_html(ucfirst($method))
        );
    }

 
    public function generatePaymentDisplay($method, $order, $methodId)
    {
        $displaySettings = $this->getDisplaySettings();

        $methodDesc = WooCommerceMethodSettings::get($methodId, 'description');
        if (!empty($methodDesc)) {
            $displaySettings['customMessage'] = WooCommerceMethodSettings::processVariables(
                $methodDesc, $methodId, [
                    'amount' => $order->get_total(),
                    'currency' => $order->get_currency(),
                    'order' => $order,
                ]
            );
            $displaySettings['showDefaultMessage'] = false;
            $displaySettings['hideMessage'] = false;
        }

        $checkout_context = [
            'is_checkout' => true,
            'order_id' => $order->get_id(),
            'product_title' => ''
        ];

        $items = $order->get_items();
        if (!empty($items)) {
            $first_item = reset($items);
            $checkout_context['product_title'] = $first_item->get_name();
        }

        $note = WooCommerceMethodSettings::getPaymentNote($methodId, [
            'order' => $order,
            'amount' => $order->get_total(),
            'currency' => $order->get_currency(),
        ]);
        return $this->displayHandler->generateDisplay(
            $displaySettings['defaultMode'],
            $method->getPaymentUrl(
                $order->get_total(),
                $note,
                $checkout_context
            ),
            $methodId,
            $displaySettings,
            [
                'amount' => $order->get_total(),
                'order_id' => $order->get_id(),
                'currency' => $order->get_currency(),
                'checkout_context' => $checkout_context
            ]
        );
    }

    private function get_method_icon(string $methodId): string
    {
        return \WalletUpPro\Core\Display\DisplayHandler::methodLogoUrl($methodId);
    }
    public function getMethodFromRegistry($methodId)
    {
        return $this->registry->getMethod($methodId);
    }

    private function validateEventType(string $eventType): bool
    {
        return in_array($eventType, self::VALID_EVENTS, true);
    }

    private function get_payment_status_html($order): string
    {
        $status = $order->get_status();
        $methodId = $order->get_meta('_wallet_up_payment_method');
        $method_name = ucfirst($methodId);

        $statuses = [
            'pending' => [
                'class' => 'pending',
                /* translators: %s: Payment method name */
                'message' => sprintf(
                    __('Waiting for %s payment confirmation.', 'walletup'),
                    $method_name
                )
            ],
            'on-hold' => [
                'class' => 'on-hold',
                'message' => __('Payment is being verified.', 'walletup')
            ],
            'processing' => [
                'class' => 'processing',
                'message' => __('Payment successfully received.', 'walletup')
            ],
            'completed' => [
                'class' => 'completed',
                'message' => __('Payment completed successfully.', 'walletup')
            ]
        ];

        if (!isset($statuses[$status])) {
            return '';
        }

        return sprintf(
            '<div class="wallet-up-payment-status status-%s"><p>%s</p></div>',
            esc_attr($statuses[$status]['class']),
            esc_html($statuses[$status]['message'])
        );
    }
    public function handle_status_change($order_id, $old_status, $new_status, $order): void
    {
        if (!$order || $order->get_payment_method() !== $this->id) {
            return;
        }

        try {
            $methodId = $order->get_meta('_wallet_up_payment_method');
            if (!$methodId) {
                return;
            }

            $this->analytics->trackEventPublic([
                'event_type' => 'order_status_change',
                'payment_method' => $methodId,
                'order_id' => $order_id,
                'old_status' => $old_status,
                'new_status' => $new_status,
                'amount' => $order->get_total()
            ]);

            switch ($new_status) {
                case 'completed':
                    $this->handle_completed_payment($order);
                    break;
                case 'processing':
                    $this->handle_processing_payment($order);
                    break;
                case 'cancelled':
                    $this->handle_cancelled_payment($order);
                    break;
            }

        } catch (Exception $e) {
            $this->errorHandler->log('Status change error: ' . $e->getMessage());
        }
    }

    private function handle_completed_payment($order): void
    {
        try {
            $methodId = $order->get_meta('_wallet_up_payment_method');

            $paymentId = $order->get_meta('_wallet_up_payment_id');
            if ($paymentId) {
                try {
                    $processor = PaymentProcessor::getInstance();
                    $processor->updatePaymentStatus($paymentId, 'completed', [
                        'payment_method' => $methodId,
                    ]);
                } catch (Exception $e) {
                    $this->errorHandler->log('Transaction sync on completion failed: ' . $e->getMessage());
                }
            }

            $notification = new PaymentNotification($order, 'completed');
            $notification->send();

            $this->analytics->trackEventPublic([
                'event_type' => 'payment_completed',
                'payment_method' => $methodId,
                'order_id' => $order->get_id(),
                'amount' => $order->get_total(),
                'completed_at' => current_time('mysql')
            ]);

            $order->update_meta_data('_wallet_up_payment_completed', current_time('mysql'));
            /* translators: %s: Payment method name */
            $order->add_order_note(
                sprintf(
                    __('Payment completed via %s.', 'walletup'),
                    ucfirst($methodId)
                )
            );
            $order->save();

        } catch (Exception $e) {
            $this->errorHandler->log('Completion handler error: ' . $e->getMessage());
        }
    }

    private function handle_processing_payment($order): void
    {
        try {
            $methodId = $order->get_meta('_wallet_up_payment_method');

            $this->analytics->trackEventPublic([
                'event_type' => 'payment_processing',
                'payment_method' => $methodId,
                'order_id' => $order->get_id(),
                'amount' => $order->get_total()
            ]);

            /* translators: %s: Payment method name */
            $order->add_order_note(
                sprintf(
                    __('Processing %s payment.', 'walletup'),
                    ucfirst($methodId)
                )
            );
            $order->save();

        } catch (Exception $e) {
            $this->errorHandler->log('Processing handler error: ' . $e->getMessage());
        }
    }

    private function handle_cancelled_payment($order): void
    {
        try {
            $methodId = $order->get_meta('_wallet_up_payment_method');

            $this->analytics->trackEventPublic([
                'event_type' => 'payment_cancelled',
                'payment_method' => $methodId,
                'order_id' => $order->get_id(),
                'amount' => $order->get_total(),
                'cancelled_at' => current_time('mysql')
            ]);

            /* translators: %s: Payment method name */
            $order->add_order_note(
                sprintf(
                    __('Payment cancelled for %s.', 'walletup'),
                    ucfirst($methodId)
                )
            );
            $order->save();

        } catch (Exception $e) {
            $this->errorHandler->log('Cancellation handler error: ' . $e->getMessage());
        }
    }

    public function check_payment_response(): void
    {
        try {
            // phpcs:disable WordPress.Security.NonceVerification.Recommended -- WooCommerce API callback (woocommerce_api_wallet_up); order key verification used instead of nonce.
            if (!isset($_GET['order_id']) || !isset($_GET['key'])) {
                throw new Exception(esc_html(__('Invalid payment verification request', 'walletup')));
            }

            $order_id = absint(wp_unslash($_GET['order_id']));
            $order = wc_get_order($order_id);

            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via wc_clean().
            if (!$order || $order->get_order_key() !== wc_clean(wp_unslash($_GET['key']))) {
            // phpcs:enable WordPress.Security.NonceVerification.Recommended
                throw new Exception(esc_html(__('Invalid order', 'walletup')));
            }

            if ($order->get_payment_method() !== $this->id) {
                throw new Exception(esc_html(__('Invalid payment method', 'walletup')));
            }

            $methodId = $order->get_meta('_wallet_up_payment_method');
            if (!$methodId) {
                throw new Exception(esc_html(__('Payment method not found', 'walletup')));
            }

            $status = $this->verify_payment_status($order);

            if ($status['verified']) {
                $order->payment_complete();
                wp_safe_redirect($this->get_return_url($order));
                exit;
            }

            $order->update_status(
                'pending',
                __('Awaiting payment verification.', 'walletup')
            );

            wp_safe_redirect(wc_get_endpoint_url(
                'order-received',
                $order->get_id(),
                wc_get_checkout_url()
            ));
            exit;

        } catch (Exception $e) {
            $this->errorHandler->log('Payment verification error: ' . $e->getMessage());
            wp_safe_redirect(wc_get_cart_url());
            exit;
        }
    }

    private function verify_payment_status($order): array
    {
        $methodId = $order->get_meta('_wallet_up_payment_method');
        $method = $this->registry->getMethod($methodId);

        if (!$method) {
            return ['verified' => false, 'message' => __('Invalid payment method', 'walletup')];
        }

        try {
            $this->analytics->trackEventPublic([
                'event_type' => 'payment_verification',
                'payment_method' => $methodId,
                'order_id' => $order->get_id(),
                'amount' => $order->get_total()
            ]);

            /* translators: %s: Payment method name */
            $order->add_order_note(
                sprintf(
                    __('Verifying %s payment.', 'walletup'),
                    ucfirst($methodId)
                )
            );
            $order->save();

            return [
                'verified' => true,
                /* translators: %s: Payment method name */
                'message' => sprintf(
                    __('%s payment verified successfully.', 'walletup'),
                    ucfirst($methodId)
                )
            ];

        } catch (Exception $e) {
            $this->errorHandler->log('Payment verification error: ' . $e->getMessage());
            return ['verified' => false, 'message' => $e->getMessage()];
        }
    }

    public function process_refund($order_id, $amount = null, $reason = ''): bool
    {
        \WalletUpPro\Core\Tenancy\Tenant::enterOrder($order_id);
        try {
            $order = wc_get_order($order_id);
            if (!$order) {
                throw new Exception(esc_html(__('Invalid order ID', 'walletup')));
            }

            $methodId = $order->get_meta('_wallet_up_payment_method');
            if (!$methodId) {
                throw new Exception(esc_html(__('Payment method not found', 'walletup')));
            }

            $methodName = PaymentMethodsConfig::getMethodName($methodId) ?: ucfirst($methodId);
            $provider = $order->get_meta('_walletup_provider');
            $providerTxnId = $order->get_meta('_walletup_provider_transaction_id');

            if (empty($provider)) {
                $providerMap = ['stripe' => 'stripe', 'paypal_commerce' => 'paypal', 'paypal' => 'paypal'];
                $provider = $providerMap[$methodId] ?? '';
            }
            if (empty($providerTxnId) && $provider === 'stripe') {
                $providerTxnId = $order->get_meta('_stripe_payment_intent_id');
            }
            if (empty($providerTxnId) && $provider === 'paypal') {
                $providerTxnId = $order->get_meta('_walletup_paypal_capture_id');
            }
            $apiRefunded = false;

            if ($provider === 'stripe' && !empty($providerTxnId) && class_exists('\\Stripe\\Stripe')) {
                try {
                    $stripeConfig = \WalletUpPro\Core\Payments\PaymentGatewayHandler::getStripeConfig();
                    $secretKey = !empty($stripeConfig['test_mode'])
                        ? ($stripeConfig['test_secret_key'] ?? '')
                        : ($stripeConfig['live_secret_key'] ?? '');

                    \Stripe\Stripe::setApiKey($secretKey);
                    $refundParams = ['payment_intent' => $providerTxnId];
                    if ($amount) {
                        $refundParams['amount'] = \WalletUpPro\Core\Payments\PaymentGatewayHandler::toMinorUnits(
                            (float) $amount,
                            $order->get_currency()
                        );
                    }
                    if ($reason) {
                        $refundParams['reason'] = 'requested_by_customer';
                        $refundParams['metadata'] = ['reason' => $reason];
                    }
                    $refund = \Stripe\Refund::create($refundParams);
                    $apiRefunded = ($refund->status === 'succeeded' || $refund->status === 'pending');

                    $order->add_order_note(sprintf(
                        /* translators: 1: Refund amount, 2: Stripe refund ID */
                        esc_html__('WalletUp: Stripe refund of %1$s processed (Refund ID: %2$s).', 'walletup'),
                        wc_price($amount),
                        $refund->id
                    ));
                } catch (Exception $e) {
                    $order->add_order_note(sprintf(
                        /* translators: 1: Refund amount, 2: Error message */
                        esc_html__('WalletUp: Stripe refund of %1$s failed: %2$s. Manual refund required.', 'walletup'),
                        wc_price($amount),
                        $e->getMessage()
                    ));
                }
            } elseif ($provider === 'paypal' && !empty($providerTxnId)) {
                try {
                    $ppConfig = \WalletUpPro\Core\Payments\PaymentGatewayHandler::getPayPalConfig();
                    $baseUrl = !empty($ppConfig['sandbox_mode'])
                        ? 'https://api-m.sandbox.paypal.com'
                        : 'https://api-m.paypal.com';

                    $authResp = wp_remote_post($baseUrl . '/v1/oauth2/token', [
                        'headers' => [
                            'Authorization' => 'Basic ' . base64_encode($ppConfig['client_id'] . ':' . $ppConfig['client_secret']),
                            'Content-Type'  => 'application/x-www-form-urlencoded',
                        ],
                        'body'    => 'grant_type=client_credentials',
                        'timeout' => 15,
                    ]);

                    if (!is_wp_error($authResp) && wp_remote_retrieve_response_code($authResp) === 200) {
                        $authBody = json_decode(wp_remote_retrieve_body($authResp), true);
                        $token = $authBody['access_token'] ?? '';
                        if (!empty($token)) {
                            $refundBody = [];
                            if ($amount) {
                                $refundBody['amount'] = [
                                    'value'         => number_format($amount, 2, '.', ''),
                                    'currency_code' => $order->get_currency(),
                                ];
                            }
                            if ($reason) {
                                $refundBody['note_to_payer'] = substr($reason, 0, 255);
                            }

                            $refundResp = wp_remote_post($baseUrl . '/v2/payments/captures/' . $providerTxnId . '/refund', [
                                'headers' => [
                                    'Authorization' => 'Bearer ' . $token,
                                    'Content-Type'  => 'application/json',
                                ],
                                'body'    => wp_json_encode($refundBody),
                                'timeout' => 15,
                            ]);

                            $refundCode = wp_remote_retrieve_response_code($refundResp);
                            if (!is_wp_error($refundResp) && ($refundCode === 201 || $refundCode === 200)) {
                                $refundData = json_decode(wp_remote_retrieve_body($refundResp), true);
                                $apiRefunded = true;
                                $order->add_order_note(sprintf(
                                    /* translators: 1: Refund amount, 2: PayPal refund ID */
                                    esc_html__('WalletUp: PayPal refund of %1$s processed (Refund ID: %2$s).', 'walletup'),
                                    wc_price($amount),
                                    $refundData['id'] ?? 'unknown'
                                ));
                            } else {
                                $errBody = wp_remote_retrieve_body($refundResp);
                                throw new Exception('PayPal refund HTTP ' . $refundCode . ': ' . $errBody);
                            }
                        }
                    }
                } catch (Exception $e) {
                    $order->add_order_note(sprintf(
                        /* translators: 1: Refund amount, 2: Error message */
                        esc_html__('WalletUp: PayPal refund of %1$s failed: %2$s. Manual refund required.', 'walletup'),
                        wc_price($amount),
                        $e->getMessage()
                    ));
                }
            }

            $this->analytics->trackEventPublic([
                'event_type' => 'payment_refunded',
                'payment_method' => $methodId,
                'order_id' => $order_id,
                'amount' => $amount,
                'reason' => $reason,
                'api_refunded' => $apiRefunded,
                'refunded_at' => current_time('mysql')
            ]);

            $paymentId = $order->get_meta('_wallet_up_payment_id');
            if ($paymentId) {
                $paymentProcessor = PaymentProcessor::getInstance();
                $paymentProcessor->updatePaymentStatus($paymentId, 'refunded', [
                    'provider_response' => [
                        'refund_amount' => $amount,
                        'refund_reason' => $reason,
                        'refunded_by' => get_current_user_id(),
                        'api_refunded' => $apiRefunded,
                    ],
                ]);
            }

            $notification = new PaymentNotification($order, 'refunded');
            $notification->send();

            if (!$apiRefunded) {
                $order->add_order_note(
                    sprintf(
                        /* translators: 1: Refund amount, 2: Payment method name, 3: Refund reason, 4: Payment method name */
                        __('Refund of %1$s recorded for %2$s payment. Reason: %3$s. NOTE: This is a manual payment method - please send the refund directly to the customer via %4$s.', 'walletup'),
                        wc_price($amount),
                        $methodName,
                        $reason ?: 'No reason provided',
                        $methodName
                    )
                );
            }

            return true;

        } catch (Exception $e) {
            $this->errorHandler->log('Refund processing error: ' . $e->getMessage());
            return false;
        }
    }

    private function isBlocksCheckout(): bool
    {
        static $is_blocks_checkout = null;
        if ($is_blocks_checkout !== null) {
            return $is_blocks_checkout;
        }

        try {
            if (function_exists('is_wc_endpoint_url') && (
                is_wc_endpoint_url('order-pay') ||
                is_wc_endpoint_url('add-payment-method')
            )) {
                $is_blocks_checkout = false;
                return $is_blocks_checkout;
            }

            if (function_exists('has_block') && has_block('woocommerce/checkout')) {
                $is_blocks_checkout = true;
                return $is_blocks_checkout;
            }

            global $post;
            if ($post && (
                strpos($post->post_content, '<!-- wp:woocommerce/checkout') !== false ||
                strpos($post->post_content, 'wp-block-woocommerce-checkout') !== false ||
                has_shortcode($post->post_content, 'woocommerce_checkout')
            )) {
                $is_blocks_checkout = true;
                return $is_blocks_checkout;
            }

            if (class_exists('\Automattic\WooCommerce\Blocks\Package') && 
                did_action('woocommerce_blocks_checkout_enqueue_data')) {
                $is_blocks_checkout = true;
                return $is_blocks_checkout;
            }

            $is_blocks_checkout = false;
            return $is_blocks_checkout;

        } catch (Exception $e) {
            $this->errorHandler->log('Error detecting blocks checkout: ' . $e->getMessage());
            $is_blocks_checkout = false;
            return $is_blocks_checkout;
        }
    }

    /**
     * Process a WC Subscription order via Stripe or PayPal subscriptions.
     * Called from process_payment() when order contains a subscription product.
     *
     * @param \WC_Order $order    WooCommerce order
     * @param string    $methodId Selected payment method ID
     * @return array WC payment result
     */
    private function processSubscriptionOrder($order, string $methodId): array
    {
        if ($methodId === 'paypal_commerce' || $methodId === 'paypal') {
            return $this->processPayPalSubscriptionOrder($order, $methodId);
        }

        if ($methodId !== 'stripe') {
            $order->add_order_note(sprintf(
                /* translators: %s: Payment method */
                esc_html__('WalletUp: %s does not support automated recurring billing. Subscription renewals will need manual payment.', 'walletup'),
                ucfirst($methodId)
            ));
            return $this->processStandardOrder($order, $methodId);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC handles nonce.
        $stripePaymentMethodId = '';

        if (!empty($_POST['walletup_stripe_payment_method_id'])) {
            $stripePaymentMethodId = sanitize_text_field(wp_unslash($_POST['walletup_stripe_payment_method_id']));
        }

        if (empty($stripePaymentMethodId) && !empty($_POST['payment_method_data'])) {
            $pmData = json_decode(sanitize_text_field(wp_unslash($_POST['payment_method_data'])), true);
            if (!empty($pmData['walletup_stripe_payment_method_id'])) {
                $stripePaymentMethodId = sanitize_text_field($pmData['walletup_stripe_payment_method_id']);
            }
        }

        if (empty($stripePaymentMethodId) && !empty($_POST['payment_method_data'])) {
            $pmData = $pmData ?? json_decode(sanitize_text_field(wp_unslash($_POST['payment_method_data'])), true);
            if (!empty($pmData['stripe_payment_method_id'])) {
                $stripePaymentMethodId = sanitize_text_field($pmData['stripe_payment_method_id']);
            }
        }

        if (empty($stripePaymentMethodId)) {
            wc_add_notice(__('Stripe payment method is required for subscriptions.', 'walletup'), 'error');
            return ['result' => 'failure', 'redirect' => ''];
        }

        $frequency = 'monthly'; // default
        if (function_exists('wcs_get_subscriptions_for_order')) {
            $wcSubs = wcs_get_subscriptions_for_order($order, ['order_type' => 'parent']);
            $wcSub = reset($wcSubs);
            if ($wcSub) {
                $billingPeriod = $wcSub->get_billing_period();
                $billingInterval = $wcSub->get_billing_interval();
                $frequency = $this->mapWcBillingToFrequency($billingPeriod, (int) $billingInterval);
            }
        }

        try {
            $subManager = \WalletUpPro\Core\Subscriptions\SubscriptionManager::getInstance();

            $wcSubscriptionId = 0;
            if (isset($wcSub)) {
                $wcSubscriptionId = $wcSub->get_id();
            }

            $subResult = $subManager->createStripeSubscription([
                'amount'              => (float) $order->get_total(),
                'currency'            => $order->get_currency(),
                'frequency'           => $frequency,
                'email'               => $order->get_billing_email(),
                'name'                => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'payment_method_id'   => $stripePaymentMethodId,
                'source_type'         => 'woocommerce',
                'source_id'           => (string) $order->get_id(),
                'wc_order_id'         => $order->get_id(),
                'wc_subscription_id'  => $wcSubscriptionId,
                'metadata'            => [
                    'order_id'           => (string) $order->get_id(),
                    'wc_order_id'        => $order->get_id(),
                    'wc_subscription_id' => $wcSubscriptionId,
                    'order_key'          => $order->get_order_key(),
                ],
            ]);

            $order->update_meta_data('_walletup_subscription_id', $subResult['subscription_id']);
            $order->update_meta_data('_walletup_stripe_subscription_id', $subResult['provider_subscription_id']);
            $order->update_meta_data('_walletup_provider', 'stripe');
            if (!empty($subResult['provider_subscription_id'])) {
                $order->update_meta_data('_walletup_provider_transaction_id', $subResult['provider_subscription_id']);
            }

            if ($subResult['status'] === 'active') {
                $order->payment_complete();
                /* translators: %s: Stripe subscription ID */
                $order->add_order_note(sprintf(
                    esc_html__('WalletUp: Stripe subscription %s created and active.', 'walletup'),
                    $subResult['provider_subscription_id']
                ));
            } else {
                $order->update_status('on-hold');
                $order->add_order_note(sprintf(
                    /* translators: 1: Stripe subscription ID, 2: Status */
                    esc_html__('WalletUp: Stripe subscription %1$s created (status: %2$s). Awaiting confirmation.', 'walletup'),
                    $subResult['provider_subscription_id'],
                    $subResult['status']
                ));
            }
            $order->save();

            if (!empty($subResult['client_secret']) && $subResult['requires_action']) {
                return [
                    'result'   => 'success',
                    'redirect' => add_query_arg([
                        'walletup_confirm_sub' => '1',
                        'client_secret'        => $subResult['client_secret'],
                        'order_id'             => $order->get_id(),
                    ], $this->get_return_url($order)),
                ];
            }

            return [
                'result'   => 'success',
                'redirect' => $this->get_return_url($order),
            ];

        } catch (Exception $e) {
            $this->errorHandler->log('WC subscription order failed: ' . $e->getMessage());
            wc_add_notice(
                sprintf(
                    /* translators: %s: error message */
                    __('Subscription setup failed: %s', 'walletup'),
                    $e->getMessage()
                ),
                'error'
            );
            return ['result' => 'failure', 'redirect' => ''];
        }
    }

    private function processPayPalSubscriptionOrder($order, string $methodId): array
    {
        $frequency = 'monthly';
        $wcSubscriptionId = 0;
        if (function_exists('wcs_get_subscriptions_for_order')) {
            $wcSubs = wcs_get_subscriptions_for_order($order, ['order_type' => 'parent']);
            $wcSub = reset($wcSubs);
            if ($wcSub) {
                $billingPeriod = $wcSub->get_billing_period();
                $billingInterval = $wcSub->get_billing_interval();
                $frequency = $this->mapWcBillingToFrequency($billingPeriod, (int) $billingInterval);
                $wcSubscriptionId = $wcSub->get_id();
            }
        }

        try {
            $subManager = \WalletUpPro\Core\Subscriptions\SubscriptionManager::getInstance();

            $subResult = $subManager->createPayPalSubscription([
                'amount'             => (float) $order->get_total(),
                'currency'           => $order->get_currency(),
                'frequency'          => $frequency,
                'email'              => $order->get_billing_email(),
                'name'               => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'source_type'        => 'woocommerce',
                'source_id'          => (string) $order->get_id(),
                'wc_order_id'        => $order->get_id(),
                'wc_subscription_id' => $wcSubscriptionId,
                'metadata'           => [
                    'wc_order_id'        => $order->get_id(),
                    'wc_subscription_id' => $wcSubscriptionId,
                    'order_key'          => $order->get_order_key(),
                ],
            ]);

            $order->update_meta_data('_walletup_subscription_id', $subResult['subscription_id']);
            $order->update_meta_data('_walletup_paypal_subscription_id', $subResult['provider_subscription_id']);
            $order->update_meta_data('_walletup_provider', 'paypal');
            if (!empty($subResult['provider_subscription_id'])) {
                $order->update_meta_data('_walletup_provider_transaction_id', $subResult['provider_subscription_id']);
            }

            $order->update_status('on-hold');
            $order->add_order_note(sprintf(
                /* translators: %s: PayPal subscription ID */
                esc_html__('WalletUp: PayPal subscription %s created. Awaiting buyer approval.', 'walletup'),
                $subResult['provider_subscription_id']
            ));
            $order->save();

            if (!empty($subResult['approval_url'])) {
                return [
                    'result'   => 'success',
                    'redirect' => $subResult['approval_url'],
                ];
            }

            return [
                'result'   => 'success',
                'redirect' => $this->get_return_url($order),
            ];
        } catch (Exception $e) {
            $this->errorHandler->log('WC PayPal subscription order failed: ' . $e->getMessage());
            wc_add_notice(
                sprintf(
                    /* translators: %s: error message */
                    __('PayPal subscription setup failed: %s', 'walletup'),
                    $e->getMessage()
                ),
                'error'
            );
            return ['result' => 'failure', 'redirect' => ''];
        }
    }

    private function processPayPalOneTimeOrder(\WC_Order $order): array
    {
        try {
            $settings = \WalletUpPro\Core\Payments\PaymentGatewayHandler::getPayPalConfig();
            if (empty($settings['client_id']) || empty($settings['client_secret'])) {
                throw new Exception(esc_html(__('PayPal is not configured. Please contact the store administrator.', 'walletup')));
            }

            $baseUrl = !empty($settings['sandbox_mode'])
                ? 'https://api-m.sandbox.paypal.com'
                : 'https://api-m.paypal.com';

            $accessToken = $this->getPayPalAccessTokenForOrder($settings, $baseUrl);
            if (!$accessToken) {
                throw new Exception(esc_html(__('Unable to connect to PayPal. Please try again.', 'walletup')));
            }

            $currency = strtoupper($order->get_currency());
            $amount = number_format((float) $order->get_total(), 2, '.', '');

            $returnUrl = add_query_arg([
                'walletup_paypal_capture' => '1',
                'order_id'               => $order->get_id(),
                'order_key'              => $order->get_order_key(),
            ], $this->get_return_url($order));

            $cancelUrl = wc_get_checkout_url();

            $orderPayload = [
                'intent'              => 'CAPTURE',
                'purchase_units'      => [
                    [
                        'reference_id' => (string) $order->get_id(),
                        'description'  => sprintf(
                            /* translators: 1: Order number, 2: Site name */
                            __('Order #%1$s from %2$s', 'walletup'),
                            $order->get_order_number(),
                            get_bloginfo('name')
                        ),
                        'custom_id'    => (string) $order->get_id(),
                        'invoice_id'   => $order->get_order_key(),
                        'amount'       => [
                            'currency_code' => $currency,
                            'value'         => $amount,
                        ],
                    ],
                ],
                'payment_source' => [
                    'paypal' => [
                        'experience_context' => [
                            'brand_name'          => get_bloginfo('name'),
                            'shipping_preference' => 'NO_SHIPPING',
                            'user_action'         => 'PAY_NOW',
                            'return_url'          => $returnUrl,
                            'cancel_url'          => $cancelUrl,
                        ],
                    ],
                ],
            ];

            $response = wp_remote_post($baseUrl . '/v2/checkout/orders', [
                'headers' => [
                    'Authorization'                 => 'Bearer ' . $accessToken,
                    'Content-Type'                  => 'application/json',
                    'PayPal-Request-Id'             => 'walletup-' . $order->get_id() . '-' . wp_generate_password(8, false),
                    'Prefer'                        => 'return=representation',
                ],
                'body'    => wp_json_encode($orderPayload),
                'timeout' => 30,
            ]);

            if (is_wp_error($response)) {
                throw new Exception(esc_html($response->get_error_message()));
            }

            $code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            if ($code < 200 || $code >= 300 || empty($body['id'])) {
                $errorMsg = $body['details'][0]['description'] ?? $body['message'] ?? __('PayPal order creation failed', 'walletup');
                throw new Exception(esc_html($errorMsg));
            }

            $paypalOrderId = $body['id'];

            $approvalUrl = '';
            foreach ($body['links'] as $link) {
                if ($link['rel'] === 'payer-action' || $link['rel'] === 'approve') {
                    $approvalUrl = $link['href'];
                    break;
                }
            }

            if (empty($approvalUrl)) {
                throw new Exception(esc_html(__('PayPal did not return an approval URL', 'walletup')));
            }

            $order->update_meta_data('_walletup_paypal_order_id', $paypalOrderId);
            $order->update_meta_data('_walletup_provider', 'paypal');
            $order->update_status('pending', sprintf(
                /* translators: %s: PayPal Order ID */
                esc_html__('Awaiting PayPal payment. PayPal Order: %s', 'walletup'),
                $paypalOrderId
            ));
            $order->save();

            if ($this->ensureTransactionTableReady()) try {
                $paymentProcessor = PaymentProcessor::getInstance();
                $paymentSession = $paymentProcessor->initializePayment([
                    'email'          => $order->get_billing_email(),
                    'first'          => $order->get_billing_first_name(),
                    'last'           => $order->get_billing_last_name(),
                    'phone'          => $order->get_billing_phone(),
                    'amt'            => $order->get_total(),
                    'currency'       => $order->get_currency(),
                    'payment_method' => 'paypal',
                    'description'    => sprintf('Order #%s', $order->get_order_number()),
                    'wc_order_id'    => $order->get_id(),
                    'metadata'       => [
                        'payment_method'   => 'paypal',
                        'provider'         => 'paypal',
                        'paypal_order_id'  => $paypalOrderId,
                        'order_id'         => $order->get_id(),
                        'order_key'        => $order->get_order_key(),
                    ],
                ]);
                $order->update_meta_data('_wallet_up_payment_id', $paymentSession['payment_id']);
                $order->save();
            } catch (Exception $e) {
                $this->errorHandler->log('PayPal transaction record creation failed (non-fatal): ' . $e->getMessage());
            }

            return [
                'result'   => 'success',
                'redirect' => $approvalUrl,
            ];

        } catch (Exception $e) {
            $this->errorHandler->log('PayPal one-time payment error: ' . $e->getMessage());
            wc_add_notice(
                sprintf(
                    /* translators: %s: error message */
                    __('PayPal payment failed: %s', 'walletup'),
                    $e->getMessage()
                ),
                'error'
            );
            return ['result' => 'failure', 'redirect' => ''];
        }
    }

    public function handlePayPalOrderReturn(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- PayPal redirect, no nonce available.
        if (empty($_GET['walletup_paypal_capture'])) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $orderId = absint(wp_unslash($_GET['order_id'] ?? 0));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $orderKey = sanitize_text_field(wp_unslash($_GET['order_key'] ?? ''));

        if (empty($orderId) || empty($orderKey)) {
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order || $order->get_order_key() !== $orderKey) {
            return;
        }

        if ($order->is_paid()) {
            return;
        }

        $paypalOrderId = $order->get_meta('_walletup_paypal_order_id');
        if (empty($paypalOrderId)) {
            return;
        }

        if (!preg_match('/^[A-Za-z0-9]+$/', $paypalOrderId)) {
            $this->errorHandler->log('PayPal capture: invalid order ID format');
            return;
        }

        try {
            $settings = \WalletUpPro\Core\Payments\PaymentGatewayHandler::getPayPalConfig();
            $baseUrl = !empty($settings['sandbox_mode'])
                ? 'https://api-m.sandbox.paypal.com'
                : 'https://api-m.paypal.com';

            $accessToken = $this->getPayPalAccessTokenForOrder($settings, $baseUrl);
            if (!$accessToken) {
                throw new Exception('Unable to obtain PayPal access token for capture');
            }

            $response = wp_remote_post($baseUrl . '/v2/checkout/orders/' . $paypalOrderId . '/capture', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type'  => 'application/json',
                    'Prefer'        => 'return=representation',
                ],
                'body'    => '{}',
                'timeout' => 30,
            ]);

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            $code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            if ($body['status'] === 'COMPLETED') {
                $captureId = '';
                if (!empty($body['purchase_units'][0]['payments']['captures'][0]['id'])) {
                    $captureId = $body['purchase_units'][0]['payments']['captures'][0]['id'];
                }

                $order->payment_complete($captureId ?: $paypalOrderId);
                /* translators: 1: PayPal Order ID, 2: Capture ID */
                $order->add_order_note(sprintf(
                    esc_html__('PayPal payment captured. Order: %1$s, Capture: %2$s', 'walletup'),
                    $paypalOrderId,
                    $captureId ?: 'N/A'
                ));

                $order->update_meta_data('_walletup_provider_transaction_id', $captureId ?: $paypalOrderId);
                if ($captureId) {
                    $order->update_meta_data('_walletup_paypal_capture_id', $captureId);
                }
                $order->save();

                WC()->cart->empty_cart();

            } elseif ($code >= 200 && $code < 300) {
                $order->add_order_note(sprintf(
                    /* translators: %s: PayPal Order status */
                    esc_html__('PayPal order status: %s. Awaiting final capture via webhook.', 'walletup'),
                    $body['status'] ?? 'UNKNOWN'
                ));
            } else {
                $errorMsg = $body['details'][0]['description'] ?? $body['message'] ?? 'Capture failed';
                throw new Exception($errorMsg);
            }

        } catch (Exception $e) {
            $this->errorHandler->log('PayPal capture error for order #' . $orderId . ': ' . $e->getMessage());
            $order->add_order_note(sprintf(
                /* translators: %s: Error message */
                esc_html__('PayPal capture failed: %s. Check PayPal dashboard or retry.', 'walletup'),
                $e->getMessage()
            ));
        }
    }

    private function getPayPalAccessTokenForOrder(array $settings, string $baseUrl): ?string
    {
        $isSandbox = str_contains($baseUrl, 'sandbox');
        $transientKey = 'walletup_paypal_token_' . ($isSandbox ? 'sandbox' : 'live');

        $cached = get_transient($transientKey);
        if ($cached) {
            return $cached;
        }

        if (empty($settings['client_id']) || empty($settings['client_secret'])) {
            return null;
        }

        $response = wp_remote_post($baseUrl . '/v1/oauth2/token', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($settings['client_id'] . ':' . $settings['client_secret']),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body'    => 'grant_type=client_credentials',
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            $this->errorHandler->log('PayPal OAuth error: ' . $response->get_error_message());
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['access_token'])) {
            return null;
        }

        $expiresIn = max(60, ($body['expires_in'] ?? 3600) - 60);
        set_transient($transientKey, $body['access_token'], $expiresIn);

        return $body['access_token'];
    }

    public function handleStripe3DSSubscriptionReturn(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Stripe redirect, no nonce available.
        if (empty($_GET['walletup_confirm_sub'])) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $clientSecret = sanitize_text_field(wp_unslash($_GET['client_secret'] ?? ''));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $orderId = absint(wp_unslash($_GET['order_id'] ?? 0));

        if (empty($clientSecret) || empty($orderId)) {
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $orderKey = sanitize_text_field(wp_unslash($_GET['key'] ?? ''));
        if ($orderKey === '' || !hash_equals($order->get_order_key(), $orderKey)) {
            return;
        }
        if ($order->is_paid()) {
            return;
        }

        try {
            $piId = explode('_secret_', $clientSecret)[0] ?? '';
            if (empty($piId) || !class_exists('\\WalletUpPro\\Core\\Payments\\PaymentGatewayHandler')) {
                return;
            }

            $gate = \WalletUpPro\Core\Payments\PaymentGatewayHandler::assertPaidFor([
                'payment_intent_id' => $piId,
                'bind_key'          => 'order_id',
                'bind_value'        => (string) $orderId,
                'also_match'        => ['order_key' => $order->get_order_key()],
                'expected_amount'   => \WalletUpPro\Core\Payments\PaymentGatewayHandler::toMinorUnits(
                    (float) $order->get_total(),
                    $order->get_currency()
                ),
                'expected_currency' => $order->get_currency(),
            ]);

            if (!empty($gate['paid'])) {
                $order->update_meta_data('_walletup_provider', 'stripe');
                $order->update_meta_data('_walletup_provider_transaction_id', $piId);
                $order->update_meta_data('_stripe_payment_intent_id', $piId);
                $order->update_status('processing', esc_html__('WalletUp: Stripe subscription payment confirmed after 3DS.', 'walletup'));
                $order->save();
            } elseif ($gate['status'] === 'requires_payment_method') {
                $order->update_status('failed', esc_html__('WalletUp: 3DS verification failed for subscription.', 'walletup'));
                $order->save();
            } elseif ($gate['status'] !== 'requires_action' && $this->errorHandler) {
                $this->errorHandler->log(sprintf(
                    'Stripe 3DS subscription return not applied to order %d (status: %s, reason: %s)',
                    $orderId,
                    $gate['status'],
                    $gate['error']
                ));
            }
            // If still 'requires_action', Stripe JS on the thank-you page will handle it
        } catch (Exception $e) {
            if ($this->errorHandler) {
                $this->errorHandler->log('Stripe 3DS sub return error: ' . $e->getMessage());
            }
        }
    }

    private function processStandardOrder($order, string $methodId): array
    {
        if ($this->ensureTransactionTableReady()) try {
            $paymentProcessor = PaymentProcessor::getInstance();
            $paymentData = [
                'email' => $order->get_billing_email(),
                'first' => $order->get_billing_first_name(),
                'last' => $order->get_billing_last_name(),
                'phone' => $order->get_billing_phone(),
                'amt' => $order->get_total(),
                'currency' => $order->get_currency(),
                /* translators: %s: Order number */
                'description' => sprintf(__('Order #%s', 'walletup'), $order->get_order_number()),
                'wc_order_id' => $order->get_id(),
                'metadata' => [
                    'payment_method' => $methodId,
                    'order_key' => $order->get_order_key(),
                    'return_url' => $this->get_return_url($order),
                ]
            ];

            $paymentSession = $paymentProcessor->initializePayment($paymentData);
            $order->update_meta_data('_wallet_up_payment_id', $paymentSession['payment_id']);
            $order->update_meta_data('_wallet_up_session_id', $paymentSession['session_id']);
            $paymentProcessor->updatePaymentStatus($paymentSession['payment_id'], 'pending', ['payment_method' => $methodId]);
            $order->save();
        } catch (Exception $e) {
            $this->errorHandler->log('Transaction record creation failed (non-fatal): ' . $e->getMessage());
        }

        $orderStatus = WooCommerceMethodSettings::getEffectiveOrderStatus($methodId);
        /* translators: %s: Payment method name */
        $order->update_status($orderStatus, sprintf(
            __('Awaiting %s payment', 'walletup'),
            WooCommerceMethodSettings::getEffectiveTitle($methodId)
        ));

        return [
            'result' => 'success',
            'redirect' => add_query_arg([
                'order-received' => $order->get_id(),
                'key' => $order->get_order_key()
            ], $this->get_return_url($order))
        ];
    }

    private function mapWcBillingToFrequency(string $period, int $interval): string
    {
        if ($period === 'week' && $interval === 1) {
            return 'weekly';
        }
        if ($period === 'week' && $interval === 2) {
            return 'biweekly';
        }
        if ($period === 'month' && $interval === 1) {
            return 'monthly';
        }
        if ($period === 'month' && $interval === 3) {
            return 'quarterly';
        }
        if ($period === 'year' && $interval === 1) {
            return 'yearly';
        }
        return 'monthly';
    }

    /**
     * Process a scheduled WC Subscriptions renewal payment.
     * Hooked to 'woocommerce_scheduled_subscription_payment_wallet_up'.
     *
     * @param float     $amountToCharge Renewal amount
     * @param \WC_Order $renewalOrder   WooCommerce renewal order
     */
    public function processScheduledRenewalPayment(float $amountToCharge, $renewalOrder): void
    {
        try {
            $subManager = \WalletUpPro\Core\Subscriptions\SubscriptionManager::getInstance();
            $subManager->processWcRenewalPayment($amountToCharge, $renewalOrder);
        } catch (Exception $e) {
            $this->errorHandler->log('WC renewal payment error: ' . $e->getMessage());
            $renewalOrder->update_status('failed');
            $renewalOrder->add_order_note('WalletUp renewal error: ' . $e->getMessage());
            $renewalOrder->save();
        }
    }

    /**
     * Cancel the provider subscription when a WC Subscription is cancelled.
     * Hooked to 'woocommerce_subscription_cancelled_wallet_up'.
     *
     * @param \WC_Subscription $wcSubscription
     */
    public function cancelWcSubscription($wcSubscription): void
    {
        try {
            $subManager = \WalletUpPro\Core\Subscriptions\SubscriptionManager::getInstance();

            global $wpdb;
            $table = $wpdb->prefix . 'walletup_subscriptions';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fresh data required.
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT subscription_id FROM {$table} WHERE wc_subscription_id = %d AND status IN ('active', 'pending', 'past_due')",
                $wcSubscription->get_id()
            ));

            if ($row) {
                $subManager->cancelSubscription($row->subscription_id);
                $wcSubscription->add_order_note(
                    esc_html__('WalletUp: Provider subscription cancelled.', 'walletup')
                );
            }
        } catch (Exception $e) {
            $this->errorHandler->log('WC subscription cancellation error: ' . $e->getMessage());
        }
    }

    public function reactivateWcSubscription($wcSubscription): void
    {
        try {
            global $wpdb;
            $table = $wpdb->prefix . 'walletup_subscriptions';

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE wc_subscription_id = %d AND status = 'cancelled'",
                $wcSubscription->get_id()
            ));

            if (!$row) {
                return;
            }

            $subManager = \WalletUpPro\Core\Subscriptions\SubscriptionManager::getInstance();

            if ($row->provider === 'stripe' && !empty($row->provider_subscription_id)) {
                try {
                    $stripeConfig = \WalletUpPro\Core\Payments\PaymentGatewayHandler::getStripeConfig();
                    $secretKey = !empty($stripeConfig['test_mode'])
                        ? ($stripeConfig['test_secret_key'] ?? '')
                        : ($stripeConfig['live_secret_key'] ?? '');

                    \Stripe\Stripe::setApiKey($secretKey);
                    \Stripe\Subscription::update($row->provider_subscription_id, [
                        'cancel_at_period_end' => false,
                    ]);

                    $subManager->updateSubscriptionStatus($row->subscription_id, 'active');
                    $wcSubscription->add_order_note(
                        esc_html__('WalletUp: Stripe subscription reactivated.', 'walletup')
                    );
                } catch (Exception $e) {
                    $wcSubscription->add_order_note(
                        sprintf(
                            /* translators: %s: error message */
                            esc_html__('WalletUp: Failed to reactivate Stripe subscription: %s', 'walletup'),
                            $e->getMessage()
                        )
                    );
                }
            } elseif ($row->provider === 'paypal') {
                $subManager->updateSubscriptionStatus($row->subscription_id, 'active');
                $wcSubscription->add_order_note(
                    esc_html__('WalletUp: Local subscription reactivated. Note: PayPal cancelled subscriptions cannot be reactivated via API - a new subscription may need to be created.', 'walletup')
                );
            }
        } catch (Exception $e) {
            $this->errorHandler->log('WC subscription reactivation error: ' . $e->getMessage());
        }
    }
    private function ensureTransactionTableReady(): bool
    {
        static $result = null;
        if ($result !== null) {
            return $result;
        }

        global $wpdb;
        $tableName = $wpdb->prefix . 'walletup_payment_transactions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fresh schema check required.
        $result = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tableName));

        if (!$result) {
            do_action('walletup_create_payment_transactions_table');
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $result = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tableName));

            if (!$result && isset($this->errorHandler)) {
                $this->errorHandler->log(
                    'walletup_payment_transactions table does not exist — ' .
                    'skipping transaction record. Re-activate the plugin to rebuild tables.'
                );
            }
        }

        return $result;
    }

}