<?php
namespace WalletUpPro\WooCommerce;

use WalletUpPro\Core\Error\ErrorHandler;
use WalletUpPro\Core\Security\DataEncryption;
use WalletUpPro\Services\PaymentProcessor;
use Exception;

if (!defined('ABSPATH')) {
    exit;
}

class StripeCardGateway extends \WC_Payment_Gateway
{
    private $errorHandler;
    private $test_mode;
    private $publishable_key;
    private $secret_key;

    public function __construct()
    {
        $this->id = 'wallet_up_card';
        $this->method_title = __('WalletUp — Card Payments (Stripe)', 'walletup');
        $this->icon = \WalletUpPro\Core\Display\DisplayHandler::methodLogoUrl('card');
        $this->method_description = __('Accept credit/debit card payments directly at checkout via Stripe. For QR codes, mobile money, crypto, or PayPal, use the main "WalletUp" gateway.', 'walletup');
        $this->has_fields = true;
        $this->supports = ['products', 'refunds'];

        $this->errorHandler = ErrorHandler::getInstance();

        $this->init_form_fields();
        $this->init_settings();

        $this->enabled = $this->get_option('enabled', 'no');
        $mainConfig = get_option('wallet_up_stripe_config', []);
        $enabledMethods = self::enabledMethods();
        $catalog = class_exists('\\WalletUpPro\\Core\\Config\\StripeMethodCatalog');
        $this->title = !empty($mainConfig['checkout_title'])
            ? $mainConfig['checkout_title']
            : ($catalog ? \WalletUpPro\Core\Config\StripeMethodCatalog::checkoutLabel($enabledMethods) : __('Credit/Debit Card', 'walletup'));
        $this->description = !empty($mainConfig['checkout_description'])
            ? $mainConfig['checkout_description']
            : ($catalog ? \WalletUpPro\Core\Config\StripeMethodCatalog::checkoutDescription($enabledMethods) : __('Pay securely with your card.', 'walletup'));
        $this->test_mode = class_exists('\\WalletUpPro\\Core\\Config\\AutomatedApiConfig')
            ? \WalletUpPro\Core\Config\AutomatedApiConfig::stripeTestMode()
            : ($this->get_option('test_mode', 'yes') === 'yes');

        $this->publishable_key = '';
        $this->secret_key = '';
        $this->loadFromMainStripeConfig();

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_stripe_scripts']);
    }

