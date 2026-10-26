<?php
namespace WalletUpPro\API;

use WalletUpPro\Core\BaseSingleton;
use WalletUpPro\Core\Error\ErrorHandler;
use WalletUpPro\Services\PaymentProcessor;
use WP_REST_Request;
use WP_REST_Response;
use Exception;

if (!defined('ABSPATH')) {
    exit;
}

class WebhookHandler extends BaseSingleton
{
    private $errorHandler;
    private $paymentProcessor;
    private $webhookTable;

    protected function initialize(): void
    {
        global $wpdb;
        $this->errorHandler = ErrorHandler::getInstance();
        $this->paymentProcessor = PaymentProcessor::getInstance();
        $this->webhookTable = $wpdb->prefix . 'walletup_webhooks_log';

        $this->registerEndpoints();
    }

    private function registerEndpoints(): void
    {
        add_action('rest_api_init', function() {
            register_rest_route('walletup/v1', '/webhook/paypal', [
                'methods' => 'POST',
                'callback' => [$this, 'handlePayPalWebhook'],
                'permission_callback' => '__return_true'
            ]);

            register_rest_route('walletup/v1', '/webhook/crypto/bitcoin', [
                'methods' => 'POST',
                'callback' => [$this, 'handleBitcoinWebhook'],
                'permission_callback' => '__return_true'
            ]);

            register_rest_route('walletup/v1', '/webhook/crypto/ethereum', [
                'methods' => 'POST',
                'callback' => [$this, 'handleEthereumWebhook'],
                'permission_callback' => '__return_true'
            ]);

            register_rest_route('walletup/v1', '/webhook/mpesa', [
                'methods' => 'POST',
                'callback' => [$this, 'handleMPesaWebhook'],
                'permission_callback' => '__return_true'
            ]);

            register_rest_route('walletup/v1', '/webhook/orange-money', [
                'methods' => 'POST',
                'callback' => [$this, 'handleOrangeMoneyWebhook'],
                'permission_callback' => '__return_true'
            ]);

            register_rest_route('walletup/v1', '/webhook/mtn-momo', [
                'methods' => 'POST',
                'callback' => [$this, 'handleMTNMoMoWebhook'],
                'permission_callback' => '__return_true'
            ]);

            register_rest_route('walletup/v1', '/webhook/moov-money', [
                'methods' => 'POST',
                'callback' => [$this, 'handleMoovMoneyWebhook'],
                'permission_callback' => '__return_true'
            ]);

            register_rest_route('walletup/v1', '/webhook/airtel-money', [
                'methods' => 'POST',
                'callback' => [$this, 'handleAirtelMoneyWebhook'],
                'permission_callback' => '__return_true'
            ]);

            register_rest_route('walletup/v1', '/webhook/zamtel-kwacha', [
                'methods' => 'POST',
                'callback' => [$this, 'handleZamtelKwachaWebhook'],
                'permission_callback' => '__return_true'
            ]);

            register_rest_route('walletup/v1', '/webhook/wave', [
                'methods' => 'POST',
                'callback' => [$this, 'handleWaveWebhook'],
                'permission_callback' => '__return_true'
            ]);

            register_rest_route('walletup/v1', '/webhook/stripe', [
                'methods' => 'POST',
                'callback' => [$this, 'handleStripeWebhook'],
                'permission_callback' => '__return_true'
            ]);

            register_rest_route('wallet-up-pro/v1', '/stripe/webhook', [
                'methods' => 'POST',
                'callback' => [$this, 'handleStripeWebhook'],
                'permission_callback' => '__return_true'
            ]);

            register_rest_route('walletup/v1', '/webhook/generic', [
                'methods' => 'POST',
                'callback' => [$this, 'handleGenericWebhook'],
                'permission_callback' => '__return_true'
            ]);

            register_rest_route('walletup/v1', '/webhook/backend-notification', [
                'methods' => 'POST',
                'callback' => [$this, 'handleBackendNotification'],
                'permission_callback' => '__return_true'
            ]);
        });
    }

