<?php
namespace WalletUpPro\WooCommerce;

use WalletUpPro\Core\Config\PaymentMethodsConfig;
use WalletUpPro\Core\Display\DisplayHandler;
use WalletUpPro\Core\PaymentRegistry;

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WC_Payment_Gateway')) {
    return;
}

/**
 * One peer-to-peer method at WooCommerce checkout on the free plan (6.6.0).
 *
 * A manual gateway in the shape of WooCommerce's own "Direct bank transfer": the
 * order goes on hold, the thank-you page shows the QR, link and "I've sent it"
 * form that already exist for pages, and the merchant confirms in Cash App,
 * Venmo, PayPal or their bank. Grouped display, more methods, discounts and
 * automatic verification stay in WooCommerceIntegration, which is Pro.
 */
class FreeP2pGateway extends \WC_Payment_Gateway
{
    public const ID = 'wallet_up_p2p';

    public function __construct()
    {
        $this->id                 = self::ID;
        $this->method_title       = __('Wallet Up: Cash App, Venmo, PayPal or Zelle', 'walletup');
        $this->method_description = __('Shows one of your Wallet Up payment methods at checkout. The customer pays in their app and confirms; the order waits on hold until you see the money. Pro adds the other methods, grouped display, discounts and automatic verification.', 'walletup');
        $this->has_fields         = false;
        $this->supports           = ['products'];

        $this->init_form_fields();
        $this->init_settings();

        $method            = $this->methodId();
        $name              = $method !== '' ? (PaymentMethodsConfig::getMethodName($method) ?: ucfirst($method)) : __('Wallet Up', 'walletup');
        $this->title       = trim((string) $this->get_option('title')) !== '' ? (string) $this->get_option('title') : sprintf(/* translators: %s: payment method name */ __('Pay with %s', 'walletup'), $name);
        $this->description = trim((string) $this->get_option('description')) !== '' ? (string) $this->get_option('description') : sprintf(/* translators: %s: payment method name */ __('After you place the order you will see a %s code and link. Pay there, then confirm on the same page.', 'walletup'), $name);
        $this->icon        = $method !== '' ? DisplayHandler::methodLogoUrl($method) : '';

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_thankyou_' . $this->id, [$this, 'renderThankYou'], 5);
        add_action('woocommerce_email_before_order_table', [$this, 'emailInstructions'], 10, 3);
    }

    public function init_form_fields(): void
    {
        $choices = [];
        foreach (PaymentMethodsConfig::FREE_METHOD_IDS as $id) {
            $choices[$id] = PaymentMethodsConfig::getMethodName($id) ?: ucfirst($id);
        }
        $this->form_fields = [
            'enabled' => [
                'title'   => __('Enable/Disable', 'walletup'),
                'type'    => 'checkbox',
                'label'   => __('Show a Wallet Up method at checkout', 'walletup'),
                'default' => 'no',
            ],
            'method' => [
                'title'       => __('Payment method', 'walletup'),
                'type'        => 'select',
                'options'     => $choices,
                'default'     => 'cashapp',
                'description' => __('The method must have a username saved and be switched on under Wallet Up → Payments. One method is included in the free plan; Pro adds the rest.', 'walletup'),
                'desc_tip'    => false,
            ],
            'title' => [
                'title'       => __('Title shown at checkout', 'walletup'),
                'type'        => 'text',
                'default'     => '',
                'description' => __('Leave empty to use "Pay with [method]".', 'walletup'),
            ],
            'description' => [
                'title'   => __('Description shown at checkout', 'walletup'),
                'type'    => 'textarea',
                'default' => '',
            ],
        ];
    }

    public function methodId(): string
    {
        $m = sanitize_key((string) $this->get_option('method', 'cashapp'));
        return in_array($m, PaymentMethodsConfig::FREE_METHOD_IDS, true) ? $m : '';
    }

    /** The chosen method must be configured and switched on, and USD-only apps need a USD store. */
    public function is_available(): bool
    {
        if (!parent::is_available()) {
            return false;
        }
        $method = $this->methodId();
        if ($method === '' || PaymentRegistry::savedHolderFor($method) === '') {
            return false;
        }
        try {
            if (!PaymentRegistry::getInstance()->isMethodEnabled($method)) {
                return false;
            }
        } catch (\Throwable $e) {
            return false;
        }
        if (in_array($method, PaymentMethodsConfig::USD_ONLY_METHOD_IDS, true) && function_exists('get_woocommerce_currency') && strtoupper(get_woocommerce_currency()) !== 'USD') {
            return false;
        }
        return true;
    }

