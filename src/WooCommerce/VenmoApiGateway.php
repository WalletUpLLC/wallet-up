<?php
namespace WalletUpPro\WooCommerce;

use WalletUpPro\Core\Error\ErrorHandler;
use WalletUpPro\Payments\VenmoBraintreeAPI;
use WalletUpPro\Services\PaymentProcessor;
use Exception;

if (!defined('ABSPATH')) {
    exit;
}

class VenmoApiGateway extends \WC_Payment_Gateway
{
    private $errorHandler;
    private array $config;

    public function __construct()
    {
        $this->id = 'wallet_up_venmo_api';
        $this->method_title = __('WalletUp — Venmo (Braintree API)', 'walletup');
        $this->icon = \WalletUpPro\Core\Display\DisplayHandler::methodLogoUrl('venmo_api');
        $this->method_description = __('Configured under Wallet Up → Seller. Credentials live in Wallet Up → Payments → Automated Payments.', 'walletup');
        $this->has_fields = true;
        $this->supports = ['products', 'refunds'];

        $this->errorHandler = ErrorHandler::getInstance();
        $this->config = (array) get_option('wallet_up_venmo_api_settings', []);

        $this->init_form_fields();
        $this->init_settings();

        $this->title = __('Venmo', 'walletup');
        $this->description = __('Pay with Venmo.', 'walletup');
        $this->enabled = !empty($this->config['enabled']) ? 'yes' : 'no';

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_venmo_scripts']);

        add_action('wp_ajax_walletup_venmo_client_token', [$this, 'handleClientToken']);
        add_action('wp_ajax_nopriv_walletup_venmo_client_token', [$this, 'handleClientToken']);
    }

    public function init_form_fields(): void
    {
        $this->form_fields = [];
    }

