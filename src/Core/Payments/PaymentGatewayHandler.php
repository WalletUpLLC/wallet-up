<?php
namespace WalletUpPro\Core\Payments;

if (!defined('ABSPATH')) {
    exit;
}

class PaymentGatewayHandler
{
    private const SERVER_ONLY_METADATA = ['membership_id'];

    private const STRIPE_OPTION = 'wallet_up_stripe_config';
    private const CLAIM_STALE_SECONDS = 900;
    private const PAYPAL_OPTION = 'wallet_up_paypal_settings';
    private const PAYPAL_COMMERCE_OPTION = 'wallet_up_paypal_commerce_settings';

    private static function encryptSecrets(array $config): array
    {
        $encryption = new \WalletUpPro\Core\Security\DataEncryption();
        $secretFields = ['test_secret_key', 'live_secret_key', 'webhook_endpoint_secret', 'client_secret'];

        foreach ($secretFields as $field) {
            if (!empty($config[$field]) && strpos($config[$field], '*') === false) {
                $config[$field] = $encryption->encrypt($config[$field]);
            }
        }
        return $config;
    }

    private static function decryptSecrets(array $config): array
    {
        $encryption = new \WalletUpPro\Core\Security\DataEncryption();
        $secretFields = ['test_secret_key', 'live_secret_key', 'webhook_endpoint_secret', 'client_secret'];

        foreach ($secretFields as $field) {
            if (!empty($config[$field])) {
                $decrypted = $encryption->decrypt($config[$field]);
                if ($decrypted !== false && $decrypted !== '') {
                    $config[$field] = $decrypted;
                }
            }
        }
        return $config;
    }

    public static function init(): void
    {
        $actions = [
            'wallet_up_get_stripe_config'           => 'handleGetStripeConfig',
            'wallet_up_save_stripe_config'          => 'handleSaveStripeConfig',
            'wallet_up_set_stripe_mode'             => 'handleSetStripeMode',
            'wallet_up_test_stripe_connection'       => 'handleTestStripeConnection',
            'wallet_up_get_stripe_stats'            => 'handleGetStripeStats',
            'wallet_up_create_stripe_payment_intent' => 'handleCreatePaymentIntent',
            'wallet_up_confirm_stripe_payment'       => 'handleConfirmStripePayment',
            'wallet_up_stripe_payment_success'       => 'handleStripePaymentSuccess',
            'wallet_up_get_paypal_settings'         => 'handleGetPayPalSettings',
            'wallet_up_save_paypal_settings'        => 'handleSavePayPalSettings',
            'wallet_up_test_paypal_connection'       => 'handleTestPayPalConnection',
        ];

        foreach ($actions as $action => $method) {
            add_action("wp_ajax_{$action}", [__CLASS__, $method]);
        }

        add_action('wp_ajax_nopriv_wallet_up_create_stripe_payment_intent', [__CLASS__, 'handleCreatePaymentIntent']);
        add_action('wp_ajax_nopriv_wallet_up_confirm_stripe_payment', [__CLASS__, 'handleConfirmStripePayment']);
        add_action('wp_ajax_nopriv_wallet_up_stripe_payment_success', [__CLASS__, 'handleStripePaymentSuccess']);

        add_action('wp_ajax_walletup_confirm_payment_sent', [__CLASS__, 'handleConfirmPaymentSent']);
        add_action('wp_ajax_nopriv_walletup_confirm_payment_sent', [__CLASS__, 'handleConfirmPaymentSent']);
    }

    /**
     * Get decrypted Stripe config for internal server-side use.
     * Used by DonationSystemHandler to create PaymentIntents without duplicating decryption logic.
     *
     * @return array Decrypted Stripe configuration
     */
    public static function updateStripeConfig(array $patch): array
    {
        $config = array_merge(self::getStripeConfig(), $patch);
        update_option(self::STRIPE_OPTION, self::encryptSecrets($config));
        return $config;
    }

    public static function getStripeConfig(): array
    {
        return self::decryptSecrets(get_option(self::STRIPE_OPTION, self::defaultStripeConfig()));
    }