    public function handlePayPalWebhook(WP_REST_Request $request): WP_REST_Response
    {
        $startTime = microtime(true);
        $webhookId = $this->generateWebhookId();

        try {
            $this->logWebhook($webhookId, 'paypal', $request);
            \WalletUpPro\Core\Tenancy\Tenant::enterWebhook((array) $request->get_json_params());

            $body = $request->get_json_params();
            $headers = $request->get_headers();

            if (!$this->verifyPayPalSignature($headers, $body)) {
                $this->updateWebhookStatus($webhookId, 'rejected', 403, microtime(true) - $startTime, 'Invalid signature');
                return new WP_REST_Response(['success' => false, 'error' => 'Invalid signature'], 403);
            }

            $eventType = $body['event_type'] ?? '';
            $resource = $body['resource'] ?? [];

            $subscriptionEvents = [
                'BILLING.SUBSCRIPTION.CREATED',
                'BILLING.SUBSCRIPTION.ACTIVATED',
                'BILLING.SUBSCRIPTION.CANCELLED',
                'BILLING.SUBSCRIPTION.SUSPENDED',
                'BILLING.SUBSCRIPTION.EXPIRED',
                'PAYMENT.SALE.COMPLETED',
            ];

            if (in_array($eventType, $subscriptionEvents, true)) {
                try {
                    $subManager = \WalletUpPro\Core\Subscriptions\SubscriptionManager::getInstance();
                    $subManager->handlePayPalSubscriptionEvent($eventType, $resource);
                } catch (Exception $e) {
                    $this->errorHandler->log('PayPal subscription webhook error: ' . $e->getMessage());
                }
            }

            if (in_array($eventType, ['PAYMENT.CAPTURE.COMPLETED', 'CHECKOUT.ORDER.COMPLETED'], true)) {
                $this->handlePayPalCaptureForWcOrder($eventType, $resource);
            }

            $processedData = [
                'payment_id' => $resource['custom_id'] ?? $resource['invoice_id'] ?? null,
                'provider_transaction_id' => $resource['id'] ?? null,
                'status' => $this->mapPayPalStatus($eventType),
                'amount' => isset($resource['amount']) ? floatval($resource['amount']['value']) : null,
                'currency' => $resource['amount']['currency_code'] ?? 'USD',
                'provider_response' => $resource
            ];

            $this->paymentProcessor->processWebhook('paypal', $processedData);

            $this->updateWebhookStatus(
                $webhookId,
                'completed',
                200,
                microtime(true) - $startTime
            );

            return new WP_REST_Response([
                'success' => true,
                'webhook_id' => $webhookId
            ], 200);

        } catch (Exception $e) {
            $this->errorHandler->log('PayPal webhook error: ' . $e->getMessage());
            $this->updateWebhookStatus(
                $webhookId,
                'failed',
                500,
                microtime(true) - $startTime,
                $e->getMessage()
            );

            return new WP_REST_Response([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function handleBitcoinWebhook(WP_REST_Request $request): WP_REST_Response
    {
        $startTime = microtime(true);
        $webhookId = $this->generateWebhookId();

        try {
            $this->logWebhook($webhookId, 'bitcoin', $request);
            \WalletUpPro\Core\Tenancy\Tenant::enterWebhook((array) $request->get_json_params());

            if (!$this->verifyWebhookSignature($request, 'bitcoin')) {
                $this->updateWebhookStatus($webhookId, 'rejected', 403, microtime(true) - $startTime, 'Invalid signature');
                return new WP_REST_Response(['success' => false, 'error' => 'Invalid signature'], 403);
            }

            $body = $request->get_json_params();

            $processedData = [
                'payment_id' => $body['payment_id'] ?? null,
                'crypto_address' => $body['address'] ?? null,
                'crypto_txn_hash' => $body['hash'] ?? null,
                'crypto_confirmations' => isset($body['confirmations']) ? intval($body['confirmations']) : 0,
                'amount' => isset($body['value']) ? $this->satoshiToBTC($body['value']) : null,
                'status' => $this->mapCryptoStatus($body),
                'provider_response' => $body
            ];

            $this->paymentProcessor->processWebhook('bitcoin', $processedData);

            $this->updateWebhookStatus($webhookId, 'completed', 200, microtime(true) - $startTime);

            return new WP_REST_Response(['success' => true], 200);

        } catch (Exception $e) {
            $this->errorHandler->log('Bitcoin webhook error: ' . $e->getMessage());
            $this->updateWebhookStatus($webhookId, 'failed', 500, microtime(true) - $startTime, $e->getMessage());

            return new WP_REST_Response(['success' => false], 500);
        }
    }

    public function handleMPesaWebhook(WP_REST_Request $request): WP_REST_Response
    {
        $startTime = microtime(true);
        $webhookId = $this->generateWebhookId();

        try {
            $this->logWebhook($webhookId, 'mpesa', $request);
            \WalletUpPro\Core\Tenancy\Tenant::enterWebhook((array) $request->get_json_params());

            if (!$this->verifyWebhookSignature($request, 'mpesa')) {
                $this->updateWebhookStatus($webhookId, 'rejected', 403, microtime(true) - $startTime, 'Invalid signature');
                return new WP_REST_Response(['success' => false, 'error' => 'Invalid signature'], 403);
            }

            $body = $request->get_json_params();

            $result = $body['Body']['stkCallback'] ?? $body;
            $resultCode = $result['ResultCode'] ?? -1;

            $processedData = [
                'payment_id' => $result['AccountReference'] ?? null,
                'provider_transaction_id' => $result['MpesaReceiptNumber'] ?? null,
                'status' => $resultCode === 0 ? 'completed' : 'failed',
                'mobile_money_reference' => $result['MpesaReceiptNumber'] ?? null,
                'provider_response' => $result
            ];

            if (isset($result['CallbackMetadata']['Item'])) {
                foreach ($result['CallbackMetadata']['Item'] as $item) {
                    if ($item['Name'] === 'Amount') {
                        $processedData['amount'] = floatval($item['Value']);
                    }
                }
            }

            $this->paymentProcessor->processWebhook('mpesa', $processedData);

            $this->updateWebhookStatus($webhookId, 'completed', 200, microtime(true) - $startTime);

            return new WP_REST_Response(['ResultCode' => 0, 'ResultDesc' => 'Success'], 200);

        } catch (Exception $e) {
            $this->errorHandler->log('M-Pesa webhook error: ' . $e->getMessage());
            $this->updateWebhookStatus($webhookId, 'failed', 500, microtime(true) - $startTime, $e->getMessage());

            return new WP_REST_Response(['ResultCode' => 1, 'ResultDesc' => 'Failed'], 500);
        }
    }

    public function handleOrangeMoneyWebhook(WP_REST_Request $request): WP_REST_Response
    {
        return $this->handleGenericMobileMoneyWebhook($request, 'orange_money');
    }

    public function handleMTNMoMoWebhook(WP_REST_Request $request): WP_REST_Response
    {
        return $this->handleGenericMobileMoneyWebhook($request, 'mtn_momo');
    }

    public function handleMoovMoneyWebhook(WP_REST_Request $request): WP_REST_Response
    {
        return $this->handleGenericMobileMoneyWebhook($request, 'moov_money');
    }

    public function handleAirtelMoneyWebhook(WP_REST_Request $request): WP_REST_Response
    {
        return $this->handleGenericMobileMoneyWebhook($request, 'airtel_money');
    }

    public function handleZamtelKwachaWebhook(WP_REST_Request $request): WP_REST_Response
    {
        return $this->handleGenericMobileMoneyWebhook($request, 'zamtel_kwacha');
    }

    public function handleWaveWebhook(WP_REST_Request $request): WP_REST_Response
    {
        return $this->handleGenericMobileMoneyWebhook($request, 'wave_ci');
    }

    public function handleEthereumWebhook(WP_REST_Request $request): WP_REST_Response
    {
        $startTime = microtime(true);
        $webhookId = $this->generateWebhookId();

        try {
            $this->logWebhook($webhookId, 'ethereum', $request);
            \WalletUpPro\Core\Tenancy\Tenant::enterWebhook((array) $request->get_json_params());

            if (!$this->verifyWebhookSignature($request, 'ethereum')) {
                $this->updateWebhookStatus($webhookId, 'rejected', 403, microtime(true) - $startTime, 'Invalid signature');
                return new WP_REST_Response(['success' => false, 'error' => 'Invalid signature'], 403);
            }

            $body = $request->get_json_params();

            $processedData = [
                'payment_id' => $body['payment_id'] ?? null,
                'crypto_address' => $body['address'] ?? null,
                'crypto_txn_hash' => $body['hash'] ?? null,
                'crypto_confirmations' => isset($body['confirmations']) ? intval($body['confirmations']) : 0,
                'amount' => isset($body['value']) ? floatval($body['value']) : null,
                'status' => $this->mapCryptoStatus($body),
                'provider_response' => $body
            ];

            $this->paymentProcessor->processWebhook('ethereum', $processedData);
            $this->updateWebhookStatus($webhookId, 'completed', 200, microtime(true) - $startTime);

            return new WP_REST_Response(['success' => true], 200);

        } catch (Exception $e) {
            $this->errorHandler->log('Ethereum webhook error: ' . $e->getMessage());
            $this->updateWebhookStatus($webhookId, 'failed', 500, microtime(true) - $startTime, $e->getMessage());
            return new WP_REST_Response(['success' => false], 500);
        }
    }

    private function handleGenericMobileMoneyWebhook(WP_REST_Request $request, string $provider): WP_REST_Response
    {
        $startTime = microtime(true);
        $webhookId = $this->generateWebhookId();

        try {
            $this->logWebhook($webhookId, $provider, $request);
            \WalletUpPro\Core\Tenancy\Tenant::enterWebhook((array) $request->get_json_params());

            if (!$this->verifyWebhookSignature($request, $provider)) {
                $this->updateWebhookStatus($webhookId, 'rejected', 403, microtime(true) - $startTime, 'Invalid signature');
                return new WP_REST_Response(['success' => false, 'error' => 'Invalid signature'], 403);
            }

            $body = $request->get_json_params();

            $processedData = [
                'payment_id' => $body['payment_id'] ?? $body['reference'] ?? null,
                'provider_transaction_id' => $body['transaction_id'] ?? null,
                'status' => $this->mapMobileMoneyStatus($body['status'] ?? ''),
                'amount' => isset($body['amount']) ? floatval($body['amount']) : null,
                'mobile_money_reference' => $body['reference'] ?? null,
                'provider_response' => $body
            ];

            $this->paymentProcessor->processWebhook($provider, $processedData);

            $this->updateWebhookStatus($webhookId, 'completed', 200, microtime(true) - $startTime);

            return new WP_REST_Response(['success' => true], 200);

        } catch (Exception $e) {
            $this->errorHandler->log("{$provider} webhook error: " . $e->getMessage());
            $this->updateWebhookStatus($webhookId, 'failed', 500, microtime(true) - $startTime, $e->getMessage());

            return new WP_REST_Response(['success' => false], 500);
        }
    }

    public function handleStripeWebhook(WP_REST_Request $request): WP_REST_Response
    {
        $startTime = microtime(true);
        $webhookId = $this->generateWebhookId();

        try {
            $this->logWebhook($webhookId, 'stripe', $request);
            \WalletUpPro\Core\Tenancy\Tenant::enterWebhook((array) $request->get_json_params());

            $webhookSecret = '';
            $encryption = \WalletUpPro\Core\Security\DataEncryption::getInstance();

            $wcSettings = get_option('woocommerce_wallet_up_card_settings', []);
            $isTestMode = class_exists('\\WalletUpPro\\Core\\Config\\AutomatedApiConfig')
                ? \WalletUpPro\Core\Config\AutomatedApiConfig::stripeTestMode()
                : (!empty($wcSettings['test_mode']) && $wcSettings['test_mode'] === 'yes');

            $stripeConfig = get_option('wallet_up_stripe_config', []);

            $decryptSafe = function ($raw) use ($encryption) {
                if (empty($raw)) {
                    return '';
                }
                $payload = str_starts_with($raw, 'walletup_enc:') ? substr($raw, strlen('walletup_enc:')) : $raw;
                try {
                    $decrypted = $encryption->decrypt($payload);
                    if ($decrypted !== false && $decrypted !== '') {
                        return $decrypted;
                    }
                } catch (Exception $e) {
                }
                return $raw;
            };

            $secrets = [];
            $addSecret = function ($raw) use (&$secrets, $decryptSafe) {
                $secret = (string) $decryptSafe($raw);
                if ($secret !== '' && !in_array($secret, $secrets, true)) {
                    $secrets[] = $secret;
                }
            };
            foreach ([$isTestMode, !$isTestMode] as $testSecret) {
                $prefix = $testSecret ? 'test' : 'live';
                $addSecret($stripeConfig[$prefix . '_webhook_endpoint_secret'] ?? '');
                $addSecret($wcSettings[$prefix . '_webhook_secret'] ?? '');
            }
            $addSecret($stripeConfig['webhook_endpoint_secret'] ?? '');
            $addSecret($wcSettings['webhook_secret'] ?? '');
            $webhookSecret = $secrets[0] ?? '';

            if (empty($webhookSecret)) {
                $this->errorHandler->log('Stripe webhook rejected: webhook endpoint secret not configured');
                $this->updateWebhookStatus($webhookId, 'rejected', 403, microtime(true) - $startTime, 'Webhook secret not configured');
                return new WP_REST_Response(['success' => false, 'error' => 'Webhook not configured'], 403);
            }

            $rawBody = $request->get_body();
            $sigHeader = $request->get_header('Stripe-Signature') ?? '';

            if (empty($sigHeader)) {
                $this->errorHandler->log('Stripe webhook rejected: missing Stripe-Signature header');
                $this->updateWebhookStatus($webhookId, 'rejected', 403, microtime(true) - $startTime, 'Missing signature');
                return new WP_REST_Response(['success' => false, 'error' => 'Missing signature'], 403);
            }

            $event = null;
            $signatureError = '';
            foreach ($secrets as $candidate) {
                try {
                    $event = \Stripe\Webhook::constructEvent($rawBody, $sigHeader, $candidate);
                    break;
                } catch (\Stripe\Exception\SignatureVerificationException $e) {
                    $signatureError = $signatureError === '' ? $e->getMessage() : $signatureError;
                } catch (\UnexpectedValueException $e) {
                    $this->errorHandler->log('Stripe webhook rejected: invalid payload — ' . $e->getMessage());
                    $this->updateWebhookStatus($webhookId, 'rejected', 400, microtime(true) - $startTime, 'Invalid payload');
                    return new WP_REST_Response(['success' => false, 'error' => 'Invalid payload'], 400);
                }
            }
            if ($event === null) {
                $this->errorHandler->log(sprintf('Stripe webhook rejected: the signature matched none of the %d configured webhook secrets (%s)', count($secrets), $signatureError));
                $this->updateWebhookStatus($webhookId, 'rejected', 403, microtime(true) - $startTime, 'Invalid signature');
                return new WP_REST_Response(['success' => false, 'error' => 'Invalid signature'], 403);
            }

            if (!$isTestMode && empty($event->livemode)) {
                $this->errorHandler->log('Stripe webhook ignored: a test-mode event (' . $event->type . ') reached a store that takes live payments');
                $this->updateWebhookStatus($webhookId, 'completed', 200, microtime(true) - $startTime, 'Test-mode event ignored on a live store');
                return new WP_REST_Response(['success' => true, 'ignored' => 'test_mode_event'], 200);
            }

            $eventType = $event->type;
            $object = $event->data->object;

            $subscriptionEvents = [
                'invoice.payment_succeeded',
                'invoice.payment_failed',
                'customer.subscription.updated',
                'customer.subscription.deleted',
            ];

            if (in_array($eventType, $subscriptionEvents, true)) {
                try {
                    $subManager = \WalletUpPro\Core\Subscriptions\SubscriptionManager::getInstance();
                    $subManager->handleStripeSubscriptionEvent($eventType, $object);
                } catch (Exception $e) {
                    $this->errorHandler->log('Stripe subscription webhook error: ' . $e->getMessage());
                }
            }

            switch ($eventType) {
                case 'payment_intent.succeeded':
                    $this->handleStripePaymentSucceeded($object);
                    break;

                case 'payment_intent.payment_failed':
                    $this->handleStripePaymentFailed($object);
                    break;

                case 'charge.refunded':
                    $this->handleStripeChargeRefunded($object);
                    break;

                case 'charge.dispute.created':
                    $this->handleStripeDisputeCreated($object);
                    break;

                case 'invoice.payment_succeeded':
                case 'invoice.payment_failed':
                case 'customer.subscription.updated':
                case 'customer.subscription.deleted':
                    break; // Already handled

                default:
                    $this->errorHandler->log("Stripe webhook: unhandled event type {$eventType}");
                    break;
            }

            $this->updateWebhookStatus($webhookId, 'completed', 200, microtime(true) - $startTime);

            return new WP_REST_Response(['success' => true, 'webhook_id' => $webhookId], 200);

        } catch (Exception $e) {
            $this->errorHandler->log('Stripe webhook error: ' . $e->getMessage());
            $this->updateWebhookStatus($webhookId, 'failed', 500, microtime(true) - $startTime, $e->getMessage());
            return new WP_REST_Response(['success' => false, 'error' => 'Internal error'], 500);
        }
    }

    private function fireResolved(object $obj, string $state): void
    {
        $md = isset($obj->metadata) ? (array) $obj->metadata : [];
        $pid = (string) ($obj->id ?? '');
        if ($state === 'refunded' && isset($obj->payment_intent)) {
            $pid = (string) $obj->payment_intent;
        }
        $data = [
            'provider_transaction_id' => $pid,
            'amount' => isset($obj->amount) ? \WalletUpPro\Core\Payments\PaymentGatewayHandler::fromMinorUnits((int) $obj->amount, (string) ($obj->currency ?? 'usd')) : 0,
            'currency' => (string) ($obj->currency ?? 'usd'),
            'donation_id' => $md['donation_id'] ?? null,
            'membership_id' => $md['membership_id'] ?? null,
            'metadata' => $md,
        ];
        do_action('walletup_payment_resolved', $state, $data);
    }

    private function handleStripePaymentSucceeded(object $paymentIntent): void
    {
        $this->fireResolved($paymentIntent, 'completed');
        if (!function_exists('wc_get_order')) {
            return;
        }

        $orderId = $paymentIntent->metadata->order_id
            ?? $paymentIntent->metadata->wc_order_id
            ?? null;

        $order = $orderId ? wc_get_order($orderId) : null;

        if (!$order) {
            global $wpdb;
            $txTable = $wpdb->prefix . 'walletup_payment_transactions';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wcOrderId = $wpdb->get_var($wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT wc_order_id FROM {$txTable} WHERE provider_transaction_id = %s AND wc_order_id IS NOT NULL AND wc_order_id > 0 LIMIT 1",
                $paymentIntent->id
            ));
            if ($wcOrderId) {
                $order = wc_get_order($wcOrderId);
                $orderId = $wcOrderId;
            }
        }

        if (!$order && function_exists('wc_get_orders')) {
            $byIntent = wc_get_orders([
                'limit'      => 1,
                'return'     => 'ids',
                'meta_key'   => '_wallet_up_payment_id',
                'meta_value' => $paymentIntent->id,
            ]);
            if (!empty($byIntent)) {
                $orderId = intval($byIntent[0]);
                $order = wc_get_order($orderId);
            }
        }

        if (!$order) {
            $this->errorHandler->log("Stripe webhook: no WC order found for PI {$paymentIntent->id}");
            return;
        }

        $orderKey = $paymentIntent->metadata->order_key ?? '';
        if ($orderKey && $order->get_order_key() !== $orderKey) {
            $this->errorHandler->log("Stripe webhook: order key mismatch for order {$orderId}");
            return;
        }

        $paidMinor = (int) ($paymentIntent->amount_received ?? $paymentIntent->amount ?? 0);
        $dueMinor = \WalletUpPro\Core\Payments\PaymentGatewayHandler::toMinorUnits((float) $order->get_total(), (string) $order->get_currency());
        $sameCurrency = strtolower((string) ($paymentIntent->currency ?? '')) === strtolower((string) $order->get_currency());
        if (!$order->is_paid() && (!$sameCurrency || $paidMinor < $dueMinor)) {
            $this->errorHandler->log("Stripe webhook: PaymentIntent {$paymentIntent->id} does not cover order {$orderId}; order left unpaid");
            /* translators: %s: Stripe PaymentIntent ID */
            $order->add_order_note(sprintf(esc_html__('Stripe reported a payment (PaymentIntent: %s) that does not match this order total, so the order was not marked as paid.', 'walletup'), $paymentIntent->id));
            $order->save();
            return;
        }

        if (!$order->is_paid()) {
            $order->payment_complete($paymentIntent->id);
            /* translators: %s: Stripe PaymentIntent ID */
            $order->add_order_note(sprintf(
                esc_html__('Payment confirmed via Stripe webhook (PaymentIntent: %s)', 'walletup'),
                $paymentIntent->id
            ));
            $order->update_meta_data('_stripe_payment_intent_id', $paymentIntent->id);
            $order->update_meta_data('_walletup_provider_transaction_id', $paymentIntent->id);
            if (!$order->get_meta('_walletup_provider')) {
                $order->update_meta_data('_walletup_provider', 'stripe');
            }
            $order->save();
        }

        $paymentId = $order->get_meta('_wallet_up_payment_id');
        if ($paymentId) {
            $this->paymentProcessor->updatePaymentStatus($paymentId, 'completed', [
                'provider_transaction_id' => $paymentIntent->id,
                'amount' => \WalletUpPro\Core\Payments\PaymentGatewayHandler::fromMinorUnits((int) ($paymentIntent->amount ?? 0), (string) ($paymentIntent->currency ?? 'usd')),
            ]);
        }

        $this->recordAnalyticsEvent('payment', 'stripe', [
            'amount' => \WalletUpPro\Core\Payments\PaymentGatewayHandler::fromMinorUnits((int) ($paymentIntent->amount ?? 0), (string) ($paymentIntent->currency ?? 'usd')),
            'currency' => $paymentIntent->currency ?? 'usd',
            'wc_order_id' => $orderId,
            'provider_id' => $paymentIntent->id,
            'status' => 'completed',
        ]);
    }

    private function handleStripePaymentFailed(object $paymentIntent): void
    {
        $this->fireResolved($paymentIntent, 'failed');
        $orderId = $paymentIntent->metadata->order_id ?? null;
        if (!$orderId || !function_exists('wc_get_order')) {
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order) {
            return;
        }

        $failureMessage = $paymentIntent->last_payment_error->message ?? 'Unknown error';
        $order->update_status('failed');
        /* translators: %s: Error message from Stripe */
        $order->add_order_note(sprintf(
            esc_html__('Stripe payment failed: %s', 'walletup'),
            $failureMessage
        ));
        $order->save();

        $paymentId = $order->get_meta('_wallet_up_payment_id');
        if ($paymentId) {
            $this->paymentProcessor->updatePaymentStatus($paymentId, 'failed', [
                'provider_transaction_id' => $paymentIntent->id,
            ]);
        }

        $this->recordAnalyticsEvent('payment_failed', 'stripe', [
            'amount' => \WalletUpPro\Core\Payments\PaymentGatewayHandler::fromMinorUnits((int) ($paymentIntent->amount ?? 0), (string) ($paymentIntent->currency ?? 'usd')),
            'wc_order_id' => $orderId,
            'error' => $failureMessage,
        ]);
    }

    private function handleStripeChargeRefunded(object $charge): void
    {
        $this->fireResolved($charge, 'refunded');
        $intentId = $charge->payment_intent ?? null;
        if (!$intentId || !function_exists('wc_get_orders')) {
            return;
        }

        $orders = wc_get_orders([
            'meta_key' => '_stripe_payment_intent_id',
            'meta_value' => $intentId,
            'limit' => 1,
        ]);

        if (empty($orders)) {
            return;
        }

        $order = $orders[0];
        $refundAmount = \WalletUpPro\Core\Payments\PaymentGatewayHandler::fromMinorUnits((int) ($charge->amount_refunded ?? 0), (string) ($charge->currency ?? 'usd'));
        /* translators: %s: Refund amount */
        $order->add_order_note(sprintf(
            esc_html__('Stripe refund processed via webhook. Amount: %s', 'walletup'),
            wc_price($refundAmount)
        ));

        if ($charge->refunded) {
            $order->update_status('refunded');
        }
        $order->save();

        $this->recordAnalyticsEvent('refund', 'stripe', [
            'amount' => $refundAmount,
            'wc_order_id' => $order->get_id(),
            'provider_id' => $charge->id ?? '',
        ]);
    }

    private function handleStripeDisputeCreated(object $dispute): void
    {
        $this->fireResolved($dispute, 'refunded');
        $chargeId = $dispute->charge ?? null;
        if (!$chargeId || !function_exists('wc_get_orders')) {
            return;
        }

        $intentId = $dispute->payment_intent ?? null;
        if (!$intentId) {
            return;
        }

        $orders = wc_get_orders([
            'meta_key' => '_stripe_payment_intent_id',
            'meta_value' => $intentId,
            'limit' => 1,
        ]);

        if (empty($orders)) {
            return;
        }

        $order = $orders[0];
        /* translators: 1: Dispute reason, 2: Dispute amount */
        $order->add_order_note(sprintf(
            esc_html__('Stripe dispute created. Reason: %1$s. Amount: %2$s. Please respond in your Stripe Dashboard.', 'walletup'),
            $dispute->reason ?? 'unknown',
            wc_price(\WalletUpPro\Core\Payments\PaymentGatewayHandler::fromMinorUnits((int) ($dispute->amount ?? 0), (string) ($dispute->currency ?? 'usd')))
        ));
        $order->update_status('on-hold');
        $order->save();

        $this->recordAnalyticsEvent('dispute', 'stripe', [
            'amount' => \WalletUpPro\Core\Payments\PaymentGatewayHandler::fromMinorUnits((int) ($dispute->amount ?? 0), (string) ($dispute->currency ?? 'usd')),
            'wc_order_id' => $order->get_id(),
            'reason' => $dispute->reason ?? 'unknown',
        ]);
    }

    private function recordAnalyticsEvent(string $eventType, string $paymentMethod, array $metadata = []): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wallet_up_analytics';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Analytics insert, no caching needed.
        $wpdb->insert($table, [
            'event_type' => sanitize_text_field($eventType),
            'payment_method' => sanitize_text_field($paymentMethod),
            'amount' => isset($metadata['amount']) ? floatval($metadata['amount']) : null,
            'metadata' => wp_json_encode($metadata),
            'created_at' => current_time('mysql'),
        ]);
    }

    public function handleBackendNotification(WP_REST_Request $request): WP_REST_Response
    {
        $startTime = microtime(true);
        $webhookId = $this->generateWebhookId();

        try {
            $this->logWebhook($webhookId, 'backend', $request);
            \WalletUpPro\Core\Tenancy\Tenant::enterWebhook((array) $request->get_json_params());

            $signature = $request->get_header('X-WalletUp-Signature');
            $timestamp = $request->get_header('X-WalletUp-Timestamp');
            $rawBody = $request->get_body();

            if (empty($signature) || empty($timestamp)) {
                $this->updateWebhookStatus($webhookId, 'rejected', 401, microtime(true) - $startTime, 'Missing signature headers');
                return new WP_REST_Response(['success' => false, 'error' => 'Missing signature'], 401);
            }

            $sentAt = ctype_digit((string) $timestamp) ? intval($timestamp) : strtotime((string) $timestamp);
            if ($sentAt === false || abs(time() - $sentAt) > 300) {
                $this->updateWebhookStatus($webhookId, 'rejected', 401, microtime(true) - $startTime, 'Timestamp too old');
                return new WP_REST_Response(['success' => false, 'error' => 'Request expired'], 401);
            }

            $apiSecret = \WalletUpPro\Core\Payments\MerchantProvisioning::getApiSecret();
            if (empty($apiSecret)) {
                $this->updateWebhookStatus($webhookId, 'rejected', 403, microtime(true) - $startTime, 'API secret not configured');
                return new WP_REST_Response(['success' => false, 'error' => 'Not provisioned'], 403);
            }

            $expectedSignature = hash_hmac('sha256', $timestamp . '.' . $rawBody, $apiSecret);
            if (!hash_equals($expectedSignature, $signature)) {
                $this->updateWebhookStatus($webhookId, 'rejected', 401, microtime(true) - $startTime, 'Invalid signature');
                return new WP_REST_Response(['success' => false, 'error' => 'Invalid signature'], 401);
            }

            $body = $request->get_json_params();
            $event = sanitize_text_field($body['event'] ?? '');
            $paymentData = $body['data'] ?? [];

            if (empty($event) || empty($paymentData)) {
                $this->updateWebhookStatus($webhookId, 'rejected', 400, microtime(true) - $startTime, 'Missing event or data');
                return new WP_REST_Response(['success' => false, 'error' => 'Invalid payload'], 400);
            }

            $paymentId = sanitize_text_field($paymentData['payment_id'] ?? '');
            $providerTxnId = sanitize_text_field($paymentData['provider_transaction_id'] ?? '');
            $amount = floatval($paymentData['amount'] ?? 0);
            $currency = sanitize_text_field($paymentData['currency'] ?? 'USD');
            $paymentMethod = sanitize_text_field($paymentData['payment_method'] ?? '');
            $paymentProvider = sanitize_text_field($paymentData['payment_provider'] ?? '');

            switch ($event) {
                case 'payment.completed':
                    $this->handleBackendPaymentCompleted($paymentId, $providerTxnId, $amount, $currency, $paymentMethod, $paymentProvider, $paymentData);
                    break;

                case 'payment.failed':
                    do_action('walletup_payment_resolved', 'failed', $paymentData);
                    if (!empty($paymentId)) {
                        $this->paymentProcessor->updatePaymentStatus($paymentId, 'failed', [
                            'provider_transaction_id' => $providerTxnId,
                            'payment_method' => $paymentMethod,
                            'payment_provider' => $paymentProvider,
                        ]);
                    }
                    break;

                case 'payment.refunded':
                    do_action('walletup_payment_resolved', 'refunded', $paymentData);
                    if (!empty($paymentId)) {
                        $this->paymentProcessor->updatePaymentStatus($paymentId, 'refunded', [
                            'provider_transaction_id' => $providerTxnId,
                        ]);
                    }
                    $wcOrderId = intval($paymentData['wc_order_id'] ?? 0);
                    if ($wcOrderId > 0 && function_exists('wc_get_order')) {
                        $order = wc_get_order($wcOrderId);
                        if ($order && !in_array($order->get_status(), ['refunded', 'cancelled'], true)) {
                            $order->update_status('refunded', __('Refunded via pay.walletup.app backend', 'walletup'));
                        }
                    }
                    break;

                default:
                    $this->errorHandler->log("Backend notification: unhandled event '{$event}'");
            }

            $this->updateWebhookStatus($webhookId, 'processed', 200, microtime(true) - $startTime);

            return new WP_REST_Response(['success' => true, 'webhook_id' => $webhookId], 200);

        } catch (Exception $e) {
            $this->errorHandler->log('Backend notification error: ' . $e->getMessage());
            $this->updateWebhookStatus($webhookId, 'error', 500, microtime(true) - $startTime, $e->getMessage());
            return new WP_REST_Response(['success' => false, 'error' => 'Processing error'], 500);
        }
    }

    private function handleBackendPaymentCompleted(
        string $paymentId,
        string $providerTxnId,
        float $amount,
        string $currency,
        string $paymentMethod,
        string $paymentProvider,
        array $paymentData
    ): void {
        if (!empty($paymentId)) {
            $this->paymentProcessor->updatePaymentStatus($paymentId, 'completed', [
                'provider_transaction_id' => $providerTxnId,
                'payment_method' => $paymentMethod,
                'payment_provider' => $paymentProvider,
                'amount' => $amount,
            ]);
        }

        $wcOrderId = intval($paymentData['wc_order_id'] ?? 0);
        if ($wcOrderId === 0 && $providerTxnId !== '' && function_exists('wc_get_orders')) {
            $byIntent = wc_get_orders([
                'limit'      => 1,
                'return'     => 'ids',
                'meta_key'   => '_wallet_up_payment_id',
                'meta_value' => $providerTxnId,
            ]);
            if (!empty($byIntent)) {
                $wcOrderId = intval($byIntent[0]);
            }
        }
        if ($wcOrderId > 0 && function_exists('wc_get_order')) {
            $order = wc_get_order($wcOrderId);
            if ($order && !in_array($order->get_status(), ['completed', 'processing'], true)) {
                $order->payment_complete($providerTxnId);
                $order->add_order_note(sprintf(
                    /* translators: %1$s: payment method, %2$s: transaction ID */
                    __('Payment completed via %1$s. Transaction: %2$s', 'walletup'),
                    $paymentMethod,
                    $providerTxnId
                ));
                $order->update_meta_data('_walletup_backend_payment_id', $paymentId);
                $order->save();
            }
        }

        $this->recordAnalyticsEvent('payment', $paymentMethod, [
            'amount'      => $amount,
            'currency'    => $currency,
            'wc_order_id' => $wcOrderId,
            'provider_id' => $providerTxnId,
            'status'      => 'completed',
            'source'      => 'backend_webhook',
        ]);

        $wpformsEntryId = intval($paymentData['wpforms_entry_id'] ?? 0);
        if ($wpformsEntryId > 0) {
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update(
                $wpdb->prefix . 'wpforms_entries',
                ['status' => 'completed'],
                ['entry_id' => $wpformsEntryId],
                ['%s'],
                ['%d']
            );
        }

        do_action('walletup_backend_payment_completed', $paymentId, $paymentData);
    }

    public function handleGenericWebhook(WP_REST_Request $request): WP_REST_Response
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return new WP_REST_Response(['success' => false, 'error' => 'Debug endpoint disabled in production'], 403);
        }

        $webhookId = $this->generateWebhookId();
        $this->logWebhook($webhookId, 'generic', $request);

        return new WP_REST_Response([
            'success' => true,
            'webhook_id' => $webhookId,
            'message' => 'Webhook received and logged'
        ], 200);
    }

