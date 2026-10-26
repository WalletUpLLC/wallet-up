<?php
namespace WalletUpPro\Core\Config;

if (!defined('ABSPATH')) {
    exit;
}

class AutomatedApiConfig
{
    public const STRIPE          = 'stripe';
    public const PAYPAL_COMMERCE = 'paypal_commerce';
    public const CASHAPP_API     = 'cashapp_api';
    public const VENMO_API       = 'venmo_api';

    private static function providers(): array
    {
        return [
            self::STRIPE => [
                'name'    => 'Card',
                'resolve' => [__CLASS__, 'resolveStripe'],
            ],
            self::PAYPAL_COMMERCE => [
                'name'    => 'PayPal Commerce',
                'resolve' => [__CLASS__, 'resolvePayPalCommerce'],
            ],
            self::VENMO_API => [
                'name'    => 'Venmo (Automatic)',
                'resolve' => [__CLASS__, 'resolveVenmoApi'],
            ],
        ];
    }

    public static function getAllKeys(): array
    {
        return array_keys(self::providers());
    }

    public static function getCatalog(): array
    {
        $enabled = self::getEnabledApis();
        $out = [];
        foreach (self::providers() as $key => $def) {
            $out[$key] = [
                'name'       => $def['name'],
                'configured' => isset($enabled[$key]),
            ];
        }
        return $out;
    }

    public static function isAutomated(string $key): bool
    {
        return in_array($key, self::getAllKeys(), true);
    }

    public static function getEnabledApis(bool $cacheOnly = false): array
    {
        $out = [];
        foreach (self::providers() as $key => $def) {
            $resolved = ($key === self::STRIPE)
                ? self::resolveStripe($cacheOnly)
                : call_user_func($def['resolve']);
            if (empty($resolved['ready'])) {
                continue;
            }
            $out[$key] = [
                'name'     => $def['name'],
                'provider' => $key,
                'ready'    => true,
                'config'   => $resolved['config'] ?? [],
            ];
        }
        return $out;
    }

    public static function getPublicConfig(): array
    {
        $out = [];
        foreach (self::getEnabledApis() as $key => $api) {
            $out[$key] = $api['config'];
        }
        return $out;
    }

    private static function resolveStripe(bool $cacheOnly = false): array
    {
        $wc = get_option('woocommerce_wallet_up_card_settings', []);
        $wcEnabled = is_array($wc) && ($wc['enabled'] ?? 'no') === 'yes';
        $standalone = get_option('wallet_up_stripe_config', []);
        $standaloneEnabled = is_array($standalone) && !empty($standalone['enabled']);

        $connect = self::getStripeConnectStatus($cacheOnly);
        $connectActive = !empty($connect['active']) && $connect['publishable_key'] !== '';

        if (!$wcEnabled && !$standaloneEnabled && !$connectActive) {
            return ['ready' => false, 'enabled' => false, 'reason' => 'disabled'];
        }

        if ($connectActive) {
            $connectLive = !empty($connect['live']);
            return [
                'ready'   => true,
                'enabled' => true,
                'reason'  => '',
                'config'  => [
                    'publishable_key'   => $connect['publishable_key'],
                    'connected_account' => $connect['account_id'],
                    'connect'           => true,
                    'test_mode'         => !$connectLive,
                    'mode'              => $connectLive ? 'live' : 'test',
                ],
            ];
        }

        if (!class_exists('\\WalletUpPro\\Core\\Payments\\PaymentGatewayHandler')) {
            return ['ready' => false, 'enabled' => true, 'reason' => 'unavailable'];
        }
        $testMode    = self::stripeTestMode();
        $pair        = self::stripeKeyPair($testMode);
        $publishable = $pair['publishable'];
        if ($publishable === '') {
            $reason = self::stripeKeyPair(!$testMode)['publishable'] !== '' ? 'mode_key_mismatch' : 'no_keys';
            return ['ready' => false, 'enabled' => true, 'reason' => $reason];
        }
        return [
            'ready'   => true,
            'enabled' => true,
            'reason'  => '',
            'config'  => [
                'publishable_key'   => $publishable,
                'connected_account' => '',
                'connect'           => false,
                'test_mode'         => $testMode,
                'mode'              => $testMode ? 'test' : 'live',
            ],
        ];
    }

