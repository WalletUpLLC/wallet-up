<?php
namespace WalletUpPro\WooCommerce;

use WalletUpPro\Core\BaseSingleton;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class CheckoutSecurityHeaders extends BaseSingleton
{
    protected function initialize(): void
    {
        add_action('template_redirect', [$this, 'modifyCheckoutHeaders'], 9999);
        add_action('send_headers', [$this, 'modifyCheckoutHeaders'], 9999);
    }

    public function modifyCheckoutHeaders(): void
    {
        if (!$this->isCheckoutContext()) {
            return;
        }

        if (self::isStrictCspEnabled()) {
            header_remove('Content-Security-Policy');
            header('Content-Security-Policy: ' . self::strictCspHeader());
        }

        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }

    public static function strictCspHeader(): string
    {
        return implode('; ', self::strictCsp());
    }

    private static function gatewaySetting(string $key, string $default = ''): string
    {
        $settings = get_option('woocommerce_wallet_up_settings', []);
        if (is_array($settings) && isset($settings[$key]) && is_scalar($settings[$key])) {
            return (string) $settings[$key];
        }
        return $default;
    }

    private static function isStrictCspEnabled(): bool
    {
        $stored = self::gatewaySetting('checkout_csp_strict');
        $enabled = ($stored === '1' || $stored === 'yes');
        return (bool) apply_filters('wallet_up_checkout_csp_strict', $enabled);
    }

    private static function strictCsp(): array
    {
        $extra = self::strictExtraDomains();
        $extraStr = $extra === '' ? '' : ' ' . $extra;

        return [
            "default-src 'self' https:",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' "
                . "https://js.stripe.com https://checkout.stripe.com "
                . "https://www.paypal.com https://www.paypalobjects.com https://*.paypal.com "
                . "https://web.squarecdn.com https://sandbox.web.squarecdn.com https://*.squarecdn.com "
                . "https://js.braintreegateway.com https://*.braintreegateway.com "
                . "https://cdn.jsdelivr.net https://cdnjs.cloudflare.com" . $extraStr,
            "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com" . $extraStr,
            "img-src 'self' data: https: blob:",
            "font-src 'self' data: https://fonts.gstatic.com https://cdnjs.cloudflare.com",
            "connect-src 'self' "
                . "https://api.stripe.com https://checkout.stripe.com "
                . "https://*.paypal.com https://*.paypalobjects.com wss://*.paypal.com "
                . "https://connect.squareup.com https://connect.squareupsandbox.com "
                . "https://pci-connect.squareup.com https://pci-connect.squareupsandbox.com "
                . "https://*.squarecdn.com https://*.cash.app "
                . "https://*.braintreegateway.com https://*.braintree-api.com" . $extraStr,
            "frame-src 'self' blob: "
                . "https://js.stripe.com https://checkout.stripe.com https://hooks.stripe.com https://m.stripe.com "
                . "https://*.paypal.com https://*.paypalobjects.com "
                . "https://*.squarecdn.com https://*.cash.app "
                . "https://assets.braintreegateway.com https://*.braintreegateway.com" . $extraStr,
            "object-src 'none'",
            "base-uri 'self'",
        ];
    }

    private static function strictExtraDomains(): string
    {
        $stored = self::gatewaySetting('checkout_csp_domains');
        $stored = (string) apply_filters('wallet_up_checkout_csp_extra_domains', $stored);
        if ($stored === '') {
            return '';
        }
        $tokens = preg_split('/[\s,]+/', $stored, -1, PREG_SPLIT_NO_EMPTY);
        $safe = [];
        foreach ((array) $tokens as $token) {
            if (preg_match('#^https://[A-Za-z0-9.\-*]+(?::\d+)?$#', $token)) {
                $safe[] = $token;
            }
        }
        return implode(' ', array_unique($safe));
    }

    private function isCheckoutContext(): bool
    {
        if (!function_exists('is_checkout') || !function_exists('is_cart')) {
            return false;
        }

        if (is_checkout()) {
            return true;
        }

        if (wp_doing_ajax()) {
            $ajax_actions = [
                'woocommerce_checkout',
                'wallet_up_wc_payment_display',
                'wc_stripe_',
                'paypal_'
            ];
            
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- AJAX action detection for security headers; no data processing.
            $current_action = sanitize_text_field(wp_unslash($_REQUEST['action'] ?? ''));
            foreach ($ajax_actions as $action) {
                if (strpos($current_action, $action) === 0) {
                    return true;
                }
            }
        }

        if (has_block('woocommerce/checkout')) {
            return true;
        }

        return false;
    }
}