<?php

namespace WalletUpPro\WooCommerce;

use WalletUpPro\Core\Config\PaymentMethodsConfig;
use WalletUpPro\Core\Payments\Availability\MethodAvailability;
use WalletUpPro\Core\Payments\MethodStatusResolver;
use WalletUpPro\Core\Settings\SettingsRegistry;

final class StoreCheckout
{
    public const MASTER_OPTION = 'wallet_up_woocommerce_settings';
    public const P2P_OPTION = 'woocommerce_wallet_up_settings';
    public const MODE_OPTION = 'wallet_up_wc_display_mode';
    public const MIXED_OPTION = 'wallet_up_wc_mixed_methods';
    public const CARD_GATEWAY = 'wallet_up_card';
    public const P2P_ACTION = 'wallet_up_wc_save_p2p_status';
    public const AUTOMATED = [
        'wallet_up_paypal_commerce' => 'wallet_up_paypal_commerce_settings',
        'wallet_up_cashapp_api' => 'wallet_up_cashapp_api_settings',
        'wallet_up_venmo_api' => 'wallet_up_venmo_api_settings',
    ];

    private static $syncing = false;

    public static function register(): void
    {
        $options = [];
        foreach (array_keys(self::AUTOMATED) as $gatewayId) {
            $options[] = 'woocommerce_' . $gatewayId . '_settings';
        }
        foreach (PaymentMethodsConfig::getAllMethodIds() as $methodId) {
            $options[] = self::methodRowOption((string) $methodId);
        }
        foreach ($options as $option) {
            add_filter('pre_update_option_' . $option, [self::class, 'onRowWrite'], 10, 3);
            add_action('add_option_' . $option, [self::class, 'onRowAdded'], 10, 2);
        }
        add_action('wp_ajax_' . self::P2P_ACTION, [self::class, 'handleP2pStatus']);
    }

    public static function masterOn(): bool
    {
        $s = get_option(self::MASTER_OPTION, []);
        if (!is_array($s)) {
            return false;
        }
        if (!isset($s['enabled'])) {
            return true;
        }
        return MethodStatusResolver::flag($s['enabled']);
    }

    public static function displayMode(): string
    {
        $mode = (string) get_option(self::MODE_OPTION, 'grouped');
        return in_array($mode, ['grouped', 'individual', 'mixed'], true) ? $mode : 'grouped';
    }

    public static function hasGroupedGateway(): bool
    {
        return self::displayMode() !== 'individual';
    }

    public static function p2pOn(): bool
    {
        $s = (array) get_option(self::P2P_OPTION, []);
        return MethodStatusResolver::flag($s['enabled'] ?? 'yes');
    }

    public static function methodOn(string $methodId): bool
    {
        return MethodStatusResolver::storeShows($methodId);
    }

    public static function p2pOffers(): array
    {
        if (!self::masterOn()) {
            return [];
        }
        $snapshot = MethodStatusResolver::snapshot();
        $offers = [];
        foreach ((array) ($snapshot['configured'] ?? []) as $methodId) {
            $methodId = (string) $methodId;
            if (self::methodOn($methodId) && MethodAvailability::shows($methodId, 'store')) {
                $offers[] = $methodId;
            }
        }
        return $offers;
    }

    public static function individualIds(): array
    {
        $mode = self::displayMode();
        if ($mode === 'individual') {
            return self::p2pOffers();
        }
        if ($mode !== 'mixed') {
            return [];
        }
        $mixed = (array) get_option(self::MIXED_OPTION, []);
        return array_values(array_intersect(self::p2pOffers(), array_map('strval', (array) ($mixed['individual'] ?? []))));
    }

    public static function groupedOffers(): array
    {
        if (!self::hasGroupedGateway() || !self::p2pOn()) {
            return [];
        }
        return array_values(array_diff(self::p2pOffers(), self::individualIds()));
    }

    public static function visibleP2p(): array
    {
        return array_values(array_unique(array_merge(self::groupedOffers(), self::individualIds())));
    }

    public static function offersMethod(string $methodId): bool
    {
        return in_array($methodId, self::visibleP2p(), true);
    }

    public static function setMethodOn(string $methodId, bool $on): void
    {
        if (SettingsRegistry::isAvailabilityV2()) {
            MethodAvailability::setShown($methodId, $on, 'store');
            return;
        }
        $s = (array) get_option(self::P2P_OPTION, []);
        $value = $on ? 'yes' : 'no';
        if (($s['enable_' . $methodId] ?? null) === $value) {
            return;
        }
        $s['enable_' . $methodId] = $value;
        self::writeP2p($s);
    }

