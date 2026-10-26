<?php
declare(strict_types=1);

namespace WalletUpPro\Core\Retention;

if (!defined('ABSPATH')) {
    exit;
}

final class SetupCheck
{
    public const UNCONFIGURED = 'unconfigured';
    public const INCOMPLETE   = 'incomplete';
    public const ENVIRONMENT  = 'environment';
    public const READY        = 'ready';

    private const PHP_MINIMUM = '8.1';

    private const PAGE_PAYMENTS = 'wallet-up-payments';

    private const GATEWAY_OPTIONS = [
        'wallet_up_stripe_config',
        'wallet_up_paypal_commerce_settings',
        'wallet_up_venmo_api_settings',
        'wallet_up_cashapp_api_settings',
    ];

    public static function run(): array
    {
        if (version_compare(PHP_VERSION, self::PHP_MINIMUM, '<')) {
            return self::finding(
                self::ENVIRONMENT,
                sprintf(
                    /* translators: 1: current PHP version, 2: required PHP version */
                    __('This site runs PHP %1$s. Wallet Up needs PHP %2$s or newer, which is why it could not start.', 'walletup'),
                    PHP_VERSION,
                    self::PHP_MINIMUM
                ),
                ''
            );
        }

        $enabled = self::enabledMethods();
        $apis    = self::readyAutomatedApis();
        $card    = self::cardStatus(isset($apis['stripe']));

        if ($enabled === [] && empty($card['enabled']) && $apis === []) {
            return self::finding(
                self::UNCONFIGURED,
                __('No payment method is switched on yet, so nothing would have appeared on your site. Switching one on takes about a minute.', 'walletup'),
                'wallet-up-onboarding'
            );
        }

        if (self::needsWooCommerce() && !self::wooCommerceActive()) {
            return self::finding(
                self::ENVIRONMENT,
                __('A WooCommerce payment method is switched on, but WooCommerce is not active on this site.', 'walletup'),
                self::PAGE_PAYMENTS
            );
        }

        if (!empty($card['enabled']) && empty($card['ready'])) {
            $cardText = ($card['reason'] ?? '') === 'mode_key_mismatch'
                ? __('Card payments are switched on, but the saved keys do not match the selected test or live mode, so cards may not appear. Open Payments and set the mode to match your keys.', 'walletup')
                : __('Card payments are switched on, but no card keys are saved yet, so cards could not appear.', 'walletup');
            return self::finding(self::INCOMPLETE, $cardText, self::PAGE_PAYMENTS);
        }

        if ($enabled !== [] && !self::hasAnyCredential($enabled) && $apis === []) {
            return self::finding(
                self::INCOMPLETE,
                sprintf(
                    /* translators: %d: number of payment methods switched on */
                    _n(
                        '%d payment method is switched on, but none has account details saved yet, so it had nothing to show.',
                        '%d payment methods are switched on, but none has account details saved yet, so they had nothing to show.',
                        count($enabled),
                        'walletup'
                    ),
                    count($enabled)
                ),
                self::PAGE_PAYMENTS
            );
        }

        return self::finding(
            self::READY,
            __('Your setup looks complete from here, so this is worth a look by a person. Send us what happened and we will work it out.', 'walletup'),
            ''
        );
    }

    public static function enabledMethods(): array
    {
        $out = [];
        foreach ((array) get_option('wallet_up_enabled_methods', []) as $method => $value) {
            if (!empty($value) && $value !== 'false' && $value !== '0') {
                $out[] = (string) $method;
            }
        }
        return $out;
    }

    private static function readyAutomatedApis(): array
    {
        if (!class_exists('\\WalletUpPro\\Core\\Config\\AutomatedApiConfig')) {
            return [];
        }
        return \WalletUpPro\Core\Config\AutomatedApiConfig::getEnabledApis(true);
    }

    private static function cardStatus(bool $alreadyReady): array
    {
        if ($alreadyReady) {
            return ['enabled' => true, 'ready' => true, 'reason' => ''];
        }
        if (!class_exists('\\WalletUpPro\\Core\\Config\\AutomatedApiConfig')) {
            return ['enabled' => false, 'ready' => false, 'reason' => ''];
        }
        $s = \WalletUpPro\Core\Config\AutomatedApiConfig::stripe(true);
        return [
            'enabled' => !empty($s['enabled']),
            'ready'   => !empty($s['ready']),
            'reason'  => (string) ($s['reason'] ?? ''),
        ];
    }

    private static function hasAnyCredential(array $enabled): bool
    {
        $transfer = (array) get_option('wallet_up_transfer_call', []);
        foreach ($enabled as $method) {
            if (trim((string) ($transfer[$method . '_holder'] ?? '')) !== '') {
                return true;
            }
        }
        foreach (self::GATEWAY_OPTIONS as $option) {
            foreach ((array) get_option($option, []) as $key => $value) {
                if (is_scalar($value) && trim((string) $value) !== '' && str_contains((string) $key, 'key')) {
                    return true;
                }
                if (is_scalar($value) && trim((string) $value) !== '' && str_contains((string) $key, 'secret')) {
                    return true;
                }
            }
        }
        return (array) get_option('wallet_up_mobile_money_settings', []) !== [];
    }

    private static function needsWooCommerce(): bool
    {
        return (array) get_option('wallet_up_wc_settings', []) !== []
            || get_option('wallet_up_wc_display_mode', '') !== '';
    }

    private static function wooCommerceActive(): bool
    {
        return class_exists('WooCommerce');
    }

    private static function finding(string $state, string $text, string $page): array
    {
        return ['state' => $state, 'text' => $text, 'page' => $page];
    }
}
