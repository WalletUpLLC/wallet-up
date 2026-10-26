<?php
namespace WalletUpPro\WooCommerce;

use WalletUpPro\Core\Error\ErrorHandler;
use WalletUpPro\Core\Analytics\AnalyticsHandler;
use WalletUpPro\Core\PaymentRegistry;
use WalletUpPro\Services\QRCodeService;
use WalletUpPro\Core\Display\DisplayHandler;
use WalletUpPro\WooCommerce\PaymentNotification;
use WalletUpPro\WooCommerce\Emails\EmailManager;
use WalletUpPro\WooCommerce\CheckoutSecurityHeaders;
use Exception;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
class WooCommerceIntegration
{
    private static $instance = null;
    private $errorHandler;
    private $registry;
    private $analytics;
    private $displayHandler;
    private $initialized = false;
    private $isHposEnabled = false;
    private $gateway;
    private $qrService;
    private $emailManager;
    private $initialization_error = null;

    private const QR_DIMENSIONS = [
        'small' => 150,
        'medium' => 180,
        'large' => 250
    ];

    public static function getInstance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        try {
            $this->errorHandler = ErrorHandler::getInstance();

            if (!$this->isWooCommerceActive()) {
                $this->initialization_error = 'WooCommerce is not active';
                $this->errorHandler->log('WooCommerce not active on construction');
                return;
            }
            $this->displayHandler = DisplayHandler::getInstance();
            $this->initializeServices();
            $this->checkHPOS();
            $this->initializeHooks();
            
            CheckoutSecurityHeaders::getInstance();
            
            $this->initialized = true;

        } catch (\Exception $e) {
            $this->initialization_error = $e->getMessage();
            if (isset($this->errorHandler)) {
                $this->errorHandler->log('WooCommerce integration initialization failed: ' . $e->getMessage());
            }
        }
    }

    public function isWooCommerceActive(): bool
    {
        return (
            class_exists('WooCommerce') &&
            class_exists('WC_Payment_Gateway') &&
            function_exists('WC') &&
            did_action('woocommerce_loaded')
        );
    }

    public function isWooCommerceConfigured(): bool
    {
        if (!$this->isWooCommerceActive()) {
            return false;
        }

        try {
            $wcSettings = get_option('woocommerce_settings', []);
            
            $gatewaySettings = get_option('woocommerce_wallet_up_settings', []);
            $gatewayEnabled = !empty($gatewaySettings['enabled']) && $gatewaySettings['enabled'] === 'yes';
            
            return (!empty($wcSettings) || $gatewayEnabled);
        } catch (\Exception $e) {
            $this->errorHandler->log('WooCommerce configuration check failed: ' . $e->getMessage());
            return false;
        }
    }
 final public function handlePaymentDisplay(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_wc_payment_display', 'nonce', false)) {
                $this->errorHandler->log('Nonce verification failed in handlePaymentDisplay. Expected: wallet_up_wc_payment_display, Received nonce: ' . sanitize_text_field(wp_unslash($_POST['nonce'] ?? 'none')));
                throw new Exception(esc_html('Invalid security token'));
            }

            $methodId = sanitize_text_field(wp_unslash($_POST['method'] ?? ''));
            $clientAmount = floatval(wp_unslash($_POST['amount'] ?? 0));

            if (empty($methodId)) {
                throw new Exception(esc_html('Method ID is required'));
            }

            if (WC()->session) {
                WC()->session->set('wallet_up_selected_method', $methodId);
                WC()->session->set('chosen_payment_method', 'wallet_up');
            }

            $amount = $clientAmount;
            if (WC()->cart) {
                WC()->cart->calculate_totals();
                $serverTotal = (float) WC()->cart->get_total('edit');
                if ($serverTotal > 0 && $amount < $serverTotal) {
                    $amount = $serverTotal;
                }
            }

            $record = !isset($_POST['record']) || (string) wp_unslash($_POST['record']) === '1';
            $html = $this->buildCheckoutDisplayHtml($methodId, (float) $amount, $record);

            wp_send_json_success([
                'html' => $html,
                'cached' => false,
                'method' => $methodId,
                'amount' => number_format((float) $amount, 2, '.', ''),
            ]);

        } catch (Exception $e) {
            $this->errorHandler->log('Payment display error: ' . $e->getMessage());
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function buildCheckoutDisplayHtml(string $methodId, float $amount, bool $recordImpression = true): string
    {
        $config = WooCommerceConfig::getInstance();
        $settings = $config->getSettings();
        $displayMode = $settings['wcSettings']['display_mode'] ?? 'qr'; // zi2-dry: ignore

        $method = $this->registry->getMethod($methodId);
        if (!$method) {
            throw new Exception(esc_html('Invalid payment method'));
        }

        if (!WooCommerceConfig::shouldShowQrOnCheckout()) {
            $deferHtml = '';
            $methodDesc = WooCommerceMethodSettings::get($methodId, 'description');
            if (!empty($methodDesc)) {
                $deferHtml .= '<div class="walletup-checkout-method-desc" style="margin-bottom:10px;font-size:14px;color:#374151;line-height:1.5;">' // zi2-dry: ignore
                    . wp_kses_post(WooCommerceMethodSettings::processVariables($methodDesc, $methodId, [
                        'amount'   => $amount,
                        'currency' => get_woocommerce_currency(),
                    ]))
                    . '</div>';
            }
            $deferHtml .= WooCommerceConfig::getCheckoutDeferredNoticeHtml();
            return $deferHtml;
        }

        $checkout_context = [
            'is_checkout' => true,
            'cart_total' => WC()->cart ? WC()->cart->get_total('') : '0',
            'currency' => get_woocommerce_currency(),
        ];

        $note = WooCommerceMethodSettings::getPaymentNote($methodId, [
            'amount' => $amount,
            'currency' => get_woocommerce_currency(),
        ]);
        $payment_url = $method->getPaymentUrl($amount, $note, $checkout_context);

        $qrSettings = $settings['wcQRSettings']; // zi2-dry: ignore
        $methodDesc = WooCommerceMethodSettings::get($methodId, 'description');
        if (!empty($methodDesc)) {
            $qrSettings['customMessage'] = WooCommerceMethodSettings::processVariables(
                $methodDesc, $methodId, [
                    'amount' => $amount,
                    'currency' => get_woocommerce_currency(),
                ]
            );
            $qrSettings['showDefaultMessage'] = false;
            $qrSettings['hideMessage'] = false;
        }

        $checkout_page_id = wc_get_page_id('checkout');
        $checkout_page = $checkout_page_id > 0 ? get_post($checkout_page_id) : null;

        return $this->displayHandler->generateDisplay(
            $displayMode,
            $payment_url,
            $methodId,
            $qrSettings,
            [
                'amount' => $amount,
                'method' => $methodId,
                'display_mode' => $displayMode, // zi2-dry: ignore
                'post_id' => $checkout_page ? $checkout_page->ID : 0,
                'post_type' => $checkout_page ? $checkout_page->post_type : 'page',
                'post_title' => $checkout_page ? $checkout_page->post_title : 'Checkout',
                'skip_confirmation' => true,
                'skip_impression' => !$recordImpression,
            ]
        );
    }

    public function handleRecordImpression(): void
    {
        if (!check_ajax_referer('wallet_up_wc_payment_display', 'nonce', false)) { // zi2-dry: ignore
            wp_send_json_error();
            return;
        }
        $methodId = sanitize_key(wp_unslash($_POST['method'] ?? ''));
        if ($methodId === '' || !in_array($methodId, \WalletUpPro\Core\Config\PaymentMethodsConfig::getAllMethodIds(), true)) {
            wp_send_json_error();
            return;
        }
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) wp_unslash($_SERVER['HTTP_USER_AGENT']) : '';
        if ($ua !== '' && preg_match('/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|headless|monitor|pingdom|uptime/i', $ua)) {
            wp_send_json_success();
            return;
        }
        if (WC()->session) {
            $impressed = WC()->session->get('wallet_up_impressed_methods', []);
            if (!is_array($impressed)) {
                $impressed = [];
            }
            if (in_array($methodId, $impressed, true)) {
                wp_send_json_success();
                return;
            }
            $impressed[] = $methodId;
            WC()->session->set('wallet_up_impressed_methods', $impressed);
        }
        if (class_exists(\WalletUpPro\Core\Analytics\AnalyticsHandler::class)) {
            $pid = function_exists('wc_get_page_id') ? (int) wc_get_page_id('checkout') : 0;
            try {
                \WalletUpPro\Core\Analytics\AnalyticsHandler::getInstance()->recordImpression($methodId, $pid > 0 ? $pid : 0, 'qr', '');
            } catch (\Exception $e) {
                $this->errorHandler->log('Record impression error: ' . $e->getMessage());
            }
        }
        wp_send_json_success();
    }

 public function generatePaymentDisplay($method, $order, $methodId)
    {
        return $this->displayHandler->generateDisplay(
            $this->get_option('display_mode', 'qr'),
            $method->getPaymentUrl($order->get_total(), sprintf('Order #%s at %s', $order->get_order_number(), get_bloginfo('name'))),
            $methodId,
            get_option('wallet_up_display_settings', []),
            [
                'amount' => $order->get_total(),
                'order_id' => $order->get_id(),
                'currency' => $order->get_currency()
            ]
        );
    }

    
    private function registerAjaxHandlers(): void
    {
        $handlers = [
            'wallet_up_wc_payment_display' => 'handlePaymentDisplay',
        ];

        foreach ($handlers as $action => $method) {
            add_action("wp_ajax_{$action}", [$this, $method]);
            add_action("wp_ajax_nopriv_{$action}", [$this, $method]);
        }
    }
    

    private function initializeServices(): void
    {
        if (!$this->isWooCommerceActive()) {
            throw new \Exception(esc_html('Cannot initialize services - WooCommerce not active'));
        }
        try {
            $this->registry = PaymentRegistry::getInstance();
            $this->analytics = AnalyticsHandler::getInstance();
            $this->qrService = QRCodeService::getInstance();
            $this->emailManager = EmailManager::getInstance();
        } catch (Exception $e) {
            if (isset($this->errorHandler)) {
                $this->errorHandler->log('Service initialization failed: ' . $e->getMessage());
            }
            throw $e;
        }
    }

    private function checkHPOS(): void
    {
        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')) {
            $this->isHposEnabled = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        }
    }

    private function initializeHooks(): void
    {
        if (!$this->isWooCommerceActive()) {
            return;
        }
        add_action('plugins_loaded', [$this, 'initializeGateway'], 11);
        add_filter('woocommerce_payment_gateways', [$this, 'registerGateway']);

        add_filter('woocommerce_available_payment_gateways', [$this, 'gateMasterDisabled'], 99);
        add_action('admin_enqueue_scripts', [$this, 'renderMasterOffWcSettingsNotice']);

        add_action('wp_enqueue_scripts', [$this, 'enqueueCheckoutMethodStyles']);

        add_filter('wallet_up_admin_data', [$this, 'addWooCommerceData']);

        add_filter('wc_get_template', [$this, 'overrideThankYouTemplate'], 10, 3);
        add_action('woocommerce_thankyou', ['\\WalletUpPro\\WooCommerce\\StripeCardGateway', 'verifyReturn'], 1);
        add_action('wp_ajax_wallet_up_stripe_block_create_intent', ['\\WalletUpPro\\WooCommerce\\StripeCardGateway', 'blockCreateIntent']);
        add_action('wp_ajax_nopriv_wallet_up_stripe_block_create_intent', ['\\WalletUpPro\\WooCommerce\\StripeCardGateway', 'blockCreateIntent']);
        add_action('woocommerce_thankyou', [$this, 'renderPaymentSection'], 5);
        add_filter('woocommerce_order_get_payment_method_title', ['\\WalletUpPro\\WooCommerce\\ConfirmationPage', 'filterPaymentMethodTitle'], 20, 2);
        if (class_exists('WalletUpPro\\WooCommerce\\OrderConfirmationBlocks')) {
            OrderConfirmationBlocks::boot();
        }

        add_action('wp_ajax_wallet_up_wc_get_settings', [$this, 'getWooCommerceSettings']);
        add_action('wp_ajax_wallet_up_wc_save_settings', [$this, 'saveWooCommerceSettings']);
        add_action('wp_ajax_wallet_up_get_wc_orders', [$this, 'getWooCommerceOrders']);
        add_action('wp_ajax_wallet_up_get_confirmation_display', [$this, 'getConfirmationDisplay']);
        add_action('wp_ajax_wallet_up_save_confirmation_display', [$this, 'saveConfirmationDisplay']);
        add_action('wp_ajax_wallet_up_preview_confirmation', [$this, 'previewConfirmation']);

        \WalletUpPro\WooCommerce\Express\ExpressStripeHandler::init();
        \WalletUpPro\WooCommerce\Express\ApplePayDomain::init();

        add_action('woocommerce_order_status_changed', [$this, 'trackOrderStatus'], 10, 4);
        add_action('woocommerce_new_order', [$this, 'trackNewOrder']);

        add_action('woocommerce_payment_complete', [$this, 'handlePaymentComplete']);
        add_action('woocommerce_order_status_changed', [$this, 'handleOrderStatusChange'], 10, 4);
        add_action('woocommerce_order_status_on-hold', [$this, 'handleOnHoldPayment']);
        add_action('woocommerce_order_status_failed', [$this, 'handleFailedPayment']);
        add_action('woocommerce_order_status_refunded', [$this, 'handleRefundedPayment']);

        
        add_action('wp_ajax_wallet_up_wc_payment_display', [$this, 'handlePaymentDisplay']);
        add_action('wp_ajax_nopriv_wallet_up_wc_payment_display', [$this, 'handlePaymentDisplay']);

        add_action('wp_ajax_wallet_up_record_impression', [$this, 'handleRecordImpression']);
        add_action('wp_ajax_nopriv_wallet_up_record_impression', [$this, 'handleRecordImpression']);

        add_action('wp_ajax_wallet_up_stripe_confirm_payment', [$this, 'handleStripeConfirmPayment']);
        add_action('wp_ajax_nopriv_wallet_up_stripe_confirm_payment', [$this, 'handleStripeConfirmPayment']);

        remove_action('wp_ajax_wallet_up_save_qr_settings', [$this, 'handleSaveQRSettings']);
        remove_action('wp_ajax_nopriv_wallet_up_save_qr_settings', [$this, 'handleSaveQRSettings']);

        add_action('wp_ajax_wallet_up_check_wc_status', [$this, 'checkWooCommerceStatus']);
        

        add_action('wp_footer', [$this, 'printCheckoutNoAutoSelectScript'], 100);

        add_action('woocommerce_blocks_loaded', [$this, 'maybeRegisterBlockSupport'], 10);

        add_action('woocommerce_blocks_loaded', [$this, 'registerStoreApiDiscountCallback'], 10);

        add_action('plugins_loaded', function () {
            if (!class_exists('WalletUpPro\\WooCommerce\\WalletUpProGateway') && class_exists('WC_Payment_Gateway')) {
                require_once dirname(__FILE__) . '/WalletUpProGateway.php';
            }
            if (class_exists('WalletUpPro\\WooCommerce\\WalletUpProGateway')) {
                add_action('woocommerce_cart_calculate_fees', [WalletUpProGateway::class, 'applyPaymentMethodDiscountStatic']);
                add_action('wp_ajax_wallet_up_set_selected_method', [WalletUpProGateway::class, 'ajaxSetSelectedMethod']);
                add_action('wp_ajax_nopriv_wallet_up_set_selected_method', [WalletUpProGateway::class, 'ajaxSetSelectedMethod']);
            }
        }, 12);

        add_action('template_redirect', [$this, 'clearStaleSelectionOnCheckoutLoad']);

        add_action('walletup_payment_status_changed', [$this, 'completeLinkedWcOrder'], 10, 3);

        add_action('init', [$this, 'earlyBlocksRegistration'], 5);
        
        add_filter('walletup_use_legacy_checkout_block', [$this, 'shouldUseLegacyCheckoutBlock']);


        $this->initialized = true;
    }

    public function initializeGateway(): void
    {
        if (!class_exists('WC_Payment_Gateway')) {
            return;
        }

        require_once dirname(__FILE__) . '/WalletUpProGateway.php';
    }

    public function completeLinkedWcOrder(string $paymentId, string $status, array $data = []): void
    {
        if ($status !== 'completed' || !function_exists('wc_get_order')) {
            return;
        }

        global $wpdb;
        $txTable = $wpdb->prefix . 'walletup_payment_transactions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh data required.
        $payment = $wpdb->get_row($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely derived from wpdb prefix.
            "SELECT wc_order_id, payment_method FROM {$txTable} WHERE payment_id = %s",
            $paymentId
        ));

        if (!$payment || empty($payment->wc_order_id)) {
            return;
        }

        $order = wc_get_order($payment->wc_order_id);
        if (!$order || $order->is_paid()) {
            return;
        }

        $gatewayId = (string) $order->get_payment_method();
        if (strpos($gatewayId, 'wallet_up') !== 0) {
            return;
        }

        $providerTxId = $data['provider_transaction_id'] ?? $paymentId;
        $order->payment_complete($providerTxId);

        if (!empty($providerTxId)) {
            $order->update_meta_data('_walletup_provider_transaction_id', $providerTxId);
        }
        if (!empty($payment->payment_method) && !$order->get_meta('_walletup_provider')) {
            $providerMap = ['stripe' => 'stripe', 'paypal_commerce' => 'paypal', 'paypal' => 'paypal'];
            $order->update_meta_data('_walletup_provider', $providerMap[$payment->payment_method] ?? $payment->payment_method);
        }

        /* translators: 1: payment method, 2: provider transaction ID */
        $order->add_order_note(sprintf(
            esc_html__('Payment confirmed (%1$s, Transaction: %2$s)', 'walletup'),
            ucfirst($payment->payment_method ?? 'unknown'),
            $providerTxId
        ));
        $order->save();
    }

    public function clearStaleSelectionOnCheckoutLoad(): void
    {
        if (wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }
        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url()) {
            return; // order-received, order-pay, etc.
        }
        if (function_exists('WC') && WC()->session) {
            WC()->session->__unset('wallet_up_selected_method');
        }
    }

    public function registerStoreApiDiscountCallback(): void
    {
        if (!function_exists('woocommerce_store_api_register_update_callback')) {
            return;
        }

        woocommerce_store_api_register_update_callback([
            'namespace' => 'walletup',
            'callback'  => function ($data) {
                if (!function_exists('WC') || !WC()->session) {
                    return;
                }
                if (!empty($data['selected_method'])) {
                    WC()->session->set('wallet_up_selected_method', sanitize_key($data['selected_method']));
                }
                if (!empty($data['payment_method'])) {
                    WC()->session->set('chosen_payment_method', sanitize_key($data['payment_method']));
                }
            },
        ]);
    }

    public function maybeRegisterBlockSupport(): void
    {
        if (!$this->validateBlocksEnvironment()) {
            return;
        }

        try {
            $blocks_file = dirname(__FILE__) . '/Blocks/WalletUpProBlocksSupport_OFFICIAL.php';
            if (!file_exists($blocks_file)) {
                throw new Exception(esc_html('WooCommerce blocks support file not found at: ' . $blocks_file));
            }

            require_once $blocks_file;

            if (!class_exists('\WalletUpPro\WooCommerce\Blocks\WalletUpProBlocksSupport_OFFICIAL')) {
                throw new Exception(esc_html('WalletUpProBlocksSupport_OFFICIAL class failed to load'));
            }

            add_action('woocommerce_blocks_payment_method_type_registration', function ($payment_method_registry) {
                $this->registerBlocksPaymentMethod($payment_method_registry);
            }, 10);

        } catch (Exception $e) {
            $this->errorHandler->log('Failed to setup blocks support: ' . $e->getMessage());
            $this->errorHandler->log('Stack trace: ' . $e->getTraceAsString());
        }
    }

    private function validateBlocksEnvironment(): bool
    {
        if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
            $this->errorHandler->log('WooCommerce Blocks AbstractPaymentMethodType not available');
            return false;
        }

        if (!class_exists('Automattic\WooCommerce\Blocks\Package')) {
            $this->errorHandler->log('WooCommerce Blocks Package class not available');
            return false;
        }

        if (!did_action('woocommerce_blocks_loaded')) {
            $this->errorHandler->log('WooCommerce blocks not yet loaded - registration may be premature');
            return false;
        }

        return true;
    }

    private function registerBlocksPaymentMethod($payment_method_registry): void
    {
        try {
            if (!is_object($payment_method_registry) || !method_exists($payment_method_registry, 'register')) {
                throw new Exception(esc_html('Invalid payment method registry provided'));
            }

            $display_mode = get_option('wallet_up_wc_display_mode', 'grouped');

            if ($display_mode === 'individual') {
                $this->registerIndividualMethodBlocks($payment_method_registry);
            } elseif ($display_mode === 'mixed') {
                $mixed_config = get_option('wallet_up_wc_mixed_methods', []);
                $individual_ids = $mixed_config['individual'] ?? [];
                $enabled_methods = $this->getEnabledMethodIds();
                $has_grouped = false;

                foreach ($enabled_methods as $method_id) {
                    if (in_array($method_id, $individual_ids, true)) {
                        $this->registerSingleMethodBlock($payment_method_registry, $method_id);
                    } else {
                        $has_grouped = true;
                    }
                }

                if ($has_grouped) {
                    $this->registerGroupedBlock($payment_method_registry);
                }
            } else {
                $this->registerGroupedBlock($payment_method_registry);
            }

            $this->registerStripeCardBlock($payment_method_registry);
            $this->registerPayPalCommerceBlock($payment_method_registry);
            $this->registerCashAppApiBlock($payment_method_registry);
            $this->registerVenmoApiBlock($payment_method_registry);

            $this->registerWalletUpPayBlock($payment_method_registry);

        } catch (Exception $e) {
            $this->errorHandler->log('Failed to register blocks payment method: ' . $e->getMessage());
            $this->errorHandler->log('Registry type: ' . (is_object($payment_method_registry) ? get_class($payment_method_registry) : gettype($payment_method_registry)));
        }
    }

    private function registerStripeCardBlock($payment_method_registry): void
    {
        try {
            if (!class_exists('\WalletUpPro\WooCommerce\Blocks\StripeCardBlocksSupport')) {
                return;
            }
            $stripe_blocks = new \WalletUpPro\WooCommerce\Blocks\StripeCardBlocksSupport();
            $stripe_blocks->initialize();
            $payment_method_registry->register($stripe_blocks);
        } catch (Exception $e) {
            $this->errorHandler->log('Failed to register Stripe card block: ' . $e->getMessage());
        }
    }

    private function registerPayPalCommerceBlock($payment_method_registry): void
    {
        try {
            if (!class_exists('\WalletUpPro\WooCommerce\Blocks\PayPalCommerceBlocksSupport')) {
                return;
            }
            $blocks = new \WalletUpPro\WooCommerce\Blocks\PayPalCommerceBlocksSupport();
            $blocks->initialize();
            $payment_method_registry->register($blocks);
        } catch (Exception $e) {
            $this->errorHandler->log('Failed to register PayPal Commerce block: ' . $e->getMessage());
        }
    }

    private function registerWalletUpPayBlock($payment_method_registry): void
    {
        try {
            if (!class_exists('\WalletUpPro\WooCommerce\Blocks\WalletUpPayBlocksSupport')) {
                return;
            }
            $blocks = new \WalletUpPro\WooCommerce\Blocks\WalletUpPayBlocksSupport();
            $blocks->initialize();
            $payment_method_registry->register($blocks);
        } catch (Exception $e) {
            $this->errorHandler->log('Failed to register WalletUp Pay express block: ' . $e->getMessage());
        }
    }

    private function registerCashAppApiBlock($payment_method_registry): void
    {
        try {
            if (!class_exists('\WalletUpPro\WooCommerce\Blocks\CashAppApiBlocksSupport')) {
                return;
            }
            $blocks = new \WalletUpPro\WooCommerce\Blocks\CashAppApiBlocksSupport();
            $blocks->initialize();
            $payment_method_registry->register($blocks);
        } catch (Exception $e) {
            $this->errorHandler->log('Failed to register Cash App Pay API block: ' . $e->getMessage());
        }
    }

    private function registerVenmoApiBlock($payment_method_registry): void
    {
        try {
            if (!class_exists('\WalletUpPro\WooCommerce\Blocks\VenmoApiBlocksSupport')) {
                return;
            }
            $blocks = new \WalletUpPro\WooCommerce\Blocks\VenmoApiBlocksSupport();
            $blocks->initialize();
            $payment_method_registry->register($blocks);
        } catch (Exception $e) {
            $this->errorHandler->log('Failed to register Venmo API block: ' . $e->getMessage());
        }
    }

    private function registerGroupedBlock($payment_method_registry): void
    {
        $blocks_integration = new \WalletUpPro\WooCommerce\Blocks\WalletUpProBlocksSupport_OFFICIAL();
        $blocks_integration->initialize();
        $payment_method_registry->register($blocks_integration);
        wp_enqueue_script('wc-walletup-blocks-integration');
    }

    private function registerIndividualMethodBlocks($payment_method_registry): void
    {
        $enabled_methods = $this->getEnabledMethodIds();
        foreach ($enabled_methods as $method_id) {
            $this->registerSingleMethodBlock($payment_method_registry, $method_id);
        }
    }

    private function registerSingleMethodBlock($payment_method_registry, string $method_id): void
    {
        try {
            $method_blocks = new \WalletUpPro\WooCommerce\Blocks\WalletUpMethodBlocksSupport($method_id);
            $method_blocks->initialize();
            $payment_method_registry->register($method_blocks);
        } catch (Exception $e) {
            $this->errorHandler->log('Failed to register method block for ' . $method_id . ': ' . $e->getMessage());
        }
    }
    
    public function earlyBlocksRegistration(): void
    {
        if (!class_exists('WooCommerce') || !function_exists('WC')) {
            return;
        }

        if (!class_exists('Automattic\WooCommerce\Blocks\Package')) {
            return;
        }

        $script_handle = 'wc-walletup-blocks-integration';

        if (wp_script_is($script_handle, 'registered')) {
            return;
        }

        $manifest_path = WALLET_UP_PATH . 'walup-react/dist/.vite/manifest.json';

        if (file_exists($manifest_path)) {
            $manifest = json_decode(file_get_contents($manifest_path), true);
            $entry = $manifest['src/checkoutBlockSimple.js'] ?? $manifest['src/checkoutBlockWrapper.js'] ?? null;

            if ($entry) {
                $script_url = WALLET_UP_URL . 'walup-react/dist/' . $entry['file'];

                wp_register_script(
                    $script_handle,
                    $script_url,
                    ['wp-element', 'wp-i18n', 'wc-blocks-registry', 'wc-settings'],
                    WALLET_UP_VERSION,
                    true
                );

            }
        }
    }

    public function addWooCommerceData($data): array
    {
        $data['woocommerce'] = [
            'enabled' => $this->isWooCommerceActive(),
            'version' => $this->isWooCommerceActive() ? WC()->version : null,
            'settings' => $this->getGatewaySettings(),
            'display_settings' => get_option(WooCommerceConfig::OPTION_KEYS['qr_settings'], []),
        ];
        return $data;
    }

    public function checkWooCommerceStatus()
    {
        try {
            if (!check_ajax_referer('wallet_up_wc_save_settings', 'nonce', false)) {
                wp_send_json([
                    'success' => false,
                    'data' => [
                        'enabled' => 'no',
                        'message' => 'Invalid security token'
                    ]
                ]);
                return;
            }

            $isActive = $this->isWooCommerceActive();
            $isConfigured = $this->isWooCommerceConfigured();

            $response = [
                'success' => $isActive,
                'data' => [
                    'enabled' => $isConfigured ? 'yes' : 'no'
                ]
            ];

            wp_send_json($response);

        } catch (Exception $e) {
            $this->errorHandler->log('WooCommerce status check failed: ' . $e->getMessage());
            wp_send_json([
                'success' => false,
                'data' => [
                    'enabled' => 'no',
                    'message' => 'Error checking WooCommerce status: ' . $e->getMessage()
                ]
            ]);
        }
    }

    public function getWooCommerceSettings()
    {
        try {
            $nonce_check = check_ajax_referer('wallet_up_wc_save_settings', 'nonce', false) || 
                          check_ajax_referer('wallet_up_wc_settings', 'nonce', false);
            
            if (!$nonce_check) {
                throw new Exception(esc_html('Invalid security token'));
            }

            if (!current_user_can('manage_woocommerce')) {
                throw new Exception(esc_html('Permission denied'));
            }

            if (!$this->isWooCommerceActive()) {
                wp_send_json_error([
                    'message' => 'WooCommerce is not active',
                    'woocommerce_enabled' => false
                ]);
                return;
            }

            $config = WooCommerceConfig::getInstance();
            $settings = $config->getSettings();

            $response_data = [
                'wcSettings' => $settings['wcSettings'] ?? WooCommerceConfig::DEFAULT_SETTINGS,
                'wcQRSettings' => $settings['wcQRSettings'] ?? WooCommerceConfig::DEFAULT_QR_SETTINGS,
                'methodSettings' => $settings['methodSettings'] ?? [],
                'methodDetails' => $settings['methodDetails'] ?? []
            ];

            wp_send_json_success($response_data);

        } catch (\Exception $e) {
            $this->errorHandler->log('Failed to retrieve WooCommerce settings: ' . $e->getMessage());
            wp_send_json_error([
                'message' => 'Unable to load WooCommerce settings',
                'error' => $e->getMessage()
            ]);
        }
    }

    public function saveWooCommerceSettings()
    {
        try {
            if (!check_ajax_referer('wallet_up_wc_save_settings', 'nonce', false)) {
                throw new Exception(esc_html('Invalid security token'));
            }

            if (!current_user_can('manage_woocommerce')) {
                throw new Exception(esc_html('Permission denied'));
            }

            if (!$this->isWooCommerceActive()) {
                wp_send_json_error([
                    'message' => 'WooCommerce is not active',
                    'woocommerce_enabled' => false
                ]);
                return;
            }

            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON decoded below; fields sanitized individually in updateSettings()
            $raw_settings = wp_unslash($_POST['settings'] ?? '');
            $settings = json_decode(trim($raw_settings), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception(esc_html('Invalid JSON format. Error: ' . json_last_error_msg()));
            }

            if (!$settings || !is_array($settings)) {
                throw new Exception(esc_html('Invalid settings format'));
            }

            if (!isset($settings['wcSettings'], $settings['wcQRSettings'], $settings['methodSettings'])) {
                throw new Exception(esc_html('Incomplete settings structure'));
            }

            if (!is_array($settings['wcSettings']) || !is_array($settings['wcQRSettings']) || !is_array($settings['methodSettings'])) {
                throw new Exception(esc_html('Settings arrays are not properly formatted'));
            }

            $config = WooCommerceConfig::getInstance();
            $config->updateSettings([
                'wcSettings' => $settings['wcSettings'],
                'wcQRSettings' => $settings['wcQRSettings'],
                'methodSettings' => $settings['methodSettings']
            ]);

            $gateways = WC()->payment_gateways()->payment_gateways();
            if (isset($gateways['wallet_up'])) {
                $gateway = $gateways['wallet_up'];
                foreach ($settings['wcSettings'] as $key => $value) {
                    $gateway->update_option($key, $value);
                }

            }
            
            $config->clearCache();

            \WalletUpPro\Core\Security\AuditLog::record('woocommerce_settings_saved');

            wp_send_json_success([
                'message' => 'Settings saved successfully',
                'settings' => $settings
            ]);

        } catch (Exception $e) {
            $this->errorHandler->log('Failed to save WooCommerce settings: ' . $e->getMessage());
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    private function normalizeBlockOrder(array $decoded): array
    {
        $default = ConfirmationPage::SECTIONS;
        $order = [];
        foreach ($decoded as $key) {
            $key = sanitize_key($key);
            if (in_array($key, $default, true) && !in_array($key, $order, true)) {
                $order[] = $key;
            }
        }
        foreach ($default as $key) {
            if (!in_array($key, $order, true)) {
                $order[] = $key;
            }
        }
        return $order;
    }

    private function sanitizeBlockConfig($decoded): array
    {
        $default = ConfirmationPage::SECTIONS;
        $config = [];
        if (!is_array($decoded)) {
            return $config;
        }
        foreach ($default as $key) {
            $raw = isset($decoded[$key]) && is_array($decoded[$key]) ? $decoded[$key] : [];
            $config[$key] = [
                'visible' => !isset($raw['visible']) || $raw['visible'] === true || $raw['visible'] === 'true' || $raw['visible'] === 1 || $raw['visible'] === '1',
                'heading' => isset($raw['heading']) ? sanitize_text_field((string) $raw['heading']) : '',
                'note'    => isset($raw['note']) ? sanitize_textarea_field((string) $raw['note']) : '',
            ];
        }
        return $config;
    }

    public function overrideThankYouTemplate($located, $template_name, $args)
    {
        if ($template_name !== 'checkout/thankyou.php') {
            return $located;
        }
        $order = is_array($args) && isset($args['order']) ? $args['order'] : null;
        if (!$order instanceof \WC_Order) {
            return $located;
        }
        $pm = $order->get_payment_method();
        if ($pm !== 'wallet_up' && strpos($pm, 'wallet_up_') !== 0) {
            return $located;
        }
        $custom = WALLET_UP_PATH . 'templates/checkout/thankyou.php';
        return file_exists($custom) ? $custom : $located;
    }

    public function renderPaymentSection($order_id): void
    {
        static $done = [];
        $order_id = (int) $order_id;
        if (isset($done[$order_id])) {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return;
        }
        $pm = $order->get_payment_method();
        if ($pm !== 'wallet_up' && strpos($pm, 'wallet_up_') !== 0) {
            return;
        }
        $done[$order_id] = true;
        $html = ConfirmationPage::buildPaymentSectionFromOrder($order);
        if ($html !== '') {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ConfirmationPage returns server-built, escaped markup.
            echo $html;
        }
    }

    public function getConfirmationDisplay(): void
    {
        if (!check_ajax_referer('wallet_up_wc_save_settings', 'nonce', false) || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }

        $default = ConfirmationPage::SECTIONS;
        $saved = get_option('wallet_up_confirmation_block_order', []);
        $order = $this->normalizeBlockOrder(is_array($saved) ? $saved : []);
        $config = $this->sanitizeBlockConfig(get_option('wallet_up_confirmation_block_config', []));

        wp_send_json_success([
            'enabled' => get_option('wallet_up_confirmation_display_enabled', 'no') === 'yes',
            'order'   => $order,
            'config'  => $config,
            'blocks'  => $default,
        ]);
    }

    public function saveConfirmationDisplay(): void
    {
        if (!check_ajax_referer('wallet_up_wc_save_settings', 'nonce', false) || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }

        $enabled = isset($_POST['enabled']) && in_array(sanitize_text_field(wp_unslash($_POST['enabled'])), ['yes', 'true', '1'], true);

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON decoded then whitelisted in normalizeBlockOrder().
        $order = $this->normalizeBlockOrder((array) json_decode(wp_unslash($_POST['order'] ?? '[]'), true));
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON decoded then each field sanitized in sanitizeBlockConfig().
        $config = $this->sanitizeBlockConfig(json_decode(wp_unslash($_POST['config'] ?? '[]'), true));

        update_option('wallet_up_confirmation_display_enabled', $enabled ? 'yes' : 'no');
        update_option('wallet_up_confirmation_block_order', $order);
        update_option('wallet_up_confirmation_block_config', $config);

        \WalletUpPro\Core\Security\AuditLog::record('confirmation_display_saved', 'enabled=' . ($enabled ? 'yes' : 'no') . ' order=[' . implode(',', $order) . ']');

        wp_send_json_success([
            'enabled' => $enabled,
            'order'   => $order,
            'config'  => $config,
        ]);
    }

    public function previewConfirmation(): void
    {
        if (!check_ajax_referer('wallet_up_wc_save_settings', 'nonce', false) || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }

        $enabled = isset($_POST['enabled']) && in_array(sanitize_text_field(wp_unslash($_POST['enabled'])), ['yes', 'true', '1'], true);
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- whitelisted in normalizeBlockOrder().
        $order = $this->normalizeBlockOrder((array) json_decode(wp_unslash($_POST['order'] ?? '[]'), true));
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in sanitizeBlockConfig().
        $config = $this->sanitizeBlockConfig(json_decode(wp_unslash($_POST['config'] ?? '[]'), true));

        $method = sanitize_key(wp_unslash($_POST['method'] ?? 'zelle'));
        if (!in_array($method, \WalletUpPro\Core\Config\PaymentMethodsConfig::getAllMethodIds(), true)) {
            $method = 'zelle';
        }

        $methodObj = \WalletUpPro\Core\PaymentRegistry::getInstance()->getMethod($method);
        $methodName = $methodObj ? $methodObj->getMethodName() : ucfirst($method);

        \WalletUpPro\Core\Display\DisplayHandler::$previewOverride = ['preview' => true];
        try {
            $display = \WalletUpPro\Core\Display\DisplayHandler::getInstance()->generateDisplay(
                'qr',
                'https://example.com/pay',
                $method,
                get_option('wallet_up_display_settings', []),
                ['amount' => '82.40', 'post_id' => 0, 'order_id' => 999999999]
            );
        } finally {
            \WalletUpPro\Core\Display\DisplayHandler::$previewOverride = null;
        }

        $ctx = ConfirmationPage::sampleContext($display, $methodName);
        $html = ConfirmationPage::renderWith($ctx, $enabled, $order, $config);

        wp_send_json_success(['html' => $html]);
    }

    private function updateWooCommercePaymentMethods(array $method_settings): void
    {
        if (!$this->isWooCommerceActive()) {
            return;
        }

        $gateways = WC()->payment_gateways->payment_gateways();
        foreach ($method_settings as $method_id => $status) {
            if (isset($gateways[$method_id])) {
                $gateways[$method_id]->enabled = $status ? 'yes' : 'no';
                update_option($gateways[$method_id]->get_option_key(), $gateways[$method_id]->settings);
            }
            update_option(WooCommerceConfig::OPTION_KEYS['method_prefix'] . $method_id, $status ? 'yes' : 'no');
        }
    }
    public function getWooCommerceOrders(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new Exception(esc_html(__('Invalid security token', 'walletup')));
            }

            if (!current_user_can('manage_woocommerce')) {
                throw new Exception(esc_html(__('You do not have permission to view orders', 'walletup')));
            }

            if (!$this->isWooCommerceActive()) {
                throw new Exception(esc_html(__('WooCommerce is not active', 'walletup')));
            }

            $use_hpos = false;
            if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')) {
                $use_hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
            }

            global $wpdb;
            if ($use_hpos) {
                $ordersTable = $wpdb->prefix . 'wc_orders';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                $orderIds = $wpdb->get_col($wpdb->prepare(
                    "SELECT id FROM {$ordersTable} WHERE payment_method LIKE %s AND type = 'shop_order' ORDER BY date_created_gmt DESC LIMIT %d",
                    'wallet_up%',
                    10
                ));
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                $orderIds = $wpdb->get_col($wpdb->prepare(
                    "SELECT p.ID FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_payment_method'
                     WHERE pm.meta_value LIKE %s AND p.post_type = 'shop_order'
                     ORDER BY p.post_date DESC LIMIT %d",
                    'wallet_up%',
                    10
                ));
            }

            $orders = !empty($orderIds) ? wc_get_orders(['post__in' => $orderIds, 'limit' => 10, 'orderby' => 'date', 'order' => 'DESC']) : [];

            if (is_wp_error($orders)) {
                throw new Exception(esc_html($orders->get_error_message()));
            }

            $formatted_orders = [];

            foreach ($orders as $order) {
                if (!$order || !is_a($order, 'WC_Order')) {
                    continue;
                }

                $date_created = $order->get_date_created();

                $method_id = $order->get_meta('_wallet_up_payment_method');
                if (empty($method_id)) {
                    $gatewayId = $order->get_payment_method();
                    $method_id = str_starts_with($gatewayId, 'wallet_up_') ? substr($gatewayId, 10) : '';
                }
                $method_name = $method_id ? (\WalletUpPro\Core\Config\PaymentMethodsConfig::getMethodName($method_id) ?: ucfirst(str_replace('_', ' ', $method_id))) : '';

                $customer_name = $this->getCustomerName($order);

                $currency = $order->get_currency();

                $formatted_orders[] = [
                    'id' => $order->get_id(),
                    'number' => $order->get_order_number(),
                    'date_created' => $date_created ? $date_created->date('Y-m-d H:i:s') : '',
                    'status' => $order->get_status(),
                    'total' => $this->formatCurrencyAmount($order->get_total(), $order->get_currency()),
                    /* translators: %s: Payment method name */
                    'payment_method_title' => $method_name ? sprintf(
                        __('WalletUpPro Pay (%s)', 'walletup'),
                        $method_name
                    ) : __('WalletUpPro Pay', 'walletup'),
                    'view_url' => $order->get_edit_order_url(),
                    'currency' => $currency,
                    'customer_name' => $customer_name, // Added customer name
                    'customer_id' => $order->get_customer_id() // Added customer ID for reference
                ];
            }

            wp_send_json_success([
                'orders' => $formatted_orders,
                'count' => count($formatted_orders)
            ]);

        } catch (Exception $e) {
            $this->errorHandler->log('Failed to get WooCommerce orders: ' . $e->getMessage());
            wp_send_json_error([
                'message' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
        }
    }

    /**
     * Gets formatted customer name from order with proper fallbacks
     * 
     * @param \WC_Order $order The order object
     * @return string Formatted customer name
     */
    private function getCustomerName(\WC_Order $order): string
    {
        $first_name = $order->get_billing_first_name();
        $last_name = $order->get_billing_last_name();

        if ($first_name || $last_name) {
            return trim(sprintf('%s %s', $first_name, $last_name));
        }

        $customer_id = $order->get_customer_id();
        if ($customer_id) {
            $customer = new \WC_Customer($customer_id);
            if ($customer) {
                $first_name = $customer->get_first_name();
                $last_name = $customer->get_last_name();
                if ($first_name || $last_name) {
                    return trim(sprintf('%s %s', $first_name, $last_name));
                }
            }
        }

        $billing_email = $order->get_billing_email();
        return $billing_email ? $billing_email : __('Guest', 'walletup');
    }
    private function formatCurrencyAmount($amount, $currency): string
    {
        $price = number_format(
            (float) $amount,
            wc_get_price_decimals(),
            wc_get_price_decimal_separator(),
            wc_get_price_thousand_separator()
        );

        $symbol = html_entity_decode(
            get_woocommerce_currency_symbol($currency),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $position = get_option('woocommerce_currency_pos');

        switch ($position) {
            case 'left':
                return $symbol . $price;
            case 'right':
                return $price . $symbol;
            case 'left_space':
                return $symbol . ' ' . $price;
            case 'right_space':
                return $price . ' ' . $symbol;
            default:
                return $symbol . $price;
        }
    }
    public function handleOrderStatusChange($order_id, $old_status, $new_status, $order): void
    {
        if (!$order || $order->get_payment_method() !== 'wallet_up') {
            return;
        }

        try {
            $methodId = $order->get_meta('_wallet_up_payment_method');
            if (!$methodId) {
                return;
            }

            $notification_type = '';
            switch ($new_status) {
                case 'completed':
                    $notification_type = 'completed';
                    break;
                case 'on-hold':
                    $notification_type = 'pending';
                    break;
                case 'failed':
                    $notification_type = 'failed';
                    break;
                case 'refunded':
                    $notification_type = 'refunded';
                    break;
            }

            if ($notification_type) {
                $sent_flag = '_wallet_up_notified_' . $notification_type;
                if ($order->get_meta($sent_flag)) {
                    $notification_type = '';
                } else {
                    $order->update_meta_data($sent_flag, current_time('mysql'));
                    $order->save_meta_data();
                }
            }
            if ($notification_type) {
                switch ($notification_type) {
                    case 'completed':
                        $this->emailManager->send_payment_completed_notification($order_id, $order);
                        break;
                    case 'pending':
                        $this->emailManager->send_payment_pending_notification($order_id, $order);
                        break;
                    case 'failed':
                        $this->emailManager->send_payment_failed_notification($order_id, $order);
                        break;
                }

                $this->errorHandler->log(sprintf(
                    'Sent %s email notification for order %s - Status change from %s to %s',
                    $notification_type,
                    $order_id,
                    $old_status,
                    $new_status
                ));
            }

            $this->analytics->trackEventPublic([
                'event_type' => 'order_status_change',
                'payment_method' => $methodId,
                'order_id' => $order_id,
                'old_status' => $old_status,
                'new_status' => $new_status,
                'amount' => $order->get_total(),
                'currency' => $order->get_currency()
            ]);

        } catch (Exception $e) {
            $this->errorHandler->log('Order status change handling failed: ' . $e->getMessage());
        }
    }

    public function handleFailedPayment($order_id): void
    {
        try {
            $order = wc_get_order($order_id);
            if (!$order || $order->get_payment_method() !== 'wallet_up') {
                return;
            }

            $notification = new PaymentNotification($order, 'failed');
            $notification->send();

            $this->analytics->trackEventPublic([
                'event_type' => 'payment_failed',
                'payment_method' => $order->get_meta('_wallet_up_payment_method'),
                'order_id' => $order_id,
                'amount' => $order->get_total()
            ]);

        } catch (Exception $e) {
            $this->errorHandler->log('Failed payment handling error: ' . $e->getMessage());
        }
    }

    public function handleRefundedPayment($order_id): void
    {
        try {
            $order = wc_get_order($order_id);
            if (!$order || $order->get_payment_method() !== 'wallet_up') {
                return;
            }

            $notification = new PaymentNotification($order, 'refunded');
            $notification->send();

            $this->analytics->trackEventPublic([
                'event_type' => 'payment_refunded',
                'payment_method' => $order->get_meta('_wallet_up_payment_method'),
                'order_id' => $order_id,
                'amount' => $order->get_total_refunded()
            ]);

        } catch (Exception $e) {
            $this->errorHandler->log('Refund handling error: ' . $e->getMessage());
        }
    }
    public function handlePaymentComplete($order_id): void
    {
        try {
            $order = wc_get_order($order_id);
            if (!$order || $order->get_payment_method() !== 'wallet_up') {
                return;
            }

            $methodId = $order->get_meta('_wallet_up_payment_method');
            if (!$methodId) {
                return;
            }

            $this->analytics->trackEventPublic([
                'event_type' => 'payment_complete',
                'payment_method' => $methodId,
                'order_id' => $order_id,
                'amount' => $order->get_total()
            ]);

            $notification = new PaymentNotification($order, 'completed');
            $notification->send();

        } catch (Exception $e) {
            $this->errorHandler->log('Payment completion handler error: ' . $e->getMessage());
        }
    }

    public function handlePendingPayment($order_id): void
    {
        try {
            $order = wc_get_order($order_id);
            if (!$order || $order->get_payment_method() !== 'wallet_up') {
                return;
            }

            $this->analytics->trackEventPublic([
                'event_type' => 'payment_pending',
                'payment_method' => $order->get_meta('_wallet_up_payment_method'),
                'order_id' => $order_id
            ]);

            $order->update_status('pending', __('Awaiting mobile payment confirmation.', 'walletup'));

        } catch (Exception $e) {
            $this->errorHandler->log('Pending payment handler error: ' . $e->getMessage());
        }
    }

    public function handleOnHoldPayment($order_id): void
    {
        try {
            $order = wc_get_order($order_id);
            if (!$order || $order->get_payment_method() !== 'wallet_up') {
                return;
            }

            $this->analytics->trackEventPublic([
                'event_type' => 'payment_on_hold',
                'payment_method' => $order->get_meta('_wallet_up_payment_method'),
                'order_id' => $order_id
            ]);

            $order->add_order_note(__('Payment is being verified.', 'walletup'));

        } catch (Exception $e) {
            $this->errorHandler->log('On-hold payment handler error: ' . $e->getMessage());
        }
    }

    public function handleStripeConfirmPayment(): void
    {
        self::confirmStripePayment();
    }

    public static function confirmStripePayment(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_stripe_payment', 'nonce', false)) {
                throw new Exception(esc_html('Invalid security token'));
            }

            $intent_id = sanitize_text_field(wp_unslash($_POST['payment_intent_id'] ?? ''));
            $order_id = intval(wp_unslash($_POST['order_id'] ?? 0));

            if (empty($intent_id) || empty($order_id)) {
                throw new Exception(esc_html('Missing payment data'));
            }

            $order = wc_get_order($order_id);
            if (!$order) {
                throw new Exception(esc_html('Invalid order'));
            }

            $existing = wc_get_orders([
                'limit'      => 1,
                'return'     => 'ids',
                'meta_key'   => '_stripe_payment_intent_id',
                'meta_value' => $intent_id,
                'exclude'    => [$order_id],
            ]);
            if (!empty($existing) || $order->is_paid()) {
                throw new Exception(esc_html__('This payment has already been recorded.', 'walletup'));
            }

            $gate = \WalletUpPro\Core\Payments\PaymentGatewayHandler::assertPaidFor([
                'payment_intent_id' => $intent_id,
                'bind_key'          => 'order_id',
                'bind_value'        => (string) $order_id,
                'also_match'        => ['order_key' => $order->get_order_key()],
                'expected_amount'   => \WalletUpPro\Core\Payments\PaymentGatewayHandler::toMinorUnits(
                    (float) $order->get_total(),
                    $order->get_currency()
                ),
                'expected_currency' => $order->get_currency(),
            ]);
            if (empty($gate['paid']) && empty($gate['processing'])) {
                throw new Exception(esc_html($gate['error']));
            }

            $body = ['status' => $gate['status']];

            if (!empty($gate['paid'])) {
                $order->payment_complete($intent_id);
                /* translators: %s: Stripe PaymentIntent ID */
                $order->add_order_note(sprintf(
                    __('Stripe payment confirmed after 3D Secure (PaymentIntent: %s)', 'walletup'),
                    $intent_id
                ));
                $order->update_meta_data('_stripe_payment_intent_id', $intent_id);
                $order->save();

                WC()->cart->empty_cart();

                $gateway = new StripeCardGateway();
                wp_send_json_success(['redirect' => $gateway->get_return_url($order)]);
            } else {
                throw new Exception(esc_html(sprintf('Payment status: %s', $body['status'])));
            }
        } catch (Exception $e) {
            ErrorHandler::getInstance()->log('Stripe 3DS confirmation error: ' . $e->getMessage());
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public static function isMasterEnabled(): bool
    {
        $s = get_option('wallet_up_woocommerce_settings', []);
        if (!is_array($s)) {
            return false;
        }
        if (!isset($s['enabled'])) {
            return true;
        }
        return $s['enabled'] === 'yes' || $s['enabled'] === true || $s['enabled'] === 1 || $s['enabled'] === '1';
    }

    public function enqueueCheckoutMethodStyles(): void
    {
        if (! function_exists('is_checkout') || ! is_checkout()) {
            return;
        }
        $sel = '.woocommerce-checkout ul.wc_payment_methods li[class*="payment_method_wallet_up_"]';
        $css = "
            {$sel}{list-style:none;padding:12px 14px;margin:0 0 8px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;}
            {$sel} > label{display:flex;align-items:center;gap:8px;margin:0;font-weight:600;line-height:1.4;cursor:pointer;}
            {$sel} > input[type=radio]{margin:0 8px 0 0;flex:0 0 auto;}
            {$sel} label img{float:none !important;max-height:22px;width:auto;margin:0;padding:0;display:inline-block;vertical-align:middle;}
            {$sel} .payment_box{margin-top:10px;background:#f8fafc;border-radius:8px;}
        ";
        wp_register_style('walletup-wc-checkout', false, [], defined('WALLET_UP_PRO_VERSION') ? WALLET_UP_PRO_VERSION : '1.0');
        wp_enqueue_style('walletup-wc-checkout');
        wp_add_inline_style('walletup-wc-checkout', $css);
    }

    public function gateMasterDisabled(array $available_gateways): array
    {
        if (self::isMasterEnabled()) {
            return $available_gateways;
        }
        foreach (array_keys($available_gateways) as $id) {
            if (strpos((string) $id, 'wallet_up') === 0) {
                unset($available_gateways[$id]);
            }
        }
        return $available_gateways;
    }

    public function renderMasterOffWcSettingsNotice(): void
    {
        if (self::isMasterEnabled()) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || strpos((string) $screen->id, 'woocommerce_page_wc-settings') === false) {
            return;
        }
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ($tab !== 'checkout') {
            return;
        }

        $handle = 'wallet-up-master-gate-notice';
        wp_register_style($handle, false, [], WALLET_UP_PRO_VERSION);
        wp_enqueue_style($handle);
        $script_handle = wp_script_is('jquery', 'enqueued') || wp_script_is('jquery', 'registered') ? 'jquery' : 'wp-dom-ready';

        $gated_label = __('Paused from Wallet Up dashboard', 'walletup');
        $css = '
            .woocommerce-list__item[id^="wallet_up"],
            .woocommerce table.wc_gateways tr[data-gateway_id^="wallet_up"] {
                --wup-gated-label: "' . esc_js($gated_label) . '";
                background: rgba(103, 79, 191, 0.045) !important;
                opacity: 0.86;
            }
            .wup-master-gate-badge {
                display: inline-block;
                margin-left: 8px;
                padding: 2px 9px;
                border: 1px solid rgba(103, 79, 191, 0.45);
                border-radius: 999px;
                background: rgba(103, 79, 191, 0.08);
                color: #674FBF;
                font-size: 11px;
                font-weight: 500;
                font-style: normal;
                line-height: 1.5;
                vertical-align: middle;
            }
            .woocommerce table.wc_gateways tr[data-gateway_id^="wallet_up"] td.name::after {
                content: " · " var(--wup-gated-label);
                color: #674FBF;
                font-size: 11px;
                font-style: italic;
                margin-left: 6px;
            }
            .wup-master-gate-banner {
                display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
                margin: 16px 0; padding: 14px 18px;
                background: rgba(103, 79, 191, 0.06);
                border: 1px solid rgba(103, 79, 191, 0.22);
                border-left: 4px solid #674FBF;
                border-radius: 8px;
                color: #2c2540;
            }
            .wup-master-gate-banner .wup-mg-strong { font-weight: 600; color: #674FBF; }
            .wup-master-gate-banner .button-primary {
                background: #674FBF; border-color: #674FBF; box-shadow: none;
            }
            .wup-master-gate-banner .button-primary:hover {
                background: #5a45a8; border-color: #5a45a8;
            }
        ';
        wp_add_inline_style($handle, $css);

        $data = [
            'badge'   => $gated_label,
            'title'   => __('Wallet Up', 'walletup'),
            'message' => __('Wallet Up is turned off from your Wallet Up dashboard. These methods stay configured here, but customers will not see them at checkout until you turn Wallet Up back on in the dashboard.', 'walletup'),
            'cta'     => __('Open Wallet Up dashboard', 'walletup'),
            'ctaUrl'  => admin_url('admin.php?page=wallet-up-payments'),
        ];
        $js = 'window.walletUpMasterGate = ' . wp_json_encode($data) . ';' . "\n" . <<<'JS'
        (function(){
          var d = window.walletUpMasterGate || {};
          function mount(){
            document.querySelectorAll('.woocommerce-list__item[id^="wallet_up"]').forEach(function(row){
              if (row.querySelector('.wup-master-gate-badge')) return;
              var title = row.querySelector('.woocommerce-list__item-title, [class*="title"]') || row;
              var b = document.createElement('span');
              b.className = 'wup-master-gate-badge';
              b.textContent = d.badge || '';
              title.appendChild(b);
            });
            if (!document.querySelector('.wup-master-gate-banner')) {
              var anchor = document.querySelector('.woocommerce-list, .wc-settings-payments, #wc_payments_settings, .woocommerce-layout__main') ;
              if (anchor) {
                var wrap = document.createElement('div');
                wrap.className = 'wup-master-gate-banner';
                var s = document.createElement('span'); s.className = 'wup-mg-strong'; s.textContent = d.title || '';
                var m = document.createElement('span'); m.textContent = d.message || '';
                var a = document.createElement('a'); a.className = 'button button-primary'; a.style.marginLeft = 'auto';
                a.href = d.ctaUrl || '#'; a.textContent = d.cta || '';
                wrap.appendChild(s); wrap.appendChild(m); wrap.appendChild(a);
                anchor.parentNode.insertBefore(wrap, anchor);
              }
            }
          }
          var elapsed = 0;
          var timer = setInterval(function(){
            mount();
            elapsed += 400;
            var rowsExist = document.querySelector('.woocommerce-list__item[id^="wallet_up"], table.wc_gateways tr[data-gateway_id^="wallet_up"]');
            var done = rowsExist && document.querySelector('.wup-master-gate-banner') &&
                       document.querySelector('.wup-master-gate-badge');
            if (done || elapsed >= 15000) clearInterval(timer);
          }, 400);
          mount();
        })();
        JS;
        wp_add_inline_script($script_handle, $js);
    }

    public function registerGateway($gateways): array
    {
        $display_mode = get_option('wallet_up_wc_display_mode', 'grouped');

        if ($display_mode === 'individual') {
            $enabled_methods = $this->getEnabledMethodIds();
            foreach ($enabled_methods as $method_id) {
                $gateways[] = new WalletUpMethodGateway($method_id);
            }
        } elseif ($display_mode === 'mixed') {
            $mixed_config = get_option('wallet_up_wc_mixed_methods', []);
            $individual_ids = $mixed_config['individual'] ?? [];
            $enabled_methods = $this->getEnabledMethodIds();
            $has_grouped = false;

            foreach ($enabled_methods as $method_id) {
                if (in_array($method_id, $individual_ids, true)) {
                    $gateways[] = new WalletUpMethodGateway($method_id);
                } else {
                    $has_grouped = true;
                }
            }

            if ($has_grouped) {
                $gateways[] = WalletUpProGateway::class;
            }
        } else {
            $gateways[] = WalletUpProGateway::class;
        }

        $stripe_config = get_option('wallet_up_stripe_config', []);
        if (!empty($stripe_config['enabled']) && class_exists('\WalletUpPro\WooCommerce\StripeGateway')) {
            $gateways[] = \WalletUpPro\WooCommerce\StripeGateway::class;
        }

        $gateways[] = StripeCardGateway::class;

        $gateways[] = PayPalCommerceGateway::class;
        $gateways[] = CashAppApiGateway::class;
        $gateways[] = VenmoApiGateway::class;

        return $gateways;
    }

    private function getEnabledMethodIds(): array
    {
        if (!$this->registry) {
            return [];
        }
        $registered = array_keys($this->registry->getAllMethods());
        $snapshot = \WalletUpPro\Core\Payments\MethodStatusResolver::snapshot();
        $configured = $snapshot['configured'] ?? [];
        return array_values(array_intersect($registered, $configured));
    }
    public function trackOrderStatus($order_id, $old_status, $new_status, $order): void
    {
        if (!$order || $order->get_payment_method() !== 'wallet_up') {
            return;
        }

        try {
            $methodId = $order->get_meta('_wallet_up_payment_method');
            if (!$methodId) {
                return;
            }

            $event_type = $this->mapStatusToEventType($new_status);
            if (!$event_type) {
                return; // Skip invalid status changes
            }

            $this->analytics->trackEventPublic([
                'event_type' => $event_type,
                'payment_method' => $methodId,
                'order_id' => $order_id,
                'old_status' => $old_status,
                'new_status' => $new_status,
                'amount' => $order->get_total(),
                'currency' => $order->get_currency(),
                'metadata' => wp_json_encode([
                    'order_status' => $new_status,
                    'payment_method' => $methodId
                ])
            ]);

        } catch (Exception $e) {
            $this->errorHandler->log('Order status tracking error: ' . $e->getMessage());
        }
    }

    private function mapStatusToEventType(string $status): ?string
    {
        $status_map = [
            'pending' => 'payment_pending',
            'processing' => 'payment_processing',
            'on-hold' => 'payment_on_hold',
            'completed' => 'payment_completed',
            'cancelled' => 'payment_cancelled',
            'refunded' => 'payment_refunded',
            'failed' => 'payment_failed'
        ];

        return $status_map[$status] ?? null;
    }

    public function trackNewOrder($order_id): void
    {
        try {
            $order = wc_get_order($order_id);
            if (!$order || $order->get_payment_method() !== 'wallet_up') {
                return;
            }
            \WalletUpPro\Core\Tenancy\Tenant::scopeOrder($order);

            $methodId = $order->get_meta('_wallet_up_payment_method');
            if (!$methodId) {
                return;
            }

            $this->analytics->trackEventPublic([
                'event_type' => 'wc_new_order',
                'payment_method' => $methodId,
                'order_id' => $order_id,
                'amount' => $order->get_total()
            ]);

        } catch (Exception $e) {
            $this->errorHandler->log('New order tracking error: ' . $e->getMessage());
        }
    }

    public function handleGetAnalytics(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_wc_settings', 'nonce', false)) {
                throw new Exception(esc_html('Invalid WC Get Analytics security token'));
            }

            if (!current_user_can('manage_woocommerce')) {
                throw new Exception(esc_html('Permission denied'));
            }

            $start_date = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : null;
            $end_date = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : null;

            $analytics = $this->analytics->getWooCommerceAnalytics($start_date, $end_date);

            wp_send_json_success([
                'total_orders' => $analytics['total_orders'],
                'total_completed' => $analytics['total_completed'],
                'total_displays' => $analytics['total_displays'],
                'total_interactions' => $analytics['total_interactions'],
                'methods' => $analytics['methods'],
                'timeline' => $this->getOrderTimeline($start_date, $end_date)
            ]);

        } catch (Exception $e) {
            $this->errorHandler->log('Analytics retrieval error: ' . $e->getMessage());
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function handleGetSettings(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_wc_settings', 'nonce', false)) {
                throw new Exception(esc_html('Invalid Handle Get Settings security token'));
            }

            if (!current_user_can('manage_woocommerce')) {
                throw new Exception(esc_html('Permission denied'));
            }

            $gateway = new WalletUpProGateway();
            $registry = \WalletUpPro\Core\PaymentRegistry::getInstance();

            $settings = [
                'enabled' => $gateway->get_option('enabled', 'yes'),
                'title' => $gateway->get_option('title', 'WalletUpPro Pay'),
                'description' => $gateway->get_option('description'),
                'display_mode' => $gateway->get_option('display_mode', 'qr')
            ];

            $methods = [];
            foreach ($registry->getAllMethods() as $methodId => $method) {
                $methods[$methodId] = [
                    'enabled' => $gateway->get_option("enable_{$methodId}", 'yes'),
                    'title' => ucfirst($methodId)
                ];
            }

            wp_send_json_success([
                'settings' => $settings,
                'methods' => $methods,
                'currency' => get_woocommerce_currency(),
                'currency_symbol' => get_woocommerce_currency_symbol()
            ]);

        } catch (Exception $e) {
            $this->errorHandler->log('Settings retrieval error: ' . $e->getMessage());
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function handleSaveSettings(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_wc_settings', 'nonce', false)) {
                throw new Exception(esc_html('Invalid Handle Save Settings security token'));
            }

            if (!current_user_can('manage_woocommerce')) {
                throw new Exception(esc_html('Permission denied'));
            }

            $settings = isset($_POST['settings']) ? json_decode(stripslashes(sanitize_text_field(wp_unslash($_POST['settings']))), true) : null;
            if (!$settings || !is_array($settings) || json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception(esc_html('Invalid settings data'));
            }

            $gateway = new WalletUpProGateway();
            foreach ($settings as $key => $value) {
                $gateway->update_option($key, $value);
            }

            wp_send_json_success([
                'message' => 'Settings saved successfully',
                'settings' => $settings
            ]);

        } catch (Exception $e) {
            $this->errorHandler->log('Settings save error: ' . $e->getMessage());
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function addOrderMetaBox(): void
    {
        add_meta_box(
            'wallet-up-payment-details',
            __('Wallet Up Payment Details', 'walletup'),
            [$this, 'renderOrderMetaBox'],
            'shop_order',
            'side',
            'default'
        );
    }

    public function renderOrderMetaBox($post): void
    {
        try {
            $order = wc_get_order($post->ID);
            if (!$order || $order->get_payment_method() !== 'wallet_up') {
                return;
            }

            $methodId = $order->get_meta('_wallet_up_payment_method');
            if (!$methodId) {
                return;
            }

            echo '<div class="wallet-up-payment-details">';
            echo '<p><strong>' . esc_html__('Payment Method:', 'walletup') . '</strong> ' .
                esc_html(ucfirst($methodId)) . '</p>';

            $analytics = $this->analytics->getOrderAnalytics($post->ID);

            if ($analytics) {
                echo '<p><strong>' . esc_html__('Payment Activity:', 'walletup') . '</strong></p>';
                echo '<ul>';
                echo '<li>' . esc_html__('Impressions:', 'walletup') . ' ' .
                    esc_html($analytics['impressions']) . '</li>';
                echo '<li>' . esc_html__('Scans:', 'walletup') . ' ' .
                    esc_html($analytics['scans']) . '</li>';
                echo '<li>' . esc_html__('Clicks:', 'walletup') . ' ' .
                    esc_html($analytics['clicks']) . '</li>';
                echo '</ul>';
            }

            echo '</div>';

        } catch (Exception $e) {
            $this->errorHandler->log('Meta box render error: ' . $e->getMessage());
            echo '<div class="error"><p>' . esc_html($e->getMessage()) . '</p></div>';
        }
    }

    private function getOrderTimeline(?string $start_date, ?string $end_date): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wallet_up_analytics';

        $date_condition = '';
        if ($start_date && $end_date) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared query with safe table name.
            $date_condition = $wpdb->prepare(
                "AND created_at BETWEEN %s AND %s",
                $start_date . ' 00:00:00',
                $end_date . ' 23:59:59'
            );
        }

        $query = "SELECT 
            DATE(created_at) as date,
            COUNT(DISTINCT CASE WHEN event_type = 'wc_new_order' THEN post_id END) as new_orders,
            COUNT(DISTINCT CASE WHEN event_type = 'wc_order_status_change' AND 
                JSON_EXTRACT(metadata, '$.new_status') = 'completed' 
                THEN post_id END) as completed_orders
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
        FROM {$table}
        WHERE post_type = 'shop_order'
        {$date_condition}
        GROUP BY DATE(created_at)
        ORDER BY date ASC";

        $cache_key = 'wallet_up_' . md5('order_timeline_' . ($start_date ?? '') . '_' . ($end_date ?? ''));
        $cached = wp_cache_get($cache_key, 'wallet_up');
        if (false !== $cached) {
            return $cached;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Results cached via wp_cache_set.
        $results = $wpdb->get_results($query);
        $data = $results ?: [];
        wp_cache_set($cache_key, $data, 'wallet_up', 300);
        return $data;
    }
    public function getGatewaySettings(): array
    {
        if (!$this->isWooCommerceActive() || !$this->initialized) {
            return [];
        }

        try {
            $gateway = WC()->payment_gateways()->payment_gateways()['wallet_up'] ?? new WalletUpProGateway();

            if ($gateway instanceof WalletUpProGateway) {
                $settings = [
                    'enabled' => $gateway->get_option('enabled', 'yes'),
                    'title' => $gateway->get_option('title', __('WalletUpPro Pay', 'walletup')),
                    'description' => $gateway->get_option('description', __('Pay with your preferred mobile payment app', 'walletup')),
                    'display_mode' => $gateway->get_option('display_mode', 'qr'),
                ];

                if ($this->registry !== null) {
                    $supportedMethods = $this->registry->getAllMethods();
                    $enabledMethods = [];

                    foreach ($supportedMethods as $methodId => $method) {
                        $optionName = "enable_{$methodId}";
                        $enabledMethods[$methodId] = $gateway->get_option($optionName, 'yes') === 'yes';
                    }

                    $settings['supported_methods'] = array_keys($enabledMethods);
                    $settings['method_settings'] = $enabledMethods;
                } else {
                    $settings['supported_methods'] = [];
                    $settings['method_settings'] = [];
                }

                return $settings;
            }

            throw new Exception(esc_html('WalletUpProGateway instance not available'));

        } catch (Exception $e) {
            $this->errorHandler->log('Failed to get gateway settings: ' . $e->getMessage());
            return [];
        }
    }
    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    public function shouldUseLegacyCheckoutBlock(): bool
    {
        $legacy_indicators = [
            version_compare(WC_VERSION, '7.0', '<'),
            !defined('SCRIPT_DEBUG') || !SCRIPT_DEBUG,
            isset($_SERVER['SERVER_SOFTWARE']) && strpos(sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'] ?? '')), 'LiteSpeed') !== false,
            get_option('walletup_force_legacy_checkout_block', false)
        ];
        
        $use_legacy = in_array(true, $legacy_indicators, true);
        
        if ($use_legacy) {
            $this->errorHandler->log('Using legacy checkout block mode');
        }
        
        return $use_legacy;
    }

    public static function init()
    {
        add_action('plugins_loaded', function () {
            add_action('woocommerce_loaded', function () {
                $instance = self::getInstance();
                if ($instance->isWooCommerceActive()) {
                    $instance->initializeServices();
                }
            }, 20);
        }, 10);
    }

    public function printCheckoutNoAutoSelectScript(): void
    {
        if (!function_exists('is_checkout') || !is_checkout() || is_order_received_page()) {
            return;
        }

        $discount_map = ['wallet_up' => '__grouped__'];
        $qr_gateways = ['wallet_up'];
        if (function_exists('WC') && WC()->payment_gateways) {
            foreach (WC()->payment_gateways()->payment_gateways() as $gw_id => $gw) {
                if (strpos($gw_id, 'wallet_up_') !== 0) {
                    continue;
                }
                $method_id = substr($gw_id, strlen('wallet_up_'));
                $dv = (float) WooCommerceMethodSettings::get($method_id, 'discount_value', 0);
                $dt = (string) WooCommerceMethodSettings::get($method_id, 'discount_type', '');
                $discount_map[$gw_id] = ($dv > 0 && $dt !== '') ? ($dt . ':' . $dv) : '';
                if ($gw instanceof WalletUpMethodGateway) {
                    $qr_gateways[] = $gw_id;
                }
            }
        }
        $impressionNonce = wp_create_nonce('wallet_up_wc_payment_display'); // zi2-dry: ignore
        ?>
        <script>
        window.walletUpDiscountMap = <?php echo wp_json_encode($discount_map); ?>;
        window.walletUpQrGateways = <?php echo wp_json_encode($qr_gateways); ?>;
        </script>
        <script>
        (function ($) {
            if (!$) { return; }
            var hasUserPicked = false;
            var wuAjaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var wuImpNonce = <?php echo wp_json_encode($impressionNonce); ?>;
            window.walletUpImpressed = window.walletUpImpressed || {};
            function wuMethodFromGateway(gw) {
                var list = window.walletUpQrGateways || [];
                if (list.indexOf(gw) === -1) { return ''; }
                if (gw === 'wallet_up') { return $('#wallet_up_payment_method').val() || ''; }
                return gw.substring('wallet_up_'.length);
            }
            function wuRecordImpression(method) {
                if (!method || window.walletUpImpressed[method]) { return; }
                window.walletUpImpressed[method] = true;
                $.post(wuAjaxUrl, { action: 'wallet_up_record_impression', method: method, nonce: wuImpNonce });
            }
            var unselectAll = function () {
                $('input[name=payment_method]:checked').prop('checked', false).trigger('change.suppress');
                $('.wc_payment_method, li.wc_payment_method').removeClass('woocommerce-payment-method--selected');
                $('input[name=wallet_up_payment_method]:checked').prop('checked', false);
                $('#wallet_up_payment_method').val('');
                $('.walletup-method-item.selected, .walletup-method-item[aria-selected="true"]').removeClass('selected').attr('aria-selected', 'false');
            };

            $(document.body).on('click change', 'input[name=payment_method]', function () {
                hasUserPicked = true;
            });
            $(document.body).on('click', 'label[for^="payment_method_"]', function () {
                hasUserPicked = true;
            });

            var __wuLastTopKey = (function () {
                var dm = window.walletUpDiscountMap || {};
                var cur = $('input[name=payment_method]:checked').val() || '';
                return Object.prototype.hasOwnProperty.call(dm, cur) ? dm[cur] : '';
            })();
            $(document.body).on('change', 'input[name=payment_method]', function () {
                var dm = window.walletUpDiscountMap || {};
                var v = $(this).val() || '';
                wuRecordImpression(wuMethodFromGateway(v));
                var newKey = Object.prototype.hasOwnProperty.call(dm, v) ? dm[v] : '';
                if (newKey !== __wuLastTopKey) {
                    $(document.body).trigger('update_checkout');
                }
                __wuLastTopKey = newKey;
            });
            window.__wuSubLastKey = window.__wuSubLastKey || '';
            $(document.body).on('change', 'input[name=wallet_up_payment_method], #wallet_up_payment_method', function () {
                hasUserPicked = true;
                var methodId = $(this).val();
                wuRecordImpression(methodId);
                var dm = (window.walletUpDiscountMap || {});
                var newKey = Object.prototype.hasOwnProperty.call(dm, 'wallet_up_' + methodId)
                    ? dm['wallet_up_' + methodId] : '';
                var keyChanged = (newKey !== window.__wuSubLastKey);
                window.__wuSubLastKey = newKey;
                if (window.walletUpCheckout && window.walletUpCheckout.ajaxurl && methodId) {
                    $.post(window.walletUpCheckout.ajaxurl, {
                        action: 'wallet_up_set_selected_method',
                        method_id: methodId
                    }).always(function () {
                        if (keyChanged) {
                            $(document.body).trigger('update_checkout');
                        }
                    });
                } else if (keyChanged) {
                    $(document.body).trigger('update_checkout');
                }
            });

            var maybeUnselect = function () {
                if (hasUserPicked) { return; }
                setTimeout(function () {
                    if (hasUserPicked) { return; }
                    unselectAll();
                }, 20);
            };
            $(document.body).on('init_checkout updated_checkout', maybeUnselect);
            $(function () { maybeUnselect(); });

            function walletUpGateError(msg) {
                var $form = $('form.checkout');
                if (!$form.length) { window.alert(msg); return; }
                $('.wallet-up-gate-error').remove();
                var $n = $('<div class="woocommerce-error wallet-up-gate-error" role="alert" tabindex="-1"></div>').text(msg);
                $form.prepend($n);
                $('html, body').animate({ scrollTop: Math.max(0, $form.offset().top - 120) }, 300);
                try { $n.focus(); } catch (e) {}
            }
            function walletUpGateGuard() {
                if ($('input[name=payment_method]').length === 0) { $('.wallet-up-gate-error').remove(); return; }
                var pm = $('input[name=payment_method]:checked').val() || '';
                if (!pm) {
                    walletUpGateError(<?php echo wp_json_encode(__('Please select a payment method before placing your order.', 'walletup')); ?>);
                    return false;
                }
                if (pm === 'wallet_up' && !(('' + ($('#wallet_up_payment_method').val() || '')).length)) {
                    walletUpGateError(<?php echo wp_json_encode(__('Please choose how you\'d like to pay (for example Cash App, Venmo, or Zelle) before placing your order.', 'walletup')); ?>);
                    return false;
                }
                $('.wallet-up-gate-error').remove();
            }
            var bindGuard = function () {
                $('form.checkout').off('checkout_place_order.walletup').on('checkout_place_order.walletup', walletUpGateGuard);
            };
            bindGuard();
            $(document.body).on('init_checkout updated_checkout', bindGuard);
        })(window.jQuery);
        </script>
        <?php
    }
}