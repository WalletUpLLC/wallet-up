<?php
namespace WalletUpPro\WooCommerce;

use WalletUpPro\Core\Error\ErrorHandler;
use WalletUpPro\Core\Security\DataEncryption;
use WalletUpPro\Services\PaymentProcessor;
use Exception;

if (!defined('ABSPATH')) {
    exit;
}

class PayPalCommerceGateway extends \WC_Payment_Gateway
{
    private $errorHandler;
    private array $config;

    public function __construct()
    {
        $this->id = 'wallet_up_paypal_commerce';
        $this->method_title = __('WalletUp — PayPal Commerce', 'walletup');
        $this->icon = \WalletUpPro\Core\Display\DisplayHandler::methodLogoUrl('paypal_commerce');
        $this->method_description = __('Configured under Wallet Up → Seller. Credentials live in Wallet Up → Payments.', 'walletup');
        $this->has_fields = true;
        $this->supports = ['products'];

        $this->errorHandler = ErrorHandler::getInstance();
        $this->config = (array) get_option('wallet_up_paypal_commerce_settings', []);

        $this->init_form_fields();
        $this->init_settings();

        $this->title = __('PayPal', 'walletup');
        $this->description = __('Pay with your PayPal account or card.', 'walletup');
        $this->enabled = !empty($this->config['enabled']) ? 'yes' : 'no';

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_paypal_scripts']);

        add_action('wp_ajax_walletup_paypal_create_order', [$this, 'handleCreateOrder']);
        add_action('wp_ajax_nopriv_walletup_paypal_create_order', [$this, 'handleCreateOrder']);
        add_action('wp_ajax_walletup_paypal_capture_order', [$this, 'handleCaptureOrder']);
        add_action('wp_ajax_nopriv_walletup_paypal_capture_order', [$this, 'handleCaptureOrder']);

        add_filter('script_loader_tag', [$this, 'addClientTokenAttribute'], 10, 3);
    }

    public function init_form_fields(): void
    {
        $this->form_fields = [];
    }

    public function admin_options(): void
    {
        $sellerUrl = admin_url('admin.php?page=wallet-up-seller#paypal-commerce');
        ?>
        <h2><?php esc_html_e('PayPal Commerce', 'walletup'); ?> <small style="color:#6b7280;font-weight:400;">(<?php esc_html_e('Wallet Up', 'walletup'); ?>)</small></h2>
        <div style="margin:16px 0;padding:20px;background:linear-gradient(135deg,#f5f3ff,#ede9fe);border:1px solid #ddd6fe;border-radius:12px;">
            <h3 style="margin:0 0 6px;color:#4c1d95;font-size:1.05em;"><?php esc_html_e('Configured in Wallet Up', 'walletup'); ?></h3>
            <p style="margin:0 0 12px;color:#4b5563;font-size:0.95em;line-height:1.5;">
                <?php esc_html_e('Enable / disable, switch sandbox/live, and review credentials in the WalletUp Seller panel.', 'walletup'); ?>
            </p>
            <a href="<?php echo esc_url($sellerUrl); ?>" class="button button-primary" style="background:#7c3aed;border-color:#6d28d9;text-shadow:none;box-shadow:none;">
                <?php esc_html_e('Open WalletUp Seller →', 'walletup'); ?>
            </a>
        </div>
        <?php
    }

    public function process_admin_options(): bool
    {
        return true;
    }

    private function getActiveMode(): string
    {
        $mode = $this->config['mode'] ?? 'sandbox';
        return in_array($mode, ['sandbox', 'live'], true) ? $mode : 'sandbox';
    }

