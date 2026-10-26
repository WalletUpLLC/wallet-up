<?php

namespace WalletUpPro\Core\Config;

if (!defined('ABSPATH')) {
    exit;
}

class StripeMethodCatalog
{
    private const SECONDARY = ['link'];

    public static function labels(): array
    {
        return [
            'card'              => __('Card', 'walletup'),
            'us_bank_account'   => __('Bank account', 'walletup'),
            'acss_debit'        => __('Bank account', 'walletup'),
            'au_becs_debit'     => __('Bank account', 'walletup'),
            'sepa_debit'        => __('Bank account', 'walletup'),
            'bacs_debit'        => __('Bank account', 'walletup'),
            'link'              => __('Link', 'walletup'),
            'cashapp'           => __('Cash App Pay', 'walletup'),
            'klarna'            => __('Klarna', 'walletup'),
            'afterpay_clearpay' => __('Afterpay', 'walletup'),
            'affirm'            => __('Affirm', 'walletup'),
            'paypal'            => __('PayPal', 'walletup'),
            'alipay'            => __('Alipay', 'walletup'),
            'wechat_pay'        => __('WeChat Pay', 'walletup'),
            'crypto'            => __('Crypto', 'walletup'),
        ];
    }

    public static function label(string $type): string
    {
        $type = trim($type);
        if ($type === '') {
            return '';
        }
        $labels = self::labels();
        return $labels[$type] ?? ucwords(str_replace('_', ' ', $type));
    }

    private static $intentLabelCache = [];

    public static function labelForIntent(string $intentId): string
    {
        if ($intentId === '' || strpos($intentId, 'pi_') !== 0 || !class_exists('\\WalletUpPro\\Core\\Payments\\StripeConnect')) {
            return '';
        }
        if (array_key_exists($intentId, self::$intentLabelCache)) {
            return self::$intentLabelCache[$intentId];
        }
        $data = \WalletUpPro\Core\Payments\StripeConnect::retrieveIntent($intentId);
        $label = (is_wp_error($data) || !is_array($data)) ? '' : self::label((string) ($data['payment_method_type'] ?? ''));
        self::$intentLabelCache[$intentId] = $label;
        return $label;
    }

    public static function primaryTypes(array $types): array
    {
        $types = array_values(array_unique(array_filter(array_map('strval', $types))));
        return array_values(array_diff($types, self::SECONDARY));
    }

    public static function isCardOnly(array $types): bool
    {
        $primary = self::primaryTypes($types);
        return empty($primary) || $primary === ['card'];
    }

    public static function checkoutLabel(array $types): string
    {
        if (self::isCardOnly($types)) {
            return __('Credit/Debit Card', 'walletup');
        }
        $primary = self::primaryTypes($types);
        if (in_array('card', $primary, true)) {
            return __('Credit/Debit Card & more', 'walletup');
        }
        if (count($primary) === 1) {
            return self::label($primary[0]);
        }
        return sprintf(
            /* translators: %s: primary payment method label (e.g. Bank account) */
            __('%s & more', 'walletup'),
            self::label($primary[0])
        );
    }

    public static function checkoutDescription(array $types): string
    {
        if (self::isCardOnly($types)) {
            return __('Pay securely with your card.', 'walletup');
        }
        return __('Choose how you would like to pay.', 'walletup');
    }
}
