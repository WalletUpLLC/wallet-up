<?php
namespace WalletUpPro\Core\Subscriptions;

use WalletUpPro\Core\BaseSingleton;
use WalletUpPro\Core\Error\ErrorHandler;
use WalletUpPro\Core\Security\DataEncryption;
use Exception;

if (!defined('ABSPATH')) {
    exit;
}

class SubscriptionManager extends BaseSingleton
{
    private $errorHandler;
    private $table;

    private const STRIPE_INTERVALS = [
        'weekly'    => ['interval' => 'week',  'interval_count' => 1],
        'biweekly'  => ['interval' => 'week',  'interval_count' => 2],
        'monthly'   => ['interval' => 'month', 'interval_count' => 1],
        'quarterly' => ['interval' => 'month', 'interval_count' => 3],
        'yearly'    => ['interval' => 'year',  'interval_count' => 1],
    ];

    private const PAYPAL_INTERVALS = [
        'weekly'    => ['interval_unit' => 'WEEK',  'interval_count' => 1],
        'biweekly'  => ['interval_unit' => 'WEEK',  'interval_count' => 2],
        'monthly'   => ['interval_unit' => 'MONTH', 'interval_count' => 1],
        'quarterly' => ['interval_unit' => 'MONTH', 'interval_count' => 3],
        'yearly'    => ['interval_unit' => 'YEAR',  'interval_count' => 1],
    ];

    protected function initialize(): void
    {
        global $wpdb;
        $this->errorHandler = ErrorHandler::getInstance();
        $this->table = $wpdb->prefix . 'walletup_subscriptions';
    }

