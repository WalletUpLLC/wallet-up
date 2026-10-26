<?php

namespace WalletUpPro\Core\Payments;

if (!defined('ABSPATH')) {
    exit;
}

class StripeConnect
{
    private const STATUS_CACHE_KEY = 'wallet_up_stripe_connect_status';
    private const STATUS_CACHE_TTL = 600; // 10 minutes

    public static function register(): void
    {
        add_action('wp_ajax_wallet_up_stripe_connect_start', [self::class, 'handleStart']);
        add_action('wp_ajax_wallet_up_stripe_connect_status', [self::class, 'handleStatus']);
        add_action('wp_ajax_wallet_up_platform_fees', [self::class, 'handlePlatformFees']);
        add_action('wp_ajax_wallet_up_card_gateway_toggle', [self::class, 'handleCardGatewayToggle']);
        add_action('admin_init', [self::class, 'maybeBustCacheOnReturn']);
    }

    public static function maybeBustCacheOnReturn(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag set by Stripe's hosted return URL; no state change here.
        if (isset($_GET['wu_stripe']) && in_array($_GET['wu_stripe'], ['connected', 'refresh'], true)) {
            delete_transient(self::STATUS_CACHE_KEY . '_live');
            delete_transient(self::STATUS_CACHE_KEY . '_test');
            delete_transient(self::STATUS_CACHE_KEY); // legacy key from before per-mode caching
        }
    }

    private static function backendUrl(): string
    {
        return MerchantProvisioning::brokerUrl();
    }

    public static function mode(): string
    {
        if (class_exists('\\WalletUpPro\\Core\\Config\\AutomatedApiConfig')) {
            return \WalletUpPro\Core\Config\AutomatedApiConfig::stripeTestMode() ? 'test' : 'live';
        }
        $card = get_option('woocommerce_wallet_up_card_settings', []);
        return (is_array($card) && ($card['test_mode'] ?? '') === 'yes') ? 'test' : 'live';
    }

