<?php
namespace WalletUpPro\WooCommerce\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use WalletUpPro\Core\Error\ErrorHandler;
use WalletUpPro\Core\Payments\MethodStatusResolver;
use WalletUpPro\WooCommerce\Express\ExpressMethodRanking;
use WalletUpPro\WooCommerce\WooCommerceIntegration;
use Exception;

if (!defined('ABSPATH')) {
    exit;
}

final class WalletUpPayBlocksSupport extends AbstractPaymentMethodType
{
    protected $name = 'wallet_up_pay';

    private $errorHandler;

    public function __construct()
    {
        $this->errorHandler = ErrorHandler::getInstance();
    }

    public function initialize()
    {
        $this->settings = get_option('wallet_up_woocommerce_settings', []);
    }

    public function is_active()
    {
        try {
            if (!WooCommerceIntegration::isMasterEnabled()) {
                return false;
            }
            if (!self::isExpressEnabled()) {
                return false;
            }
            $snapshot = MethodStatusResolver::snapshot();
            $hasStripe = self::stripeAvailable();
            $hasMethods = !empty($snapshot['liveAtCheckout']) || !empty($snapshot['configured']);
            return $hasStripe || $hasMethods;
        } catch (Exception $e) {
            $this->errorHandler->log('WalletUp Pay is_active error: ' . $e->getMessage());
            return false;
        }
    }

    public function get_payment_method_script_handles()
    {
        $handle = 'wc-walletup-pay-express';
        $asset  = $this->getScriptAsset();
        if (!$asset) {
            return [];
        }
        wp_register_script($handle, WALLET_UP_URL . $asset['path'], $asset['dependencies'], $asset['version'], true);
        return [$handle];
    }

    public function get_payment_method_data()
    {
        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '';
        $device   = self::detectDeviceType();

        $ranked = ExpressMethodRanking::topMethods(3, [
            'device_type' => $device,
            'currency'    => $currency,
        ]);

        $display_mode    = get_option('wallet_up_wc_display_mode', 'grouped');
        $mixed_individual = [];
        if ($display_mode === 'mixed') {
            $mixed_config = get_option('wallet_up_wc_mixed_methods', []);
            $mixed_individual = $mixed_config['individual'] ?? [];
        }
        $methods = [];
        foreach ($ranked as $id => $m) {
            $isIndividual = ($display_mode === 'individual')
                || ($display_mode === 'mixed' && in_array($id, $mixed_individual, true));
            $m['targetGateway'] = $isIndividual ? 'wallet_up_' . $id : 'wallet_up';
            $m['subMethod']     = $isIndividual ? '' : $id;
            $methods[$id] = $m;
        }

        return [
            'title'        => __('WalletUp Pay', 'walletup'),
            'brandColor'   => '#674FBF',
            'methods'      => array_values($methods),
            'stripe'       => [
                'available'      => self::stripeAvailable(),
                'oneTapEligible' => self::stripeAvailable() && !self::cartNeedsShipping(),
                'publishableKey' => self::stripePublishableKey(),
                'connectedAccount' => self::connectedAccountId(),
                'cardGateway'    => 'wallet_up_card',
                'country'        => function_exists('wc_get_base_location') ? (wc_get_base_location()['country'] ?? 'US') : 'US',
            ],
            'currency'     => $currency,
            'checkoutUrl'  => function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : '',
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'nonces'       => [
                'wcPaymentDisplay' => wp_create_nonce('wallet_up_wc_payment_display'),
                'tracking'         => wp_create_nonce('wallet_up_admin_nonce'),
                'express'          => wp_create_nonce('wallet_up_pay_express'),
            ],
            'i18n'         => [
                'buttonLabel'    => __('WalletUp Pay', 'walletup'),
                'tagline'        => __('Fast checkout', 'walletup'),
                'drawerTitle'    => __('WalletUp Pay', 'walletup'),
                'bestForYou'     => __('Best for you', 'walletup'),
                'moreMethods'    => __('More payment methods', 'walletup'),
                'payWith'        => __('Pay with %s', 'walletup'),
                'close'          => __('Close', 'walletup'),
                'oneTap'         => __('Pay in one tap', 'walletup'),
                'continue'       => __('Continue', 'walletup'),
                'noMethods'      => __('No payment methods are available right now.', 'walletup'),
                'processing'     => __('Processing…', 'walletup'),
                'ariaLabel'      => __('Pay quickly with WalletUp', 'walletup'),
                'oneTapFailed'   => __('Payment could not be completed. Please try another method.', 'walletup'),
                'totalLabel'     => __('Total', 'walletup'),
            ],
        ];
    }