    public static function createTable(): void
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table = $wpdb->prefix . 'walletup_subscriptions';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $sql = "CREATE TABLE {$table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            subscription_id varchar(64) NOT NULL,
            provider varchar(20) NOT NULL,
            provider_subscription_id varchar(128) DEFAULT '',
            provider_customer_id varchar(128) DEFAULT '',
            provider_plan_id varchar(128) DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'pending',
            customer_email varchar(100) NOT NULL,
            customer_name varchar(100) DEFAULT '',
            amount decimal(10,2) NOT NULL,
            currency varchar(10) DEFAULT 'USD',
            frequency varchar(20) NOT NULL DEFAULT 'monthly',
            source_type varchar(30) NOT NULL DEFAULT 'donation',
            source_id varchar(64) DEFAULT '',
            wc_order_id bigint(20) DEFAULT NULL,
            wc_subscription_id bigint(20) DEFAULT NULL,
            metadata text,
            next_payment_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            cancelled_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY subscription_id (subscription_id),
            KEY provider_subscription_id (provider_subscription_id),
            KEY customer_email (customer_email),
            KEY status (status),
            KEY source_type (source_type),
            KEY wc_subscription_id (wc_subscription_id)
        ) $charset_collate;";

        dbDelta($sql);
    }

    public static function tableExists(): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'walletup_subscriptions';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
    }

    public function createStripeSubscription(array $params): array
    {
        $stripeConfig = \WalletUpPro\Core\Payments\PaymentGatewayHandler::getStripeConfig();
        $secretKey = !empty($stripeConfig['test_mode'])
            ? ($stripeConfig['test_secret_key'] ?? '')
            : ($stripeConfig['live_secret_key'] ?? '');

        if (empty($secretKey)) {
            throw new Exception(__('Recurring payments are not configured.', 'walletup'));
        }

        \Stripe\Stripe::setApiKey($secretKey);

        $amount      = floatval($params['amount']);
        $currency    = strtolower($params['currency'] ?? 'USD');
        $frequency   = $params['frequency'] ?? 'monthly';
        $email       = sanitize_email($params['email']);
        $name        = sanitize_text_field($params['name'] ?? '');
        $pmId        = sanitize_text_field($params['payment_method_id'] ?? '');
        $sourceType  = sanitize_text_field($params['source_type'] ?? 'donation');
        $sourceId    = sanitize_text_field($params['source_id'] ?? '');
        $metadata    = $params['metadata'] ?? [];

        $interval = self::STRIPE_INTERVALS[$frequency] ?? self::STRIPE_INTERVALS['monthly'];
        $amountCents = \WalletUpPro\Core\Payments\PaymentGatewayHandler::toMinorUnits($amount, $currency);

        if ($amountCents <= 0) {
            throw new Exception(__('Amount must be greater than zero.', 'walletup'));
        }
        $twoDecimal = $amountCents === (int) round($amount * 100);
        if ($twoDecimal && $amountCents < 50) {
            /* translators: %s: ISO currency code, e.g. USD */
            throw new Exception(sprintf(__('Amount must be at least %s 0.50', 'walletup'), strtoupper($currency)));
        }

        $customer = $this->getOrCreateStripeCustomer($email, $name, $pmId);

        if ($pmId !== '') {
            try {
                $pm = \Stripe\PaymentMethod::retrieve($pmId);
                if ($pm->customer !== $customer->id) {
                    $pm->attach(['customer' => $customer->id]);
                }
            } catch (\Stripe\Exception\ApiErrorException $e) {
            }

            \Stripe\Customer::update($customer->id, [
                'invoice_settings' => ['default_payment_method' => $pmId],
            ]);
        }

        $price = \Stripe\Price::create([
            'unit_amount'  => $amountCents,
            'currency'     => $currency,
            'recurring'    => [
                'interval'       => $interval['interval'],
                'interval_count' => $interval['interval_count'],
            ],
            'product_data' => [
                'name' => $sourceType === 'donation'
                    /* translators: %s: billing frequency, e.g. Monthly */
                    ? sprintf(__('Recurring Donation — %s', 'walletup'), ucfirst($frequency))
                    /* translators: %s: billing frequency, e.g. Monthly */
                    : sprintf(__('Subscription — %s', 'walletup'), ucfirst($frequency)),
            ],
        ]);

        $subParams = [
            'customer'               => $customer->id,
            'items'                  => [['price' => $price->id]],
            'payment_behavior'       => 'default_incomplete',
            'payment_settings'       => [
                'save_default_payment_method' => 'on_subscription',
            ],
            'expand'                 => ['latest_invoice.confirmation_secret', 'latest_invoice.payment_intent'],
            'metadata'               => \WalletUpPro\Core\Tenancy\Tenant::metadata(array_merge($metadata, [
                'source_type' => $sourceType,
                'source_id'   => $sourceId,
                'frequency'   => $frequency,
                'walletup'    => 'true',
            ])),
        ];

        if ($pmId !== '') {
            $subParams['default_payment_method'] = $pmId;
            $subParams['payment_settings']['payment_method_types'] = ['card'];
        }

        $subscription = \Stripe\Subscription::create($subParams);

        $clientSecret = null;
        $requiresAction = false;
        if ($subscription->status === 'incomplete') {
            $latestInvoice = $subscription->latest_invoice;
            if ($latestInvoice && !empty($latestInvoice->payment_intent)) {
                $clientSecret = $latestInvoice->payment_intent->client_secret;
                $requiresAction = in_array(
                    $latestInvoice->payment_intent->status,
                    ['requires_action', 'requires_payment_method'],
                    true
                );
            } elseif ($latestInvoice && !empty($latestInvoice->confirmation_secret)) {
                $clientSecret = $latestInvoice->confirmation_secret->client_secret;
                $requiresAction = true;
            }
        }

        $firstIntentId = '';
        if (!empty($subscription->latest_invoice->payment_intent->id)) {
            $firstIntentId = (string) $subscription->latest_invoice->payment_intent->id;
        } elseif (is_string($clientSecret) && strpos($clientSecret, 'pi_') === 0) {
            $firstIntentId = explode('_secret_', $clientSecret)[0];
        }
        if (!empty($metadata)) {
            $bound = false;
            if ($firstIntentId !== '') {
                try {
                    \Stripe\PaymentIntent::update($firstIntentId, ['metadata' => $metadata]);
                    $bound = true;
                } catch (\Throwable $e) {
                    $this->errorHandler->log('Subscription intent binding failed: ' . $e->getMessage());
                }
            }
            if (!$bound && $pmId === '') {
                $this->cancelStripeSubscriptionQuietly($subscription);
                throw new Exception(__('Could not start the recurring payment. Please try again.', 'walletup'));
            }
        }

        $subscriptionId = 'sub_walup_' . wp_generate_uuid4();
        $nextPayment = null;
        if ($subscription->current_period_end) {
            $nextPayment = gmdate('Y-m-d H:i:s', $subscription->current_period_end);
        }

        $this->insertSubscription([
            'subscription_id'          => $subscriptionId,
            'provider'                 => 'stripe',
            'provider_subscription_id' => $subscription->id,
            'provider_customer_id'     => $customer->id,
            'provider_plan_id'         => $price->id,
            'status'                   => $this->mapStripeSubStatus($subscription->status),
            'customer_email'           => $email,
            'customer_name'            => $name,
            'amount'                   => $amount,
            'currency'                 => strtoupper($currency),
            'frequency'                => $frequency,
            'source_type'              => $sourceType,
            'source_id'                => $sourceId,
            'wc_order_id'              => isset($params['wc_order_id']) ? absint($params['wc_order_id']) : null,
            'wc_subscription_id'       => isset($params['wc_subscription_id']) ? absint($params['wc_subscription_id']) : null,
            'metadata'                 => wp_json_encode($metadata),
            'next_payment_at'          => $nextPayment,
        ]);

        $publishableKey = $stripeConfig['test_mode']
            ? ($stripeConfig['test_publishable_key'] ?? '')
            : ($stripeConfig['live_publishable_key'] ?? '');

        return [
            'subscription_id'          => $subscriptionId,
            'provider_subscription_id' => $subscription->id,
            'payment_intent_id'        => $firstIntentId,
            'client_secret'            => $clientSecret,
            'requires_action'          => $requiresAction,
            'publishable_key'          => $publishableKey,
            'status'                   => $subscription->status,
        ];
    }

    private function cancelStripeSubscriptionQuietly($subscription): void
    {
        try {
            if (!empty($subscription->id)) {
                $subscription->cancel();
            }
        } catch (\Throwable $e) {
        }
    }

    private function getOrCreateStripeCustomer(string $email, string $name, string $paymentMethodId = ''): \Stripe\Customer
    {
        $customers = \Stripe\Customer::search([
            'query' => "email:'{$email}'",
            'limit' => 1,
        ]);

        if (!empty($customers->data)) {
            return $customers->data[0];
        }

        $create = [
            'email'    => $email,
            'name'     => $name,
            'metadata' => ['source' => 'walletup'],
        ];
        if ($paymentMethodId !== '') {
            $create['payment_method'] = $paymentMethodId;
        }

        return \Stripe\Customer::create($create);
    }

    public function createPayPalSubscription(array $params): array
    {
        $settings = \WalletUpPro\Core\Config\AutomatedApiConfig::getPayPalCommerceCredentials();
        if (empty($settings['client_id']) || empty($settings['client_secret'])) {
            throw new Exception(__('Recurring payments are not configured.', 'walletup'));
        }

        $baseUrl = !empty($settings['sandbox'])
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';

        $accessToken = $this->getPayPalAccessToken($settings, $baseUrl);
        if (!$accessToken) {
            throw new Exception(__('Could not reach the payment provider. Please try again.', 'walletup'));
        }

        $amount    = floatval($params['amount']);
        $currency  = strtoupper($params['currency'] ?? 'USD');
        $frequency = $params['frequency'] ?? 'monthly';
        $email     = sanitize_email($params['email']);
        $name      = sanitize_text_field($params['name'] ?? '');
        $sourceType = sanitize_text_field($params['source_type'] ?? 'donation');
        $sourceId   = sanitize_text_field($params['source_id'] ?? '');
        $metadata   = $params['metadata'] ?? [];

        $interval = self::PAYPAL_INTERVALS[$frequency] ?? self::PAYPAL_INTERVALS['monthly'];

        if (!empty($params['return_url'])) {
            $returnUrl = $params['return_url'];
        } else {
            $returnUrl = add_query_arg([
                'walletup_paypal_sub' => 'confirm',
                'source_type'        => $sourceType,
                'source_id'          => $sourceId,
            ], site_url('/'));
        }

        if (!empty($params['cancel_url'])) {
            $cancelUrl = $params['cancel_url'];
        } else {
            $cancelUrl = add_query_arg([
                'walletup_paypal_sub' => 'cancel',
                'source_type'        => $sourceType,
                'source_id'          => $sourceId,
            ], site_url('/'));
        }

        $productResponse = wp_remote_post($baseUrl . '/v1/catalogs/products', [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'name'        => $sourceType === 'donation'
                    ? 'Recurring Donation'
                    : 'WalletUp Subscription',
                'type'        => 'SERVICE',
                'description' => sprintf('%s recurring %s payment via WalletUp', ucfirst($sourceType), $frequency),
            ]),
            'timeout' => 15,
        ]);

        if (is_wp_error($productResponse)) {
            throw new Exception('PayPal product creation failed: ' . $productResponse->get_error_message());
        }

        $productBody = json_decode(wp_remote_retrieve_body($productResponse), true);
        if (empty($productBody['id'])) {
            throw new Exception('PayPal product creation failed: ' . wp_json_encode($productBody));
        }
        $productId = $productBody['id'];

        $planResponse = wp_remote_post($baseUrl . '/v1/billing/plans', [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'product_id'  => $productId,
                'name'        => sprintf('%s %s Plan — $%s', ucfirst($sourceType), ucfirst($frequency), number_format($amount, 2)),
                'status'      => 'ACTIVE',
                'billing_cycles' => [
                    [
                        'frequency' => [
                            'interval_unit'  => $interval['interval_unit'],
                            'interval_count' => $interval['interval_count'],
                        ],
                        'tenure_type'  => 'REGULAR',
                        'sequence'     => 1,
                        'total_cycles' => 0,
                        'pricing_scheme' => [
                            'fixed_price' => [
                                'value'         => number_format($amount, 2, '.', ''),
                                'currency_code' => $currency,
                            ],
                        ],
                    ],
                ],
                'payment_preferences' => [
                    'auto_bill_outstanding'     => true,
                    'payment_failure_threshold' => 3,
                ],
            ]),
            'timeout' => 15,
        ]);

        if (is_wp_error($planResponse)) {
            throw new Exception('PayPal plan creation failed: ' . $planResponse->get_error_message());
        }

        $planBody = json_decode(wp_remote_retrieve_body($planResponse), true);
        if (empty($planBody['id'])) {
            throw new Exception('PayPal plan creation failed: ' . wp_json_encode($planBody));
        }
        $planId = $planBody['id'];

        $subPayload = [
            'plan_id'    => $planId,
            'subscriber' => [
                'name'          => ['given_name' => explode(' ', $name)[0] ?? '', 'surname' => explode(' ', $name, 2)[1] ?? ''],
                'email_address' => $email,
            ],
            'application_context' => [
                'brand_name'          => get_bloginfo('name'),
                'user_action'         => 'SUBSCRIBE_NOW',
                'return_url'          => $returnUrl,
                'cancel_url'          => $cancelUrl,
                'shipping_preference' => 'NO_SHIPPING',
            ],
            'custom_id' => $sourceId,
        ];

        $subResponse = wp_remote_post($baseUrl . '/v1/billing/subscriptions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode($subPayload),
            'timeout' => 15,
        ]);

        if (is_wp_error($subResponse)) {
            throw new Exception('PayPal subscription creation failed: ' . $subResponse->get_error_message());
        }

        $subBody = json_decode(wp_remote_retrieve_body($subResponse), true);
        if (empty($subBody['id'])) {
            throw new Exception('PayPal subscription creation failed: ' . wp_json_encode($subBody));
        }

        $approvalUrl = '';
        foreach ($subBody['links'] ?? [] as $link) {
            if ($link['rel'] === 'approve') {
                $approvalUrl = $link['href'];
                break;
            }
        }

        $subscriptionId = 'sub_walup_' . wp_generate_uuid4();

        $this->insertSubscription([
            'subscription_id'          => $subscriptionId,
            'provider'                 => 'paypal',
            'provider_subscription_id' => $subBody['id'],
            'provider_customer_id'     => '',
            'provider_plan_id'         => $planId,
            'status'                   => 'pending',
            'customer_email'           => $email,
            'customer_name'            => $name,
            'amount'                   => $amount,
            'currency'                 => $currency,
            'frequency'                => $frequency,
            'source_type'              => $sourceType,
            'source_id'                => $sourceId,
            'wc_order_id'              => isset($params['wc_order_id']) ? absint($params['wc_order_id']) : null,
            'wc_subscription_id'       => isset($params['wc_subscription_id']) ? absint($params['wc_subscription_id']) : null,
            'metadata'                 => wp_json_encode($metadata),
            'next_payment_at'          => null,
        ]);

        return [
            'subscription_id'          => $subscriptionId,
            'provider_subscription_id' => $subBody['id'],
            'approval_url'             => $approvalUrl,
            'status'                   => $subBody['status'] ?? 'APPROVAL_PENDING',
        ];
    }

    public function handleStripeSubscriptionEvent(string $eventType, object $object): void
    {
        switch ($eventType) {
            case 'invoice.payment_succeeded':
                $this->handleStripeInvoiceSucceeded($object);
                break;

            case 'invoice.payment_failed':
                $this->handleStripeInvoiceFailed($object);
                break;

            case 'customer.subscription.updated':
                $this->handleStripeSubscriptionUpdated($object);
                break;

            case 'customer.subscription.deleted':
                $this->handleStripeSubscriptionDeleted($object);
                break;
        }
    }

    public function handlePayPalSubscriptionEvent(string $eventType, array $resource): void
    {
        switch ($eventType) {
            case 'BILLING.SUBSCRIPTION.CREATED':
                $this->errorHandler->log('PayPal subscription created: ' . ($resource['id'] ?? 'unknown'));
                break;

            case 'BILLING.SUBSCRIPTION.ACTIVATED':
                $this->handlePayPalSubscriptionActivated($resource);
                break;

            case 'BILLING.SUBSCRIPTION.CANCELLED':
            case 'BILLING.SUBSCRIPTION.SUSPENDED':
            case 'BILLING.SUBSCRIPTION.EXPIRED':
                $this->handlePayPalSubscriptionEnded($resource, $eventType);
                break;

            case 'PAYMENT.SALE.COMPLETED':
                $this->handlePayPalSaleCompleted($resource);
                break;
        }
    }

    private function handleStripeInvoiceSucceeded(object $invoice): void
    {
        $stripeSubId = $invoice->subscription ?? null;
        if (!$stripeSubId) {
            return;
        }

        $sub = $this->getByProviderSubscriptionId($stripeSubId);
        if (!$sub) {
            return;
        }

        $this->updateSubscriptionStatus($sub->subscription_id, 'active');

        if (isset($invoice->lines->data[0]->period->end)) {
            $this->updateNextPayment($sub->subscription_id, gmdate('Y-m-d H:i:s', $invoice->lines->data[0]->period->end));
        }

        $this->recordRecurringPayment($sub, [
            'provider_transaction_id' => $invoice->payment_intent ?? $invoice->id,
            'amount'                  => \WalletUpPro\Core\Payments\PaymentGatewayHandler::fromMinorUnits(
                (int) ($invoice->amount_paid ?? 0),
                (string) ($invoice->currency ?? 'usd')
            ),
            'currency'                => strtoupper($invoice->currency ?? 'usd'),
        ]);
    }

    private function handleStripeInvoiceFailed(object $invoice): void
    {
        $stripeSubId = $invoice->subscription ?? null;
        if (!$stripeSubId) {
            return;
        }

        $sub = $this->getByProviderSubscriptionId($stripeSubId);
        if (!$sub) {
            return;
        }

        $this->updateSubscriptionStatus($sub->subscription_id, 'past_due');

        $this->errorHandler->log(sprintf(
            'Recurring payment failed for subscription %s (Stripe: %s)',
            $sub->subscription_id,
            $stripeSubId
        ));

        do_action('walletup_recurring_payment_failed', $sub);
    }

    private function handleStripeSubscriptionUpdated(object $subscription): void
    {
        $sub = $this->getByProviderSubscriptionId($subscription->id);
        if (!$sub) {
            return;
        }

        $newStatus = $this->mapStripeSubStatus($subscription->status);
        $this->updateSubscriptionStatus($sub->subscription_id, $newStatus);

        if ($subscription->current_period_end) {
            $this->updateNextPayment($sub->subscription_id, gmdate('Y-m-d H:i:s', $subscription->current_period_end));
        }
    }

    private function handleStripeSubscriptionDeleted(object $subscription): void
    {
        $sub = $this->getByProviderSubscriptionId($subscription->id);
        if (!$sub) {
            return;
        }

        $this->updateSubscriptionStatus($sub->subscription_id, 'cancelled');
        $this->setCancelledAt($sub->subscription_id);

        if ($sub->source_type === 'donation' && !empty($sub->source_id)) {
            global $wpdb;
            $donTable = $wpdb->prefix . 'wallet_up_donations';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update($donTable, ['status' => 'cancelled'], ['donation_id' => $sub->source_id]);
        }

        do_action('walletup_subscription_cancelled', $sub);
    }

    private function handlePayPalSubscriptionActivated(array $resource): void
    {
        $ppSubId = $resource['id'] ?? '';
        if (empty($ppSubId)) {
            return;
        }

        $sub = $this->getByProviderSubscriptionId($ppSubId);
        if (!$sub) {
            return;
        }

        $this->updateSubscriptionStatus($sub->subscription_id, 'active');

        if (!empty($resource['billing_info']['next_billing_time'])) {
            $next = strtotime($resource['billing_info']['next_billing_time']);
            if ($next) {
                $this->updateNextPayment($sub->subscription_id, gmdate('Y-m-d H:i:s', $next));
            }
        }
    }

    private function handlePayPalSubscriptionEnded(array $resource, string $eventType): void
    {
        $ppSubId = $resource['id'] ?? '';
        if (empty($ppSubId)) {
            return;
        }

        $sub = $this->getByProviderSubscriptionId($ppSubId);
        if (!$sub) {
            return;
        }

        $statusMap = [
            'BILLING.SUBSCRIPTION.CANCELLED' => 'cancelled',
            'BILLING.SUBSCRIPTION.SUSPENDED' => 'paused',
            'BILLING.SUBSCRIPTION.EXPIRED'   => 'expired',
        ];
        $newStatus = $statusMap[$eventType] ?? 'cancelled';

        $this->updateSubscriptionStatus($sub->subscription_id, $newStatus);

        if ($newStatus === 'cancelled' || $newStatus === 'expired') {
            $this->setCancelledAt($sub->subscription_id);
        }
    }

    private function handlePayPalSaleCompleted(array $resource): void
    {
        $ppSubId = $resource['billing_agreement_id'] ?? '';
        $sub = null;

        if (!empty($ppSubId)) {
            $sub = $this->getByProviderSubscriptionId($ppSubId);
        }

        if (!$sub && !empty($resource['custom'])) {
            $sub = $this->getBySourceId($resource['custom']);
        }

        if (!$sub) {
            return;
        }

        $this->updateSubscriptionStatus($sub->subscription_id, 'active');

        $this->recordRecurringPayment($sub, [
            'provider_transaction_id' => $resource['id'] ?? '',
            'amount'                  => floatval($resource['amount']['total'] ?? $sub->amount),
            'currency'                => $resource['amount']['currency'] ?? $sub->currency,
        ]);
    }

    private function recordRecurringPayment(object $sub, array $paymentData): void
    {
        $providerTxnId = $paymentData['provider_transaction_id'] ?? '';
        if (!empty($providerTxnId)) {
            global $wpdb;
            $txnTable = $wpdb->prefix . 'walletup_payment_transactions';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$txnTable} WHERE provider_transaction_id = %s",
                $providerTxnId
            ));
            if ($exists > 0) {
                $this->errorHandler->log('Skipping duplicate recurring payment: ' . $providerTxnId);
                return;
            }
        }

        try {
            $processor = \WalletUpPro\Services\PaymentProcessor::getInstance();
            $paymentResult = $processor->initializePayment([
                'email'          => $sub->customer_email,
                'amt'            => $paymentData['amount'],
                'currency'       => $paymentData['currency'] ?? $sub->currency,
                'first'          => explode(' ', $sub->customer_name)[0] ?? '',
                'last'           => explode(' ', $sub->customer_name, 2)[1] ?? '',
                'phone'          => '',
                'payment_method' => $sub->provider,
                'description'    => sprintf('Recurring payment — %s (%s)', $sub->subscription_id, $sub->frequency),
                'metadata'       => [
                    'subscription_id' => $sub->subscription_id,
                    'source_type'     => $sub->source_type,
                    'source_id'       => $sub->source_id,
                    'recurring'       => true,
                    'frequency'       => $sub->frequency,
                ],
            ]);

            if ($paymentResult && !empty($paymentResult['payment_id'])) {
                $processor->updatePaymentStatus($paymentResult['payment_id'], 'completed', $paymentData);
            }
        } catch (Exception $e) {
            $this->errorHandler->log('Failed to record recurring payment for ' . $sub->subscription_id . ': ' . $e->getMessage());
        }

        if ($sub->source_type === 'donation') {
            $this->recordRecurringDonation($sub, $paymentData);
        }

        if ($sub->source_type === 'woocommerce' && !empty($sub->wc_subscription_id) && function_exists('wcs_get_subscription')) {
            $wcSub = wcs_get_subscription($sub->wc_subscription_id);
            if ($wcSub) {
                $renewalOrders = $wcSub->get_related_orders('all', 'renewal');
                foreach ($renewalOrders as $renewalOrder) {
                    if (!$renewalOrder->is_paid() && in_array($renewalOrder->get_status(), ['pending', 'on-hold', 'failed'], true)) {
                        $renewalOrder->payment_complete($paymentData['provider_transaction_id'] ?? '');
                        /* translators: 1: provider, 2: transaction ID */
                        $renewalOrder->add_order_note(sprintf(
                            esc_html__('WalletUp: Recurring payment confirmed via %1$s (Transaction: %2$s)', 'walletup'),
                            $sub->provider,
                            $paymentData['provider_transaction_id'] ?? 'N/A'
                        ));
                        $renewalOrder->save();
                        break;
                    }
                }
            }
        }

        do_action('walletup_recurring_payment_recorded', $sub, $paymentData);
    }

    private function recordRecurringDonation(object $sub, array $paymentData): void
    {
        global $wpdb;
        $donTable = $wpdb->prefix . 'wallet_up_donations';

        $donationId = 'don_' . wp_generate_uuid4();
        $metadata = json_decode($sub->metadata ?? '{}', true) ?: [];
        $metadata['subscription_id'] = $sub->subscription_id;
        $metadata['recurring_charge'] = true;
        $metadata['provider_transaction_id'] = $paymentData['provider_transaction_id'] ?? '';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->insert($donTable, [
            'donation_id'    => $donationId,
            'amount'         => $paymentData['amount'],
            'currency'       => $paymentData['currency'] ?? $sub->currency,
            'donor_name'     => $sub->customer_name,
            'donor_email'    => $sub->customer_email,
            'payment_method' => $sub->provider,
            'status'         => 'completed',
            'campaign_id'    => $metadata['campaign_id'] ?? '',
            'form_id'        => $metadata['form_id'] ?? '',
            'is_recurring'   => 1,
            'is_anonymous'   => 0,
            'metadata'       => wp_json_encode($metadata),
            'created_at'     => current_time('mysql'),
        ]);
    }

    public function cancelSubscription(string $subscriptionId): bool
    {
        $sub = $this->getBySubscriptionId($subscriptionId);
        if (!$sub) {
            return false;
        }

        if (in_array($sub->status, ['cancelled', 'expired'], true)) {
            return true;
        }

        try {
            if ($sub->provider === 'stripe' && !empty($sub->provider_subscription_id)) {
                $this->cancelStripeSubscription($sub->provider_subscription_id);
            } elseif ($sub->provider === 'paypal' && !empty($sub->provider_subscription_id)) {
                $this->cancelPayPalSubscription($sub->provider_subscription_id);
            }
        } catch (Exception $e) {
            $this->errorHandler->log('Failed to cancel subscription ' . $subscriptionId . ': ' . $e->getMessage());
        }

        $this->updateSubscriptionStatus($subscriptionId, 'cancelled');
        $this->setCancelledAt($subscriptionId);

        return true;
    }

    private function cancelStripeSubscription(string $stripeSubId): void
    {
        $stripeConfig = \WalletUpPro\Core\Payments\PaymentGatewayHandler::getStripeConfig();
        $secretKey = $stripeConfig['test_mode']
            ? ($stripeConfig['test_secret_key'] ?? '')
            : ($stripeConfig['live_secret_key'] ?? '');

        \Stripe\Stripe::setApiKey($secretKey);
        \Stripe\Subscription::update($stripeSubId, ['cancel_at_period_end' => true]);
    }

    private function cancelPayPalSubscription(string $ppSubId): void
    {
        $settings = \WalletUpPro\Core\Payments\PaymentGatewayHandler::getPayPalConfig();
        $baseUrl = !empty($settings['sandbox_mode'])
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';

        $accessToken = $this->getPayPalAccessToken($settings, $baseUrl);
        if (!$accessToken) {
            throw new Exception('Failed to obtain PayPal access token');
        }

        $response = wp_remote_post($baseUrl . '/v1/billing/subscriptions/' . $ppSubId . '/cancel', [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode(['reason' => 'Cancelled by merchant via WalletUp']),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            throw new Exception('PayPal cancel error: ' . $response->get_error_message());
        }

        $httpCode = wp_remote_retrieve_response_code($response);
        if ($httpCode >= 300 && $httpCode !== 422) {
            $body = wp_remote_retrieve_body($response);
            throw new Exception('PayPal cancel HTTP ' . $httpCode . ': ' . $body);
        }
    }

    public function processWcRenewalPayment(float $amountToCharge, $renewalOrder): void
    {
        \WalletUpPro\Core\Tenancy\Tenant::scopeOrder($renewalOrder);
        $wcSubscriptionId = 0;
        if (function_exists('wcs_get_subscriptions_for_renewal_order')) {
            $subs = wcs_get_subscriptions_for_renewal_order($renewalOrder);
            $wcSub = reset($subs);
            if ($wcSub) {
                $wcSubscriptionId = $wcSub->get_id();
            }
        }

        if (!$wcSubscriptionId) {
            $renewalOrder->update_status('failed');
            /* translators: %s: Error message */
            $renewalOrder->add_order_note(esc_html__('WalletUp: No linked WC subscription found for renewal.', 'walletup'));
            $renewalOrder->save();
            return;
        }

        $sub = $this->getByWcSubscriptionId($wcSubscriptionId);
        if (!$sub || empty($sub->provider_subscription_id)) {
            $renewalOrder->update_status('failed');
            $renewalOrder->add_order_note(esc_html__('WalletUp: No active provider subscription found for renewal.', 'walletup'));
            $renewalOrder->save();
            return;
        }

        if ($sub->provider === 'stripe') {
            try {
                $stripeConfig = \WalletUpPro\Core\Payments\PaymentGatewayHandler::getStripeConfig();
                $secretKey = $stripeConfig['test_mode']
                    ? ($stripeConfig['test_secret_key'] ?? '')
                    : ($stripeConfig['live_secret_key'] ?? '');

                \Stripe\Stripe::setApiKey($secretKey);
                $stripeSub = \Stripe\Subscription::retrieve($sub->provider_subscription_id);

                if (in_array($stripeSub->status, ['active', 'trialing'], true)) {
                    $renewalOrder->add_order_note(sprintf(
                        /* translators: %s: Stripe subscription ID */
                        esc_html__('WalletUp: Stripe subscription %s is active. Payment will be confirmed via webhook.', 'walletup'),
                        $stripeSub->id
                    ));
                    $renewalOrder->save();
                    return;
                }

                $renewalOrder->update_status('failed');
                $renewalOrder->add_order_note(sprintf(
                    /* translators: 1: Stripe subscription ID, 2: Status */
                    esc_html__('WalletUp: Stripe subscription %1$s is %2$s — renewal cannot proceed.', 'walletup'),
                    $stripeSub->id,
                    $stripeSub->status
                ));
                $renewalOrder->save();
            } catch (Exception $e) {
                $renewalOrder->update_status('failed');
                $renewalOrder->add_order_note('WalletUp: Stripe error — ' . $e->getMessage());
                $renewalOrder->save();
            }
            return;
        }

        if ($sub->provider === 'paypal') {
            $renewalOrder->add_order_note(sprintf(
                /* translators: %s: PayPal subscription ID */
                esc_html__('WalletUp: PayPal subscription %s is active. Payment will be confirmed via webhook.', 'walletup'),
                $sub->provider_subscription_id
            ));
            $renewalOrder->save();
        }
    }

    public function getByCustomerEmail(string $email): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE customer_email = %s ORDER BY created_at DESC",
            $email
        ));
    }

    public function getStats(): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row(
            "SELECT COUNT(*) as active_count, COALESCE(SUM(amount), 0) as mrr FROM {$this->table} WHERE status = 'active'",
            ARRAY_A
        );

        return [
            'active_count' => (int) ($row['active_count'] ?? 0),
            'mrr'          => (float) ($row['mrr'] ?? 0),
        ];
    }

    private function insertSubscription(array $data): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->insert($this->table, \WalletUpPro\Core\Tenancy\Tenant::stamp($data, $this->table));
    }

    public function getBySubscriptionId(string $subscriptionId): ?object
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE subscription_id = %s",
            $subscriptionId
        ));
    }

    private function getByProviderSubscriptionId(string $providerSubId): ?object
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE provider_subscription_id = %s",
            $providerSubId
        ));
    }

    private function getBySourceId(string $sourceId): ?object
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE source_id = %s AND status IN ('active', 'pending')",
            $sourceId
        ));
    }

    private function getByWcSubscriptionId(int $wcSubId): ?object
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE wc_subscription_id = %d AND status IN ('active', 'pending')",
            $wcSubId
        ));
    }

    
   public function updateSubscriptionStatus(string $subscriptionId, string $status): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update($this->table, ['status' => $status], ['subscription_id' => $subscriptionId]);
    }

    private function updateNextPayment(string $subscriptionId, string $datetime): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update($this->table, ['next_payment_at' => $datetime], ['subscription_id' => $subscriptionId]);
    }

    private function setCancelledAt(string $subscriptionId): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update($this->table, ['cancelled_at' => current_time('mysql')], ['subscription_id' => $subscriptionId]);
    }

    private function mapStripeSubStatus(string $stripeStatus): string
    {
        $map = [
            'active'              => 'active',
            'trialing'            => 'active',
            'incomplete'          => 'pending',
            'incomplete_expired'  => 'expired',
            'past_due'            => 'past_due',
            'canceled'            => 'cancelled',
            'unpaid'              => 'past_due',
            'paused'              => 'paused',
        ];
        return $map[$stripeStatus] ?? 'unknown';
    }

    private function getPayPalAccessToken(array $settings, string $baseUrl): ?string
    {
        $cached = get_transient('walletup_paypal_sub_access_token');
        if ($cached) {
            return $cached;
        }

        $response = wp_remote_post($baseUrl . '/v1/oauth2/token', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($settings['client_id'] . ':' . $settings['client_secret']),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body'    => 'grant_type=client_credentials',
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['access_token'])) {
            return null;
        }

        $expiresIn = max(60, ($body['expires_in'] ?? 3600) - 60);
        set_transient('walletup_paypal_sub_access_token', $body['access_token'], $expiresIn);

        return $body['access_token'];
    }
}