    public static function stripe(bool $cacheOnly = false): array
    {
        return self::resolveStripe($cacheOnly);
    }

    public static function stripeKeyPair(?bool $test = null): array
    {
        $test = $test ?? self::stripeTestMode();
        $none = ['publishable' => '', 'secret' => '', 'test' => $test];
        if (!class_exists('\\WalletUpPro\\Core\\Payments\\PaymentGatewayHandler')) {
            return $none;
        }
        $cfg = \WalletUpPro\Core\Payments\PaymentGatewayHandler::getStripeConfig();
        $mode = $test ? 'test' : 'live';
        $publishable = (string) ($cfg[$mode . '_publishable_key'] ?? '');
        $secret = (string) ($cfg[$mode . '_secret_key'] ?? '');
        if (!self::stripeKeyIs($publishable, ['pk'], $mode) || !self::stripeKeyIs($secret, ['sk', 'rk'], $mode)) {
            return $none;
        }
        return ['publishable' => $publishable, 'secret' => $secret, 'test' => $test];
    }

    public static function stripeKeyMode(string $key): string
    {
        return preg_match('/^(pk|sk|rk)_(test|live)_[A-Za-z0-9]+$/', $key, $m) ? $m[2] : '';
    }

    public static function migrateLegacyCardKeys(): void
    {
        $card = get_option('woocommerce_wallet_up_card_settings', []);
        if (!is_array($card) || (empty($card['publishable_key']) && empty($card['secret_key']))) {
            return;
        }
        if (!class_exists('\\WalletUpPro\\Core\\Payments\\PaymentGatewayHandler')) {
            return;
        }
        $publishable = (string) ($card['publishable_key'] ?? '');
        $secret = (string) ($card['secret_key'] ?? '');
        if (str_starts_with($secret, 'walletup_enc:')) {
            $plain = \WalletUpPro\Core\Security\DataEncryption::getInstance()->decrypt(substr($secret, strlen('walletup_enc:')));
            $secret = ($plain !== false && $plain !== '') ? (string) $plain : '';
        }
        $mode = self::stripeKeyMode($publishable);
        if ($mode === '' || self::stripeKeyMode($secret) !== $mode) {
            return;
        }
        if (self::stripeKeyPair($mode === 'test')['secret'] === '') {
            \WalletUpPro\Core\Payments\PaymentGatewayHandler::updateStripeConfig([
                $mode . '_publishable_key' => $publishable,
                $mode . '_secret_key'      => $secret,
            ]);
        }
        unset($card['publishable_key'], $card['secret_key']);
        update_option('woocommerce_wallet_up_card_settings', $card);
    }

    private static function stripeKeyIs(string $key, array $types, string $mode): bool
    {
        return (bool) preg_match('/^(' . implode('|', $types) . ')_' . $mode . '_[A-Za-z0-9]+$/', $key);
    }

    public static function stripeTestMode(): bool
    {
        $cfg = get_option('wallet_up_stripe_config', []);
        if (is_array($cfg) && array_key_exists('test_mode', $cfg)) {
            return !empty($cfg['test_mode']);
        }
        $wc = get_option('woocommerce_wallet_up_card_settings', []);
        if (is_array($wc) && array_key_exists('test_mode', $wc)) {
            return ($wc['test_mode'] ?? '') === 'yes';
        }
        return true;
    }

    public static function setStripeTestMode(bool $test): void
    {
        $cfg = get_option('wallet_up_stripe_config', []);
        if (!is_array($cfg)) {
            $cfg = [];
        }
        $cfg['test_mode'] = $test;
        update_option('wallet_up_stripe_config', $cfg);
    }

    public static function getStripeConnectStatus(bool $cacheOnly = false): array
    {
        $out = ['active' => false, 'account_id' => '', 'publishable_key' => '', 'live' => true];
        if (!class_exists('\\WalletUpPro\\Core\\Payments\\StripeConnect')) {
            return $out;
        }
        try {
            $s = $cacheOnly
                ? \WalletUpPro\Core\Payments\StripeConnect::getCachedStatus()
                : \WalletUpPro\Core\Payments\StripeConnect::getStatus();
        } catch (\Throwable $e) {
            return $out;
        }
        $out['account_id']      = (string) ($s['account_id'] ?? '');
        $out['publishable_key'] = (string) ($s['publishable_key'] ?? '');
        $out['live'] = strncmp($out['publishable_key'], 'pk_test_', 8) !== 0;
        $out['active'] = !empty($s['connected']) && !empty($s['charges_enabled']) && $out['account_id'] !== '';
        return $out;
    }

