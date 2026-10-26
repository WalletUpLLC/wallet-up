<?php
namespace WalletUpPro\WooCommerce;

use WalletUpPro\Core\Config\PaymentMethodsConfig;

if (!defined('ABSPATH')) {
    exit;
}

class WooCommerceMethodSettings
{
    private const OPTION_KEY = 'wallet_up_wc_method_settings';

    private const VALID_FIELDS = [
        'title',
        'description',
        'instructions',
        'email_instructions',
        'order_status',
        'min_amount',
        'max_amount',
        'virtual_only',
        'hide_icon',
        'discount_type',
        'discount_value',
        'payment_note',
        'show_discount_in_label',
    ];

    private const VALID_ORDER_STATUSES = [
        'on-hold',
        'pending',
        'processing',
    ];

    private const DEFAULTS = [
        'title' => '',
        'description' => '',
        'instructions' => '',
        'email_instructions' => '',
        'order_status' => 'on-hold',
        'min_amount' => 0,
        'max_amount' => 0,
        'virtual_only' => false,
        'discount_type' => '',
        'discount_value' => 0,
        'payment_note' => '',
        'show_discount_in_label' => '', // '' inherit global, 'show' force on, 'hide' force off
    ];

    public static function getDefaultCustomerInstructions(string $methodId): string
    {
        $map = [
            'cashapp' => "1. Tap or scan the code above to open Cash App.\n2. Send {amount} {currency} to {holder}.\n3. Tap \"I've sent my payment\" below so we can verify and complete your order.",
            'paypal' => "1. Tap or scan the code to open PayPal.Me.\n2. Send {amount} {currency} to {holder}.\n3. Tap \"I've sent my payment\" below so we can verify and complete your order.",
            'venmo' => "1. Tap or scan the code to open Venmo.\n2. Send {amount} {currency} to {holder}.\n3. Tap \"I've sent my payment\" below so we can verify and complete your order.",
            'zelle' => "1. Scan the code with your banking app, or open Zelle and send to {holder}.\n2. Send {amount} {currency}.\n3. Tap \"I've sent my payment\" below — include your order number as the note.",
            'interac' => "1. Open your Canadian bank app and start an Interac e-Transfer to {holder}.\n2. Send {amount} {currency} (autodeposit is enabled, so no security question is needed).\n3. Tap \"I've sent my payment\" below — add your order number in the message so we can match it.",
            'payid' => "1. Open your Australian bank app and choose \"Pay someone\" → PayID.\n2. Send {amount} {currency} to the PayID {holder}. It arrives instantly via Osko.\n3. Tap \"I've sent my payment\" below — add your order number in the description.",
            'bpay' => "1. Open your Australian bank app and choose \"Pay a bill\" → BPAY.\n2. Enter Biller Code {holder}, use your order number as the reference (CRN), and pay {amount} {currency}.\n3. Tap \"I've sent my payment\" below so we can match and confirm it.",
            'payto' => "1. Open your Australian bank app and choose PayTo (or \"Pay someone\").\n2. Authorise a payment of {amount} {currency} to {holder}.\n3. Tap \"I've sent my payment\" below — add your order number so we can match it.",
            'fbpay' => "1. Tap or scan the code to open Meta Pay.\n2. Send {amount} {currency} to {holder}.\n3. Tap \"I've sent my payment\" below so we can verify and complete your order.",
            'waluppay' => "1. Open WalletUp Pay and choose to send a payment.\n2. Send {amount} {currency} to the handle {holder}.\n3. Tap \"I've sent my payment\" below — add your order number so we can match it.",
            'bitcoin' => "1. Scan the code or copy the address to your Bitcoin wallet.\n2. Send exactly {amount} BTC to the address.\n3. Tap \"I've sent my payment\" and include the transaction hash. We'll verify on-chain.",
            'ethereum' => "1. Scan the code or copy the address to your Ethereum wallet.\n2. Send exactly {amount} ETH to the address.\n3. Tap \"I've sent my payment\" and include the transaction hash. We'll verify on-chain.",
            'usdt' => "1. Scan the code or copy the address to your crypto wallet.\n2. Send exactly {amount} USDT (confirm network matches the address).\n3. Tap \"I've sent my payment\" and include the transaction hash.",
            'usdc' => "1. Scan the code or copy the address to your crypto wallet.\n2. Send exactly {amount} USDC (confirm network matches the address).\n3. Tap \"I've sent my payment\" and include the transaction hash.",
            'bnb' => "1. Scan the code or copy the address to your BNB Chain wallet.\n2. Send exactly {amount} BNB to the address.\n3. Tap \"I've sent my payment\" and include the transaction hash.",
            'dogecoin' => "1. Scan the code or copy the address to your Dogecoin wallet.\n2. Send exactly {amount} DOGE to the address.\n3. Tap \"I've sent my payment\" and include the transaction hash.",
        ];

        if (isset($map[$methodId])) {
            return $map[$methodId];
        }

        $mobileMoneyIds = \WalletUpPro\Core\Config\PaymentMethodsConfig::getMobileMoneyMethods();
        if (in_array($methodId, $mobileMoneyIds, true)) {
            return "1. Open your mobile money app or dial the USSD code for this provider.\n2. Send {amount} {currency} to {holder}.\n3. Tap \"I've sent my payment\" below — include the transaction reference. We'll verify and complete your order.";
        }

        return "1. Tap or scan the code above to open your payment app.\n2. Send {amount} {currency} to complete payment.\n3. Tap \"I've sent my payment\" below so we can verify and complete your order.";
    }

