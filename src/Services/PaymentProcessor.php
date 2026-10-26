<?php
namespace WalletUpPro\Services;

use WalletUpPro\Core\BaseSingleton;
use WalletUpPro\Core\Error\ErrorHandler;
use WalletUpPro\Core\Payments\MerchantProvisioning;
use Exception;

if (!defined('ABSPATH')) {
    exit;
}

class PaymentProcessor extends BaseSingleton
{
    private $errorHandler;
    private $table;

    protected function initialize(): void
    {
        global $wpdb;
        $this->errorHandler = ErrorHandler::getInstance();
        $this->table = $wpdb->prefix . 'walletup_payment_transactions';
    }

    /**
     * Record a charge that a provider has already confirmed, in one shot.
     *
     * initializePayment covers the two-phase flow (pending row now, settle later). Surfaces that
     * only ever learn about a payment once it has succeeded had no home here, so each hand-rolled
     * its own insert with a different column set — rows arrived with no customer, no completed_at
     * and no status event, and the listeners that finish orders and activate memberships never ran.
     *
     * Idempotent on provider_transaction_id: returns the existing payment_id instead of a second
     * row. The claim, not the SELECT, is what makes that safe against two concurrent posts.
     *
     * @param array $ctx provider, method, provider_transaction_id, amount_minor, currency,
     *                   description, customer[email,first,last], link[wc_order_id,…], metadata
     * @return string|null payment_id, or null when it could not be written
     */
    public function recordCompletedPayment(array $ctx): ?string
    {
        global $wpdb;

        $txnId = trim((string) ($ctx['provider_transaction_id'] ?? ''));
        if ($txnId === '') {
            return null;
        }

        $existing = $wpdb->get_var($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT payment_id FROM {$this->table} WHERE provider_transaction_id = %s LIMIT 1",
            $txnId
        ));
        if ($existing) {
            return (string) $existing;
        }