    public function admin_options(): void
    {
        $method = $this->methodId();
        $problems = [];
        if ($method !== '' && PaymentRegistry::savedHolderFor($method) === '') {
            $problems[] = sprintf(/* translators: %s: method name */ __('%s has no username saved under Wallet Up → Payments, so this gateway stays hidden at checkout.', 'walletup'), PaymentMethodsConfig::getMethodName($method));
        }
        if ($method !== '' && in_array($method, PaymentMethodsConfig::USD_ONLY_METHOD_IDS, true) && function_exists('get_woocommerce_currency') && strtoupper(get_woocommerce_currency()) !== 'USD') {
            $problems[] = sprintf(/* translators: 1: method name, 2: store currency */ __('%1$s pays out in US dollars only and your store currency is %2$s, so this gateway stays hidden at checkout.', 'walletup'), PaymentMethodsConfig::getMethodName($method), get_woocommerce_currency());
        }
        foreach ($problems as $p) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html($p) . '</p></div>';
        }
        parent::admin_options();
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            wc_add_notice(__('Invalid order.', 'walletup'), 'error');
            return ['result' => 'failure'];
        }
        $method = $this->methodId();
        $name   = PaymentMethodsConfig::getMethodName($method) ?: ucfirst($method);

        $order->update_meta_data('_wallet_up_payment_method', $method);
        $order->update_meta_data('_wallet_up_payment_initiated', current_time('mysql'));
        $order->update_status('on-hold', sprintf(/* translators: %s: method name */ __('Awaiting %s payment. The customer sees the code and link on the order-received page.', 'walletup'), $name));
        $order->save();

        if (function_exists('wc_reduce_stock_levels')) {
            wc_reduce_stock_levels($order_id);
        }
        if (function_exists('WC') && WC()->cart) {
            WC()->cart->empty_cart();
        }

        return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
    }

    /** The same QR, link and "I've sent it" form the pages use, keyed to the order. */
    public function renderThankYou($order_id): void
    {
        // Themes can run the order-received content more than once per request; render once.
        static $done = [];
        $order_id = (int) $order_id;
        if (isset($done[$order_id])) {
            return;
        }
        $done[$order_id] = true;
        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== $this->id) {
            return;
        }
        if (in_array($order->get_status(), ['processing', 'completed'], true)) {
            return;
        }
        $html = '';
        if (class_exists(ConfirmationPage::class)) {
            try {
                $html = ConfirmationPage::buildPaymentSectionFromOrder($order);
            } catch (\Throwable $e) {
                $html = '';
            }
        }
        if ($html === '') {
            $html = $this->fallbackSection($order);
        }
        if ($html !== '') {
            echo '<section class="walletup-free-checkout-pay" style="margin:24px 0;">' . $html . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- server-built, escaped markup
        }
    }

    private function fallbackSection(\WC_Order $order): string
    {
        $method = $this->methodId();
        try {
            $instance = PaymentRegistry::getInstance()->getMethod($method);
            if (!$instance) {
                return '';
            }
            $amount = (string) $order->get_total();
            $url = (string) $instance->getPaymentUrl($amount, (string) $order->get_order_number());
            $settings = get_option('wallet_up_display_settings', []);
            return DisplayHandler::getInstance()->generateDisplay('qr', $url, $method, is_array($settings) ? $settings : [], [
                'amount'   => $amount,
                'order_id' => $order->get_id(),
                'note'     => (string) $order->get_order_number(),
                'post_id'  => 0,
            ]);
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function emailInstructions($order, $sent_to_admin, $plain_text = false): void
    {
        if (!$order instanceof \WC_Order || $sent_to_admin || $order->get_payment_method() !== $this->id || !$order->has_status('on-hold')) {
            return;
        }
        $method = $this->methodId();
        $name   = PaymentMethodsConfig::getMethodName($method) ?: ucfirst($method);
        $line   = sprintf(/* translators: 1: method name, 2: order-received URL */ __('Pay with %1$s using the code and link on your order page: %2$s', 'walletup'), $name, $this->get_return_url($order));
        echo $plain_text ? esc_html($line) . "\n\n" : '<p>' . esc_html($line) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
}
