<?php
namespace WalletUpPro\Core\Payments;

if (!defined('ABSPATH')) {
    exit;
}

class MethodStatusResolver
{
    /**
     * Full status snapshot: three sets of method IDs (enabled, configured, liveAtCheckout)
     * plus derived booleans per method. Pass a cache-busting flag only when you know options
     * just changed.
     *
     * @return array{
     *   enabled: string[],
     *   configured: string[],
     *   liveAtCheckout: string[],
     *   byMethod: array<string, array{enabled:bool, configured:bool, liveAtCheckout:bool, holder:string}>
     * }
     */
    public static function snapshot(): array
    {
        $enabledMap  = (array) get_option('wallet_up_enabled_methods', []);
        $transferCall = (array) get_option('wallet_up_transfer_call', []);
        $mobileMoney  = (array) get_option('wallet_up_mobile_money_settings', []);
        $gateway      = (array) get_option('woocommerce_wallet_up_settings', []);
        $gatewayOn    = !empty($gateway['enabled']) && $gateway['enabled'] === 'yes';

        $byMethod = [];
        $enabled = [];
        $configured = [];
        $liveAtCheckout = [];

        $methodIds = array_values(array_unique(array_merge(
            array_map('strval', array_keys($enabledMap)),
            \WalletUpPro\Core\Config\PaymentMethodsConfig::getAllMethodIds()
        )));
        foreach ($methodIds as $methodId) {
            $isEnabled = self::flag($enabledMap[$methodId] ?? false);

            $holder = self::resolveHolder($methodId, $transferCall, $mobileMoney);
            $isConfigured = $holder !== '';

            $isLive = $gatewayOn && $isEnabled && $isConfigured && self::storeShows($methodId, $gateway);

            $byMethod[$methodId] = [
                'enabled'         => $isEnabled,
                'configured'      => $isConfigured,
                'liveAtCheckout'  => $isLive,
                'holder'          => $holder,
            ];

            if ($isEnabled)    { $enabled[] = $methodId; }
            if ($isEnabled && $isConfigured) { $configured[] = $methodId; }
            if ($isLive)       { $liveAtCheckout[] = $methodId; }
        }

        return [
            'enabled'        => $enabled,
            'configured'     => $configured,
            'liveAtCheckout' => $liveAtCheckout,
            'byMethod'       => $byMethod,
        ];
    }

    public static function isConfigured(string $methodId): bool
    {
        return self::resolveHolder(
            $methodId,
            (array) get_option('wallet_up_transfer_call', []),
            (array) get_option('wallet_up_mobile_money_settings', [])
        ) !== '';
    }

    public static function isEnabled(string $methodId): bool
    {
        $enabled = (array) get_option('wallet_up_enabled_methods', []);
        return self::flag($enabled[$methodId] ?? false);
    }

    public static function flag($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }
        if (!is_string($value)) {
            return false;
        }
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    public static function storeShows(string $methodId, ?array $gateway = null): bool
    {
        if (class_exists('\\WalletUpPro\\Core\\Settings\\SettingsRegistry') && \WalletUpPro\Core\Settings\SettingsRegistry::isAvailabilityV2()) {
            return \WalletUpPro\Core\Payments\Availability\MethodAvailability::state($methodId, 'store') === \WalletUpPro\Core\Payments\Availability\MethodAvailability::SHOWN;
        }
        $gateway = $gateway ?? (array) get_option('woocommerce_wallet_up_settings', []);
        return self::flag($gateway['enable_' . $methodId] ?? 'yes');
    }

    public static function holderFor(string $methodId): string
    {
        return self::resolveHolder(
            $methodId,
            (array) get_option('wallet_up_transfer_call', []),
            (array) get_option('wallet_up_mobile_money_settings', [])
        );
    }

    public static function isReady(string $methodId): bool
    {
        return self::isEnabled($methodId) && self::isConfigured($methodId);
    }

    private static function resolveHolder(string $methodId, array $transferCall, array $mobileMoney): string
    {
        if (in_array($methodId, \WalletUpPro\Core\Config\PaymentMethodsConfig::getMobileMoneyMethods(), true)) {
            return self::mobileMoneyPhone($methodId, $mobileMoney);
        }
        return trim((string) ($transferCall[$methodId . '_holder'] ?? ''));
    }

    public static function mobileMoneyPhone(string $methodId, ?array $settings = null): string
    {
        $settings = $settings ?? (array) get_option('wallet_up_mobile_money_settings', []);
        $row = isset($settings[$methodId]) && is_array($settings[$methodId]) ? $settings[$methodId] : [];
        foreach (['merchant_phone', 'phone_number'] as $key) {
            $phone = trim((string) ($row[$key] ?? ''));
            if ($phone !== '') {
                return $phone;
            }
        }
        return '';
    }
}