    public function init_form_fields(): void
    {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'walletup'),
                'type' => 'checkbox',
                'label' => __('Enable Card Payments at checkout', 'walletup'),
                'default' => 'no',
            ],
            'test_mode' => [
                'title' => __('Test Mode', 'walletup'),
                'type' => 'checkbox',
                'label' => __('Enable test mode', 'walletup'),
                'default' => 'yes',
                'description' => __('When on, uses test keys from Wallet Up → Payments → Stripe.', 'walletup'),
            ],
        ];
    }

    public function admin_options(): void
    {
        $paymentsUrl = admin_url('admin.php?page=wallet-up-payments#stripe');
        ?>
        <h2><?php esc_html_e('Card Payments — Stripe', 'walletup'); ?> <small style="color:#6b7280;font-weight:400;">(<?php esc_html_e('Wallet Up', 'walletup'); ?>)</small></h2>

        <div style="margin:16px 0;padding:20px;background:linear-gradient(135deg,#f5f3ff,#ede9fe);border:1px solid #ddd6fe;border-radius:12px;">
            <div style="display:flex;align-items:flex-start;gap:14px;">
                <div style="flex-shrink:0;width:40px;height:40px;background:#7c3aed;border-radius:10px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:18px;">W</div>
                <div style="flex:1;min-width:0;">
                    <h3 style="margin:0 0 6px;color:#4c1d95;font-size:1.05em;"><?php esc_html_e('Stripe is configured in Wallet Up', 'walletup'); ?></h3>
                    <p style="margin:0 0 12px;color:#4b5563;font-size:0.95em;line-height:1.5;">
                        <?php esc_html_e('API keys, webhook signing secrets, checkout title, and description all live in one place. The toggle below only controls whether this method appears at WooCommerce checkout.', 'walletup'); ?>
                    </p>
                    <a href="<?php echo esc_url($paymentsUrl); ?>" class="button button-primary" style="background:#7c3aed;border-color:#6d28d9;text-shadow:none;box-shadow:none;">
                        <?php esc_html_e('Open Stripe Configuration →', 'walletup'); ?>
                    </a>
                </div>
            </div>
        </div>

        <table class="form-table">
            <?php
            $fields = $this->get_form_fields();
            $connectActive = $this->isConnectActive();
            if ( $connectActive ) {
                unset( $fields['test_mode'] );
            }
            echo $this->generate_settings_html( $fields, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WC generates escaped HTML.
            if ( $connectActive ) {
                $isTest = $this->is_test_mode();
                printf(
                    '<tr valign="top"><th scope="row" class="titledesc">%s</th><td class="forminp"><span style="display:inline-block;padding:3px 12px;border-radius:12px;font-weight:600;font-size:12px;%s">%s</span><p class="description" style="margin-top:8px;">%s</p></td></tr>',
                    esc_html__( 'Payment mode', 'walletup' ),
                    $isTest ? 'background:#fef3c7;color:#92400e;' : 'background:#dcfce7;color:#166534;',
                    $isTest ? esc_html__( 'Test', 'walletup' ) : esc_html__( 'Live', 'walletup' ),
                    esc_html__( 'Managed by your connected Stripe account. The manual test-mode toggle applies only when Stripe Connect is not in use.', 'walletup' )
                );
            }
            ?>
        </table>
        <?php
    }

    public function process_admin_options(): bool
    {
        return parent::process_admin_options();
    }

    private function loadFromMainStripeConfig(): void
    {
        if (!class_exists('\\WalletUpPro\\Core\\Config\\AutomatedApiConfig')) {
            return;
        }
        $pair = \WalletUpPro\Core\Config\AutomatedApiConfig::stripeKeyPair((bool) $this->test_mode);
        $this->publishable_key = $pair['publishable'];
        $this->secret_key = $pair['secret'];
    }

    public function getSecretKey(): string
    {
        return $this->secret_key ?? '';
    }

    public function getPublishableKey(): string
    {
        return $this->publishable_key ?? '';
    }

    public function needs_setup(): bool
    {
        return empty($this->publishable_key) || empty($this->secret_key);
    }

    private ?array $connectStatus = null;

    private function connect(): array
    {
        if ($this->connectStatus === null) {
            if (!class_exists('\\WalletUpPro\\Core\\Payments\\StripeConnect', false)
                && file_exists(dirname(__DIR__) . '/Core/Payments/StripeConnect.php')) {
                require_once dirname(__DIR__) . '/Core/Payments/StripeConnect.php';
            }
            $this->connectStatus = class_exists('\\WalletUpPro\\Core\\Payments\\StripeConnect')
                ? \WalletUpPro\Core\Payments\StripeConnect::getStatus()
                : ['connected' => false];
        }
        return $this->connectStatus;
    }

    public function isConnectActive(): bool
    {
        $s = $this->connect();
        return !empty($s['connected']) && !empty($s['charges_enabled']) && !empty($s['account_id']);
    }

    public function getConnectedAccountId(): string
    {
        return $this->isConnectActive() ? (string) $this->connect()['account_id'] : '';
    }

    public function getEffectivePublishableKey(): string
    {
        if ($this->isConnectActive() && !empty($this->connect()['publishable_key'])) {
            return (string) $this->connect()['publishable_key'];
        }
        return $this->publishable_key ?? '';
    }

    public function is_test_mode(): bool
    {
        if ($this->isConnectActive() && class_exists('\\WalletUpPro\\Core\\Payments\\StripeConnect')) {
            return !\WalletUpPro\Core\Payments\StripeConnect::isLive();
        }
        return (bool) $this->test_mode;
    }

    public function is_available(): bool
    {
        if ($this->enabled !== 'yes') {
            return false;
        }
        if (class_exists('\WalletUpPro\Core\Payments\Availability\MethodAvailability') && \WalletUpPro\Core\Payments\Availability\MethodAvailability::automatedOff(\WalletUpPro\Core\Config\AutomatedApiConfig::STRIPE)) {
            return false;
        }

        if ($this->isConnectActive()) {
            return true;
        }

        // Own keys are a Pro capability. Without this, a site whose Pro licence lapsed would keep
        if (class_exists('\\WalletUpPro\\WooCommerce\\FreeCardBootstrap')
            && !\WalletUpPro\WooCommerce\FreeCardBootstrap::premiumActive()) {
            return false;
        }

        if ($this->needs_setup()) {
            return false;
        }

        return true;
    }

    public function enqueue_stripe_scripts(): void
    {
        if (!is_checkout() && !is_wc_endpoint_url('order-pay')) {
            return;
        }

        if (!$this->is_available()) {
            return;
        }

        // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- External Stripe.js; version controlled by Stripe CDN.
        wp_enqueue_script(
            'stripe-js',
            'https://js.stripe.com/v3/',
            [],
            null,
            true
        );

        wp_enqueue_script(
            'walletup-stripe-checkout',
            WALLET_UP_URL . 'assets/js/stripe-checkout.js',
            ['stripe-js', 'jquery'],
            \WalletUpPro\Core\Assets\AssetManager::fileVersion(WALLET_UP_PATH . 'assets/js/stripe-checkout.js'),
            true
        );

        wp_localize_script('walletup-stripe-checkout', 'walletUpStripe', [
            'publishableKey' => $this->getEffectivePublishableKey(),
            'connectedAccount' => $this->getConnectedAccountId(),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wallet_up_stripe_payment'),
            'paymentElement' => self::paymentElementEnabled(),
            'amount' => $this->checkoutAmountMinor(),
            'currency' => strtolower(get_woocommerce_currency()),
            'confirmNonce' => wp_create_nonce('wallet_up_block_intent'),
        ]);
    }

    public static function paymentElementEnabled(): bool
    {
        if (class_exists('\\WalletUpPro\\WooCommerce\\FreeCardBootstrap')
            && !FreeCardBootstrap::premiumActive()) {
            return false;
        }
        $s = get_option('wallet_up_woocommerce_settings', []);
        return is_array($s) && ($s['payment_element'] ?? 'no') === 'yes';
    }

    private function checkoutAmountMinor(): int
    {
        $currency = get_woocommerce_currency();
        if (is_wc_endpoint_url('order-pay')) {
            $orderId = absint(get_query_var('order-pay'));
            $order = $orderId ? wc_get_order($orderId) : null;
            if ($order) {
                return $this->get_stripe_amount((float) $order->get_total(), $order->get_currency());
            }
        }
        $total = (WC()->cart && method_exists(WC()->cart, 'get_total')) ? (float) WC()->cart->get_total('edit') : 0.0;
        return $this->get_stripe_amount($total, $currency);
    }

    public function payment_fields(): void
    {
        if ($this->description) {
            echo wp_kses_post(wpautop(wptexturize($this->description)));
        }

        if ($this->is_test_mode()) {
            echo '<p class="walletup-stripe-test-notice" style="color: #e67e22; font-size: 0.85em; margin-bottom: 10px;">';
            echo esc_html__('TEST MODE ENABLED — no real charges will be made.', 'walletup');
            echo '</p>';
        }

        $mountId = self::paymentElementEnabled() ? 'walletup-stripe-payment-element' : 'walletup-stripe-card-element';
        printf(
            '<div id="%s" style="padding: 12px; border: 1px solid #ddd; border-radius: 4px; background: #fff; min-height: 40px;"></div>',
            esc_attr($mountId)
        );
        echo '<div id="walletup-stripe-card-errors" role="alert" style="color: #e74c3c; font-size: 0.85em; margin-top: 5px;"></div>';
        echo '<input type="hidden" name="walletup_stripe_payment_method_id" id="walletup_stripe_payment_method_id" />';
        echo '<input type="hidden" name="walletup_stripe_intent_id" id="walletup_stripe_intent_id" />';
    }

    public function process_payment($order_id): array
    {
        \WalletUpPro\Core\Tenancy\Tenant::enterOrder($order_id);
        try {
            $order = wc_get_order($order_id);
            if (!$order) {
                throw new Exception(esc_html(__('Invalid order', 'walletup')));
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce process_payment callback; WC verifies the checkout nonce.
            $payment_method_id = sanitize_text_field(wp_unslash($_POST['walletup_stripe_payment_method_id'] ?? ''));

            /* translators: 1: Order number, 2: Site name */
            $description = sprintf(
                __('Order #%1$s from %2$s', 'walletup'),
                $order->get_order_number(),
                get_bloginfo('name')
            );

            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce process_payment callback; WC verifies the checkout nonce.
            $intent_id = sanitize_text_field(wp_unslash($_POST['walletup_stripe_intent_id'] ?? ''));

            if ($payment_method_id === '' && $intent_id !== '') {
                if (strpos($intent_id, 'pi_') !== 0) {
                    throw new Exception(esc_html(__('Invalid payment reference.', 'walletup')));
                }
                $data = \WalletUpPro\Core\Payments\StripeConnect::retrieveIntent($intent_id);
                if (is_wp_error($data)) {
                    throw new Exception(esc_html($data->get_error_message()));
                }
                $expectedMinor = $this->get_stripe_amount($order->get_total(), $order->get_currency());
                if ((int) ($data['amount'] ?? 0) !== $expectedMinor
                    || strtolower((string) ($data['currency'] ?? '')) !== strtolower($order->get_currency())) {
                    throw new Exception(esc_html(__('We could not verify the payment amount. Please try again.', 'walletup')));
                }
                if (\WalletUpPro\Core\Payments\PaymentGatewayHandler::intentAlreadySettled($intent_id)) {
                    throw new Exception(esc_html(__('This payment has already been used.', 'walletup')));
                }
                self::cacheEnabledMethods($data['payment_method_types'] ?? []);
                $this->recordTransaction($order, [
                    'status'            => (string) ($data['status'] ?? ''),
                    'client_secret'     => '',
                    'id'                => $intent_id,
                    'connected_account' => '',
                ]);
                $order->update_meta_data('_wallet_up_payment_method', 'card');
                $order->update_meta_data('_wallet_up_payment_id', $intent_id);
                $order->update_meta_data('_stripe_payment_intent_id', $intent_id);
                $order->update_status('pending', __('Awaiting payment confirmation.', 'walletup'));
                $order->save();

                return [
                    'result'   => 'success',
                    'redirect' => $this->get_return_url($order),
                ];
            }

            if ($payment_method_id === '') {
                throw new Exception(esc_html(__('No payment method provided. Please try again.', 'walletup')));
            }

            $res = \WalletUpPro\Core\Payments\PaymentGatewayHandler::createCardIntent([
                'amount'         => $this->get_stripe_amount($order->get_total(), $order->get_currency()),
                'currency'       => strtolower($order->get_currency()),
                'payment_method' => $payment_method_id,
                'description'    => $description,
                'receipt_email'  => $order->get_billing_email(),
                'return_url'     => $this->get_return_url($order),
                'metadata'       => [
                    'order_id'  => (string) $order->get_id(),
                    'order_key' => $order->get_order_key(),
                ],
                'source'         => 'woocommerce',
            ]);
            if (is_wp_error($res)) {
                throw new Exception(esc_html($res->get_error_message()));
            }
            $intent = [
                'status'            => $res['status'] ?? '',
                'client_secret'     => $res['client_secret'] ?? '',
                'id'                => $res['payment_intent_id'] ?? '',
                'connected_account' => $res['connected_account'] ?? '',
            ];

            $this->recordTransaction($order, $intent);

            $order->update_meta_data('_wallet_up_payment_method', 'card');
            if (!empty($intent['id'])) {
                $order->update_meta_data('_wallet_up_payment_id', $intent['id']);
            }
            $order->save();

            if ($intent['status'] === 'requires_action' && !empty($intent['client_secret'])) {
                $args = [
                    'walletup_stripe_3ds' => '1',
                    'client_secret' => $intent['client_secret'],
                    'order_id' => $order_id,
                    'return_url' => rawurlencode($this->get_return_url($order)),
                ];
                if (!empty($intent['connected_account'])) {
                    $args['stripe_account'] = $intent['connected_account'];
                }
                return [
                    'result' => 'success',
                    'redirect' => add_query_arg($args, $order->get_checkout_payment_url()),
                ];
            }

            if ($intent['status'] === 'succeeded') {
                $order->payment_complete($intent['id']);
                /* translators: %s: Stripe PaymentIntent ID */
                $order->add_order_note(sprintf(
                    __('Stripe payment complete (PaymentIntent: %s)', 'walletup'),
                    $intent['id']
                ));
                $order->update_meta_data('_stripe_payment_intent_id', $intent['id']);
                $order->save();

                $this->updateTransactionStatus($order, 'completed', $intent['id']);

                WC()->cart->empty_cart();

                return [
                    'result' => 'success',
                    'redirect' => $this->get_return_url($order),
                ];
            }

            /* translators: %s: Payment status from Stripe */
            throw new Exception(esc_html(sprintf(
                __('Unexpected payment status: %s', 'walletup'),
                $intent['status']
            )));

        } catch (Exception $e) {
            $this->errorHandler->log('Stripe payment error: ' . $e->getMessage());
            wc_add_notice($e->getMessage(), 'error');
            return ['result' => 'failure', 'redirect' => ''];
        }
    }

    public static function verifyReturn($order_id): void
    {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== 'wallet_up_card') {
            return;
        }
        if (in_array($order->get_status(), ['processing', 'completed', 'refunded', 'cancelled'], true)) {
            return;
        }
        $intentId = $order->get_meta('_wallet_up_payment_id');
        if (!$intentId) {
            $intentId = $order->get_meta('_stripe_payment_intent_id');
        }
        if (!$intentId || strpos((string) $intentId, 'pi_') !== 0) {
            return;
        }
        $gate = \WalletUpPro\Core\Payments\PaymentGatewayHandler::assertPaidFor([
            'payment_intent_id' => $intentId,
            'expected_amount'   => \WalletUpPro\Core\Payments\PaymentGatewayHandler::toMinorUnits((float) $order->get_total(), $order->get_currency()),
            'expected_currency' => $order->get_currency(),
            'also_match'        => ['order_id' => (string) $order->get_id()],
        ]);
        if (!empty($gate['paid'])) {
            if (\WalletUpPro\Core\Payments\PaymentGatewayHandler::intentAlreadySettled((string) $intentId)) {
                $order->add_order_note(__('This payment reference was already used on another order. Not completed.', 'walletup'));
                $order->save();
                return;
            }
            self::storeMethodLabel($order, (string) $intentId);
            \WalletUpPro\Services\PaymentProcessor::getInstance()->tagMethodLabel((string) $intentId);
            $order->update_meta_data('_stripe_payment_intent_id', (string) $intentId);
            $order->payment_complete((string) $intentId);
            $order->add_order_note(__('Payment confirmed (WalletUp).', 'walletup'));
            $order->delete_meta_data('_wallet_up_client_secret');
            $order->save();
            if (function_exists('WC') && WC()->cart) {
                WC()->cart->empty_cart();
            }
        } elseif (!empty($gate['processing'])) {
            self::storeMethodLabel($order, (string) $intentId);
            \WalletUpPro\Services\PaymentProcessor::getInstance()->tagMethodLabel((string) $intentId);
            if ($order->get_status() !== 'on-hold') {
                $order->update_status('on-hold', __('Payment processing (WalletUp).', 'walletup'));
            }
            $order->save();
        }
    }

    public static function blockCreateIntent(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Session-scoped: the secret is returned only to this buyer's session and is never stored.
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, 'wallet_up_block_intent')) {
            wp_send_json_error(['message' => __('Your session expired. Please refresh and try again.', 'walletup')], 403);
        }
        if (function_exists('wc_load_cart') && (!function_exists('WC') || !WC()->cart)) {
            wc_load_cart();
        }
        if (!function_exists('WC') || !WC()->cart) {
            wp_send_json_error(['message' => __('Your cart is unavailable.', 'walletup')], 400);
        }
        $currency = get_woocommerce_currency();
        $total = (float) WC()->cart->get_total('edit');
        if ($total <= 0) {
            wp_send_json_error(['message' => __('Nothing to pay.', 'walletup')], 400);
        }
        $amountMinor = \WalletUpPro\Core\Payments\PaymentGatewayHandler::toMinorUnits($total, $currency);
        $res = \WalletUpPro\Core\Payments\PaymentGatewayHandler::createCardIntent([
            'amount'      => $amountMinor,
            'currency'    => strtolower($currency),
            'description' => get_bloginfo('name'),
            'metadata'    => ['source' => 'woocommerce'],
            'source'      => 'woocommerce',
        ]);
        if (is_wp_error($res)) {
            wp_send_json_error(['message' => \WalletUpPro\Core\Payments\BackendErrors::forCustomer($res, 'store card')], 400);
        }
        if (empty($res['client_secret']) || empty($res['payment_intent_id'])) {
            wp_send_json_error(['message' => __('Could not start payment.', 'walletup')], 400);
        }
        self::cacheEnabledMethods($res['payment_method_types'] ?? []);
        wp_send_json_success([
            'client_secret' => (string) $res['client_secret'],
            'intent_id'     => (string) $res['payment_intent_id'],
            'amount'        => (string) $amountMinor,
            'currency'      => strtolower($currency),
        ]);
    }

    private static function storeMethodLabel(\WC_Order $order, string $intentId): void
    {
        if (!class_exists('\\WalletUpPro\\Core\\Config\\StripeMethodCatalog')) {
            return;
        }
        $label = \WalletUpPro\Core\Config\StripeMethodCatalog::labelForIntent($intentId);
        if ($label !== '') {
            $order->update_meta_data('_wallet_up_pay_method_label', $label);
            $order->set_payment_method_title($label);
        }
    }

    private static function modeKey(): string
    {
        return (class_exists('\\WalletUpPro\\Core\\Config\\AutomatedApiConfig') && \WalletUpPro\Core\Config\AutomatedApiConfig::stripeTestMode()) ? 'test' : 'live';
    }

    private static function enabledMethods(): array
    {
        $cached = get_transient('wallet_up_stripe_pe_methods_' . self::modeKey());
        return is_array($cached) ? $cached : [];
    }

    private static function cacheEnabledMethods(array $types): void
    {
        $types = array_values(array_filter(array_map('strval', $types)));
        if (empty($types)) {
            return;
        }
        set_transient('wallet_up_stripe_pe_methods_' . self::modeKey(), $types, HOUR_IN_SECONDS);
    }

    private function recordTransaction(\WC_Order $order, array $intent): void
    {
        try {
            $processor = PaymentProcessor::getInstance();

            $processor->initializePayment([
                'email'          => $order->get_billing_email(),
                'first'          => $order->get_billing_first_name(),
                'last'           => $order->get_billing_last_name(),
                'phone'          => $order->get_billing_phone(),
                'amt'            => $order->get_total(),
                'currency'       => $order->get_currency(),
                'payment_method' => 'card',
                'description'    => sprintf('Order #%s', $order->get_order_number()),
                'wc_order_id'    => $order->get_id(),
                'metadata'       => [
                    'payment_method'    => 'card',
                    'provider'          => 'stripe',
                    'stripe_intent_id'  => $intent['id'] ?? '',
                    'order_key'         => $order->get_order_key(),
                    'return_url'        => $this->get_return_url($order),
                ],
            ]);
        } catch (Exception $e) {
            $this->errorHandler->log('Failed to record Stripe transaction: ' . $e->getMessage());
        }
    }

    private function updateTransactionStatus(\WC_Order $order, string $status, string $providerTxnId = ''): void
    {
        try {
            global $wpdb;
            $table = $wpdb->prefix . 'walletup_payment_transactions';

            $update = [
                'status'     => $status,
                'updated_at' => current_time('mysql'),
            ];
            if ($status === 'completed') {
                $update['completed_at'] = current_time('mysql');
            }
            if ($providerTxnId) {
                $update['provider_transaction_id'] = $providerTxnId;
                $update['payment_provider'] = 'stripe';
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update($table, $update, ['wc_order_id' => $order->get_id()]);
        } catch (Exception $e) {
            $this->errorHandler->log('Failed to update Stripe transaction status: ' . $e->getMessage());
        }
    }

    public function process_refund($order_id, $amount = null, $reason = ''): bool|\WP_Error
    {
        \WalletUpPro\Core\Tenancy\Tenant::enterOrder($order_id);
        $order = wc_get_order($order_id);
        if (!$order) {
            return new \WP_Error('invalid_order', __('Invalid order', 'walletup'));
        }

        $intent_id = $order->get_meta('_stripe_payment_intent_id');
        if (empty($intent_id)) {
            return new \WP_Error('no_intent', __('No Stripe payment intent found for this order', 'walletup'));
        }

        try {
            $refund_data = ['payment_intent' => $intent_id];
            if ($amount) {
                $refund_data['amount'] = $this->get_stripe_amount($amount, $order->get_currency());
            }
            if ($reason) {
                $refund_data['reason'] = 'requested_by_customer';
                $refund_data['metadata[reason]'] = sanitize_text_field($reason);
            }

            $response = wp_remote_post('https://api.stripe.com/v1/refunds', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->secret_key,
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => $refund_data,
                'timeout' => 30,
            ]);

            if (is_wp_error($response)) {
                return $response;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);

            if (isset($body['error'])) {
                return new \WP_Error('stripe_refund_error', $body['error']['message'] ?? __('Refund failed', 'walletup'));
            }

            /* translators: %s: Stripe Refund ID */
            $order->add_order_note(sprintf(
                __('Stripe refund processed (Refund ID: %s)', 'walletup'),
                $body['id']
            ));

            return true;

        } catch (Exception $e) {
            $this->errorHandler->log('Stripe refund error: ' . $e->getMessage());
            return new \WP_Error('refund_error', $e->getMessage());
        }
    }

    private function get_stripe_amount(float $amount, string $currency): int
    {
        return \WalletUpPro\Core\Payments\PaymentGatewayHandler::toMinorUnits($amount, $currency);
    }
}
