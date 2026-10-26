<?php
namespace WalletUpPro\WooCommerce;

use WalletUpPro\Core\Error\ErrorHandler;

if (!defined('ABSPATH')) {
    exit;
}

class PaymentNotification
{
    private $order;
    private $status;
    private $errorHandler;

    public function __construct(\WC_Order $order, string $status)
    {
        \WalletUpPro\Core\Tenancy\Tenant::scopeOrder($order);
        $this->order = $order;
        $this->status = $status;
        $this->errorHandler = ErrorHandler::getInstance();
    }

    public function send(): void
    {
        try {
            $mailer = WC()->mailer();
            $orderId = $this->order->get_id();
            $methodId = $this->order->get_meta('_wallet_up_payment_method') ?: 'wallet_up';

            switch ($this->status) {
                case 'completed':
                case 'paid':
                    do_action('woocommerce_payment_complete_notification', $orderId);

                    $emails = $mailer->get_emails();
                    if (isset($emails['WalletUpProPaymentCompletedEmail'])) {
                        $emails['WalletUpProPaymentCompletedEmail']->trigger($orderId);
                    }

                    /* translators: 1: Customer email address, 2: Payment method name */
                    $this->order->add_order_note(
                        sprintf(
                            __('Payment completion notification sent to %1$s (method: %2$s).', 'walletup'),
                            $this->order->get_billing_email(),
                            ucfirst($methodId)
                        )
                    );
                    break;

                case 'pending':
                    $emails = $mailer->get_emails();
                    if (isset($emails['WalletUpProPaymentPendingEmail'])) {
                        $emails['WalletUpProPaymentPendingEmail']->trigger($orderId);
                    }
                    break;

                case 'failed':
                    do_action('woocommerce_order_status_failed_notification', $orderId);

                    /* translators: %s: Payment method name */
                    $this->order->add_order_note(
                        sprintf(
                            __('Payment failed notification sent (method: %s).', 'walletup'),
                            ucfirst($methodId)
                        )
                    );
                    break;

                case 'refunded':
                    do_action('woocommerce_order_fully_refunded_notification', $orderId);

                    /* translators: 1: Customer email address, 2: Payment method name */
                    $this->order->add_order_note(
                        sprintf(
                            __('Refund notification sent to %1$s (method: %2$s).', 'walletup'),
                            $this->order->get_billing_email(),
                            ucfirst($methodId)
                        )
                    );
                    break;

                default:
                    $this->errorHandler->log(
                        sprintf('PaymentNotification: Unknown status "%s" for order #%d', $this->status, $orderId)
                    );
                    break;
            }
        } catch (\Exception $e) {
            $this->errorHandler->log(
                sprintf('PaymentNotification send error for order #%d: %s', $this->order->get_id(), $e->getMessage())
            );
        }
    }
}
