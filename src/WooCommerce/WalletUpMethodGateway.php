<?php
namespace WalletUpPro\WooCommerce;

use WalletUpPro\Core\PaymentRegistry;
use WalletUpPro\Core\Config\PaymentMethodsConfig;
use WalletUpPro\Core\Display\DisplayHandler;
use WalletUpPro\Core\Error\ErrorHandler;
use WalletUpPro\Services\PaymentProcessor;
use Exception;

if (!defined('ABSPATH')) {
    exit;
}

class WalletUpMethodGateway extends \WC_Payment_Gateway
{
    private string $method_id;
    private $registry;
    private $displayHandler;
    private $errorHandler;

    public function __construct(string $method_id)
    {
        $this->method_id = $method_id;
        $this->id = 'wallet_up_' . $method_id;
        $this->errorHandler = ErrorHandler::getInstance();

        $effectiveTitle = WooCommerceMethodSettings::getEffectiveTitle($method_id);
        $this->method_title = $effectiveTitle;
        /* translators: %s: Payment method name */
        $this->method_description = sprintf(
            __('Accept payments via %s', 'walletup'),
            $effectiveTitle
        );
        $this->has_fields = true;

        $this->supports = ['products', 'default_credit_card_form'];

        try {
            $this->registry = PaymentRegistry::getInstance();
            $this->displayHandler = DisplayHandler::getInstance();
        } catch (Exception $e) {
            $this->errorHandler->log('WalletUpMethodGateway init failed for ' . $method_id . ': ' . $e->getMessage());
        }

        $this->init_form_fields();
        $this->init_settings();

        $this->enabled = $this->get_option('enabled', 'yes');

        $walletUpTitle = WooCommerceMethodSettings::get($method_id, 'title', '');
        $rawTitle = !empty($walletUpTitle)
            ? $walletUpTitle
            : $this->get_option('title', $effectiveTitle);
        $this->title = WooCommerceMethodSettings::appendDiscountBadge($method_id, $rawTitle);

        $walletUpDescription = WooCommerceMethodSettings::get($method_id, 'description', '');
        $this->description = !empty($walletUpDescription)
            ? $walletUpDescription
            : $this->get_option('description', '');

        $this->icon = $this->get_method_icon();

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_email_before_order_table', [$this, 'email_instructions'], 10, 3);
    }

