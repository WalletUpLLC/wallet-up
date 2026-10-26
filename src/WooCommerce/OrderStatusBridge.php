<?php
namespace WalletUpPro\WooCommerce;

if (!defined('ABSPATH')) {
    exit;
}

class OrderStatusBridge
{
    public static function init(): void
    {
        add_action('walletup_payment_status_changed', [__CLASS__, 'onPaymentStatusChanged'], 10, 3);
        add_action('woocommerce_order_status_changed', [__CLASS__, 'onOrderStatusChanged'], 20, 4);
        add_action('woocommerce_order_refunded', [__CLASS__, 'onOrderRefunded'], 20, 2);
    }

    public static function onPaymentStatusChanged(string $paymentId, string $status, array $data): void
    {
        if (!class_exists('WooCommerce')) {
            return;
        }

        $orderId = $data['order_id'] ?? null;
        if (!$orderId) {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Required to look up order by payment ID; indexed meta key.
            $orders = wc_get_orders([
                'meta_key'   => '_wallet_up_payment_id',
                'meta_value' => $paymentId,
                'limit'      => 1,
            ]);
            if (empty($orders)) {
                return;
            }
            $orderId = $orders[0]->get_id();
        }

        $order = wc_get_order($orderId);
        \WalletUpPro\Core\Tenancy\Tenant::scopeOrder($order);
        if (!$order || $order->get_payment_method() !== 'wallet_up') {
            return;
        }

        $normalizedStatus = strtolower(trim($status));

        switch ($normalizedStatus) {
            case 'completed':
            case 'paid':
                $order->payment_complete($paymentId);
                /* translators: %s: Payment ID */
                $order->add_order_note(
                    sprintf(__('WalletUp payment completed (ID: %s).', 'walletup'), $paymentId)
                );
                break;

            case 'processing':
                if (!in_array($order->get_status(), ['processing', 'completed'], true)) {
                    $order->update_status('processing',
                        /* translators: %s: Payment ID */
                        sprintf(__('WalletUp payment processing (ID: %s).', 'walletup'), $paymentId)
                    );
                }
                break;

            case 'failed':
                $order->update_status('failed',
                    /* translators: %s: Payment ID */
                    sprintf(__('WalletUp payment failed (ID: %s).', 'walletup'), $paymentId)
                );
                break;

            case 'refunded':
                $order->update_status('refunded',
                    /* translators: %s: Payment ID */
                    sprintf(__('WalletUp payment refunded (ID: %s).', 'walletup'), $paymentId)
                );
                break;

            case 'cancelled':
                $order->update_status('cancelled',
                    /* translators: %s: Payment ID */
                    sprintf(__('WalletUp payment cancelled (ID: %s).', 'walletup'), $paymentId)
                );
                break;

            case 'pending':
                if ($order->get_status() === 'on-hold') {
                    $order->update_status('pending',
                        /* translators: %s: Payment ID */
                        sprintf(__('WalletUp payment pending (ID: %s).', 'walletup'), $paymentId)
                    );
                }
                break;
        }
    }

    public static function onOrderStatusChanged($orderId, $from, $to, $order = null): void
    {
        if (!class_exists('WooCommerce')) {
            return;
        }
        $orderId = absint($orderId);
        if (!$orderId) {
            return;
        }
        if (!$order instanceof \WC_Order) {
            $order = function_exists('wc_get_order') ? wc_get_order($orderId) : null;
        }
        if (!$order instanceof \WC_Order || strpos((string) $order->get_payment_method(), 'wallet_up') !== 0) {
            return;
        }
        $done = 'completed';
        $map = [
            'processing' => $done,
            'completed'  => $done,
            'refunded'   => 'refunded',
            'cancelled'  => 'cancelled',
            'failed'     => 'failed',
        ];
        $to = strtolower((string) $to);
        if (!isset($map[$to])) {
            return;
        }
        $target = $map[$to];
        $intent = (string) $order->get_meta('_wallet_up_payment_id');
        global $wpdb;
        $table = $wpdb->prefix . 'walletup_payment_transactions';
        if ($target === $done) {
            if (strpos($intent, 'pi_') === 0) {
                $like = '%' . $wpdb->esc_like('"stripe_intent_id":"' . $intent . '"') . '%';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$table} SET status = %s, completed_at = %s WHERE wc_order_id = %d AND (provider_transaction_id = %s OR metadata LIKE %s) AND status NOT IN ('completed','refunded','cancelled')",
                    $target,
                    current_time('mysql'),
                    $orderId,
                    $intent,
                    $like
                ));
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$table} SET status = 'cancelled' WHERE wc_order_id = %d AND (provider_transaction_id IS NULL OR provider_transaction_id <> %s) AND metadata NOT LIKE %s AND status NOT IN ('completed','refunded','cancelled')",
                    $orderId,
                    $intent,
                    $like
                ));
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$table} SET status = %s, completed_at = %s WHERE wc_order_id = %d AND status NOT IN ('completed','refunded','cancelled')",
                    $target,
                    current_time('mysql'),
                    $orderId
                ));
            }
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status = %s WHERE wc_order_id = %d AND status <> %s",
                $target,
                $orderId,
                $target
            ));
        }
    }

    public static function onOrderRefunded($orderId, $refundId = 0): void
    {
        if (!class_exists('WooCommerce')) {
            return;
        }
        $orderId = absint($orderId);
        if (!$orderId) {
            return;
        }
        $order = wc_get_order($orderId);
        if (!$order) {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'walletup_payment_transactions';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema introspection.
        $hasCol = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            DB_NAME,
            $table,
            'refunded_amount'
        ));
        $refunded = (float) $order->get_total_refunded();
        if ($hasCol > 0) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET refunded_amount = %f WHERE wc_order_id = %d",
                $refunded,
                $orderId
            ));
        }
        $total = (float) $order->get_total();
        if ($total > 0 && $refunded >= $total) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status = 'refunded' WHERE wc_order_id = %d AND status <> 'refunded'",
                $orderId
            ));
        }
    }
}