    public function admin_options(): void
    {
        $sellerUrl = admin_url('admin.php?page=wallet-up-seller#venmo-api');
        ?>
        <h2><?php esc_html_e('Venmo (Braintree API)', 'walletup'); ?> <small style="color:#6b7280;font-weight:400;">(<?php esc_html_e('Wallet Up', 'walletup'); ?>)</small></h2>
        <div style="margin:16px 0;padding:20px;background:linear-gradient(135deg,#f5f3ff,#ede9fe);border:1px solid #ddd6fe;border-radius:12px;">
            <h3 style="margin:0 0 6px;color:#4c1d95;font-size:1.05em;"><?php esc_html_e('Configured in Wallet Up', 'walletup'); ?></h3>
            <p style="margin:0 0 12px;color:#4b5563;font-size:0.95em;line-height:1.5;">
                <?php esc_html_e('Enable / disable, switch sandbox/production, and review credentials in the WalletUp Seller panel.', 'walletup'); ?>
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

    public function getEnvironment(): string
    {
        $env = $this->config['environment'] ?? 'sandbox';
        return in_array($env, ['sandbox', 'production'], true) ? $env : 'sandbox';
    }

    public function hasCredentials(): bool
    {
        return !empty($this->config['merchant_id'])
            && !empty($this->config['public_key'])
            && !empty($this->config['private_key']);
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
        if (class_exists('\WalletUpPro\Core\Payments\Availability\MethodAvailability') && \WalletUpPro\Core\Payments\Availability\MethodAvailability::automatedOff(\WalletUpPro\Core\Config\AutomatedApiConfig::VENMO_API)) {
            return false;
        }
        return $this->hasCredentials();
    }

    public function enqueue_venmo_scripts(): void
    {
        if (!is_checkout() && !is_wc_endpoint_url('order-pay')) {
            return;
        }
        if (!$this->is_available()) {
            return;
        }

        $sandbox = $this->getEnvironment() === 'sandbox';

        // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- External Braintree SDK.
        wp_enqueue_script(
            'braintree-client',
            'https://js.braintreegateway.com/web/3.97.0/js/client.min.js',
            [],
            null,
            true
        );

        // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- External Braintree SDK.
        wp_enqueue_script(
            'braintree-venmo',
            'https://js.braintreegateway.com/web/3.97.0/js/venmo.min.js',
            ['braintree-client'],
            null,
            true
        );

        wp_enqueue_script(
            'walletup-venmo-checkout',
            WALLET_UP_URL . 'assets/js/venmo-api-checkout.js',
            ['braintree-client', 'braintree-venmo', 'jquery'],
            WALLET_UP_VERSION,
            true
        );

        wp_localize_script('walletup-venmo-checkout', 'walletUpVenmo', [
            'clientTokenUrl' => admin_url('admin-ajax.php'),
            'clientTokenAction' => 'walletup_venmo_client_token',
            'clientTokenNonce' => wp_create_nonce('walletup_venmo_client_token'),
            'sandboxMode' => $sandbox,
            'paymentField' => 'walletup_venmo_nonce',
        ]);
    }

    public function payment_fields(): void
    {
        if ($this->description) {
            echo wp_kses_post(wpautop(wptexturize($this->description)));
        }
        if ($this->getEnvironment() === 'sandbox') {
            echo '<p style="color:#e67e22;font-size:0.85em;margin-top:8px;">';
            echo esc_html__('SANDBOX MODE — no real charges will be made.', 'walletup');
            echo '</p>';
        }
        echo '<div id="walletup-venmo-button-classic" style="margin-top:12px;"></div>';
        echo '<div id="walletup-venmo-error-classic" role="alert" style="color:#e74c3c;font-size:0.85em;margin-top:8px;"></div>';
        echo '<input type="hidden" name="walletup_venmo_nonce" id="walletup_venmo_nonce" value="" />';
    }

    public function handleClientToken(): void
    {
        try {
            if (!check_ajax_referer('walletup_venmo_client_token', 'nonce', false)) {
                throw new Exception(esc_html__('Invalid security token', 'walletup'));
            }

            $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '0.0.0.0';
            $rate_key = 'walletup_venmo_wc_token_' . md5($ip);
            $attempts = (int) get_transient($rate_key);
            if ($attempts >= 20) {
                throw new Exception(esc_html__('Too many requests. Please try again later.', 'walletup'));
            }
            set_transient($rate_key, $attempts + 1, 60);

            if (!$this->hasCredentials()) {
                throw new Exception(esc_html__('Venmo is not configured.', 'walletup'));
            }

            $api = VenmoBraintreeAPI::getInstance();
            $token = $api->generateClientToken();

            wp_send_json_success(['clientToken' => $token]);
        } catch (Exception $e) {
            $this->errorHandler->log('Venmo client token error: ' . $e->getMessage());
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function process_payment($order_id): array
    {
        \WalletUpPro\Core\Tenancy\Tenant::enterOrder($order_id);
        try {
            $order = wc_get_order($order_id);
            if (!$order) {
                throw new Exception(esc_html__('Invalid order', 'walletup'));
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce process_payment callback; WC verifies the checkout nonce.
            $nonce = isset($_POST['walletup_venmo_nonce']) ? sanitize_text_field(wp_unslash($_POST['walletup_venmo_nonce'])) : '';
            if (empty($nonce)) {
                throw new Exception(esc_html__('No Venmo authorization received. Please tap "Pay with Venmo" before placing the order.', 'walletup'));
            }

            if (!$this->hasCredentials()) {
                throw new Exception(esc_html__('Venmo is not configured.', 'walletup'));
            }

            $api = VenmoBraintreeAPI::getInstance();
            $result = $api->processPayment([
                'payment_nonce' => $nonce,
                'amount'        => (float) $order->get_total(),
                'order_id'      => (string) $order->get_id(),
                'email'         => $order->get_billing_email(),
                'first_name'    => $order->get_billing_first_name(),
                'last_name'     => $order->get_billing_last_name(),
            ]);

            $transactionId = $result['transaction_id'] ?? '';
            $btStatus = $result['braintree_status'] ?? '';
            $mappedStatus = $result['status'] ?? 'pending';

            if (empty($transactionId)) {
                throw new Exception(esc_html__('Venmo transaction failed.', 'walletup'));
            }

            $this->recordTransaction($order, $result);

            $order->update_meta_data('_venmo_transaction_id', $transactionId);
            $order->update_meta_data('_venmo_braintree_status', $btStatus);
            $order->save();

            if (in_array($mappedStatus, ['completed', 'processing'], true)) {
                $order->payment_complete($transactionId);
                /* translators: 1: Braintree transaction ID, 2: Braintree status */
                $order->add_order_note(sprintf(
                    __('Venmo payment captured (Transaction: %1$s, status: %2$s)', 'walletup'),
                    $transactionId,
                    $btStatus
                ));
                $this->updateTransactionStatus($order, 'completed', $transactionId);
            } else {
                $order->update_status(
                    'on-hold',
                    /* translators: 1: Braintree transaction ID, 2: Braintree status */
                    sprintf(__('Venmo payment pending (Transaction: %1$s, status: %2$s)', 'walletup'), $transactionId, $btStatus)
                );
            }

            WC()->cart->empty_cart();

            return [
                'result'   => 'success',
                'redirect' => $this->get_return_url($order),
            ];
        } catch (Exception $e) {
            $this->errorHandler->log('Venmo payment error: ' . $e->getMessage());
            wc_add_notice($e->getMessage(), 'error');
            return ['result' => 'failure', 'redirect' => ''];
        }
    }

    private function recordTransaction(\WC_Order $order, array $providerResult = []): void
    {
        try {
            PaymentProcessor::getInstance()->initializePayment([
                'email'          => $order->get_billing_email(),
                'first'          => $order->get_billing_first_name(),
                'last'           => $order->get_billing_last_name(),
                'phone'          => $order->get_billing_phone(),
                'amt'            => $order->get_total(),
                'currency'       => $order->get_currency(),
                'payment_method' => 'venmo_api',
                'description'    => sprintf('Order #%s', $order->get_order_number()),
                'wc_order_id'    => $order->get_id(),
                'metadata'       => [
                    'payment_method'         => 'venmo_api',
                    'provider'               => 'braintree_venmo',
                    'environment'            => $this->getEnvironment(),
                    'order_key'              => $order->get_order_key(),
                    'return_url'             => $this->get_return_url($order),
                    'provider_transaction_id' => $providerResult['transaction_id'] ?? '',
                    'braintree_status'       => $providerResult['braintree_status'] ?? '',
                    'venmo_username'         => $providerResult['venmo_username'] ?? '',
                ],
            ]);
        } catch (Exception $e) {
            $this->errorHandler->log('Failed to record Venmo transaction: ' . $e->getMessage());
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
                $update['payment_provider'] = 'braintree_venmo';
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update($table, $update, ['wc_order_id' => $order->get_id()]);
        } catch (Exception $e) {
            $this->errorHandler->log('Failed to update Venmo transaction status: ' . $e->getMessage());
        }
    }

    public function process_refund($order_id, $amount = null, $reason = ''): bool|\WP_Error
    {
        \WalletUpPro\Core\Tenancy\Tenant::enterOrder($order_id);
        $order = wc_get_order($order_id);
        if (!$order) {
            return new \WP_Error('invalid_order', __('Invalid order', 'walletup'));
        }

        $transactionId = (string) $order->get_meta('_venmo_transaction_id');
        if (empty($transactionId)) {
            return new \WP_Error('no_txn', __('No Venmo transaction found for this order', 'walletup'));
        }

        try {
            $api = VenmoBraintreeAPI::getInstance();
            $refund = $api->refundTransaction($transactionId, $amount ? (float) $amount : 0);

            /* translators: 1: Refund ID, 2: Refund status */
            $order->add_order_note(sprintf(
                __('Venmo refund processed (Refund: %1$s, status: %2$s)', 'walletup'),
                $refund['refund_id'] ?? '',
                $refund['status'] ?? ''
            ));
            return true;
        } catch (Exception $e) {
            $this->errorHandler->log('Venmo refund error: ' . $e->getMessage());
            return new \WP_Error('refund_error', $e->getMessage());
        }
    }
}