    private static function ensureCap(): void
    {
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'walletup')], 403);
        }
    }

    public static function handleStart(): void
    {
        self::ensureCap();
        if (!check_ajax_referer('wallet_up_stripe_connect', 'nonce', false)) {
            wp_send_json_error(['message' => __('Invalid security token', 'walletup')], 400);
        }
        if (function_exists('\WalletUpPro\wallet_up_is_preview_sandbox') && \WalletUpPro\wallet_up_is_preview_sandbox()) {
            wp_send_json_error([
                'message' => __('This is a preview, so card setup is turned off here. Install Wallet Up on your own WordPress site and one click connects Stripe.', 'walletup'),
                'preview' => true,
                'soft' => true,
            ], 200);
        }
        $apiKey = MerchantProvisioning::getApiKey();
        $refusal = ['message' => ''];
        if ($apiKey === '') {
            $provisioned = MerchantProvisioning::getInstance()->provision();
            if (!empty($provisioned['success'])) {
                $apiKey = MerchantProvisioning::getApiKey();
            } else {
                $refusal = MerchantProvisioning::refusalPayload($provisioned);
            }
        }
        if ($apiKey === '') {
            if ($refusal['message'] === '') {
                $refusal['message'] = __('Card payments need an active Wallet Up account on this site. Finish setup, then come back and connect Stripe.', 'walletup');
            }
            wp_send_json_error($refusal + ['soft' => true], 200);
        }
        $returnPage = sanitize_key(wp_unslash($_POST['return_page'] ?? ''));
        $allowedReturn = ['wallet-up', 'wallet-up-onboarding', 'wallet-up-payments'];
        $returnSlug = in_array($returnPage, $allowedReturn, true) ? $returnPage : 'wallet-up';
        $returnTo = add_query_arg('wu_stripe', 'connected', admin_url('admin.php?page=' . $returnSlug));

        $resp = wp_remote_post(self::backendUrl() . '/api/merchant/connect/stripe/start', [
            'headers' => ['X-API-Key' => $apiKey, 'Content-Type' => 'application/json'],
            'body' => wp_json_encode(['return_to' => $returnTo]),
            'timeout' => 25,
        ]);
        if (is_wp_error($resp)) {
            wp_send_json_error(['message' => \WalletUpPro\Core\Payments\BackendErrors::forCustomer($resp, 'connect start')], 502);
        }
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if (empty($body['success']) || empty($body['data']['url'])) {
            wp_send_json_error(['message' => $body['error'] ?? __('Could not start the Stripe connection.', 'walletup')], 502);
        }
        wp_send_json_success([
            'url' => $body['data']['url'],
            'account_id' => $body['data']['account_id'] ?? '',
        ]);
    }

    public static function handleStatus(): void
    {
        self::ensureCap();
        $data = self::getStatus(true);
        $data['card_gateway_enabled'] = self::cardGatewayEnabled();
        $data['is_preview'] = function_exists('\WalletUpPro\wallet_up_is_preview_sandbox') && \WalletUpPro\wallet_up_is_preview_sandbox();
        wp_send_json_success($data);
    }

    public static function cardGatewayEnabled(): bool
    {
        $card = get_option('woocommerce_wallet_up_card_settings', []);
        return is_array($card) && ($card['enabled'] ?? 'no') === 'yes';
    }

    public static function handleCardGatewayToggle(): void
    {
        self::ensureCap();
        if (!check_ajax_referer('wallet_up_stripe_connect', 'nonce', false)) {
            wp_send_json_error(['message' => __('Invalid security token', 'walletup')], 400);
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above
        $on = isset($_POST['enabled']) && in_array((string) wp_unslash($_POST['enabled']), ['1', 'yes', 'true'], true);

        $card = get_option('woocommerce_wallet_up_card_settings', []);
        if (!is_array($card)) {
            $card = [];
        }
        $card['enabled'] = $on ? 'yes' : 'no';
        update_option('woocommerce_wallet_up_card_settings', $card);

        wp_send_json_success(['card_gateway_enabled' => $on]);
    }

    /**
     * Cached connection status for use on hot paths (gateway availability, checkout enqueue).
     * The backend /status endpoint does a live Stripe sync, so we cache the result in a
     * transient and only force a refresh when an admin explicitly views the Connect card.
     *
     * @return array{connected:bool,account_id?:string,charges_enabled?:bool,publishable_key?:string,status?:string,livemode?:bool}
     */
    public static function getStatus(bool $force = false, ?string $mode = null): array
    {
        $mode = ($mode === 'test' || $mode === 'live') ? $mode : self::mode();
        $cacheKey = self::STATUS_CACHE_KEY . '_' . $mode; // separate live/test caches
        if (!$force) {
            $cached = get_transient($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }
        $apiKey = MerchantProvisioning::getApiKey();
        if ($apiKey === '') {
            $data = ['connected' => false];
            set_transient($cacheKey, $data, self::STATUS_CACHE_TTL);
            return $data;
        }
        $url = self::backendUrl() . '/api/merchant/connect/stripe/status'
            . ($mode === 'test' ? '?mode=test' : '');
        $resp = wp_remote_get($url, [
            'headers' => ['X-API-Key' => $apiKey],
            'timeout' => 20,
        ]);
        if (is_wp_error($resp)) {
            $cached = get_transient($cacheKey);
            return is_array($cached) ? $cached : ['connected' => false];
        }
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        $data = (is_array($body) && !empty($body['data'])) ? $body['data'] : ['connected' => false];
        set_transient($cacheKey, $data, self::STATUS_CACHE_TTL);
        return $data;
    }

    public static function getCachedStatus(?string $mode = null): array
    {
        $mode = ($mode === 'test' || $mode === 'live') ? $mode : self::mode();
        $cached = get_transient(self::STATUS_CACHE_KEY . '_' . $mode);
        return is_array($cached) ? $cached : ['connected' => false];
    }

    public static function getPlatformFees(int $limit = 25): array
    {
        $apiKey = MerchantProvisioning::getApiKey();
        if ($apiKey === '') {
            return ['fees' => [], 'terms' => null, 'totals' => null];
        }
        $resp = wp_remote_get(
            self::backendUrl() . '/api/merchant/connect/stripe/platform-fees?limit=' . max(1, min(100, $limit)),
            ['headers' => ['X-API-Key' => $apiKey], 'timeout' => 20]
        );
        if (is_wp_error($resp)) {
            return ['fees' => [], 'terms' => null, 'totals' => null];
        }
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        return (is_array($body) && !empty($body['data']))
            ? $body['data']
            : ['fees' => [], 'terms' => null, 'totals' => null];
    }

    public static function handlePlatformFees(): void
    {
        if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false) || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'walletup')], 403);
        }
        wp_send_json_success(self::getPlatformFees(25));
    }

    public static function isActive(): bool
    {
        $s = self::getStatus();
        return !empty($s['connected']) && !empty($s['charges_enabled']) && !empty($s['account_id']);
    }

    public static function isLive(): bool
    {
        $s = self::getStatus();
        $pk = (string) ($s['publishable_key'] ?? '');
        return strncmp($pk, 'pk_test_', 8) !== 0; // live unless the broker serves a test key
    }

    public static function getMode(): string
    {
        return self::isLive() ? 'live' : 'test';
    }

    /**
     * Create + confirm a card charge on the merchant's connected account, via the backend
     * broker (platform key + Stripe-Account header). Returns the decoded data array on
     * success or a WP_Error the gateway can surface to the buyer.
     *
     * @return array|\WP_Error
     */
    public static function registerPaymentMethodDomain(string $domain)
    {
        $apiKey = MerchantProvisioning::getApiKey();
        if ($apiKey === '') {
            return new \WP_Error('wu_no_account', __('Payments account is not connected.', 'walletup'));
        }
        $resp = wp_remote_post(self::backendUrl() . '/api/merchant/connect/stripe/payment-method-domain', [
            'headers' => ['X-API-Key' => $apiKey, 'Content-Type' => 'application/json'],
            'body' => wp_json_encode(['domain' => $domain, 'mode' => self::mode()]),
            'timeout' => 30,
        ]);
        if (is_wp_error($resp)) {
            return $resp;
        }
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if (empty($body['success']) || empty($body['data'])) {
            return new \WP_Error('wu_domain_failed', (is_array($body) && !empty($body['code'])) ? (string) $body['code'] : 'failed');
        }
        return $body['data'];
    }

    public static function createPaymentIntent(array $payload)
    {
        $apiKey = MerchantProvisioning::getApiKey();
        if ($apiKey === '') {
            return new \WP_Error('wu_no_account', __('Payments account is not connected.', 'walletup'));
        }
        if (!isset($payload['mode'])) {
            $payload['mode'] = self::mode();
        }
        $resp = wp_remote_post(self::backendUrl() . '/api/merchant/connect/stripe/payment-intent', [
            'headers' => ['X-API-Key' => $apiKey, 'Content-Type' => 'application/json'],
            'body' => wp_json_encode($payload),
            'timeout' => 45,
        ]);
        if (is_wp_error($resp)) {
            return $resp;
        }
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if (empty($body['success']) || empty($body['data'])) {
            $msg = (is_array($body) && !empty($body['error'])) ? $body['error'] : __('Card payment could not be processed.', 'walletup');
            return new \WP_Error('wu_intent_failed', $msg);
        }
        return $body['data'];
    }

    /**
     * Read back a PaymentIntent's status on the connected account (post-3DS confirmation).
     *
     * @return array|\WP_Error
     */
    public static function retrieveIntent(string $intentId)
    {
        $apiKey = MerchantProvisioning::getApiKey();
        if ($apiKey === '') {
            return new \WP_Error('wu_no_account', __('Payments account is not connected.', 'walletup'));
        }
        $resp = wp_remote_get(self::backendUrl() . '/api/merchant/connect/stripe/payment-intent/' . rawurlencode($intentId)
            . (self::mode() === 'test' ? '?mode=test' : ''), [
            'headers' => ['X-API-Key' => $apiKey],
            'timeout' => 30,
        ]);
        if (is_wp_error($resp)) {
            return $resp;
        }
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if (empty($body['success']) || empty($body['data'])) {
            return new \WP_Error('wu_intent_lookup_failed', __('Could not verify the payment.', 'walletup'));
        }
        return $body['data'];
    }

    public static function nonprofitStatus()
    {
        $apiKey = MerchantProvisioning::getApiKey();
        if ($apiKey === '') {
            return null;
        }
        $resp = wp_remote_get(self::backendUrl() . '/api/merchant/nonprofit/status', [
            'headers' => ['X-API-Key' => $apiKey],
            'timeout' => 5,
        ]);
        if (is_wp_error($resp)) {
            return null;
        }
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if (empty($body['success']) || empty($body['data'])) {
            return null;
        }
        return $body['data'];
    }
}