    public function get_supported_features()
    {
        return ['products'];
    }

    public static function isExpressEnabled(): bool
    {
        $s = get_option('wallet_up_woocommerce_settings', []);
        if (!is_array($s) || !isset($s['express_enabled'])) {
            return true;
        }
        return $s['express_enabled'] === 'yes' || $s['express_enabled'] === true
            || $s['express_enabled'] === 1 || $s['express_enabled'] === '1';
    }

    /** True when the current cart requires a shipping address (one-tap is gated off then). */
    private static function cartNeedsShipping(): bool
    {
        if (!function_exists('WC') || !WC()->cart) {
            return false;
        }
        try {
            return (bool) WC()->cart->needs_shipping();
        } catch (\Throwable $e) {
            return true; // be safe — if unsure, don't offer one-tap
        }
    }

    private static function stripeAvailable(): bool
    {
        $card = get_option('woocommerce_wallet_up_card_settings', []);
        if (($card['enabled'] ?? 'no') !== 'yes') {
            return false;
        }
        return self::stripePublishableKey() !== '';
    }

    private static function stripePublishableKey(): string
    {
        if (class_exists('\\WalletUpPro\\Core\\Payments\\StripeConnect')
            && \WalletUpPro\Core\Payments\StripeConnect::isActive()) {
            $s = \WalletUpPro\Core\Payments\StripeConnect::getStatus();
            if (!empty($s['publishable_key'])) {
                return (string) $s['publishable_key'];
            }
        }
        return class_exists('\\WalletUpPro\\WooCommerce\\Express\\ExpressStripeHandler')
            ? \WalletUpPro\WooCommerce\Express\ExpressStripeHandler::keys()['publishable']
            : '';
    }

    private static function connectedAccountId(): string
    {
        if (class_exists('\\WalletUpPro\\Core\\Payments\\StripeConnect')
            && \WalletUpPro\Core\Payments\StripeConnect::isActive()) {
            $s = \WalletUpPro\Core\Payments\StripeConnect::getStatus();
            return (string) ($s['account_id'] ?? '');
        }
        return '';
    }

    private static function detectDeviceType(): string
    {
        $ua = strtolower(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')));
        if ($ua === '') {
            return 'desktop';
        }
        if (preg_match('/ipad|tablet|(android(?!.*mobile))/', $ua)) {
            return 'tablet';
        }
        if (preg_match('/mobi|iphone|ipod|android.*mobile|blackberry|windows phone/', $ua)) {
            return 'mobile';
        }
        return 'desktop';
    }

    private function getScriptAsset(): ?array
    {
        try {
            $manifest_path = WALLET_UP_PATH . 'walup-react/dist/.vite/manifest.json';
            if (!file_exists($manifest_path)) {
                return null;
            }
            $manifest = json_decode(file_get_contents($manifest_path), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return null;
            }
            $entry = $manifest['src/walletUpPay.js'] ?? null;
            if (!$entry || empty($entry['file'])) {
                return null;
            }
            $file = $entry['file'];
            $relative = (strpos($file, 'assets/') === 0)
                ? 'walup-react/dist/' . $file
                : 'walup-react/dist/assets/' . $file;
            return [
                'path' => $relative,
                'dependencies' => [
                    'wp-element', 'wp-i18n', 'wc-blocks-registry', 'wc-blocks-checkout',
                    'wc-settings', 'wp-data', 'wp-components',
                ],
                'version' => WALLET_UP_VERSION,
            ];
        } catch (Exception $e) {
            $this->errorHandler->log('WalletUp Pay script asset error: ' . $e->getMessage());
            return null;
        }
    }
}