    /**
     * The single card-intent engine every surface calls. Reads AutomatedApiConfig::stripe() and
     * routes Connect (broker) or manual (merchant secret). $args: amount (integer minor units),
     * currency, description?, receipt_email?, metadata?, payment_method? (confirm inline), return_url?.
     * @return array{client_secret:string,payment_intent_id:string,publishable_key:string,connected_account:string,payment_method_types:array,mode:string,connect:bool}|\WP_Error
     */
    public static function createCardIntent(array $args)
    {
        if (!class_exists('\\WalletUpPro\\Core\\Config\\AutomatedApiConfig')) {
            return new \WP_Error('wu_stripe_unavailable', __('Card payments are unavailable.', 'walletup'));
        }
        if (class_exists('\WalletUpPro\Core\Payments\Availability\MethodAvailability') && \WalletUpPro\Core\Payments\Availability\MethodAvailability::automatedOff(\WalletUpPro\Core\Config\AutomatedApiConfig::STRIPE)) {
            return new \WP_Error('wu_stripe_off', \WalletUpPro\Core\Payments\Availability\MethodAvailability::refusal());
        }
        $r = \WalletUpPro\Core\Config\AutomatedApiConfig::stripe();
        if (empty($r['ready'])) {
            return new \WP_Error('wu_stripe_not_ready', __('Card payments are not configured.', 'walletup'));
        }
        $cfg = $r['config'];

        $amount = (int) round($args['amount'] ?? 0);
        $currency = strtolower((string) ($args['currency'] ?? 'usd'));
        $metadata = \WalletUpPro\Core\Tenancy\Tenant::metadata(array_merge(
            is_array($args['metadata'] ?? null) ? $args['metadata'] : [],
            ['source' => (string) ($args['source'] ?? 'wallet_up_pro')]
        ));

        if (!empty($cfg['connect']) && class_exists('\\WalletUpPro\\Core\\Payments\\StripeConnect')) {
            $payload = [
                'amount'      => $amount,
                'currency'    => $currency,
                'description' => (string) ($args['description'] ?? ''),
                'metadata'    => $metadata,
                'mode'        => $cfg['mode'],
            ];
            if (!empty($args['receipt_email'])) {
                $payload['receipt_email'] = $args['receipt_email'];
            }
            if (!empty($args['allow_redirects'])) {
                $payload['allow_redirects'] = $args['allow_redirects'];
            }
            if (!empty($args['payment_method'])) {
                $payload['payment_method'] = $args['payment_method'];
                if (!empty($args['return_url'])) {
                    $payload['return_url'] = $args['return_url'];
                }
            }
            if (isset($args['donor_fee_percent'])) {
                $payload['donor_fee_percent'] = $args['donor_fee_percent'];
            }
            $ci = \WalletUpPro\Core\Payments\StripeConnect::createPaymentIntent($payload);
            if (is_wp_error($ci)) {
                return $ci;
            }
            $isDestination = !empty($ci['destination']);
            return [
                'client_secret'        => (string) ($ci['client_secret'] ?? ''),
                'payment_intent_id'    => (string) ($ci['id'] ?? ''),
                'status'               => (string) ($ci['status'] ?? ''),
                'publishable_key'      => (string) ($ci['publishable_key'] ?? $cfg['publishable_key']),
                'connected_account'    => $isDestination ? '' : (string) ($ci['account_id'] ?? ($cfg['connected_account'] ?? '')),
                'destination'          => $isDestination,
                'settlement'           => is_array($ci['settlement'] ?? null) ? $ci['settlement'] : null,
                'payment_method_types' => $ci['payment_method_types'] ?? [],
                'mode'                 => $cfg['mode'],
                'connect'              => true,
            ];
        }

        $secret = \WalletUpPro\Core\Config\AutomatedApiConfig::stripeKeyPair()['secret'];
        if ($secret === '') {
            return new \WP_Error('wu_stripe_no_key', __('Card payments are not fully configured.', 'walletup'));
        }
        try {
            \Stripe\Stripe::setApiKey($secret);
            $params = ['amount' => $amount, 'currency' => $currency, 'metadata' => $metadata];
            if (!empty($args['description'])) {
                $params['description'] = $args['description'];
            }
            if (!empty($args['receipt_email'])) {
                $params['receipt_email'] = $args['receipt_email'];
            }
            if (!empty($args['payment_method'])) {
                $params['payment_method'] = $args['payment_method'];
                $params['confirm'] = true;
                $params['confirmation_method'] = 'automatic';
                if (!empty($args['return_url'])) {
                    $params['return_url'] = $args['return_url'];
                }
            } else {
                $params['automatic_payment_methods'] = ['enabled' => true];
                if (($args['allow_redirects'] ?? '') === 'never') {
                    $params['automatic_payment_methods']['allow_redirects'] = 'never';
                }
            }
            if (!empty($config['statement_descriptor'])) {
                $params['statement_descriptor'] = $config['statement_descriptor'];
            }
            if (($config['capture_method'] ?? '') === 'manual' && empty($args['payment_method'])) {
                $params['capture_method'] = 'manual';
            }
            $intent = \Stripe\PaymentIntent::create($params);
            return [
                'client_secret'        => (string) $intent->client_secret,
                'payment_intent_id'    => (string) $intent->id,
                'status'               => (string) ($intent->status ?? ''),
                'publishable_key'      => (string) $cfg['publishable_key'],
                'connected_account'    => '',
                'payment_method_types' => $intent->payment_method_types ?? [],
                'mode'                 => $cfg['mode'],
                'connect'              => false,
            ];
        } catch (\Throwable $e) {
            return new \WP_Error('wu_stripe_intent', $e->getMessage());
        }
    }

    public static function toMinorUnits(float $amount, string $currency): int
    {
        $zeroDecimal = [
            'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
            'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
        ];
        $code = strtoupper($currency);
        if (in_array($code, $zeroDecimal, true)) {
            return (int) round($amount);
        }
        if (in_array($code, ['BHD', 'JOD', 'KWD', 'OMR', 'TND'], true)) {
            return ((int) round($amount * 100)) * 10;
        }
        return (int) round($amount * 100);
    }

    public static function fromMinorUnits(int $amount, string $currency): float
    {
        $code = strtoupper($currency);
        if (in_array($code, [
            'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
            'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
        ], true)) {
            return (float) $amount;
        }
        if (in_array($code, ['BHD', 'JOD', 'KWD', 'OMR', 'TND'], true)) {
            return round($amount / 1000, 3);
        }
        return round($amount / 100, 2);
    }

    public static function verifyIntent(string $paymentIntentId, string $rail = '')
    {
        $paymentIntentId = trim($paymentIntentId);
        if ($paymentIntentId === '' || strpos($paymentIntentId, 'pi_') !== 0) {
            return new \WP_Error('wu_no_intent', __('Missing or invalid payment reference.', 'walletup'));
        }
        if (!class_exists('\\WalletUpPro\\Core\\Config\\AutomatedApiConfig')) {
            return new \WP_Error('wu_stripe_unavailable', __('Payments are unavailable.', 'walletup'));
        }
        $cfg = (\WalletUpPro\Core\Config\AutomatedApiConfig::stripe()['config'] ?? []);
        if ($rail === 'manual') {
            $cfg['connect'] = false;
        } elseif ($rail === 'connect') {
            $cfg['connect'] = true;
        }

        if ($rail === '') {
            $preferred = self::readIntentFrom($paymentIntentId, !empty($cfg['connect']), $cfg);
            if (!is_wp_error($preferred)) {
                return $preferred;
            }
            $other = self::readIntentFrom($paymentIntentId, empty($cfg['connect']), $cfg);
            return is_wp_error($other) ? $preferred : $other;
        }
        return self::readIntentFrom($paymentIntentId, !empty($cfg['connect']), $cfg);
    }