    public static function setP2pOn(bool $on): void
    {
        $s = (array) get_option(self::P2P_OPTION, []);
        $value = $on ? 'yes' : 'no';
        if (($s['enabled'] ?? null) === $value) {
            return;
        }
        $s['enabled'] = $value;
        self::writeP2p($s);
    }

    public static function mergeP2pSettings(array $values): void
    {
        $s = (array) get_option(self::P2P_OPTION, []);
        $next = array_replace($s, $values);
        if ($next !== $s) {
            self::writeP2p($next);
        }
    }

    private static function writeP2p(array $s): void
    {
        update_option(self::P2P_OPTION, $s);
        if (function_exists('WC') && did_action('woocommerce_loaded') && WC()->payment_gateways()) {
            $gateways = WC()->payment_gateways()->payment_gateways();
            if (isset($gateways['wallet_up'])) {
                $gateways['wallet_up']->settings = $s;
                $gateways['wallet_up']->enabled = MethodStatusResolver::flag($s['enabled'] ?? 'yes') ? 'yes' : 'no';
            }
        }
    }

    public static function automatedOn(string $gatewayId): bool
    {
        if (!isset(self::AUTOMATED[$gatewayId])) {
            return false;
        }
        $s = (array) get_option(self::AUTOMATED[$gatewayId], []);
        return !empty($s['enabled']);
    }

    public static function methodRowOption(string $methodId): string
    {
        return 'woocommerce_wallet_up_' . $methodId . '_settings';
    }

    public static function onRowWrite($value, $old, $option)
    {
        self::onRowUpdated($old, $value, $option);
        return $value;
    }

    public static function onRowAdded($option, $value): void
    {
        self::onRowUpdated(null, $value, $option);
    }

    public static function onRowUpdated($old, $new, $option): void
    {
        if (self::$syncing || !is_array($new) || !array_key_exists('enabled', $new)) {
            return;
        }
        $on = MethodStatusResolver::flag($new['enabled']);
        $gatewayId = substr((string) $option, strlen('woocommerce_'), -strlen('_settings'));
        self::$syncing = true;
        try {
            if (isset(self::AUTOMATED[$gatewayId])) {
                if (self::automatedOn($gatewayId) !== $on) {
                    $s = (array) get_option(self::AUTOMATED[$gatewayId], []);
                    $s['enabled'] = $on;
                    update_option(self::AUTOMATED[$gatewayId], $s);
                }
                return;
            }
            $methodId = substr($gatewayId, strlen('wallet_up_'));
            if ($methodId !== '' && self::methodOn($methodId) !== $on) {
                self::setMethodOn($methodId, $on);
            }
        } finally {
            self::$syncing = false;
        }
    }

    public static function gatewayAvailable(string $gatewayId): bool
    {
        if (!function_exists('WC') || !WC()->payment_gateways()) {
            return false;
        }
        $gateways = WC()->payment_gateways()->payment_gateways();
        if (!isset($gateways[$gatewayId])) {
            return false;
        }
        try {
            return (bool) $gateways[$gatewayId]->is_available();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function summary(): array
    {
        $gateways = [];
        foreach (array_merge([self::CARD_GATEWAY], array_keys(self::AUTOMATED)) as $gatewayId) {
            $gateways[$gatewayId] = self::gatewayAvailable($gatewayId);
        }
        return [
            'master' => self::masterOn(),
            'p2p' => self::p2pOn(),
            'hasGrouped' => self::hasGroupedGateway(),
            'displayMode' => self::displayMode(),
            'p2pAtCheckout' => self::visibleP2p(),
            'gatewaysAtCheckout' => $gateways,
        ];
    }

    public static function handleP2pStatus(): void
    {
        if (!check_ajax_referer('wallet_up_wc_save_settings', 'nonce', false) || !current_user_can('manage_woocommerce')) {
            wp_send_json_error(['code' => 'FORBIDDEN'], 403);
        }
        $on = MethodStatusResolver::flag(sanitize_text_field(wp_unslash($_POST['enabled'] ?? '')));
        self::setP2pOn($on);
        \WalletUpPro\Core\Security\AuditLog::record('woocommerce_p2p_status', 'enabled=' . ($on ? 'yes' : 'no'));
        wp_send_json_success(['storeCheckout' => self::summary()]);
    }
}