    private static function resolvePayPalCommerce(): array
    {
        $creds = self::getPayPalCommerceCredentials();
        if (($creds['client_id'] ?? '') === '') {
            return ['ready' => false];
        }
        return [
            'ready'  => true,
            'config' => [
                'client_id' => $creds['client_id'],
            ],
        ];
    }

    public static function getPayPalCommerceCredentials(): array
    {
        $commerce = get_option('wallet_up_paypal_commerce_settings', []);
        if (is_array($commerce) && !empty($commerce)) {
            $mode = in_array(($commerce['mode'] ?? 'sandbox'), ['sandbox', 'live'], true)
                ? $commerce['mode'] : 'sandbox';
            $clientId = self::decryptField((string) ($mode === 'live'
                ? ($commerce['live_client_id'] ?? '')
                : ($commerce['sandbox_client_id'] ?? '')));
            $secret = self::decryptField((string) ($mode === 'live'
                ? ($commerce['live_secret'] ?? '')
                : ($commerce['sandbox_secret'] ?? '')));
            if ($clientId === '') {
                $clientId = self::decryptField((string) ($mode === 'live'
                    ? ($commerce['sandbox_client_id'] ?? '')
                    : ($commerce['live_client_id'] ?? '')));
            }
            if ($clientId !== '') {
                return [
                    'client_id'     => $clientId,
                    'client_secret' => $secret,
                    'sandbox'       => $mode === 'sandbox',
                ];
            }
        }

        if (class_exists('\\WalletUpPro\\Core\\Payments\\PaymentGatewayHandler')) {
            $cfg = \WalletUpPro\Core\Payments\PaymentGatewayHandler::getPayPalConfig();
            if (!empty($cfg['client_id'])) {
                return [
                    'client_id'     => (string) $cfg['client_id'],
                    'client_secret' => (string) ($cfg['client_secret'] ?? ''),
                    'sandbox'       => !empty($cfg['sandbox_mode']),
                ];
            }
        }

        return ['client_id' => '', 'client_secret' => '', 'sandbox' => true];
    }

    private static function decryptField(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (!class_exists('\\WalletUpPro\\Core\\Security\\DataEncryption')) {
            return $value;
        }
        $enc = \WalletUpPro\Core\Security\DataEncryption::getInstance();
        if (str_starts_with($value, 'walletup_enc:')) {
            try {
                $decrypted = $enc->decrypt(substr($value, strlen('walletup_enc:')));
                return ($decrypted !== false && $decrypted !== '') ? $decrypted : '';
            } catch (\Exception $e) {
                return '';
            }
        }
        if (preg_match('#[+/=]#', $value) || strlen($value) > 100) {
            try {
                $decrypted = $enc->decrypt($value);
                if ($decrypted !== false && $decrypted !== '') {
                    return $decrypted;
                }
            } catch (\Exception $e) {
            }
        }
        return $value;
    }

    private static function resolveCashAppApi(): array
    {
        if (!class_exists('\\WalletUpPro\\Payments\\CashAppPayAPI')) {
            return ['ready' => false];
        }
        $settings = get_option('wallet_up_cashapp_api_settings', []);
        if (empty($settings['enabled'])) {
            return ['ready' => false];
        }
        $api = \WalletUpPro\Payments\CashAppPayAPI::getInstance();
        if (!$api->isConfigured()) {
            return ['ready' => false];
        }
        return [
            'ready'  => true,
            'config' => [
                'sandbox' => $api->isSandbox(),
            ],
        ];
    }

    private static function resolveVenmoApi(): array
    {
        if (!class_exists('\\WalletUpPro\\Payments\\VenmoBraintreeAPI')) {
            return ['ready' => false];
        }
        $settings = get_option('wallet_up_venmo_api_settings', []);
        if (empty($settings['enabled'])) {
            return ['ready' => false];
        }
        $api = \WalletUpPro\Payments\VenmoBraintreeAPI::getInstance();
        if (!$api->isConfigured()) {
            return ['ready' => false];
        }
        return [
            'ready'  => true,
            'config' => [
                'sandbox' => $api->isSandbox(),
            ],
        ];
    }
}