        $claimKey = 'record_' . $txnId;
        if (!\WalletUpPro\Core\Payments\PaymentGatewayHandler::claimIntent($claimKey)) {
            $winner = $wpdb->get_var($wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT payment_id FROM {$this->table} WHERE provider_transaction_id = %s LIMIT 1",
                $txnId
            ));
            return $winner ? (string) $winner : null;
        }

        $currency = strtoupper((string) ($ctx['currency'] ?? 'USD'));
        $amount   = \WalletUpPro\Core\Payments\PaymentGatewayHandler::fromMinorUnits(
            (int) ($ctx['amount_minor'] ?? 0),
            $currency
        );
        $customer = (array) ($ctx['customer'] ?? []);
        $link     = (array) ($ctx['link'] ?? []);
        $now      = current_time('mysql');

        $paymentId = $this->generatePaymentId();
        $row = [
            'payment_id'              => $paymentId,
            'payment_hash'            => $this->generatePaymentHash($ctx),
            'payment_method'          => sanitize_text_field((string) ($ctx['method'] ?? '')),
            'payment_provider'        => sanitize_text_field((string) ($ctx['provider'] ?? '')),
            'customer_email'          => sanitize_email((string) ($customer['email'] ?? '')),
            'customer_first_name'     => sanitize_text_field((string) ($customer['first'] ?? '')),
            'customer_last_name'      => sanitize_text_field((string) ($customer['last'] ?? '')),
            'amount'                  => $amount,
            'currency'                => $currency,
            'status'                  => 'completed',
            'provider_transaction_id' => $txnId,
            'transaction_type'        => sanitize_text_field((string) ($ctx['transaction_type'] ?? 'payment')),
            'description'             => sanitize_text_field((string) ($ctx['description'] ?? '')),
            'metadata'                => wp_json_encode(\WalletUpPro\Core\Tenancy\Tenant::metadata((array) ($ctx['metadata'] ?? []))),
            'ip_address'              => $this->getClientIP(),
            'user_agent'              => sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')),
            'wp_user_id'              => get_current_user_id() ?: null,
            'wc_order_id'             => isset($link['wc_order_id']) ? absint($link['wc_order_id']) : null,
            'wpforms_entry_id'        => isset($link['wpforms_entry_id']) ? absint($link['wpforms_entry_id']) : null,
            'created_at'              => $now,
            'updated_at'              => $now,
            'completed_at'            => $now,
        ];
        if (isset($ctx['fee_minor'])) {
            $fee = \WalletUpPro\Core\Payments\PaymentGatewayHandler::fromMinorUnits((int) $ctx['fee_minor'], $currency);
            $row['fee_amount'] = $fee;
            $row['net_amount'] = $amount - $fee;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Write operation, caching not applicable.
        $row = \WalletUpPro\Core\Tenancy\Tenant::stamp($row, $this->table);
        $ok  = $wpdb->insert($this->table, $row);
        if ($ok === false) {
            \WalletUpPro\Core\Payments\PaymentGatewayHandler::releaseIntentClaim($claimKey);
            $this->errorHandler->log('Failed to record completed payment: ' . $wpdb->last_error);
            return null;
        }

        do_action('walletup_payment_status_changed', $paymentId, 'completed', [
            'provider_transaction_id' => $txnId,
            'payment_method'          => $row['payment_method'],
            'amount'                  => $amount,
            'currency'                => $currency,
        ]);

        return $paymentId;
    }

    /**
     * Initialize a new payment session
     *
     * @param array $data Payment data
     * @return array Payment session info
     * @throws Exception
     */
    public function initializePayment(array $data): array
    {
        global $wpdb;

        try {
            $paymentId = $this->generatePaymentId();
            $paymentHash = $this->generatePaymentHash($data);
            $sessionId = $this->generateSessionId();

            $this->validatePaymentData($data);

            $paymentMethod = $data['payment_method']
                ?? $data['metadata']['payment_method']
                ?? '';

            $transaction = [
                'payment_id' => $paymentId,
                'payment_hash' => $paymentHash,
                'session_id' => $sessionId,
                'payment_method' => sanitize_text_field($paymentMethod),
                'customer_email' => sanitize_email($data['email']),
                'customer_first_name' => sanitize_text_field($data['first'] ?? ''),
                'customer_last_name' => sanitize_text_field($data['last'] ?? ''),
                'customer_phone' => sanitize_text_field($data['phone'] ?? ''),
                'amount' => floatval($data['amt']),
                'currency' => sanitize_text_field($data['currency'] ?? 'USD'),
                'description' => sanitize_text_field($data['description'] ?? ''),
                'status' => 'pending',
                'metadata' => wp_json_encode(\WalletUpPro\Core\Tenancy\Tenant::metadata((array) ($data['metadata'] ?? []))),
                'ip_address' => $this->getClientIP(),
                'user_agent' => sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')) ?? '',
                'created_at' => current_time('mysql'),
                'expires_at' => gmdate('Y-m-d H:i:s', strtotime('+24 hours')),
                'wp_user_id' => get_current_user_id() ?: null,
                'wc_order_id' => isset($data['wc_order_id']) ? absint($data['wc_order_id']) : null,
                'wpforms_entry_id' => isset($data['wpforms_entry_id']) ? absint($data['wpforms_entry_id']) : null
            ];

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Write operation, caching not applicable.
            $transaction = \WalletUpPro\Core\Tenancy\Tenant::stamp($transaction, $this->table);
            $result      = $wpdb->insert($this->table, $transaction);

            if ($result === false) {
                throw new Exception(esc_html('Failed to create payment record: ' . $wpdb->last_error));
            }

            $paymentUrl = '';
            if ($this->requiresBackendProcessing($paymentMethod)) {
                $backendSession = $this->createBackendPaymentSession($paymentId, $transaction);
                if (!empty($backendSession['payment_url'])) {
                    $paymentUrl = $this->getBackendBaseUrl() . $backendSession['payment_url'];
                }
            }

            return [
                'success' => true,
                'payment_id' => $paymentId,
                'payment_hash' => $paymentHash,
                'session_id' => $sessionId,
                'payment_url' => $paymentUrl,
                'expires_at' => $transaction['expires_at']
            ];

        } catch (Exception $e) {
            $this->errorHandler->log('Payment initialization error: ' . $e->getMessage());
            throw $e;
        }
    }

    public function getPaymentStatus(string $paymentId): ?object
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fresh data required for accurate results.
        $payment = $wpdb->get_row($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
            "SELECT * FROM {$this->table} WHERE payment_id = %s",
            $paymentId
        ));

        if ($payment && $payment->metadata) {
            $payment->metadata = json_decode($payment->metadata, true);
        }

        return $payment;
    }

    public function tagMethodLabel(string $intentId): void
    {
        if (!class_exists('\\WalletUpPro\\Core\\Config\\StripeMethodCatalog') || strpos($intentId, 'pi_') !== 0) {
            return;
        }
        $label = \WalletUpPro\Core\Config\StripeMethodCatalog::labelForIntent($intentId);
        if ($label === '') {
            return;
        }
        global $wpdb;
        $like = '%' . $wpdb->esc_like('"stripe_intent_id":"' . $intentId . '"') . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row($wpdb->prepare("SELECT payment_id, metadata FROM {$this->table} WHERE provider_transaction_id = %s OR metadata LIKE %s ORDER BY id DESC LIMIT 1", $intentId, $like), ARRAY_A);
        if (!$row) {
            return;
        }
        $meta = $row['metadata'] ? json_decode((string) $row['metadata'], true) : [];
        if (!is_array($meta)) {
            $meta = [];
        }
        $meta['method_label'] = $label;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write operation.
        $wpdb->update($this->table, ['metadata' => wp_json_encode($meta)], ['payment_id' => $row['payment_id']]);
    }

    public function updatePaymentStatus(string $paymentId, string $status, array $data = []): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $currentStatus = strtolower(trim((string) $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$this->table} WHERE payment_id = %s LIMIT 1",
            $paymentId
        ))));
        $newStatus = strtolower(trim($status));
        if ($currentStatus !== '') {
            if ($currentStatus === $newStatus) {
                return true;
            }
            if (in_array($currentStatus, ['refunded', 'cancelled'], true)) {
                $this->errorHandler->log("Payment {$paymentId}: ignored {$currentStatus} -> {$newStatus} (terminal state)");
                return false;
            }
            if ($currentStatus === 'completed' && !in_array($newStatus, ['refunded', 'cancelled'], true)) {
                $this->errorHandler->log("Payment {$paymentId}: ignored {$currentStatus} -> {$newStatus} (no regression from completed)");
                return false;
            }
        }

        $update = [
            'status' => $status,
            'updated_at' => current_time('mysql')
        ];

        if ($status === 'completed') {
            $update['completed_at'] = current_time('mysql');
        }

        if (isset($data['provider_transaction_id'])) {
            $providerTxn = (string) $data['provider_transaction_id'];
            $update['provider_transaction_id'] = $providerTxn !== '' ? $providerTxn : null;
        }

        if (isset($data['payment_method'])) {
            $update['payment_method'] = $data['payment_method'];
        }

        if (isset($data['payment_provider'])) {
            $update['payment_provider'] = $data['payment_provider'];
        }

        if (isset($data['provider_response'])) {
            $update['provider_response'] = wp_json_encode($data['provider_response']);
        }

        if (isset($data['fee_amount'])) {
            $update['fee_amount'] = floatval($data['fee_amount']);
            $update['net_amount'] = floatval($data['amount']) - floatval($data['fee_amount']);
        }

        if (!empty($data['provider_transaction_id'])
            && strpos((string) $data['provider_transaction_id'], 'pi_') === 0
            && class_exists('\\WalletUpPro\\Core\\Config\\StripeMethodCatalog')) {
            $methodLabel = \WalletUpPro\Core\Config\StripeMethodCatalog::labelForIntent((string) $data['provider_transaction_id']);
            if ($methodLabel !== '') {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $existingMeta = $wpdb->get_var($wpdb->prepare("SELECT metadata FROM {$this->table} WHERE payment_id = %s", $paymentId));
                $ledgerMeta = $existingMeta ? json_decode((string) $existingMeta, true) : [];
                if (!is_array($ledgerMeta)) {
                    $ledgerMeta = [];
                }
                $ledgerMeta['method_label'] = $methodLabel;
                $update['metadata'] = wp_json_encode($ledgerMeta);
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Write operation, caching not applicable.
        $result = $wpdb->update(
            $this->table,
            $update,
            ['payment_id' => $paymentId],
            array_fill(0, count($update), '%s'),
            ['%s']
        );

        if ($result !== false) {
            $this->errorHandler->log("Payment {$paymentId} status updated to: {$status}");

            do_action('walletup_payment_status_changed', $paymentId, $status, $data);

            return true;
        }

        return false;
    }

    public function processWebhook(string $source, array $webhookData): bool
    {
        try {
            $payment = $this->findPaymentByWebhook($source, $webhookData);

            if (!$payment) {
                throw new Exception(esc_html('Payment not found for webhook'));
            }

            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Write operation, caching not applicable.
            $wpdb->update(
                $this->table,
                [
                    'webhook_received_at' => current_time('mysql'),
                    'webhook_attempts' => $payment->webhook_attempts + 1,
                    'webhook_data' => wp_json_encode($webhookData)
                ],
                ['payment_id' => $payment->payment_id]
            );

            $this->handleWebhookStatus($payment, $source, $webhookData);

            return true;

        } catch (Exception $e) {
            $this->errorHandler->log("Webhook processing error: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Determine if a payment method requires the pay.walletup.app backend.
     *
     * Mobile money methods need hosted processing on our backend.
     * Traditional (bank, CashApp, Venmo, Zelle, PayPal.me) and crypto (BTC, ETH, etc.)
     * are P2P — the plugin handles them entirely within WordPress via QR codes / links.
     */
    private function requiresBackendProcessing(string $paymentMethod): bool
    {
        if (class_exists(\WalletUpPro\Core\Config\PaymentMethodsConfig::class)) {
            if (\WalletUpPro\Core\Config\PaymentMethodsConfig::isMobileMoney($paymentMethod)) {
                return true;
            }
        }

        return \WalletUpPro\Core\Config\PaymentMethodsConfig::isMobileMoney($paymentMethod);
    }

    private function getBackendBaseUrl(): string
    {
        return defined('WALLETUP_PAYMENT_BACKEND_URL')
            ? WALLETUP_PAYMENT_BACKEND_URL
            : 'https://pay.walletup.app';
    }

    private function getPaymentUrl(string $paymentId): string
    {
        return $this->getBackendBaseUrl() . "/pay/{$paymentId}";
    }

    private function generatePaymentId(): string
    {
        return 'wup_' . bin2hex(random_bytes(16));
    }

    private function generatePaymentHash(array $data): string
    {
        $secret = defined('WALLET_UP_LINK_SECRET')
            ? WALLET_UP_LINK_SECRET
            : wp_salt('auth');

        $values = implode('|', [
            $data['email'] ?? '',
            $data['amt'] ?? '',
            time(),
            wp_rand()
        ]);

        return hash_hmac('sha256', $values, $secret);
    }

    private function generateSessionId(): string
    {
        return 'sess_' . bin2hex(random_bytes(16));
    }

    private function validatePaymentData(array $data): void
    {
        $required = ['email', 'amt'];

        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new Exception(esc_html("Missing required field: {$field}"));
            }
        }

        if (!is_email($data['email'])) {
            throw new Exception(esc_html('Invalid email address'));
        }

        if (!is_numeric($data['amt']) || floatval($data['amt']) <= 0) {
            throw new Exception(esc_html('Invalid amount'));
        }
    }

    private function getClientIP(): string
    {
        $headers = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (!empty(sanitize_text_field(wp_unslash($_SERVER[$header] ?? '')))) {
                $ips = explode(',', sanitize_text_field(wp_unslash($_SERVER[$header] ?? '')));
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    private function findPaymentByWebhook(string $source, array $data): ?object
    {
        global $wpdb;

        if (!empty($data['provider_transaction_id'])) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fresh data required for accurate results.
            $payment = $wpdb->get_row($wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
                "SELECT * FROM {$this->table}
                 WHERE provider_transaction_id = %s
                 AND payment_provider = %s",
                $data['provider_transaction_id'],
                $source
            ));

            if ($payment) {
                return $payment;
            }
        }

        if (!empty($data['payment_id'])) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fresh data required for accurate results.
            return $wpdb->get_row($wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
                "SELECT * FROM {$this->table} WHERE payment_id = %s",
                $data['payment_id']
            ));
        }

        return null;
    }

    private function handleWebhookStatus(object $payment, string $source, array $data): void
    {
        $status = $data['status'] ?? 'unknown';

        switch ($status) {
            case 'completed':
            case 'success':
                $this->updatePaymentStatus(
                    $payment->payment_id,
                    'completed',
                    $data
                );
                break;

            case 'failed':
            case 'declined':
                $this->updatePaymentStatus(
                    $payment->payment_id,
                    'failed',
                    $data
                );
                break;

            case 'pending':
            case 'processing':
                $this->updatePaymentStatus(
                    $payment->payment_id,
                    'processing',
                    $data
                );
                break;
        }
    }

    public function getPaymentsByStatus(string $status, int $limit = 50): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fresh data required for accurate results.
        $payments = $wpdb->get_results($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
            "SELECT * FROM {$this->table}
             WHERE status = %s
             ORDER BY created_at DESC
             LIMIT %d",
            $status,
            $limit
        ));

        return $payments ?: [];
    }

    public function getCustomerPayments(string $email, int $limit = 50): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fresh data required for accurate results.
        $payments = $wpdb->get_results($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
            "SELECT * FROM {$this->table}
             WHERE customer_email = %s
             ORDER BY created_at DESC
             LIMIT %d",
            $email,
            $limit
        ));

        return $payments ?: [];
    }

    private function createBackendPaymentSession(string $paymentId, array $transaction): array
    {
        $apiKey = MerchantProvisioning::getApiKey();
        if (empty($apiKey)) {
            $this->errorHandler->log('Backend session skipped: not provisioned (no API key)');
            return [];
        }

        $backendUrl = $this->getBackendBaseUrl();
        $webhookUrl = rest_url('walletup/v1/webhook/backend-notification');

        $response = wp_remote_post($backendUrl . '/api/payment/create-session', [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-API-Key' => $apiKey,
            ],
            'body' => wp_json_encode([
                'payment_id' => $paymentId,
                'amount' => floatval($transaction['amount']),
                'currency' => $transaction['currency'] ?? 'USD',
                'payment_method' => $transaction['payment_method'] ?? '',
                'customer_email' => $transaction['customer_email'] ?? '',
                'description' => $transaction['description'] ?? '',
                'webhook_url' => $webhookUrl,
                'return_url' => home_url('/walletup-payment-return/?payment_id=' . $paymentId),
                'wc_order_id' => $transaction['wc_order_id'] ?? null,
                'wpforms_entry_id' => $transaction['wpforms_entry_id'] ?? null,
                'expires_at' => $transaction['expires_at'] ?? '',
            ]),
            'timeout' => 10,
        ]);

        if (is_wp_error($response)) {
            $this->errorHandler->log('Backend session creation failed: ' . $response->get_error_message());
            return [];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200 || $code === 201) {
            return $body ?? [];
        }

        $this->errorHandler->log('Backend session creation failed: HTTP ' . $code . ' — ' . ($body['error'] ?? 'Unknown'));
        return [];
    }

    public static function backendRequest(string $endpoint, string $method = 'GET', array $body = []): ?array
    {
        $apiKey = MerchantProvisioning::getApiKey();
        if (empty($apiKey)) {
            return null;
        }

        $backendUrl = defined('WALLETUP_PAYMENT_BACKEND_URL')
            ? WALLETUP_PAYMENT_BACKEND_URL
            : 'https://pay.walletup.app';

        $args = [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-API-Key' => $apiKey,
            ],
            'timeout' => 15,
        ];

        if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
            $args['method'] = $method;
            $args['body'] = wp_json_encode($body);
            $response = wp_remote_post($backendUrl . $endpoint, $args);
        } else {
            $response = wp_remote_get($backendUrl . $endpoint, $args);
        }

        if (is_wp_error($response)) {
            return null;
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }

    public function cleanExpiredPayments(): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Direct query required, caching not applicable.
        $result = $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
            "UPDATE {$this->table}
             SET status = 'expired', updated_at = NOW()
             WHERE status = 'pending'
             AND expires_at < NOW()"
        );

        if ($result) {
            $this->errorHandler->log("Cleaned {$result} expired payments");
        }

        return $result ?: 0;
    }
}
