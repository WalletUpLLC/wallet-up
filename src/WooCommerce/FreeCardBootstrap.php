<?php

namespace WalletUpPro\WooCommerce;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The one WooCommerce surface the free plugin gets: card payments over Stripe Connect.
 *
 * WooCommerceIntegration stays premium. It registers 48 hooks — thank-you template override,
 * order tracking, the discount engine, per-method gateways, the P2P rails, the WC settings AJAX —
 * and booting all of that for free would ship far more than card payments. Free needs exactly
 * three things: the card gateway, its 3-D Secure completion, and its block-checkout registration.
 *
 * The platform fee is applied by the broker on the Connect path, not here, so free cannot take
 * cards without it. StripeCardGateway::is_available() refuses the manual own-keys path unless
 * premium is active, which is what keeps that true after a licence lapses.
 */
final class FreeCardBootstrap
{
    public static function premiumActive(): bool
    {
        if (!function_exists('\\WalletUpPro\\wallet_up_fs')) {
            return false;
        }
        $fs = \WalletUpPro\wallet_up_fs();
        return method_exists($fs, 'can_use_premium_code__premium_only')
            && (bool) $fs->can_use_premium_code__premium_only();
    }

    public static function init(): void
    {
        if (self::premiumActive() || !class_exists('WooCommerce') || !class_exists('WC_Payment_Gateway')) {
            return;
        }

        add_filter('woocommerce_payment_gateways', [self::class, 'registerGateway']);
        add_filter('woocommerce_available_payment_gateways', [self::class, 'gateMasterDisabled'], 99);
        add_action('woocommerce_blocks_loaded', [self::class, 'registerBlockSupport']);

        add_action('wp_ajax_wallet_up_stripe_confirm_payment', [WooCommerceIntegration::class, 'confirmStripePayment']);
        add_action('wp_ajax_nopriv_wallet_up_stripe_confirm_payment', [WooCommerceIntegration::class, 'confirmStripePayment']);
    }

    public static function registerGateway($gateways): array
    {
        $gateways = is_array($gateways) ? $gateways : [];
        if (class_exists(StripeCardGateway::class)) {
            $gateways[] = StripeCardGateway::class;
        }
        return $gateways;
    }

    public static function gateMasterDisabled($available)
    {
        if (!is_array($available) || WooCommerceIntegration::isMasterEnabled()) {
            return $available;
        }
        foreach (array_keys($available) as $id) {
            if (strpos((string) $id, 'wallet_up') === 0) {
                unset($available[$id]);
            }
        }
        return $available;
    }

    public static function registerBlockSupport(): void
    {
        if (!class_exists('\\Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType')) {
            return;
        }
        add_action('woocommerce_blocks_payment_method_type_registration', static function ($registry) {
            $cls = '\\WalletUpPro\\WooCommerce\\Blocks\\StripeCardBlocksSupport';
            if (!class_exists($cls)) {
                return;
            }
            try {
                $support = new $cls();
                $support->initialize();
                $registry->register($support);
            } catch (\Throwable $e) {
                \WalletUpPro\Core\Error\ErrorHandler::getInstance()
                    ->log('Free card block registration failed: ' . $e->getMessage());
            }
        }, 10);
    }
}