    public static function get(string $methodId, string $field, $default = null): mixed
    {
        $all = get_option(self::OPTION_KEY, []);
        $value = $all[$methodId][$field] ?? null;

        if ($value === null) {
            return $default ?? (self::DEFAULTS[$field] ?? '');
        }

        return $value;
    }

    public static function set(string $methodId, string $field, $value): void
    {
        if (!in_array($field, self::VALID_FIELDS, true)) {
            return;
        }

        $all = get_option(self::OPTION_KEY, []);
        if (!isset($all[$methodId])) {
            $all[$methodId] = [];
        }
        $all[$methodId][$field] = self::sanitizeField($field, $value);
        update_option(self::OPTION_KEY, $all);
    }

    public static function getAll(): array
    {
        return get_option(self::OPTION_KEY, []);
    }

    public static function saveAll(array $settings): void
    {
        $sanitized = [];
        $validMethodIds = PaymentMethodsConfig::getAllMethodIds();

        foreach ($settings as $methodId => $fields) {
            if (!in_array($methodId, $validMethodIds, true)) {
                continue;
            }

            $sanitized[$methodId] = [];
            foreach ($fields as $field => $value) {
                if (in_array($field, self::VALID_FIELDS, true)) {
                    $sanitized[$methodId][$field] = self::sanitizeField($field, $value);
                }
            }
        }

        update_option(self::OPTION_KEY, $sanitized);
    }

    public static function getEffectiveTitle(string $methodId): string
    {
        $title = self::get($methodId, 'title');
        if (!empty($title)) {
            return $title;
        }

        $config = PaymentMethodsConfig::getMethodConfig($methodId);
        return $config['name'] ?? ucfirst($methodId);
    }

    public static function getEffectiveOrderStatus(string $methodId): string
    {
        $status = self::get($methodId, 'order_status', 'on-hold');
        if (in_array($status, self::VALID_ORDER_STATUSES, true)) {
            return $status;
        }
        return 'on-hold';
    }

    public static function appendDiscountBadge(string $methodId, string $title): string
    {
        $isPro = function_exists('WalletUpPro\\wallet_up_fs') && \WalletUpPro\wallet_up_fs()->can_use_premium_code();
        if (!$isPro) {
            return $title;
        }

        $type = self::get($methodId, 'discount_type');
        $value = (float) self::get($methodId, 'discount_value', 0);
        if ($value <= 0 || empty($type)) {
            return $title;
        }

        $perMethod = (string) self::get($methodId, 'show_discount_in_label', '');
        if ($perMethod === 'hide') {
            return $title;
        }
        if ($perMethod !== 'show') {
            $wcSettings = get_option('wallet_up_wc_settings', []);
            if (empty($wcSettings['show_discount_in_label'])) {
                return $title;
            }
        }

        if ($type === 'percentage') {
            /* translators: %s: discount percentage */
            $badge = sprintf(__('(%s%% off)', 'walletup'), rtrim(rtrim(number_format($value, 2), '0'), '.'));
        } elseif ($type === 'fixed') {
            $symbolRaw = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '';
            $symbol = html_entity_decode($symbolRaw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            /* translators: 1: currency symbol, 2: discount amount */
            $badge = sprintf(__('(%1$s%2$s off)', 'walletup'), $symbol, number_format($value, 2));
        } else {
            return $title;
        }

        return trim($title) . ' ' . $badge;
    }

    public static function isAmountInRange(string $methodId, float $amount): bool
    {
        $min = (float) self::get($methodId, 'min_amount', 0);
        $max = (float) self::get($methodId, 'max_amount', 0);

        if ($min > 0 && $amount < $min) {
            return false;
        }

        if ($max > 0 && $amount > $max) {
            return false;
        }

        return true;
    }

    private static function resolveCustomerFirstName(?\WC_Order $order): string
    {
        if ($order instanceof \WC_Order) {
            $name = $order->get_billing_first_name();
            if (!empty($name)) {
                return $name;
            }
        }

        if (function_exists('WC') && WC()->customer) {
            $name = WC()->customer->get_billing_first_name();
            if (!empty($name)) {
                return $name;
            }
        }

        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            if (!empty($user->first_name)) {
                return $user->first_name;
            }
        }

        return '';
    }