    private static function readIntentFrom(string $paymentIntentId, bool $useConnect, array $cfg)
    {
        if ($useConnect && class_exists('\\WalletUpPro\\Core\\Payments\\StripeConnect')) {
            $data = \WalletUpPro\Core\Payments\StripeConnect::retrieveIntent($paymentIntentId);
            if (is_wp_error($data)) {
                return $data;
            }
            $status = (string) ($data['status'] ?? '');
            $amount = isset($data['amount_received']) && (int) $data['amount_received'] > 0
                ? (int) $data['amount_received'] : (int) ($data['amount'] ?? 0);
            return [
                'status'     => $status,
                'amount'     => $amount,
                'currency'   => strtolower((string) ($data['currency'] ?? '')),
                'metadata'   => is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
                'succeeded'  => $status === 'succeeded',
                'processing' => $status === 'processing',
            ];
        }

        $secret = \WalletUpPro\Core\Config\AutomatedApiConfig::stripeKeyPair()['secret'];
        if ($secret === '') {
            return new \WP_Error('wu_stripe_no_key', __('Payments are not configured.', 'walletup'));
        }
        try {
            \Stripe\Stripe::setApiKey($secret);
            $intent = \Stripe\PaymentIntent::retrieve($paymentIntentId);
            $status = (string) $intent->status;
            $amount = (int) ($intent->amount_received ?? 0) > 0 ? (int) $intent->amount_received : (int) $intent->amount;
            return [
                'status'     => $status,
                'amount'     => $amount,
                'currency'   => strtolower((string) $intent->currency),
                'metadata'   => isset($intent->metadata) ? (array) $intent->metadata->toArray() : [],
                'succeeded'  => $status === 'succeeded',
                'processing' => $status === 'processing',
            ];
        } catch (\Throwable $e) {
            return new \WP_Error('wu_intent_verify', $e->getMessage());
        }
    }

