<?php
namespace WalletUpPro\WooCommerce;

use WalletUpPro\Core\Error\ErrorHandler;
use WalletUpPro\Core\Security\DataEncryption;
use WalletUpPro\Services\PaymentProcessor;
use Exception;

if (!defined('ABSPATH')) {
    exit;
}

class CashAppApiGateway extends \WC_Payment_Gateway
{
    private $errorHandler;
    private array $config;

    public function __construct()
    {
        $this->id = 'wallet_up_cashapp_api';
        $this->method_title = __('WalletUp — Cash App Pay API', 'walletup');
        $this->icon = \WalletUpPro\Core\Display\DisplayHandler::methodLogoUrl('cashapp_api');
        $this->method_description = __('Configured under Wallet Up → Seller. Credentials live in Wallet Up → Payments → Automated Payments.', 'walletup');
        $this->has_fields = true;
        $this->supports = ['products'];

        $this->errorHandler = ErrorHandler::getInstance();
        $this->config = (array) get_option('wallet_up_cashapp_api_settings', []);

        $this->init_form_fields();
        $this->init_settings();

        $this->title = __('Cash App Pay', 'walletup');
        $this->description = __('Pay with Cash App.', 'walletup');
        $this->enabled = !empty($this->config['enabled']) ? 'yes' : 'no';

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_cashapp_scripts']);
    }

    public function init_form_fields(): void
    {
        $this->form_fields = [];
    }

