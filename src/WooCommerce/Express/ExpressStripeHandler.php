<?php
namespace WalletUpPro\WooCommerce\Express;

use WalletUpPro\Core\Error\ErrorHandler;
use Exception;

if (!defined('ABSPATH')) {
    exit;
}

class ExpressStripeHandler
{
    private const ZERO_DECIMAL = ['BIF','CLP','DJF','GNF','JPY','KMF','KRW','MGA','PYG','RWF','UGX','VND','VUV','XAF','XOF','XPF'];

    public static function init(): void
    {
        add_action('wp_ajax_wallet_up_pay_express', [__CLASS__, 'handle']);
        add_action('wp_ajax_nopriv_wallet_up_pay_express', [__CLASS__, 'handle']);
    }

    public static function handle(): void
    {
        $errors = ErrorHandler::getInstance();
        try {
            if (!check_ajax_referer('wallet_up_pay_express', 'nonce', false)) {
                throw new Exception(__('Your session expired. Please refresh and try again.', 'walletup'));
            }
            if (!class_exists('WooCommerce') || !WC()->cart || WC()->cart->is_empty()) {
                throw new Exception(__('Your cart is empty.', 'walletup'));
            }

            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized field-by-field below
            $pm = sanitize_text_field(wp_unslash($_POST['payment_method_id'] ?? ''));
            if ($pm === '' || strpos($pm, 'pm_') !== 0) {
                throw new Exception(__('Invalid payment method.', 'walletup'));
            }
            $email = sanitize_email(wp_unslash($_POST['payer_email'] ?? ''));
            $name  = sanitize_text_field(wp_unslash($_POST['payer_name'] ?? ''));
            if ($email === '') {
                throw new Exception(__('An email address is required to complete checkout.', 'walletup'));
            }

            \WalletUpPro\Core\Tenancy\Tenant::enterHints(['cart' => WC()->cart]);
            WC()->cart->calculate_totals();
            if (WC()->cart->needs_shipping()) {
                throw new Exception(__('This order needs a shipping address. Please use the full checkout.', 'walletup'));
            }

            $connectActive = class_exists('\\WalletUpPro\\Core\\Payments\\StripeConnect')
                && \WalletUpPro\Core\Payments\StripeConnect::isActive();
            $secret = $connectActive ? '' : self::secretKey();
            if (!$connectActive && $secret === '') {
                throw new Exception(__('Card payments are not fully configured.', 'walletup'));
            }

            $order = self::buildOrderFromCart($email, $name);
            $currency = $order->get_currency();
            $description = sprintf(
                /* translators: 1: order number, 2: site name */
                __('Order #%1$s from %2$s', 'walletup'),
                $order->get_order_number(),
                get_bloginfo('name')
            );

            $expressMetadata = \WalletUpPro\Core\Tenancy\Tenant::metadata([
                'order_id'  => (string) $order->get_id(),
                'order_key' => $order->get_order_key(),
                'source'    => 'wallet_up_pay_express',
            ]);

            if ($connectActive) {
                $ci = \WalletUpPro\Core\Payments\StripeConnect::createPaymentIntent([
                    'amount'         => self::stripeAmount((float) $order->get_total(), $currency),
                    'currency'       => strtolower($currency),
                    'payment_method' => $pm,
                    'description'    => $description,
                    'receipt_email'  => $email,
                    'metadata'       => $expressMetadata,
                ]);
                if (is_wp_error($ci)) {
                    throw new Exception(esc_html($ci->get_error_message()));
                }
                $intent = (array) $ci;
            } else {
                $flatMetadata = [];
                foreach ($expressMetadata as $metaKey => $metaValue) {
                    $flatMetadata['metadata[' . $metaKey . ']'] = $metaValue;
                }
                $intent = self::createPaymentIntent($secret, array_merge([
                    'amount'         => self::stripeAmount((float) $order->get_total(), $currency),
                    'currency'       => strtolower($currency),
                    'payment_method' => $pm,
                    'confirm'        => 'true',
                    'confirmation_method' => 'automatic',
                    'description'    => $description,
                    'receipt_email'       => $email,
                ], $flatMetadata));
            }

            if (($intent['status'] ?? '') === 'requires_action' && !empty($intent['client_secret'])) {
                $order->update_meta_data('_stripe_payment_intent_id', $intent['id']);
                $order->save();
                wp_send_json_success([
                    'requires_action' => true,
                    'client_secret'   => $intent['client_secret'],
                    'order_id'        => $order->get_id(),
                ]);
                return;
            }

            if (($intent['status'] ?? '') !== 'succeeded') {
                throw new Exception(sprintf(
                    /* translators: %s: payment status */
                    __('Payment could not be completed (%s).', 'walletup'),
                    $intent['status'] ?? 'unknown'
                ));
            }

            $order->payment_complete($intent['id']);
            $order->add_order_note(sprintf(
                /* translators: %s: Stripe PaymentIntent id */
                __('WalletUp Pay one-tap complete (Stripe PaymentIntent: %s)', 'walletup'),
                $intent['id']
            ));
            $order->update_meta_data('_stripe_payment_intent_id', $intent['id']);
            $order->update_meta_data('_wallet_up_pay_express', 'yes');
            $order->save();

            WC()->cart->empty_cart();

            wp_send_json_success(['redirect' => $order->get_checkout_order_received_url()]);

        } catch (Exception $e) {
            $errors->log('WalletUp Pay express error: ' . $e->getMessage());
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    private static function buildOrderFromCart(string $email, string $name): \WC_Order
    {
        $order = wc_create_order(['customer_id' => get_current_user_id()]);

        foreach (WC()->cart->get_cart() as $values) {
            $order->add_product($values['data'], $values['quantity'], [
                'subtotal' => $values['line_subtotal'],
                'total'    => $values['line_total'],
            ]);
        }
        foreach (WC()->cart->get_fees() as $fee) {
            $item = new \WC_Order_Item_Fee();
            $item->set_name($fee->name);
            $item->set_amount((float) $fee->amount);
            $item->set_total((float) $fee->total);
            $item->set_tax_class($fee->tax_class);
            $order->add_item($item);
        }
        foreach (WC()->cart->get_coupons() as $code => $coupon) {
            $order->apply_coupon($code);
        }

        $parts = preg_split('/\s+/', trim($name), 2);
        $billing = [
            'first_name' => $parts[0] ?? '',
            'last_name'  => $parts[1] ?? '',
            'email'      => $email,
        ];
        $order->set_address($billing, 'billing');
        $order->set_payment_method('wallet_up_card');
        $order->set_payment_method_title(__('WalletUp Pay (card)', 'walletup'));
        $order->calculate_totals();
        $order->save();
        return $order;
    }

    private static function createPaymentIntent(string $secret, array $body): array
    {
        $response = wp_remote_post('https://api.stripe.com/v1/payment_intents', [
            'headers' => [
                'Authorization' => 'Bearer ' . $secret,
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body'    => $body,
            'timeout' => 30,
        ]);
        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }
        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($decoded['error'])) {
            throw new Exception($decoded['error']['message'] ?? __('Payment failed.', 'walletup'));
        }
        return is_array($decoded) ? $decoded : [];
    }

    public static function keys(): array
    {
        return class_exists('\\WalletUpPro\\Core\\Config\\AutomatedApiConfig')
            ? \WalletUpPro\Core\Config\AutomatedApiConfig::stripeKeyPair()
            : ['publishable' => '', 'secret' => '', 'test' => true];
    }

    public static function secretKey(): string
    {
        return self::keys()['secret'];
    }

    private static function stripeAmount(float $total, string $currency): int
    {
        return \WalletUpPro\Core\Payments\PaymentGatewayHandler::toMinorUnits($total, $currency);
    }
}
