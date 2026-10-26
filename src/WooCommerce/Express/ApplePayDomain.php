<?php
namespace WalletUpPro\WooCommerce\Express;

use WalletUpPro\Core\Error\ErrorHandler;
use Exception;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Makes WalletUp Pay's one-tap work with Apple Pay (Google Pay needs none of this).
 *
 * Apple Pay on the web requires the merchant domain to be verified with Apple.
 * Stripe handles the merchant validation, but the domain must be (a) serving the
 * Apple domain-association file at the well-known path and (b) registered on the
 * Stripe account via POST /v1/payment_method_domains. This class does both
 * automatically so the merchant doesn't have to touch the Stripe dashboard:
 *
 *   • Serves /.well-known/apple-developer-merchantid-domain-association from the
 *     bundled Stripe association file (the file is universal — Stripe is the Apple
 *     Pay merchant on the site's behalf, so the same file works for every account).
 *   • Registers the current site domain with Stripe once (cached), whenever Stripe
 *     is configured.
 */
class ApplePayDomain
{
    private const WELL_KNOWN = '/.well-known/apple-developer-merchantid-domain-association';
    private const REGISTERED_OPT = 'wallet_up_applepay_domain_registered';
    private const RETRY_PAUSE = 'wallet_up_applepay_domain_retry';

    public static function init(): void
    {
        add_action('init', [__CLASS__, 'maybeServeAssociationFile'], 0);
        add_action('admin_init', [__CLASS__, 'maybeRegisterDomain']);
        add_action('wp', [__CLASS__, 'maybeRegisterDomain']);
    }

    public static function maybeServeAssociationFile(): void
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH) : '';
        if ($uri !== self::WELL_KNOWN) {
            return;
        }
        $file = WALLET_UP_PATH . 'assets/apple-pay/apple-developer-merchantid-domain-association';
        if (!file_exists($file)) {
            return;
        }
        nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- verbatim Apple association file
        echo file_get_contents($file);
        exit;
    }

    public static function maybeRegisterDomain(): void
    {
        try {
            $domain = wp_parse_url(home_url(), PHP_URL_HOST);
            if (!$domain) {
                return;
            }
            if (get_transient(self::RETRY_PAUSE)) {
                return;
            }
            $status = self::connectStatus(is_admin() && !wp_doing_ajax());
            $connect = !empty($status['connected']) && !empty($status['charges_enabled']) && !empty($status['account_id']);
            $signature = self::registrationSignature($domain, $connect, $status);
            if ($signature === '' || get_option(self::REGISTERED_OPT) === $signature) {
                return;
            }
            set_transient(self::RETRY_PAUSE, 1, HOUR_IN_SECONDS);
            if ($connect) {
                $out = \WalletUpPro\Core\Payments\StripeConnect::registerPaymentMethodDomain($domain);
                if (!is_wp_error($out) && !empty($out['registered'])) {
                    update_option(self::REGISTERED_OPT, $signature, false);
                    delete_transient(self::RETRY_PAUSE);
                }
                return;
            }
            $secret = ExpressStripeHandler::secretKey();
            $response = wp_remote_post('https://api.stripe.com/v1/payment_method_domains', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $secret,
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                ],
                'body'    => ['domain_name' => $domain],
                'timeout' => 20,
            ]);
            if (is_wp_error($response)) {
                return;
            }
            $code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $alreadyExists = isset($body['error']['code']) && $body['error']['code'] === 'resource_already_exists';
            if ($code === 200 || $alreadyExists) {
                update_option(self::REGISTERED_OPT, $signature, false);
                delete_transient(self::RETRY_PAUSE);
            }
        } catch (Exception $e) {
            ErrorHandler::getInstance()->log('Apple Pay domain registration error: ' . $e->getMessage());
        }
    }

    private static function connectStatus(bool $mayFetch): array
    {
        if (!class_exists('\\WalletUpPro\\Core\\Payments\\StripeConnect')) {
            return [];
        }
        return $mayFetch
            ? \WalletUpPro\Core\Payments\StripeConnect::getStatus()
            : \WalletUpPro\Core\Payments\StripeConnect::getCachedStatus();
    }

    public static function registrationSignature(string $domain, bool $connect, array $status = []): string
    {
        if ($connect) {
            $account = (string) ($status['account_id'] ?? '');
            return $account === '' ? '' : implode('|', [$domain, $account, \WalletUpPro\Core\Payments\StripeConnect::mode()]);
        }
        $keys = ExpressStripeHandler::keys();
        return $keys['secret'] === '' ? '' : implode('|', [$domain, 'own', $keys['test'] ? 'test' : 'live']);
    }
}