    public function admin_options(): void
    {
        $sellerUrl = admin_url('admin.php?page=wallet-up-seller#cashapp-api');
        ?>
        <h2><?php esc_html_e('Cash App Pay API', 'walletup'); ?> <small style="color:#6b7280;font-weight:400;">(<?php esc_html_e('Wallet Up', 'walletup'); ?>)</small></h2>
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

    private function getEnvironment(): string
    {
        $env = $this->config['environment'] ?? 'sandbox';
        return in_array($env, ['sandbox', 'production'], true) ? $env : 'sandbox';
    }

    private function getApiBase(): string
    {
        return $this->getEnvironment() === 'production'
            ? 'https://connect.squareup.com'
            : 'https://connect.squareupsandbox.com';
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
                $this->errorHandler->log('Failed to decrypt Cash App credential: ' . $e->getMessage());
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

    public function hasCredentials(): bool
    {
        return !empty($this->config['client_id'])
            && !empty($this->config['api_key'])
            && !empty($this->config['location_id']);
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
        return $this->hasCredentials();
    }

    public function enqueue_cashapp_scripts(): void
    {
        if (!is_checkout() && !is_wc_endpoint_url('order-pay')) {
            return;
        }
        if (!$this->is_available()) {
            return;
        }

        $sdkUrl = $this->getEnvironment() === 'production'
            ? 'https://web.squarecdn.com/v1/square.js'
            : 'https://sandbox.web.squarecdn.com/v1/square.js';

        // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- External Square Web Payments SDK; version controlled by Square CDN.
        wp_enqueue_script(
            'square-web-payments-sdk',
            $sdkUrl,
            [],
            null,
            true
        );

        wp_enqueue_script(
            'walletup-cashapp-api-checkout',
            WALLET_UP_URL . 'assets/js/cashapp-api-checkout.js',
            ['square-web-payments-sdk', 'jquery'],
            WALLET_UP_VERSION,
            true
        );

        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD';
        wp_localize_script('walletup-cashapp-api-checkout', 'walletUpCashAppApi', [
            'applicationId' => (string) ($this->config['client_id'] ?? ''),
            'locationId'    => (string) ($this->config['location_id'] ?? ''),
            'currency'      => strtoupper($currency),
            'sandboxMode'   => $this->getEnvironment() === 'sandbox',
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
        echo '<div id="walletup-cashapp-pay-classic" style="min-height:48px;margin-top:8px;"></div>';
        echo '<div id="walletup-cashapp-pay-errors" role="alert" style="color:#e74c3c;font-size:0.85em;margin-top:5px;"></div>';
        echo '<input type="hidden" name="walletup_cashapp_source_id" id="walletup_cashapp_source_id" value="" />';
    }

    public function process_payment($order_id): array
    {
        \WalletUpPro\Core\Tenancy\Tenant::enterOrder($order_id);
        try {
            $order = wc_get_order($order_id);
            if (!$order) {
                throw new Exception(esc_html(__('Invalid order', 'walletup')));
            }

            if (!$this->hasCredentials()) {
                throw new Exception(esc_html(__('Cash App Pay is not configured.', 'walletup')));
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce process_payment callback; WC verifies the checkout nonce.
            $sourceId = sanitize_text_field(wp_unslash($_POST['walletup_cashapp_source_id'] ?? ''));
            if (empty($sourceId)) {
                throw new Exception(esc_html(__('No Cash App Pay source provided. Please tap "Pay with Cash App" before placing the order.', 'walletup')));
            }

            $accessToken = $this->decryptField((string) ($this->config['api_key'] ?? ''));
            $locationId  = (string) ($this->config['location_id'] ?? '');
            $currency    = strtoupper($order->get_currency());
            $amountMinor = $this->toMinorUnits((float) $order->get_total(), $currency);

            $body = [
                'source_id'        => $sourceId,
                'idempotency_key'  => uniqid('wu_cashapp_', true),
                'amount_money'     => [
                    'amount'   => $amountMinor,
                    'currency' => $currency,
                ],
                'location_id'      => $locationId,
                'reference_id'     => (string) $order->get_id(),
                'note'             => sprintf(
                    /* translators: %s: Order number */
                    __('Order #%s', 'walletup'),
                    $order->get_order_number()
                ),
            ];

            $response = wp_remote_post($this->getApiBase() . '/v2/payments', [
                'headers' => [
                    'Authorization'  => 'Bearer ' . $accessToken,
                    'Square-Version' => '2024-01-18',
                    'Content-Type'   => 'application/json',
                    'Accept'         => 'application/json',
                ],
                'body'    => wp_json_encode($body),
                'timeout' => 30,
            ]);

            if (is_wp_error($response)) {
                throw new Exception(esc_html($response->get_error_message()));
            }

            $decoded = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($decoded)) {
                throw new Exception(esc_html(__('Invalid response from Square.', 'walletup')));
            }

            if (!empty($decoded['errors']) && is_array($decoded['errors'])) {
                $first = $decoded['errors'][0] ?? [];
                $msg = is_array($first) && !empty($first['detail'])
                    ? (string) $first['detail']
                    : __('Cash App Pay charge failed.', 'walletup');
                throw new Exception(esc_html($msg));
            }

            $payment = $decoded['payment'] ?? null;
            if (!is_array($payment) || empty($payment['id'])) {
                throw new Exception(esc_html(__('Square did not return a payment object.', 'walletup')));
            }

            $status = strtoupper((string) ($payment['status'] ?? ''));
            if ($status !== 'COMPLETED' && $status !== 'APPROVED') {
                /* translators: %s: Square payment status */
                throw new Exception(esc_html(sprintf(
                    __('Unexpected Cash App Pay status: %s', 'walletup'),
                    $status
                )));
            }

            $paymentId = (string) $payment['id'];
            $order->payment_complete($paymentId);
            /* translators: %s: Square payment ID */
            $order->add_order_note(sprintf(
                __('Cash App Pay completed (Square Payment ID: %s)', 'walletup'),
                $paymentId
            ));
            $order->update_meta_data('_cashapp_payment_id', $paymentId);
            $order->save();

            $this->recordTransaction($order, $payment);

            WC()->cart->empty_cart();

            return [
                'result'   => 'success',
                'redirect' => $this->get_return_url($order),
            ];
        } catch (Exception $e) {
            $this->errorHandler->log('Cash App Pay payment error: ' . $e->getMessage());
            wc_add_notice($e->getMessage(), 'error');
            return ['result' => 'failure', 'redirect' => ''];
        }
    }

    private function toMinorUnits(float $amount, string $currency): int
    {
        return \WalletUpPro\Core\Payments\PaymentGatewayHandler::toMinorUnits($amount, $currency);
    }

    private function recordTransaction(\WC_Order $order, array $payment = []): void
    {
        try {
            PaymentProcessor::getInstance()->initializePayment([
                'email'          => $order->get_billing_email(),
                'first'          => $order->get_billing_first_name(),
                'last'           => $order->get_billing_last_name(),
                'phone'          => $order->get_billing_phone(),
                'amt'            => $order->get_total(),
                'currency'       => $order->get_currency(),
                'payment_method' => 'cashapp_api',
                'description'    => sprintf('Order #%s', $order->get_order_number()),
                'wc_order_id'    => $order->get_id(),
                'metadata'       => [
                    'payment_method'      => 'cashapp_api',
                    'provider'            => 'cashapp',
                    'environment'         => $this->getEnvironment(),
                    'order_key'           => $order->get_order_key(),
                    'return_url'          => $this->get_return_url($order),
                    'square_payment_id'   => (string) ($payment['id'] ?? ''),
                    'square_status'       => (string) ($payment['status'] ?? ''),
                ],
            ]);
        } catch (Exception $e) {
            $this->errorHandler->log('Failed to record Cash App Pay transaction: ' . $e->getMessage());
        }
    }
}