    public static function intentAlreadySettled(string $intentId): bool
    {
        if ($intentId === '' || strpos($intentId, 'pi_') !== 0) {
            return false;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'walletup_payment_transactions';
        $like = '%' . $wpdb->esc_like('"stripe_intent_id":"' . $intentId . '"') . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE status = 'completed' AND (provider_transaction_id = %s OR metadata LIKE %s)",
            $intentId,
            $like
        ));
        return $count > 0;
    }

    public static function assertPaidFor(array $ctx): array
    {
        $intentId = trim((string) ($ctx['payment_intent_id'] ?? ''));
        $fail = static function (string $msg, string $status = '') use ($intentId): array {
            if ($intentId !== '') {
                self::releaseIntentClaim($intentId);
            }
            return ['paid' => false, 'processing' => false, 'status' => $status, 'error' => $msg, 'amount' => 0];
        };

        if ($intentId === '' || strpos($intentId, 'pi_') !== 0) {
            return ['paid' => false, 'processing' => false, 'status' => '', 'amount' => 0,
                'error' => __('Missing or invalid payment reference.', 'walletup')];
        }
        if (!self::claimIntent($intentId)) {
            return [
                'paid' => false, 'processing' => false, 'status' => 'claimed', 'amount' => 0,
                'error' => __('This payment has already been processed.', 'walletup'),
            ];
        }

        $hasBinding = ((string) ($ctx['bind_value'] ?? '')) !== '';
        $hasAmount = ((int) ($ctx['expected_amount'] ?? 0)) > 0 || isset($ctx['expected_major']);
        if (!$hasBinding && !$hasAmount) {
            return $fail(__('This payment cannot be verified.', 'walletup'));
        }

        $v = self::verifyIntent($intentId);
        if (is_wp_error($v)) {
            return $fail($v->get_error_message());
        }

        $bindValue = (string) ($ctx['bind_value'] ?? '');
        if ($bindValue !== '') {
            $seen = (string) ($v['metadata'][$ctx['bind_key'] ?? ''] ?? '');
            if (!hash_equals($bindValue, $seen)) {
                return $fail(__('This payment does not belong to this order.', 'walletup'), $v['status']);
            }
        }

        foreach ((array) ($ctx['also_match'] ?? []) as $key => $value) {
            $seen = (string) ($v['metadata'][$key] ?? '');
            if ($seen !== '' && !hash_equals((string) $value, $seen)) {
                return $fail(__('This payment does not belong to this order.', 'walletup'), $v['status']);
            }
        }

        $expected = (int) ($ctx['expected_amount'] ?? 0);
        if (isset($ctx['expected_major'])) {
            $expected = self::toMinorUnits((float) $ctx['expected_major'], (string) $v['currency']);
        }
        $currency = strtolower((string) ($ctx['expected_currency'] ?? ''));
        if ($expected > 0 && $currency === '') {
            return $fail(__('The amount paid does not match.', 'walletup'), $v['status']);
        }
        if ($expected > 0 && (int) $v['amount'] < $expected) {
            return $fail(__('The amount paid does not match.', 'walletup'), $v['status']);
        }
        if ($currency !== '' && $currency !== (string) $v['currency']) {
            return $fail(__('The amount paid does not match.', 'walletup'), $v['status']);
        }

        if (!empty($v['succeeded'])) {
            return ['paid' => true, 'processing' => false, 'status' => $v['status'], 'error' => '',
                'amount' => (int) $v['amount'], 'currency' => (string) $v['currency'],
                'metadata' => is_array($v['metadata'] ?? null) ? $v['metadata'] : []];
        }
        if (!empty($v['processing'])) {
            self::releaseIntentClaim($intentId);
            return ['paid' => false, 'processing' => true, 'status' => $v['status'], 'error' => '', 'amount' => (int) $v['amount']];
        }
        return $fail(__('This payment has not completed.', 'walletup'), $v['status']);
    }

    public static function claimIntent(string $paymentIntentId): bool
    {
        $paymentIntentId = trim($paymentIntentId);
        if ($paymentIntentId === '') {
            return false;
        }
        $key = 'walup_pi_' . md5($paymentIntentId);
        if (add_option($key, time(), '', 'no')) {
            return true;
        }
        $held = (int) get_option($key, 0);
        if ($held > 0 && (time() - $held) > self::CLAIM_STALE_SECONDS) {
            update_option($key, time(), 'no');
            return true;
        }
        return false;
    }

    public static function releaseIntentClaim(string $paymentIntentId): void
    {
        $paymentIntentId = trim($paymentIntentId);
        if ($paymentIntentId !== '') {
            delete_option('walup_pi_' . md5($paymentIntentId));
        }
    }

    public static function handleGetStripeConfig(): void
    {
        check_ajax_referer('wallet_up_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        $config = self::decryptSecrets(get_option(self::STRIPE_OPTION, self::defaultStripeConfig()));

        $masked = $config;
        foreach (['test_secret_key', 'live_secret_key', 'webhook_endpoint_secret'] as $key) {
            if (!empty($masked[$key]) && strlen($masked[$key]) > 8) {
                $masked[$key] = substr($masked[$key], 0, 8) . str_repeat('*', strlen($masked[$key]) - 8);
            }
        }

        wp_send_json_success(['config' => $masked, 'has_keys' => !empty($config['test_secret_key']) || !empty($config['live_secret_key'])]);
    }

    public static function handleSetStripeMode(): void
    {
        check_ajax_referer('wallet_up_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized', 'walletup')], 403);
        }
        if (!class_exists('\\WalletUpPro\\Core\\Config\\AutomatedApiConfig')) {
            wp_send_json_error(['message' => __('Card payments are unavailable.', 'walletup')], 500);
        }
        $test = filter_var(wp_unslash($_POST['test_mode'] ?? 'true'), FILTER_VALIDATE_BOOLEAN);
        \WalletUpPro\Core\Config\AutomatedApiConfig::setStripeTestMode($test);
        \WalletUpPro\Core\Security\AuditLog::record('stripe_mode_' . ($test ? 'test' : 'live'));
        wp_send_json_success(['test_mode' => $test]);
    }

    public static function handleSaveStripeConfig(): void
    {
        check_ajax_referer('wallet_up_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON decoded and validated below.
        $input = json_decode(wp_unslash($_POST['config'] ?? '{}'), true);
        if (is_array($input) && function_exists('\\WalletUpPro\\wallet_up_fs')) {
            $keyFields = ['test_publishable_key', 'test_secret_key', 'live_publishable_key', 'live_secret_key'];
            $sendsKeys = false;
            foreach ($keyFields as $f) {
                if (!empty($input[$f])) {
                    $sendsKeys = true;
                    break;
                }
            }
            if ($sendsKeys && !\WalletUpPro\wallet_up_fs()->can_use_premium_code()) {
                wp_send_json_error([
                    'message' => __('Using your own Stripe keys is part of Pro. On the free plan, connect Stripe instead.', 'walletup'),
                ], 403);
            }
        }
        if (!is_array($input)) {
            wp_send_json_error('Invalid config data');
        }

        $existing = self::decryptSecrets(get_option(self::STRIPE_OPTION, self::defaultStripeConfig()));

        $config = [
            'enabled'                 => (bool) ($input['enabled'] ?? false),
            'test_mode'               => (bool) ($input['test_mode'] ?? true),
            'test_publishable_key'    => sanitize_text_field($input['test_publishable_key'] ?? ''),
            'test_secret_key'         => self::unmaskedKey($input['test_secret_key'] ?? '', $existing['test_secret_key'] ?? ''),
            'live_publishable_key'    => sanitize_text_field($input['live_publishable_key'] ?? ''),
            'live_secret_key'         => self::unmaskedKey($input['live_secret_key'] ?? '', $existing['live_secret_key'] ?? ''),
            'webhook_endpoint_secret' => self::unmaskedKey($input['webhook_endpoint_secret'] ?? '', $existing['webhook_endpoint_secret'] ?? ''),
            'capture_method'          => sanitize_text_field($input['capture_method'] ?? 'automatic'),
            'statement_descriptor'    => substr(sanitize_text_field($input['statement_descriptor'] ?? ''), 0, 22),
            'currency'                => strtolower(sanitize_text_field($input['currency'] ?? 'usd')),
        ];

        update_option(self::STRIPE_OPTION, self::encryptSecrets($config));
        \WalletUpPro\Core\Security\AuditLog::record('stripe_config_saved');
        wp_send_json_success(['message' => 'Stripe configuration saved']);
    }

    public static function handleTestStripeConnection(): void
    {
        check_ajax_referer('wallet_up_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        $config = self::decryptSecrets(get_option(self::STRIPE_OPTION, self::defaultStripeConfig()));
        $testMode = filter_var(wp_unslash($_POST['test_mode'] ?? 'true'), FILTER_VALIDATE_BOOLEAN);

        $secretKey = $testMode ? ($config['test_secret_key'] ?? '') : ($config['live_secret_key'] ?? '');

        if (empty($secretKey)) {
            wp_send_json_error('No ' . ($testMode ? 'test' : 'live') . ' secret key configured');
        }

        try {
            \Stripe\Stripe::setApiKey($secretKey);
            \Stripe\Stripe::setMaxNetworkRetries(0);
            $curlOpts = [\CURLOPT_TIMEOUT => 5, \CURLOPT_CONNECTTIMEOUT => 3];
            $httpClient = new \Stripe\HttpClient\CurlClient($curlOpts);
            \Stripe\ApiRequestor::setHttpClient($httpClient);
            $account = \Stripe\Account::retrieve();

            wp_send_json_success([
                'connected'    => true,
                'account_id'   => $account->id,
                'business_name' => $account->business_profile->name ?? $account->settings->dashboard->display_name ?? 'Connected',
                'country'      => $account->country ?? 'Unknown',
                'mode'         => $testMode ? 'test' : 'live',
            ]);
        } catch (\Stripe\Exception\AuthenticationException $e) {
            wp_send_json_error('Invalid API key: ' . $e->getMessage());
        } catch (\Exception $e) {
            wp_send_json_error('Connection failed: ' . $e->getMessage());
        }
    }

    public static function handleGetStripeStats(): void
    {
        check_ajax_referer('wallet_up_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        $config = self::decryptSecrets(get_option(self::STRIPE_OPTION, self::defaultStripeConfig()));
        $secretKey = $config['test_mode'] ? ($config['test_secret_key'] ?? '') : ($config['live_secret_key'] ?? '');

        if (empty($secretKey)) {
            wp_send_json_success([
                'total_payments'  => 0,
                'total_revenue'   => 0,
                'successful_rate' => 0,
                'recent_charges'  => [],
            ]);
            return;
        }

        try {
            \Stripe\Stripe::setApiKey($secretKey);

            $charges = \Stripe\Charge::all(['limit' => 10]);

            $totalPayments = 0;
            $totalRevenue = 0;
            $successful = 0;
            $recentCharges = [];

            foreach ($charges->data as $charge) {
                $totalPayments++;
                if ($charge->status === 'succeeded') {
                    $totalRevenue += self::fromMinorUnits((int) $charge->amount, (string) $charge->currency);
                    $successful++;
                }
                $recentCharges[] = [
                    'id'       => $charge->id,
                    'amount'   => self::fromMinorUnits((int) $charge->amount, (string) $charge->currency),
                    'currency' => $charge->currency,
                    'status'   => $charge->status,
                    'created'  => gmdate('Y-m-d H:i:s', $charge->created),
                    'description' => $charge->description ?? '',
                ];
            }

            wp_send_json_success([
                'total_payments'  => $totalPayments,
                'total_revenue'   => round($totalRevenue, 2),
                'successful_rate' => $totalPayments > 0 ? round(($successful / $totalPayments) * 100) : 0,
                'recent_charges'  => $recentCharges,
            ]);
        } catch (\Exception $e) {
            wp_send_json_success([
                'total_payments'  => 0,
                'total_revenue'   => 0,
                'successful_rate' => 0,
                'recent_charges'  => [],
                'error'           => $e->getMessage(),
            ]);
        }
    }

    private const RATE_LIMIT = 10;

    private const RATE_WINDOW = 300;

    private static function withinIntentRate(): bool
    {
        $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));
        $key = 'walletup_stripe_pi_' . md5($ip);
        $now = time();
        $bucket = get_transient($key);
        if (!is_array($bucket) || !isset($bucket['start'], $bucket['count']) || ($now - (int) $bucket['start']) >= self::RATE_WINDOW) {
            $bucket = ['start' => $now, 'count' => 0];
        }
        if ((int) $bucket['count'] >= self::RATE_LIMIT) {
            return false;
        }
        $bucket['count'] = (int) $bucket['count'] + 1;
        set_transient($key, $bucket, self::RATE_WINDOW - ($now - (int) $bucket['start']) + 1);
        return true;
    }

    public static function handleCreatePaymentIntent(): void
    {
        $nonceValid = wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'wallet_up_admin_nonce')
            || wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'wallet_up_create_stripe_payment_intent')
            || wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? '')), 'wp_rest');

        if (!$nonceValid) {
            wp_send_json_error(['message' => __('This page has expired. Reload it and try again.', 'walletup')], 403);
        }

        if (!self::withinIntentRate()) {
            wp_send_json_error(['message' => __('Too many payment requests. Please try again in a few minutes.', 'walletup')], 429);
        }

        $currencyEarly = sanitize_text_field(wp_unslash($_POST['currency'] ?? 'usd'));
        $amountEarly = self::toMinorUnits(floatval(wp_unslash($_POST['amount'] ?? 0)), $currencyEarly);
        if ($amountEarly <= 0) {
            wp_send_json_error(['message' => __('Enter an amount before paying.', 'walletup')], 400);
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON decoded here.
        $meta = json_decode(wp_unslash($_POST['metadata'] ?? '{}'), true);
        $meta = is_array($meta) ? array_diff_key($meta, array_flip(self::SERVER_ONLY_METADATA)) : [];
        $intentArgs = [
            'amount'      => $amountEarly,
            'currency'    => $currencyEarly,
            'description' => sanitize_text_field(wp_unslash($_POST['description'] ?? '')),
            'metadata'    => $meta,
        ];
        if (isset($_POST['donorFeePercent']) && $_POST['donorFeePercent'] !== '') {
            $intentArgs['donor_fee_percent'] = (float) wp_unslash($_POST['donorFeePercent']);
        }
        $res = self::createCardIntent($intentArgs);
        if (is_wp_error($res)) {
            wp_send_json_error(['message' => \WalletUpPro\Core\Payments\BackendErrors::forCustomer($res, 'card intent')], 400);
        }
        wp_send_json_success([
            'client_secret'        => $res['client_secret'],
            'payment_intent_id'    => $res['payment_intent_id'],
            'publishable_key'      => $res['publishable_key'],
            'connected_account'    => $res['connected_account'],
            'destination'          => $res['destination'] ?? false,
            'payment_method_types' => $res['payment_method_types'],
        ]);
    }

    public static function handleConfirmStripePayment(): void
    {
        $nonceValid = wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'wallet_up_admin_nonce')
            || wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'wallet_up_confirm_stripe_payment');

        if (!$nonceValid) {
            wp_send_json_error('Invalid nonce', 403);
        }

        $paymentIntentId = sanitize_text_field(wp_unslash($_POST['payment_intent_id'] ?? ''));
        if (empty($paymentIntentId)) {
            wp_send_json_error('Missing payment intent ID');
        }

        try {
            $intent = self::verifyIntent($paymentIntentId);
            if (is_wp_error($intent)) {
                wp_send_json_error('Confirmation failed: ' . $intent->get_error_message());
            }

            $currency = (string) ($intent['currency'] ?? '');
            wp_send_json_success([
                'status'    => $intent['status'] ?? '',
                'amount'    => self::fromMinorUnits((int) ($intent['amount'] ?? 0), $currency),
                'currency'  => $currency,
                'confirmed' => !empty($intent['succeeded']),
            ]);
        } catch (\Exception $e) {
            wp_send_json_error('Confirmation failed: ' . $e->getMessage());
        }
    }

    public static function handleStripePaymentSuccess(): void
    {
        $nonceValid = wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'wallet_up_admin_nonce')
            || wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'wallet_up_stripe_payment_success')
            || wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'walup_submit_donation');

        if (!$nonceValid) {
            wp_send_json_error('Invalid nonce', 403);
        }

        $paymentIntentId = sanitize_text_field(wp_unslash($_POST['payment_intent_id'] ?? ''));

        try {
            $intent = self::verifyIntent($paymentIntentId);
            if (is_wp_error($intent)) {
                wp_send_json_error('Failed to record payment: ' . $intent->get_error_message());
            }

            $status = (string) ($intent['status'] ?? '');
            if ($status !== 'succeeded') {
                wp_send_json_error('Payment not yet completed. Status: ' . ($status !== '' ? $status : 'unknown'));
            }

            global $wpdb;
            $currency = strtoupper((string) ($intent['currency'] ?? ''));
            $major    = self::fromMinorUnits((int) ($intent['amount'] ?? 0), $currency);
            $method   = sanitize_key(wp_unslash($_POST['payment_method'] ?? '')) ?: 'stripe';

            $meta = is_array($intent['metadata'] ?? null) ? $intent['metadata'] : [];

            $ownerKeys = ['donation_id', 'subscription_id', 'order_id', 'membership_id', 'wpforms_entry_id', 'form_id'];
            $ownedElsewhere = false;
            foreach ($ownerKeys as $k) {
                if ((string) ($meta[$k] ?? '') !== '' || sanitize_text_field(wp_unslash($_POST[$k] ?? '')) !== '') {
                    $ownedElsewhere = true;
                    break;
                }
            }

            $recorded = true;
            if (!$ownedElsewhere) {
                $recorded = (bool) \WalletUpPro\Services\PaymentProcessor::getInstance()->recordCompletedPayment([
                    'provider'                => 'stripe',
                    'method'                  => $method,
                    'provider_transaction_id' => $paymentIntentId,
                    'amount_minor'            => (int) ($intent['amount'] ?? 0),
                    'currency'                => $currency,
                    'description'             => sanitize_text_field(wp_unslash($_POST['description'] ?? '')),
                    'metadata'                => [
                        'source'       => 'stripe_payment_success',
                        'reference'    => sanitize_text_field(wp_unslash($_POST['reference'] ?? '')),
                        'method_label' => class_exists('\\WalletUpPro\\Core\\Config\\StripeMethodCatalog')
                            ? \WalletUpPro\Core\Config\StripeMethodCatalog::labelForIntent($paymentIntentId)
                            : '',
                    ],
                ]);
            }

            $analyticsTable = $wpdb->prefix . 'wallet_up_analytics';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $analyticsTable)) === $analyticsTable) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Write operation, caching not applicable.
                $wpdb->insert($analyticsTable, [
                    'event_type'     => 'payment',
                    'payment_method' => $method,
                    'post_type'      => 'direct',
                    'post_title'     => 'Direct Payment',
                    'user_ip'        => sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '')),
                    'order_total'    => $major,
                    'currency'       => $currency,
                    'metadata'       => wp_json_encode(['payment_intent_id' => $paymentIntentId]),
                    'created_at'     => current_time('mysql'),
                ]);
            }

            $metadata = $meta;
            $donationId = isset($metadata['donation_id']) ? (string) $metadata['donation_id'] : '';
            if ($donationId !== '') {
                $donationsTable = $wpdb->prefix . 'wallet_up_donations';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Read on plugin-owned table.
                $donation = $wpdb->get_row($wpdb->prepare(
                    "SELECT amount, currency, status FROM {$donationsTable} WHERE donation_id = %s",
                    $donationId
                ), ARRAY_A);
                $donationCurrency = strtolower((string) ($donation['currency'] ?? '') ?: $currency);
                $expectedMinor = $donation ? self::toMinorUnits((float) $donation['amount'], $donationCurrency) : 0;
                $amountCovers = $expectedMinor > 0 && (int) ($intent['amount'] ?? 0) >= $expectedMinor;
                $currencyMatches = $donationCurrency === strtolower($currency);
                if ($donation && $amountCovers && $currencyMatches) {
                    if (class_exists(\WalletUpPro\Core\Donations\DonationSystemHandler::class)) {
                        \WalletUpPro\Core\Donations\DonationSystemHandler::recordFeeSplit($donationId, $metadata, $donationCurrency);
                    }
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Write operation on plugin-owned table.
                    $wpdb->query($wpdb->prepare(
                        "UPDATE {$donationsTable} SET status = 'completed', payment_method = 'stripe' WHERE donation_id = %s AND status NOT IN ('completed','refunded','cancelled')",
                        $donationId
                    ));
                    if ($wpdb->rows_affected > 0 && class_exists(\WalletUpPro\Core\Donations\DonationSystemHandler::class)) {
                        \WalletUpPro\Core\Donations\DonationSystemHandler::sendDonationReceiptOnce($donationId);
                    }
                }
            }

            if (!$recorded) {
                wp_send_json_error([
                    'code'              => 'payment_not_recorded',
                    'payment_intent_id' => $paymentIntentId,
                ], 500);
            }

            wp_send_json_success([
                'code'              => 'payment_recorded',
                'payment_intent_id' => $paymentIntentId,
                'amount'            => $major,
                'currency'          => $currency,
            ]);
        } catch (\Exception $e) {
            wp_send_json_error('Failed to record payment: ' . $e->getMessage());
        }
    }

    /**
     * Get decrypted PayPal config for internal server-side use.
     * Used by WebhookHandler for signature verification and DonationSystemHandler for order capture.
     *
     * @return array Decrypted PayPal configuration
     */
    public static function getPayPalConfig(): array
    {
        return self::decryptSecrets(get_option(self::PAYPAL_OPTION, self::defaultPayPalSettings()));
    }

    public static function handleGetPayPalSettings(): void
    {
        check_ajax_referer('wallet_up_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        $settings = self::decryptSecrets(get_option(self::PAYPAL_OPTION, self::defaultPayPalSettings()));

        if (!empty($settings['client_secret']) && strlen($settings['client_secret']) > 8) {
            $settings['client_secret'] = substr($settings['client_secret'], 0, 8) . str_repeat('*', 20);
        }

        $webhookId = get_option('walletup_paypal_webhook_id', '');

        wp_send_json_success([
            'settings' => $settings,
            'ipnSettings' => [
                'paypalEmail'   => $settings['paypal_email'] ?? '',
                'ipnEnabled'    => $settings['ipn_enabled'] ?? false,
                'ipnUrl'        => $settings['ipn_url'] ?? '',
            ],
            'commerceSettings' => [
                'commerceEnabled' => !empty($settings['client_id']),
                'clientId'        => $settings['client_id'] ?? '',
                'clientSecret'    => $settings['client_secret'] ?? '',
                'sandboxMode'     => $settings['sandbox_mode'] ?? true,
                'currency'        => $settings['currency'] ?? 'USD',
                'webhookId'       => $webhookId,
            ],
        ]);
    }

    public static function handleSavePayPalSettings(): void
    {
        check_ajax_referer('wallet_up_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        $existing = self::decryptSecrets(get_option(self::PAYPAL_OPTION, self::defaultPayPalSettings()));

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON decoded; fields sanitized individually below.
        $ipnSettingsJson = json_decode(wp_unslash($_POST['ipnSettings'] ?? '{}'), true);
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON decoded; fields sanitized individually below.
        $commerceSettingsJson = json_decode(wp_unslash($_POST['commerceSettings'] ?? '{}'), true);

        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Boolean casts are the sanitization; text fields use sanitize_text_field; emails use sanitize_email; URLs use sanitize_url.
        $settings = [
            'enabled'          => (bool) (wp_unslash($_POST['enabled'] ?? $ipnSettingsJson['ipnEnabled'] ?? $existing['enabled'] ?? false)),
            'sandbox_mode'     => (bool) (wp_unslash($_POST['sandbox_mode'] ?? $commerceSettingsJson['sandboxMode'] ?? $existing['sandbox_mode'] ?? true)),
            'paypal_email'     => sanitize_email(wp_unslash($_POST['paypal_email'] ?? $ipnSettingsJson['paypalEmail'] ?? $existing['paypal_email'] ?? '')),
            'client_id'        => sanitize_text_field(wp_unslash($_POST['client_id'] ?? $commerceSettingsJson['clientId'] ?? $existing['client_id'] ?? '')),
            'client_secret'    => self::unmaskedKey(
                sanitize_text_field(wp_unslash($_POST['client_secret'] ?? $commerceSettingsJson['clientSecret'] ?? '')),
                $existing['client_secret'] ?? ''
            ),
            'ipn_enabled'      => (bool) (wp_unslash($_POST['ipn_enabled'] ?? $ipnSettingsJson['ipnEnabled'] ?? $existing['ipn_enabled'] ?? false)),
            'ipn_url'          => sanitize_url(wp_unslash($_POST['ipn_url'] ?? $ipnSettingsJson['ipnUrl'] ?? $existing['ipn_url'] ?? '')),
            'currency'         => strtoupper(sanitize_text_field(wp_unslash($_POST['currency'] ?? $commerceSettingsJson['currency'] ?? $existing['currency'] ?? 'USD'))),
            'button_style'     => sanitize_text_field(wp_unslash($_POST['button_style'] ?? $existing['button_style'] ?? 'gold')),
            'auto_return'      => (bool) (wp_unslash($_POST['auto_return'] ?? $existing['auto_return'] ?? true)),
            'return_url'       => sanitize_url(wp_unslash($_POST['return_url'] ?? $existing['return_url'] ?? '')),
            'cancel_url'       => sanitize_url(wp_unslash($_POST['cancel_url'] ?? $existing['cancel_url'] ?? '')),
        ];
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        update_option(self::PAYPAL_OPTION, self::encryptSecrets($settings));
        \WalletUpPro\Core\Security\AuditLog::record('paypal_config_saved');

        $commerceConfig = get_option(self::PAYPAL_COMMERCE_OPTION, []);
        if (!empty($settings['client_id'])) {
            $commerceConfig['client_id'] = $settings['client_id'];
        }
        if (!empty($settings['client_secret'])) {
            $encryption = new \WalletUpPro\Core\Security\DataEncryption();
            $commerceConfig['client_secret'] = $encryption->encrypt($settings['client_secret']);
        }
        if (!empty($settings['sandbox'])) {
            $commerceConfig['sandbox'] = $settings['sandbox'];
        }
        if (!empty($settings['currency'])) {
            $commerceConfig['currency'] = $settings['currency'];
        }
        update_option(self::PAYPAL_COMMERCE_OPTION, $commerceConfig);

        $webhookId = sanitize_text_field($commerceSettingsJson['webhookId'] ?? wp_unslash($_POST['webhook_id'] ?? ''));
        if (!empty($webhookId)) {
            update_option('walletup_paypal_webhook_id', $webhookId);
        }

        delete_transient('walletup_paypal_token_sandbox');
        delete_transient('walletup_paypal_token_live');

        wp_send_json_success(['message' => 'PayPal settings saved']);
    }

    public static function handleTestPayPalConnection(): void
    {
        check_ajax_referer('wallet_up_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        $settings = self::decryptSecrets(get_option(self::PAYPAL_OPTION, self::defaultPayPalSettings()));

        if (empty($settings['client_id']) || empty($settings['client_secret'])) {
            $accounts = (array) get_option('wallet_up_transfer_call', []);
            $paypalme = $accounts['paypal_holder'] ?? ($accounts['paypal'] ?? '');
            if (!empty($paypalme)) {
                wp_send_json_success([
                    'connected'   => true,
                    'mode'        => 'paypal_me',
                    'account'     => $paypalme,
                    'message'     => 'PayPal.me link configured (no API keys needed for basic payments)',
                ]);
                return;
            }
            wp_send_json_error('No PayPal credentials or PayPal.me link configured');
        }

        $baseUrl = $settings['sandbox_mode']
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';

        $response = wp_remote_post($baseUrl . '/v1/oauth2/token', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($settings['client_id'] . ':' . $settings['client_secret']),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body'    => 'grant_type=client_credentials',
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(\WalletUpPro\Core\Payments\BackendErrors::forCustomer($response, 'connection test'));
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200 && !empty($body['access_token'])) {
            wp_send_json_success([
                'connected'  => true,
                'mode'       => $settings['sandbox_mode'] ? 'sandbox' : 'live',
                'token_type' => $body['token_type'] ?? 'Bearer',
                'app_id'     => $body['app_id'] ?? 'N/A',
                'message'    => 'PayPal API connection successful',
            ]);
        } else {
            wp_send_json_error('Authentication failed: ' . ($body['error_description'] ?? 'Unknown error'));
        }
    }

    private static function unmaskedKey(string $input, string $existing): string
    {
        if (strpos($input, '*') !== false) {
            return $existing;
        }
        return sanitize_text_field($input);
    }

    private static function defaultStripeConfig(): array
    {
        return [
            'enabled'                 => false,
            'test_mode'               => true,
            'test_publishable_key'    => '',
            'test_secret_key'         => '',
            'live_publishable_key'    => '',
            'live_secret_key'         => '',
            'webhook_endpoint_secret' => '',
            'capture_method'          => 'automatic',
            'statement_descriptor'    => '',
            'currency'                => 'usd',
        ];
    }

    private static function defaultPayPalSettings(): array
    {
        return [
            'enabled'       => false,
            'sandbox_mode'  => true,
            'paypal_email'  => '',
            'client_id'     => '',
            'client_secret' => '',
            'ipn_enabled'   => false,
            'ipn_url'       => home_url('/wp-json/walup/v1/paypal-ipn'),
            'currency'      => 'USD',
            'button_style'  => 'gold',
            'auto_return'   => true,
            'return_url'    => '',
            'cancel_url'    => '',
        ];
    }

    public static function handleConfirmPaymentSent(): void
    {
        try {
            $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));
            $rateKey = 'walletup_confirm_' . md5($ip);
            $attempts = (int) get_transient($rateKey);
            if ($attempts >= 10) {
                wp_send_json_error(['message' => __('Too many requests. Please try again later.', 'walletup')], 429);
                return;
            }
            set_transient($rateKey, $attempts + 1, 60);

            if (!check_ajax_referer('walletup_confirm_payment', 'nonce', false)) {
                wp_send_json_error(['message' => __('Security check failed. Please refresh and try again.', 'walletup')]);
                return;
            }

            $paymentMethod = sanitize_text_field(wp_unslash($_POST['payment_method'] ?? ''));
            $amount = floatval($_POST['amount'] ?? 0);
            $customerName = sanitize_text_field(wp_unslash($_POST['customer_name'] ?? ''));
            $customerEmail = sanitize_email(wp_unslash($_POST['customer_email'] ?? ''));
            $txReference = sanitize_text_field(wp_unslash($_POST['transaction_reference'] ?? ''));
            $wcOrderId = intval($_POST['wc_order_id'] ?? 0);
            $postId = intval($_POST['post_id'] ?? 0);

            if (empty($paymentMethod)) {
                wp_send_json_error(['message' => __('Payment method is required.', 'walletup')]);
                return;
            }

            if ($wcOrderId > 0 && function_exists('wc_get_order')) {
                $order = wc_get_order($wcOrderId);
                if ($order) {
                    $customerName = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
                    $customerEmail = $order->get_billing_email();
                    $amount = floatval($order->get_total());
                }
            }

            if (empty($customerName) && empty($customerEmail)) {
                wp_send_json_error(['message' => __('Please enter your name or email.', 'walletup')]);
                return;
            }

            global $wpdb;
            $table = $wpdb->prefix . 'walletup_payment_transactions';

            if ($wcOrderId > 0 && function_exists('wc_get_order') && isset($order) && $order) {
            }

            if ($wcOrderId > 0) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $alreadyConfirmed = $wpdb->get_var($wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    "SELECT payment_id FROM {$table} WHERE wc_order_id = %d AND status = 'pending_verification' LIMIT 1",
                    $wcOrderId
                ));
                if ($alreadyConfirmed) {
                    wp_send_json_success(['message' => __('Your payment confirmation was already received. The merchant will verify it shortly.', 'walletup')]);
                    return;
                }
            }

            $existingTxId = null;
            if ($wcOrderId > 0) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $existingTxId = $wpdb->get_var($wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    "SELECT payment_id FROM {$table} WHERE wc_order_id = %d AND status = 'pending' ORDER BY created_at DESC LIMIT 1",
                    $wcOrderId
                ));
            }

            $nameParts = explode(' ', $customerName, 2);
            $firstName = $nameParts[0] ?? '';
            $lastName = $nameParts[1] ?? '';

            $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD';
            if ($wcOrderId > 0 && function_exists('wc_get_order')) {
                $currencyOrder = wc_get_order($wcOrderId);
                if ($currencyOrder) {
                    $currency = $currencyOrder->get_currency();
                }
            }

            if ($existingTxId) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->update($table, [
                    'status'                  => 'pending_verification',
                    'provider_transaction_id' => !empty($txReference) ? $txReference : null,
                    'customer_email'          => $customerEmail ?: null,
                    'customer_first_name'     => $firstName,
                    'customer_last_name'      => $lastName,
                    'description'             => sprintf('Customer confirmed %s payment sent', $paymentMethod),
                    'metadata'                => wp_json_encode([
                        'source'              => 'customer_confirmation',
                        'customer_name'       => $customerName,
                        'transaction_ref'     => $txReference,
                        'confirmed_at'        => current_time('mysql'),
                    ]),
                    'updated_at'              => current_time('mysql'),
                ], ['payment_id' => $existingTxId]);
                $paymentId = $existingTxId;
            } else {
                $paymentId = 'wup_' . bin2hex(random_bytes(16));
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $inserted = $wpdb->insert($table, [
                    'payment_id'              => $paymentId,
                    'payment_hash'            => hash('sha256', $paymentId . wp_salt()),
                    'customer_email'          => $customerEmail ?: null,
                    'customer_first_name'     => $firstName,
                    'customer_last_name'      => $lastName,
                    'payment_method'          => $paymentMethod,
                    'amount'                  => $amount,
                    'currency'                => $currency,
                    'status'                  => 'pending_verification',
                    'provider_transaction_id' => $txReference ?: null,
                    'transaction_type'        => 'payment',
                    'wc_order_id'             => $wcOrderId ?: null,
                    'post_id'                 => $postId ?: null,
                    'ip_address'              => $ip,
                    'user_agent'              => sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')),
                    'description'             => sprintf('Customer confirmed %s payment sent', $paymentMethod),
                    'metadata'                => wp_json_encode([
                        'source'              => 'customer_confirmation',
                        'customer_name'       => $customerName,
                        'transaction_ref'     => $txReference,
                    ]),
                    'created_at'              => current_time('mysql'),
                    'updated_at'              => current_time('mysql'),
                ]);
                if (!$inserted) {
                    wp_send_json_error(['message' => __('Failed to record confirmation. Please try again.', 'walletup')]);
                    return;
                }
            }

            $analyticsTable = $wpdb->prefix . 'wallet_up_analytics';
            $wpdb->insert($analyticsTable, [
                'event_type'     => 'payment_confirmation',
                'payment_method' => $paymentMethod,
                'post_id'        => $postId,
                'post_type'      => $postId ? (get_post_type($postId) ?: 'unknown') : 'direct',
                'post_title'     => $postId ? (get_the_title($postId) ?: 'Unknown') : 'Direct Payment',
                'user_ip'        => $ip,
                'created_at'     => current_time('mysql'),
                'order_id'       => $wcOrderId ?: null,
                'order_total'    => $amount,
                'currency'       => $currency,
                'metadata'       => wp_json_encode(['reference' => $txReference, 'name' => $customerName]),
            ]);

            if ($wcOrderId > 0 && function_exists('wc_get_order')) {
                $order = wc_get_order($wcOrderId);
                if ($order) {
                    $order->add_order_note(sprintf(
                        __('Customer reports payment sent via %1$s. Name: %2$s. Reference: %3$s. Awaiting merchant verification.', 'walletup'),
                        $paymentMethod,
                        $customerName,
                        $txReference ?: 'N/A'
                    ));
                    $order->save();
                }
            }

            wp_send_json_success([
                'message' => __('Thank you! Your payment confirmation has been received. The merchant will verify and process your payment shortly.', 'walletup'),
                'payment_id' => $paymentId,
            ]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => __('An error occurred. Please try again.', 'walletup')]);
        }
    }
}