    public function init_form_fields(): void
    {
        $effectiveTitle = WooCommerceMethodSettings::getEffectiveTitle($this->method_id);

        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'walletup'),
                'type' => 'checkbox',
                /* translators: %s: Payment method name */
                'label' => sprintf(__('Enable %s at checkout', 'walletup'), $effectiveTitle),
                'default' => 'yes',
            ],
        ];
    }

    public function admin_options(): void
    {
        $effectiveTitle = WooCommerceMethodSettings::getEffectiveTitle($this->method_id);
        $sellerUrl = admin_url('admin.php?page=wallet-up-seller#method-' . $this->method_id);
        ?>
        <h2><?php echo esc_html($effectiveTitle); ?> <small style="color:#6b7280;font-weight:400;">(<?php esc_html_e('Wallet Up', 'walletup'); ?>)</small></h2>

        <div style="margin:16px 0;padding:20px;background:linear-gradient(135deg,#f5f3ff,#ede9fe);border:1px solid #ddd6fe;border-radius:12px;">
            <div style="display:flex;align-items:flex-start;gap:14px;">
                <div style="flex-shrink:0;width:40px;height:40px;background:#7c3aed;border-radius:10px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:18px;">W</div>
                <div style="flex:1;min-width:0;">
                    <h3 style="margin:0 0 6px;color:#4c1d95;font-size:1.05em;"><?php esc_html_e('Full settings for this method live in Wallet Up', 'walletup'); ?></h3>
                    <p style="margin:0 0 12px;color:#4b5563;font-size:0.95em;line-height:1.5;">
                        <?php esc_html_e('Customize the checkout title and description, per-method instructions, thank-you page text, email templates, discounts, order status, and min/max amounts — all in one place.', 'walletup'); ?>
                    </p>
                    <a href="<?php echo esc_url($sellerUrl); ?>" class="button button-primary" style="background:#7c3aed;border-color:#6d28d9;text-shadow:none;box-shadow:none;">
                        <?php esc_html_e('Open Per-Method Settings →', 'walletup'); ?>
                    </a>
                </div>
            </div>
        </div>

        <p style="color:#6b7280;font-size:0.9em;margin-top:16px;">
            <?php esc_html_e('The toggle below only controls whether this method appears at WooCommerce checkout.', 'walletup'); ?>
        </p>

        <table class="form-table">
            <?php echo $this->generate_settings_html($this->get_form_fields(), false); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WC generates escaped HTML. ?>
        </table>
        <?php
    }

    public function is_available(): bool
    {
        if ($this->enabled !== 'yes') {
            return false;
        }

        if (!$this->registry || !$this->registry->getMethod($this->method_id)) {
            return false;
        }

        if (!\WalletUpPro\Core\Payments\MethodStatusResolver::isReady($this->method_id)) {
            return false;
        }
        if (!\WalletUpPro\Core\Payments\Availability\MethodAvailability::shows((string) $this->method_id, 'store')) {
            return false;
        }

        return self::passesCartChecks($this->method_id);
    }

    public static function passesCartChecks(string $methodId): bool
    {
        if (!function_exists('WC') || !WC()->cart) {
            return true; // No cart context (admin preview, REST call) — let caller decide.
        }
        $total = (float) WC()->cart->get_total('');
        if (!WooCommerceMethodSettings::isAmountInRange($methodId, $total)) {
            return false;
        }
        if (WooCommerceMethodSettings::get($methodId, 'virtual_only', false)) {
            foreach (WC()->cart->get_cart() as $item) {
                $product = $item['data'];
                if ($product && !$product->is_virtual() && !$product->is_downloadable()) {
                    return false;
                }
            }
        }
        return true;
    }

    public function payment_fields(): void
    {
        if ($this->description) {
            $desc = WooCommerceMethodSettings::processVariables($this->description, $this->method_id);
            echo wp_kses_post(wpautop(wptexturize($desc)));
        }

        if (!$this->registry || !$this->displayHandler) {
            return;
        }

        $method = $this->registry->getMethod($this->method_id);
        if (!$method) {
            return;
        }

        if (!WooCommerceConfig::shouldShowQrOnCheckout()) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped notice
            echo WooCommerceConfig::getCheckoutDeferredNoticeHtml();
            echo '<input type="hidden" name="wallet_up_payment_method" value="' . esc_attr($this->method_id) . '" />';
            wp_nonce_field('wallet_up_wc_payment_display', 'wallet_up_payment_nonce');
            return;
        }

        $amount = WC()->cart ? WC()->cart->get_total('') : 0;
        $config = WooCommerceConfig::getInstance();
        $settings = $config->getSettings();
        $display_mode = $settings['wcSettings']['display_mode'] ?? 'qr';

        try {
            $checkout_page_id = wc_get_page_id('checkout');
            $checkout_page = $checkout_page_id > 0 ? get_post($checkout_page_id) : null;

            $note = WooCommerceMethodSettings::getPaymentNote($this->method_id, [
                'amount' => $amount,
                'currency' => get_woocommerce_currency(),
            ]);
            $payment_url = $method->getPaymentUrl($amount, $note, ['is_checkout' => true]);
            $html = $this->displayHandler->generateDisplay(
                $display_mode,
                $payment_url,
                $this->method_id,
                $settings['wcQRSettings'] ?? [],
                [
                    'amount' => $amount,
                    'method' => $this->method_id,
                    'post_id' => $checkout_page ? $checkout_page->ID : 0,
                    'post_type' => $checkout_page ? $checkout_page->post_type : 'page',
                    'post_title' => $checkout_page ? $checkout_page->post_title : 'WooCommerce Checkout',
                    'skip_confirmation' => true,
                    'skip_impression' => true,
                ]
            );
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated by DisplayHandler
            echo $html;
        } catch (Exception $e) {
            $this->errorHandler->log('Payment fields error for ' . $this->method_id . ': ' . $e->getMessage());
        }

        echo '<input type="hidden" name="wallet_up_payment_method" value="' . esc_attr($this->method_id) . '" />';
        wp_nonce_field('wallet_up_wc_payment_display', 'wallet_up_payment_nonce');
    }

    public function process_payment($order_id): array
    {
        \WalletUpPro\Core\Tenancy\Tenant::enterOrder($order_id);
        try {
            $order = wc_get_order($order_id);
            if (!$order) {
                throw new Exception(esc_html(__('Invalid order ID', 'walletup')));
            }

            $method = $this->registry->getMethod($this->method_id);
            if (!$method) {
                throw new Exception(esc_html(__('Invalid payment method', 'walletup')));
            }

            $order->update_meta_data('_wallet_up_payment_method', $this->method_id);
            $order->update_meta_data('_wallet_up_payment_initiated', current_time('mysql'));
            $order->set_payment_method($this->id);
            $order->set_payment_method_title(WooCommerceMethodSettings::getEffectiveTitle($this->method_id));
            $order->save();

            if (PaymentMethodsConfig::isMobileMoney($this->method_id)) {
                return $this->processMobileMoneyPayment($order);
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
                        'payment_method' => $this->method_id,
                        'order_key' => $order->get_order_key(),
                        'return_url' => $this->get_return_url($order),
                    ],
                ];

                $paymentSession = $paymentProcessor->initializePayment($paymentData);
                $order->update_meta_data('_wallet_up_payment_id', $paymentSession['payment_id']);
                $order->update_meta_data('_wallet_up_session_id', $paymentSession['session_id']);

                $paymentProcessor->updatePaymentStatus($paymentSession['payment_id'], 'pending', [
                    'payment_method' => $this->method_id,
                ]);

                $order->save();
            } catch (Exception $e) {
                $this->errorHandler->log('Transaction record creation failed (non-fatal): ' . $e->getMessage());
            }

            $orderStatus = WooCommerceMethodSettings::getEffectiveOrderStatus($this->method_id);
            /* translators: %s: Payment method name */
            $order->update_status($orderStatus, sprintf(
                __('Awaiting %s payment', 'walletup'),
                WooCommerceMethodSettings::getEffectiveTitle($this->method_id)
            ));

            return [
                'result' => 'success',
                'redirect' => $this->get_return_url($order),
            ];
        } catch (Exception $e) {
            $this->errorHandler->log('Payment processing error: ' . $e->getMessage());
            wc_add_notice($e->getMessage(), 'error');
            return ['result' => 'failure', 'redirect' => ''];
        }
    }

    private function processMobileMoneyPayment(\WC_Order $order): array
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
                    'payment_method' => $this->method_id,
                    'order_key' => $order->get_order_key(),
                    'return_url' => $this->get_return_url($order),
                    'cancel_url' => wc_get_cart_url(),
                ],
            ];

            $paymentSession = $paymentProcessor->initializePayment($paymentData);
            $paymentProcessor->updatePaymentStatus($paymentSession['payment_id'], 'pending', [
                'payment_method' => $this->method_id,
            ]);

            $order->update_meta_data('_wallet_up_payment_id', $paymentSession['payment_id']);
            $order->update_meta_data('_wallet_up_session_id', $paymentSession['session_id']);
            $order->save();

            return [
                'result' => 'success',
                'redirect' => $paymentSession['payment_url'] . '?method=' . $this->method_id,
            ];
        } catch (Exception $e) {
            $this->errorHandler->log('Mobile money payment failed: ' . $e->getMessage());
            wc_add_notice(__('Unable to initialize mobile money payment. Please try again.', 'walletup'), 'error');
            return ['result' => 'failure', 'redirect' => ''];
        }
    }

    public function email_instructions($order, $sent_to_admin, $plain_text = false): void
    {
        if ($order->get_payment_method() !== $this->id) {
            return;
        }

        $email_instructions = WooCommerceMethodSettings::get($this->method_id, 'email_instructions', '');
        if (!empty($email_instructions)) {
            $email_instructions = WooCommerceMethodSettings::processVariables($email_instructions, $this->method_id, [
                'amount' => $order->get_total(),
                'currency' => $order->get_currency(),
                'order' => $order,
            ]);
            if ($plain_text) {
                echo "\n" . esc_html(wp_strip_all_tags($email_instructions)) . "\n\n";
            } else {
                echo wp_kses_post(wpautop(wptexturize($email_instructions)));
            }
        }
    }

    private function get_method_icon(): string
    {
        return \WalletUpPro\Core\Display\DisplayHandler::methodLogoUrl($this->method_id);
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

            if (!$result) {
                if (isset($this->errorHandler)) {
                    $this->errorHandler->log(
                        'walletup_payment_transactions table does not exist — ' .
                        'skipping transaction record. Re-activate the plugin to rebuild tables.'
                    );
                }
            }
        }

        return $result;
    }

}