    public function getApiBase(): string
    {
        return $this->getActiveMode() === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    private function decryptField(string $value): string
    {
        if (empty($value)) {
            return '';
        }

        $enc = DataEncryption::getInstance();

        if (str_starts_with($value, 'walletup_enc:')) {
            try {
                $decrypted = $enc->decrypt(substr($value, strlen('walletup_enc:')));
                return ($decrypted !== false && $decrypted !== '') ? $decrypted : '';
            } catch (Exception $e) {
                $this->errorHandler->log('Failed to decrypt PayPal credential: ' . $e->getMessage());
                return '';
            }
        }

        if (preg_match('#[+/=]#', $value) || strlen($value) > 100) {
            try {
                $decrypted = $enc->decrypt($value);
                if ($decrypted !== false && $decrypted !== '') {
                    return $decrypted;
                }
            } catch (Exception $e) {
            }
        }

        return $value;
    }

    public function getActiveClientId(): string
    {
        $mode = $this->getActiveMode();
        $primary = $mode === 'live' ? ($this->config['live_client_id'] ?? '') : ($this->config['sandbox_client_id'] ?? '');
        $primary = $this->decryptField((string) $primary);
        if (!empty($primary)) {
            return $primary;
        }
        $fallback = $mode === 'live' ? ($this->config['sandbox_client_id'] ?? '') : ($this->config['live_client_id'] ?? '');
        return $this->decryptField((string) $fallback);
    }

    public function getActiveSecret(): string
    {
        $mode = $this->getActiveMode();
        $primary = $mode === 'live' ? ($this->config['live_secret'] ?? '') : ($this->config['sandbox_secret'] ?? '');
        $primary = $this->decryptField((string) $primary);
        if (!empty($primary)) {
            return $primary;
        }
        $fallback = $mode === 'live' ? ($this->config['sandbox_secret'] ?? '') : ($this->config['live_secret'] ?? '');
        return $this->decryptField((string) $fallback);
    }

    public function hasCredentials(): bool
    {
        return !empty($this->getActiveClientId()) && !empty($this->getActiveSecret());
    }

    public function isSandbox(): bool
    {
        return $this->getActiveMode() === 'sandbox';
    }

    public function needs_setup(): bool
    {
        return !$this->hasCredentials();
    }

    public function is_available(): bool
    {
        if ($this->enabled !== 'yes') {
            return false;
        }
        if (class_exists('\WalletUpPro\Core\Payments\Availability\MethodAvailability') && \WalletUpPro\Core\Payments\Availability\MethodAvailability::automatedOff(\WalletUpPro\Core\Config\AutomatedApiConfig::PAYPAL_COMMERCE)) {
            return false;
        }
        return $this->hasCredentials();
    }

    private function getAccessToken(): string
    {
        $clientId = $this->getActiveClientId();
        $secret = $this->getActiveSecret();
        if (empty($clientId) || empty($secret)) {
            return '';
        }

        $response = wp_remote_post($this->getApiBase() . '/v1/oauth2/token', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $secret),
                'Content-Type'  => 'application/x-www-form-urlencoded',
                'Accept'        => 'application/json',
            ],
            'body'    => 'grant_type=client_credentials',
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            $this->errorHandler->log('PayPal OAuth error: ' . $response->get_error_message());
            return '';
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['access_token'])) {
            $msg = is_array($body) ? wp_json_encode($body) : (string) $body;
            $this->errorHandler->log('PayPal OAuth missing access_token: ' . $msg);
            return '';
        }

        return (string) $body['access_token'];
    }

    private function getClientToken(): string
    {
        $clientId = $this->getActiveClientId();
        if (empty($clientId)) {
            return '';
        }

        $cacheKey = 'wallet_up_paypal_ct_' . md5($this->getActiveMode() . '|' . $clientId);
        $cached = get_transient($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $accessToken = $this->getAccessToken();
        if (empty($accessToken)) {
            return '';
        }

        $response = wp_remote_post($this->getApiBase() . '/v1/identity/generate-token', [
            'headers' => [
                'Authorization'   => 'Bearer ' . $accessToken,
                'Content-Type'    => 'application/json',
                'Accept'          => 'application/json',
                'Accept-Language' => 'en_US',
            ],
            'body'    => '{}',
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            $this->errorHandler->log('PayPal generate-token error: ' . $response->get_error_message());
            return '';
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['client_token'])) {
            $msg = is_array($body) ? wp_json_encode($body) : (string) $body;
            $this->errorHandler->log('PayPal generate-token bad response: ' . $msg);
            return '';
        }

        $token = (string) $body['client_token'];
        set_transient($cacheKey, $token, 5 * MINUTE_IN_SECONDS);
        return $token;
    }

    public function addClientTokenAttribute($tag, $handle, $src)
    {
        if ($handle !== 'walletup-paypal-sdk') {
            return $tag;
        }
        $token = $this->getClientToken();
        if (empty($token)) {
            return $tag;
        }
        return str_replace('<script ', '<script data-client-token="' . esc_attr($token) . '" ', $tag);
    }

    public function enqueue_paypal_scripts(): void
    {
        if (!function_exists('is_checkout')) {
            return;
        }
        if (!is_checkout() && !is_wc_endpoint_url('order-pay')) {
            return;
        }
        if (!$this->is_available()) {
            return;
        }

        $clientId = $this->getActiveClientId();
        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD';
        if (empty($clientId)) {
            return;
        }

        $sdkUrl = add_query_arg([
            'client-id'  => $clientId,
            'components' => 'buttons,card-fields',
            'currency'   => $currency,
            'intent'     => 'capture',
        ], 'https://www.paypal.com/sdk/js');

        // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- External PayPal SDK; version controlled by PayPal.
        wp_enqueue_script(
            'walletup-paypal-sdk',
            $sdkUrl,
            [],
            null,
            true
        );

        wp_enqueue_script(
            'walletup-paypal-commerce-checkout',
            WALLET_UP_URL . 'assets/js/paypal-commerce-checkout.js',
            ['walletup-paypal-sdk', 'jquery'],
            WALLET_UP_VERSION,
            true
        );

        wp_localize_script('walletup-paypal-commerce-checkout', 'walletUpPayPal', [
            'createOrderUrl'    => admin_url('admin-ajax.php'),
            'captureOrderUrl'   => admin_url('admin-ajax.php'),
            'createOrderNonce'  => wp_create_nonce('wallet_up_paypal_create_order'),
            'captureOrderNonce' => wp_create_nonce('wallet_up_paypal_capture_order'),
            'sandboxMode'       => $this->isSandbox(),
            'currency'          => $currency,
            'paymentField'      => 'walletup_paypal_order_id',
        ]);
    }

    public function payment_fields(): void
    {
        if ($this->description) {
            echo wp_kses_post(wpautop(wptexturize($this->description)));
        }
        if ($this->isSandbox()) {
            echo '<p style="color:#e67e22;font-size:0.85em;margin:8px 0;">';
            echo esc_html__('SANDBOX MODE — no real charges will be made.', 'walletup');
            echo '</p>';
        }
        echo '<div id="walletup-paypal-buttons-classic" style="margin:12px 0;min-height:48px;"></div>';
        echo '<div id="walletup-paypal-card-fields-classic" style="margin:12px 0;"></div>';
        echo '<input type="hidden" name="walletup_paypal_order_id" id="walletup_paypal_order_id" value="" />';
    }

    public function handleCreateOrder(): void
    {
        check_ajax_referer('wallet_up_paypal_create_order', 'nonce');

        if (!$this->is_available()) {
            wp_send_json_error(['message' => __('PayPal Commerce is not configured.', 'walletup')]);
        }

        if (!function_exists('WC') || !WC()->cart) {
            wp_send_json_error(['message' => __('Cart unavailable.', 'walletup')]);
        }

        $cart = WC()->cart;
        if ($cart->is_empty()) {
            wp_send_json_error(['message' => __('Your cart is empty.', 'walletup')]);
        }

        $cart->calculate_totals();
        $total = (float) $cart->get_total('edit');
        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD';

        if ($total <= 0) {
            wp_send_json_error(['message' => __('Invalid cart total.', 'walletup')]);
        }

        $token = $this->getAccessToken();
        if (empty($token)) {
            wp_send_json_error(['message' => __('Could not authenticate with PayPal.', 'walletup')]);
        }

        $payload = [
            'intent'         => 'CAPTURE',
            'purchase_units' => [[
                'amount' => [
                    'currency_code' => $currency,
                    'value'         => number_format($total, 2, '.', ''),
                ],
            ]],
        ];

        $response = wp_remote_post($this->getApiBase() . '/v2/checkout/orders', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'body'    => wp_json_encode($payload),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            $this->errorHandler->log('PayPal create order error: ' . $response->get_error_message());
            wp_send_json_error(['message' => \WalletUpPro\Core\Payments\BackendErrors::forCustomer($response, 'paypal')]);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['id'])) {
            $msg = isset($body['message']) ? (string) $body['message'] : __('PayPal returned an invalid response.', 'walletup');
            $this->errorHandler->log('PayPal create order bad response: ' . wp_json_encode($body));
            wp_send_json_error(['message' => $msg]);
        }

        wp_send_json_success(['id' => $body['id']]);
    }

    public function handleCaptureOrder(): void
    {
        check_ajax_referer('wallet_up_paypal_capture_order', 'nonce');

        if (!$this->is_available()) {
            wp_send_json_error(['message' => __('PayPal Commerce is not configured.', 'walletup')]);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above via check_ajax_referer.
        $orderId = isset($_POST['order_id']) ? sanitize_text_field(wp_unslash($_POST['order_id'])) : '';
        if (empty($orderId)) {
            wp_send_json_error(['message' => __('Missing PayPal order id.', 'walletup')]);
        }

        $token = $this->getAccessToken();
        if (empty($token)) {
            wp_send_json_error(['message' => __('Could not authenticate with PayPal.', 'walletup')]);
        }

        $response = wp_remote_post($this->getApiBase() . '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'body'    => '{}',
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            $this->errorHandler->log('PayPal capture error: ' . $response->get_error_message());
            wp_send_json_error(['message' => \WalletUpPro\Core\Payments\BackendErrors::forCustomer($response, 'paypal')]);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['status']) || $body['status'] !== 'COMPLETED') {
            $msg = isset($body['message']) ? (string) $body['message'] : __('PayPal capture failed.', 'walletup');
            $this->errorHandler->log('PayPal capture bad response: ' . wp_json_encode($body));
            wp_send_json_error(['message' => $msg]);
        }

        $captureId = '';
        if (!empty($body['purchase_units'][0]['payments']['captures'][0]['id'])) {
            $captureId = (string) $body['purchase_units'][0]['payments']['captures'][0]['id'];
        }

        wp_send_json_success([
            'status'     => $body['status'],
            'capture_id' => $captureId,
            'payer'      => $body['payer'] ?? null,
        ]);
    }

    private function fetchPayPalOrder(string $orderId): ?array
    {
        $token = $this->getAccessToken();
        if (empty($token)) {
            return null;
        }

        $response = wp_remote_get($this->getApiBase() . '/v2/checkout/orders/' . rawurlencode($orderId), [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            $this->errorHandler->log('PayPal fetch order error: ' . $response->get_error_message());
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($body) ? $body : null;
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
            $paypalOrderId = sanitize_text_field(wp_unslash($_POST['walletup_paypal_order_id'] ?? ''));
            if (empty($paypalOrderId)) {
                throw new Exception(esc_html(__('PayPal payment was not authorized. Please complete the PayPal flow above and try again.', 'walletup')));
            }

            $paypalOrder = $this->fetchPayPalOrder($paypalOrderId);
            if (!$paypalOrder) {
                throw new Exception(esc_html(__('Could not verify PayPal order. Please try again.', 'walletup')));
            }

            $status = (string) ($paypalOrder['status'] ?? '');
            if ($status !== 'COMPLETED') {
                throw new Exception(esc_html(sprintf(
                    /* translators: %s: PayPal order status */
                    __('PayPal payment not complete (status: %s).', 'walletup'),
                    $status !== '' ? $status : 'UNKNOWN'
                )));
            }

            $unit = $paypalOrder['purchase_units'][0] ?? [];
            $paidValue = (float) ($unit['payments']['captures'][0]['amount']['value']
                ?? $unit['amount']['value'] ?? 0);
            $paidCurrency = strtoupper((string) ($unit['payments']['captures'][0]['amount']['currency_code']
                ?? $unit['amount']['currency_code'] ?? ''));
            if ($paidValue + 0.001 < (float) $order->get_total()
                || ($paidCurrency !== '' && $paidCurrency !== strtoupper($order->get_currency()))) {
                throw new Exception(esc_html__('Payment amount does not match this order.', 'walletup'));
            }
            $duplicate = wc_get_orders([
                'limit'      => 1,
                'return'     => 'ids',
                'meta_key'   => '_paypal_commerce_order_id',
                'meta_value' => $paypalOrderId,
                'exclude'    => [$order->get_id()],
            ]);
            if (!empty($duplicate) || $order->is_paid()) {
                throw new Exception(esc_html__('This payment has already been recorded.', 'walletup'));
            }

            $captureId = '';
            if (!empty($paypalOrder['purchase_units'][0]['payments']['captures'][0]['id'])) {
                $captureId = (string) $paypalOrder['purchase_units'][0]['payments']['captures'][0]['id'];
            }

            $order->update_meta_data('_paypal_commerce_order_id', $paypalOrderId);
            $order->update_meta_data('_walletup_provider', 'paypal');
            if ($captureId !== '') {
                $order->update_meta_data('_paypal_commerce_capture_id', $captureId);
                $order->update_meta_data('_walletup_paypal_capture_id', $captureId);
                $order->update_meta_data('_walletup_provider_transaction_id', $captureId);
            }
            $order->add_order_note(sprintf(
                /* translators: 1: PayPal order ID, 2: capture ID */
                __('PayPal Commerce payment complete (Order: %1$s, Capture: %2$s)', 'walletup'),
                $paypalOrderId,
                $captureId !== '' ? $captureId : '—'
            ));
            $order->payment_complete($paypalOrderId);
            $order->save();

            $this->recordTransaction($order, $paypalOrderId, $captureId);

            WC()->cart->empty_cart();

            return [
                'result'   => 'success',
                'redirect' => $this->get_return_url($order),
            ];
        } catch (Exception $e) {
            $this->errorHandler->log('PayPal Commerce payment error: ' . $e->getMessage());
            wc_add_notice($e->getMessage(), 'error');
            return ['result' => 'failure', 'redirect' => ''];
        }
    }

    private function recordTransaction(\WC_Order $order, string $paypalOrderId = '', string $captureId = ''): void
    {
        try {
            PaymentProcessor::getInstance()->initializePayment([
                'email'          => $order->get_billing_email(),
                'first'          => $order->get_billing_first_name(),
                'last'           => $order->get_billing_last_name(),
                'phone'          => $order->get_billing_phone(),
                'amt'            => $order->get_total(),
                'currency'       => $order->get_currency(),
                'payment_method' => 'paypal_commerce',
                'description'    => sprintf('Order #%s', $order->get_order_number()),
                'wc_order_id'    => $order->get_id(),
                'metadata'       => [
                    'payment_method'   => 'paypal_commerce',
                    'provider'         => 'paypal',
                    'mode'             => $this->getActiveMode(),
                    'paypal_order_id'  => $paypalOrderId,
                    'paypal_capture_id'=> $captureId,
                    'order_key'        => $order->get_order_key(),
                    'return_url'       => $this->get_return_url($order),
                ],
            ]);
        } catch (Exception $e) {
            $this->errorHandler->log('Failed to record PayPal Commerce transaction: ' . $e->getMessage());
        }
    }
}