    public static function processVariables(string $text, string $methodId, array $context = []): string
    {
        if (empty($text) || strpos($text, '{') === false) {
            return $text;
        }

        $amount_raw = $context['amount'] ?? (WC()->cart ? WC()->cart->get_total('') : '0');
        $currency = $context['currency'] ?? get_woocommerce_currency();
        $order = $context['order'] ?? null;

        $cryptoConverted = false;
        if (PaymentMethodsConfig::isCryptocurrency($methodId)
            && class_exists('\\WalletUpPro\\Core\\Crypto\\CryptoConverter')) {
            $conv = \WalletUpPro\Core\Crypto\CryptoConverter::getInstance()
                ->convertFiatToCrypto((float) $amount_raw, $methodId, (string) $currency);
            if (!empty($conv['success'])) {
                $amount_raw = $conv['amount'];
                $currency = $conv['symbol'];
                $formattedAmount = $conv['amount'];
                $cryptoConverted = true;
            }
        }

        if (!$cryptoConverted) {
            $formattedAmount = function_exists('wc_price')
                ? html_entity_decode(wp_strip_all_tags(wc_price($amount_raw)), ENT_QUOTES | ENT_HTML5, 'UTF-8')
                : $currency . ' ' . $amount_raw;
        }

        $boldAmount = '<strong>' . $formattedAmount . '</strong>';

        $holder = '';
        $mobileMoneyIds = \WalletUpPro\Core\Config\PaymentMethodsConfig::getMobileMoneyMethods();
        if (in_array($methodId, $mobileMoneyIds, true)) {
            $holder = \WalletUpPro\Core\Payments\MethodStatusResolver::mobileMoneyPhone($methodId);
        } else {
            $accounts = get_option('wallet_up_transfer_call', []);
            $holder = $accounts[$methodId . '_holder'] ?? '';
        }
        if (empty($holder)) {
            $accounts = $accounts ?? get_option('wallet_up_transfer_call', []);
            $holder = $accounts['holder_owner'] ?? get_bloginfo('name');
        }

        $replacements = [
            '{amount}' => $boldAmount,
            '{amount_raw}' => $amount_raw,
            '{method}' => self::getEffectiveTitle($methodId),
            '{method_id}' => $methodId,
            '{currency}' => $currency,
            '{holder}' => '<strong>' . esc_html($holder) . '</strong>',
            '{site_name}' => get_bloginfo('name'),
            '{order_id}' => $order ? ($order->get_order_number() ?: (string) $order->get_id()) : '',
            '{customer_name}' => self::resolveCustomerFirstName($order),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $text);
    }

    private static function sanitizeForUrlNote(string $text): string
    {
        $text = wp_strip_all_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    /**
     * Get the payment note for a method, with variable substitution.
     * Used in Venmo's note= URL parameter.
     * Supports: {order_id}, {customer_name}, {amount}, {site_name}, {method}
     *
     * @param string $methodId
     * @param array  $context  Keys: order (\WC_Order), amount, currency
     * @return string
     */
    public static function getPaymentNote(string $methodId, array $context = []): string
    {
        $custom = self::get($methodId, 'payment_note', '');
        if (!empty($custom)) {
            $resolved = self::sanitizeForUrlNote(self::processVariables($custom, $methodId, $context));
            if (trim($resolved) !== '') {
                return $resolved;
            }
        }

        $order = $context['order'] ?? null;
        $parts = [];
        if ($order instanceof \WC_Order) {
            $orderNumber = $order->get_order_number() ?: (string) $order->get_id();
            $parts[] = sprintf('Order #%s', $orderNumber);
            $name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
            if ($name) {
                $parts[] = $name;
            }
        }
        $parts[] = get_bloginfo('name');

        return self::sanitizeForUrlNote(implode(' — ', array_filter($parts)));
    }

    private static function sanitizeField(string $field, $value): mixed
    {
        return match ($field) {
            'title', 'description', 'instructions', 'email_instructions' => sanitize_text_field((string) $value),
            'order_status' => in_array($value, self::VALID_ORDER_STATUSES, true) ? $value : 'on-hold',
            'min_amount', 'max_amount', 'discount_value' => max(0, (float) $value),
            'virtual_only', 'hide_icon' => (bool) $value,
            'discount_type' => in_array($value, ['', 'percentage', 'fixed'], true) ? $value : '',
            'show_discount_in_label' => in_array($value, ['', 'show', 'hide'], true) ? $value : '',
            default => sanitize_text_field((string) $value),
        };
    }
}
