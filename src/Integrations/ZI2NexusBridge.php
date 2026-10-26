<?php
namespace WalletUpPro\Integrations;

if (!defined('ABSPATH')) {
    exit;
}

class ZI2NexusBridge
{
    const OPT_URL     = 'walletup_zi2_url';
    const OPT_SECRET  = 'walletup_zi2_secret';
    const OPT_ENABLED = 'walletup_zi2_enabled';

    public static function init(): void
    {
        add_action('walletup_payment_status_changed', [__CLASS__, 'onPaymentStatusChanged'], 20, 3);
        add_action('woocommerce_order_status_completed', [__CLASS__, 'onWooOrderCompleted'], 20, 1);
    }

    public static function isEnabled(): bool
    {
        return get_option(self::OPT_ENABLED) === '1' && !empty(get_option(self::OPT_URL));
    }

    public static function onPaymentStatusChanged(string $paymentId, string $status, array $data): void
    {
        if (strtolower(trim($status)) !== 'completed' && strtolower(trim($status)) !== 'paid') {
            return;
        }
        if (!self::isEnabled()) {
            return;
        }
        $customer = $data['customer'] ?? [];
        self::dispatch([
            'event'    => 'payment.completed',
            'customer' => [
                'email'      => $customer['email'] ?? ($data['email'] ?? ''),
                'first_name' => $customer['first_name'] ?? '',
                'last_name'  => $customer['last_name'] ?? '',
                'company'    => $customer['company'] ?? '',
                'phone'      => $customer['phone'] ?? '',
                'title'      => $customer['title'] ?? '',
            ],
            'amount'    => floatval($data['amount'] ?? 0),
            'currency'  => $data['currency'] ?? 'USD',
            'method'    => $data['method'] ?? 'walletup',
            'payment_id' => $paymentId,
            'source'    => 'walletup_pro',
            'meta'      => $data,
        ]);
    }

    public static function onWooOrderCompleted(int $orderId): void
    {
        if (!self::isEnabled() || !function_exists('wc_get_order')) {
            return;
        }
        $order = wc_get_order($orderId);
        if (!$order) {
            return;
        }
        self::dispatch([
            'event'   => 'payment.completed',
            'customer' => [
                'email'      => $order->get_billing_email(),
                'first_name' => $order->get_billing_first_name(),
                'last_name'  => $order->get_billing_last_name(),
                'company'    => $order->get_billing_company(),
                'phone'      => $order->get_billing_phone(),
            ],
            'amount'   => floatval($order->get_total()),
            'currency' => $order->get_currency(),
            'method'   => $order->get_payment_method() ?: 'walletup',
            'payment_id' => $order->get_transaction_id() ?: ('wc_order_' . $orderId),
            'source'   => 'walletup_pro_woocommerce',
            'meta'     => ['order_id' => $orderId],
        ]);
    }

    protected static function dispatch(array $payload): void
    {
        $url    = (string) get_option(self::OPT_URL);
        $secret = (string) get_option(self::OPT_SECRET);
        $body   = wp_json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!$body || !$url) {
            return;
        }
        $sig    = 'sha256=' . hash_hmac('sha256', $body, $secret);

        wp_remote_post($url, [
            'method'      => 'POST',
            'timeout'     => 5,
            'redirection' => 0,
            'blocking'    => true, // short blocking call — Nexus responds in ~50ms via localhost
            'headers'     => [
                'Content-Type'    => 'application/json',
                'User-Agent'      => 'WalletUpPro-ZI2NexusBridge/1.0',
                'X-ZI2-Signature' => $sig,
            ],
            'body'        => $body,
        ]);
    }
}