    private function logWebhook(string $webhookId, string $source, WP_REST_Request $request): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Write operation, caching not applicable.
        $wpdb->insert(
            $this->webhookTable,
            [
                'webhook_id' => sanitize_text_field($webhookId),
                'source' => sanitize_text_field($source),
                'request_method' => sanitize_text_field($request->get_method()),
                'request_headers' => wp_json_encode($request->get_headers()),
                'request_body' => wp_json_encode($request->get_json_params()),
                'status' => 'received',
                'ip_address' => sanitize_text_field($this->getClientIP()),
                'created_at' => current_time('mysql')
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
    }

    private function updateWebhookStatus(
        string $webhookId,
        string $status,
        int $responseStatus,
        float $processingTime,
        ?string $error = null
    ): void {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Write operation, caching not applicable.
        $wpdb->update(
            $this->webhookTable,
            [
                'status' => $status,
                'response_status' => $responseStatus,
                'processing_time_ms' => intval($processingTime * 1000),
                'processed_at' => current_time('mysql'),
                'error_message' => $error
            ],
            ['webhook_id' => $webhookId]
        );
    }

    private function verifyPayPalSignature(array $headers, array $body): bool
    {
        $webhook_id = '';
        $ppSettings = get_option('wallet_up_paypal_commerce_settings', []);
        if (is_array($ppSettings) && !empty($ppSettings['webhook_id'])) {
            $webhook_id = (string) $ppSettings['webhook_id'];
        }
        if (empty($webhook_id)) {
            $webhook_id = (string) get_option('walletup_paypal_webhook_id', '');
        }
        if (empty($webhook_id)) {
            $this->errorHandler->log('PayPal webhook rejected: webhook ID not configured');
            return false;
        }

        $required_headers = [
            'paypal-transmission-id',
            'paypal-transmission-time',
            'paypal-transmission-sig',
            'paypal-cert-url',
            'paypal-auth-algo',
        ];

        foreach ($required_headers as $header) {
            $normalized = str_replace('-', '_', $header);
            if (empty($headers[$normalized][0] ?? $headers[$header][0] ?? null)) {
                $this->errorHandler->log("PayPal webhook missing header: {$header}");
                return false;
            }
        }

        $transmission_id  = $headers['paypal_transmission_id'][0] ?? $headers['paypal-transmission-id'][0] ?? '';
        $timestamp        = $headers['paypal_transmission_time'][0] ?? $headers['paypal-transmission-time'][0] ?? '';
        $transmission_sig = $headers['paypal_transmission_sig'][0] ?? $headers['paypal-transmission-sig'][0] ?? '';
        $cert_url         = $headers['paypal_cert_url'][0] ?? $headers['paypal-cert-url'][0] ?? '';
        $auth_algo        = $headers['paypal_auth_algo'][0] ?? $headers['paypal-auth-algo'][0] ?? '';

        $accessToken = $this->getPayPalAccessToken();
        if (!$accessToken) {
            $this->errorHandler->log('PayPal webhook rejected: unable to obtain access token for verification');
            return false;
        }

        $settings = \WalletUpPro\Core\Config\AutomatedApiConfig::getPayPalCommerceCredentials();
        $baseUrl = !empty($settings['sandbox'])
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';

        $verifyPayload = [
            'auth_algo'         => $auth_algo,
            'cert_url'          => $cert_url,
            'transmission_id'   => $transmission_id,
            'transmission_sig'  => $transmission_sig,
            'transmission_time' => $timestamp,
            'webhook_id'        => $webhook_id,
            'webhook_event'     => $body,
        ];

        $response = wp_remote_post($baseUrl . '/v1/notifications/verify-webhook-signature', [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode($verifyPayload),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            $this->errorHandler->log('PayPal webhook verification API error: ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $responseBody = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200 && ($responseBody['verification_status'] ?? '') === 'SUCCESS') {
            $this->errorHandler->log('PayPal webhook signature verified for transmission: ' . $transmission_id);
            return true;
        }

        $this->errorHandler->log(
            'PayPal webhook signature verification failed: status=' . ($responseBody['verification_status'] ?? 'unknown')
            . ' http=' . $code
        );
        return false;
    }

    private function getPayPalAccessToken(): ?string
    {
        $settings = \WalletUpPro\Core\Config\AutomatedApiConfig::getPayPalCommerceCredentials();
        if (empty($settings['client_id']) || empty($settings['client_secret'])) {
            $this->errorHandler->log('PayPal OAuth skipped: no client credentials resolved from the PayPal Commerce settings or the legacy settings');
            return null;
        }

        $isSandbox = !empty($settings['sandbox']);
        $transientKey = 'walletup_paypal_token_' . ($isSandbox ? 'sandbox' : 'live');

        $cached = get_transient($transientKey);
        if ($cached) {
            return $cached;
        }

        $baseUrl = $isSandbox
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
            $this->errorHandler->log('PayPal OAuth error: ' . $response->get_error_message());
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['access_token'])) {
            return null;
        }

        $expiresIn = max(60, ($body['expires_in'] ?? 3600) - 60);
        set_transient($transientKey, $body['access_token'], $expiresIn);

        return $body['access_token'];
    }

    private function verifyWebhookSignature(WP_REST_Request $request, string $provider): bool
    {
        $secret = get_option("walletup_{$provider}_webhook_secret", '');

        if (!empty($secret) && str_starts_with($secret, 'walletup_enc:')) {
            try {
                $enc = \WalletUpPro\Core\Security\DataEncryption::getInstance();
                $secret = $enc->decrypt(substr($secret, strlen('walletup_enc:')));
            } catch (Exception $e) {
                $secret = '';
            }
        }

        if (empty($secret)) {
            $this->errorHandler->log("{$provider} webhook rejected: no webhook secret configured. Set walletup_{$provider}_webhook_secret in WP options.");
            return false;
        }

        $signature = $request->get_header('X-Webhook-Signature')
            ?? $request->get_header('X-Signature')
            ?? $request->get_header('X-Hub-Signature-256')
            ?? '';

        if (empty($signature)) {
            $this->errorHandler->log("{$provider} webhook rejected: missing signature header");
            return false;
        }

        $signature = preg_replace('/^sha256=/', '', $signature);

        $rawBody = $request->get_body();
        $expected = hash_hmac('sha256', $rawBody, $secret);

        if (!hash_equals($expected, $signature)) {
            $this->errorHandler->log("{$provider} webhook rejected: signature mismatch");
            return false;
        }

        return true;
    }

    private function handlePayPalCaptureForWcOrder(string $eventType, array $resource): void
    {
        if (!function_exists('wc_get_order')) {
            return;
        }

        $wcOrderId = null;
        $captureId = '';
        $paypalOrderId = '';
        if ($eventType === 'PAYMENT.CAPTURE.COMPLETED' || $eventType === 'PAYMENT.CAPTURE.DENIED' || $eventType === 'PAYMENT.CAPTURE.REFUNDED') {
            $wcOrderId = $resource['custom_id'] ?? null;
            $captureId = $resource['id'] ?? '';
            $paypalOrderId = $resource['supplementary_data']['related_ids']['order_id'] ?? '';
        } elseif ($eventType === 'CHECKOUT.ORDER.APPROVED' || $eventType === 'CHECKOUT.ORDER.COMPLETED') {
            $purchaseUnit = $resource['purchase_units'][0] ?? [];
            $wcOrderId = $purchaseUnit['custom_id'] ?? $purchaseUnit['reference_id'] ?? null;
            $captureId = $purchaseUnit['payments']['captures'][0]['id'] ?? '';
            $paypalOrderId = $resource['id'] ?? '';
        }

        $order = null;
        if (!empty($wcOrderId) && is_numeric($wcOrderId)) {
            $order = wc_get_order(absint($wcOrderId));
        }

        if (!$order && function_exists('wc_get_orders')) {
            $candidates = [];
            if ($captureId) {
                $candidates = wc_get_orders([
                    'limit' => 1,
                    'meta_key' => '_walletup_paypal_capture_id',
                    'meta_value' => $captureId,
                ]);
            }
            if (empty($candidates) && $paypalOrderId) {
                $candidates = wc_get_orders([
                    'limit' => 1,
                    'meta_key' => '_paypal_commerce_order_id',
                    'meta_value' => $paypalOrderId,
                ]);
            }
            if (!empty($candidates)) {
                $order = is_array($candidates) ? reset($candidates) : null;
            }
        }

        if (!$order) {
            return;
        }

        $supportedMethods = ['wallet_up', 'wallet_up_paypal_commerce'];
        if (!in_array($order->get_payment_method(), $supportedMethods, true)) {
            return;
        }

        if ($eventType === 'PAYMENT.CAPTURE.REFUNDED') {
            $refundedAmount = $resource['amount']['value'] ?? '';
            $order->add_order_note(sprintf(
                /* translators: 1: refund amount, 2: capture ID */
                esc_html__('PayPal webhook: capture refunded (amount: %1$s, capture: %2$s).', 'walletup'),
                $refundedAmount,
                $captureId ?: 'N/A'
            ));
            if ($order->get_status() !== 'refunded' && method_exists($order, 'update_status')) {
                $order->update_status('refunded', esc_html__('Refunded via PayPal.', 'walletup'));
            }
            $order->save();
            return;
        }

        if ($eventType === 'PAYMENT.CAPTURE.DENIED') {
            $order->add_order_note(sprintf(
                /* translators: %s: capture ID */
                esc_html__('PayPal webhook: capture denied (capture: %s).', 'walletup'),
                $captureId ?: 'N/A'
            ));
            $order->update_status('failed', esc_html__('PayPal capture denied.', 'walletup'));
            $order->save();
            return;
        }

        if ($order->is_paid()) {
            return;
        }

        $order->payment_complete($captureId);
        /* translators: 1: Event type, 2: Capture ID */
        $order->add_order_note(sprintf(
            esc_html__('PayPal webhook: %1$s — payment captured (ID: %2$s).', 'walletup'),
            $eventType,
            $captureId ?: 'N/A'
        ));

        $order->update_meta_data('_walletup_provider', 'paypal');
        $order->update_meta_data('_walletup_provider_transaction_id', $captureId);
        if (!empty($captureId)) {
            $order->update_meta_data('_walletup_paypal_capture_id', $captureId);
        }
        $order->save();

        $paymentId = $order->get_meta('_wallet_up_payment_id');
        if ($paymentId && $this->paymentProcessor) {
            $this->paymentProcessor->updatePaymentStatus($paymentId, 'completed', [
                'provider_transaction_id' => $captureId,
                'payment_method' => 'paypal_commerce',
                'payment_provider' => 'paypal',
            ]);
        }

        $this->recordAnalyticsEvent('payment', 'paypal_commerce', [
            'amount'      => floatval($order->get_total()),
            'currency'    => $order->get_currency(),
            'wc_order_id' => $order->get_id(),
            'provider_id' => $captureId,
            'status'      => 'completed',
            'source'      => 'paypal_webhook',
        ]);
    }

    private function mapPayPalStatus(string $eventType): string
    {
        $statusMap = [
            'PAYMENT.CAPTURE.COMPLETED' => 'completed',
            'PAYMENT.CAPTURE.DENIED' => 'failed',
            'PAYMENT.CAPTURE.PENDING' => 'processing',
            'CHECKOUT.ORDER.APPROVED' => 'processing',
            'CHECKOUT.ORDER.COMPLETED' => 'completed'
        ];

        return $statusMap[$eventType] ?? 'unknown';
    }

    private function mapCryptoStatus(array $data): string
    {
        $confirmations = intval($data['confirmations'] ?? 0);
        $required = intval($data['confirmations_required'] ?? 1);

        if ($confirmations >= $required) {
            return 'completed';
        } elseif ($confirmations > 0) {
            return 'processing';
        }

        return 'pending';
    }

    private function mapMobileMoneyStatus(string $status): string
    {
        $statusMap = [
            'SUCCESS' => 'completed',
            'SUCCESSFUL' => 'completed',
            'COMPLETED' => 'completed',
            'FAILED' => 'failed',
            'PENDING' => 'processing',
            'PROCESSING' => 'processing'
        ];

        return $statusMap[strtoupper($status)] ?? 'unknown';
    }

    private function satoshiToBTC(int $satoshi): float
    {
        return $satoshi / 100000000;
    }

    private function generateWebhookId(): string
    {
        return 'whook_' . bin2hex(random_bytes(12));
    }

    private function getClientIP(): string
    {
        $headers = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (!empty(sanitize_text_field(wp_unslash($_SERVER[$header] ?? '')))) {
                $ip = trim(explode(',', sanitize_text_field(wp_unslash($_SERVER[$header] ?? '')))[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }
}
