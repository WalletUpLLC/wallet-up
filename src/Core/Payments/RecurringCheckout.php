<?php

namespace WalletUpPro\Core\Payments;

if (!defined('ABSPATH')) {
    exit;
}

class RecurringCheckout
{
    public static function start(array $ctx): array
    {
        $sourceType = (string) ($ctx['source_type'] ?? '');
        $sourceId   = (string) ($ctx['source_id'] ?? '');
        if ($sourceType === '' || $sourceId === '') {
            return self::error(__('This recurring payment is missing its reference.', 'walletup'));
        }
        if (!class_exists('\\WalletUpPro\\Core\\Subscriptions\\SubscriptionManager')) {
            return self::error(__('Recurring payments are unavailable.', 'walletup'));
        }

        $metadata = is_array($ctx['metadata'] ?? null) ? $ctx['metadata'] : [];
        $metadata[$sourceType . '_id'] = $sourceId;
        $metadata['type'] = $sourceType;

        try {
            $sub = \WalletUpPro\Core\Subscriptions\SubscriptionManager::getInstance()->createStripeSubscription([
                'amount'            => (float) ($ctx['amount'] ?? 0),
                'currency'          => strtolower((string) ($ctx['currency'] ?? 'usd')),
                'frequency'         => (string) ($ctx['frequency'] ?? 'monthly'),
                'email'             => (string) ($ctx['email'] ?? ''),
                'name'              => (string) ($ctx['name'] ?? ''),
                'payment_method_id' => (string) ($ctx['payment_method_id'] ?? ''),
                'source_type'       => $sourceType,
                'source_id'         => $sourceId,
                'metadata'          => $metadata,
            ]);
        } catch (\Throwable $e) {
            return self::error($e->getMessage());
        }

        if (empty($sub['client_secret']) && empty($sub['subscription_id'])) {
            return self::error(__('Could not start the recurring payment.', 'walletup'));
        }

        return [
            'ok'                => true,
            'error'             => '',
            'client_secret'     => (string) ($sub['client_secret'] ?? ''),
            'publishable_key'   => (string) ($sub['publishable_key'] ?? ''),
            'subscription_id'   => (string) ($sub['subscription_id'] ?? ''),
            'payment_intent_id' => (string) ($sub['payment_intent_id'] ?? ''),
            'requires_action'   => !empty($sub['requires_action']),
            'status'            => (string) ($sub['status'] ?? ''),
        ];
    }

    public static function confirmFirstCharge(array $ctx): array
    {
        if (!class_exists(PaymentGatewayHandler::class)) {
            return ['paid' => false, 'processing' => false, 'status' => '', 'amount' => 0,
                'error' => __('Payments are unavailable.', 'walletup')];
        }
        return PaymentGatewayHandler::assertPaidFor([
            'payment_intent_id' => (string) ($ctx['payment_intent_id'] ?? ''),
            'bind_key'          => (string) ($ctx['source_type'] ?? '') . '_id',
            'bind_value'        => (string) ($ctx['source_id'] ?? ''),
            'expected_amount'   => (int) ($ctx['expected_amount'] ?? 0),
            'expected_currency' => (string) ($ctx['expected_currency'] ?? ''),
        ]);
    }

    private static function error(string $message): array
    {
        return [
            'ok' => false, 'error' => $message, 'client_secret' => '', 'publishable_key' => '',
            'subscription_id' => '', 'payment_intent_id' => '', 'requires_action' => false, 'status' => '',
        ];
    }
}
