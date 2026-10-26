<?php
namespace WalletUpPro\WooCommerce\Emails;

use WalletUpPro\Core\Error\ErrorHandler;
use Exception;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class EmailManager
{
    /**
     * Single instance
     *
     * @var EmailManager
     */
    private static $instance = null;

    /**
     * Error handler instance
     *
     * @var ErrorHandler
     */
    private $errorHandler;

    public static function getInstance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->errorHandler = ErrorHandler::getInstance();
        $this->init();
    }

    private function init()
    {
        try {
            add_filter('woocommerce_email_classes', [$this, 'register_emails']);
            
            add_action('woocommerce_email_actions', [$this, 'add_email_actions']);

        } catch (Exception $e) {
            $this->errorHandler->log('Email manager initialization failed: ' . $e->getMessage());
        }
    }

    /**
     * Register WalletUpPro emails with WooCommerce
     * 
     * @param array $email_classes Existing email classes
     * @return array Modified email classes
     */
    public function register_emails($email_classes)
    {
        try {
            $email_classes['WalletUpProPaymentCompletedEmail'] = new WalletUpProPaymentCompletedEmail();
            $email_classes['WalletUpProPaymentPendingEmail']   = new WalletUpProPaymentPendingEmail();
            
        } catch (Exception $e) {
            $this->errorHandler->log('Failed to register WalletUpPro emails: ' . $e->getMessage());
        }

        return $email_classes;
    }

    /**
     * Add email actions to WooCommerce email system
     * 
     * @param array $email_actions Existing email actions
     * @return array Modified email actions
     */
    public function add_email_actions($email_actions)
    {
        $walletup_actions = [
            'walletup_payment_completed_notification',
            'walletup_payment_pending_notification',
            'walletup_payment_failed_notification',
            'walletup_payment_refunded_notification',
        ];

        return array_merge($email_actions, $walletup_actions);
    }

    private function ensure_mailer_ready(): void
    {
        if (function_exists('WC') && WC()) {
            WC()->mailer();
        }
    }

    /**
     * Send payment completed notification
     *
     * @param int $order_id Order ID
     * @param \WC_Order|null $order Order object
     */
    public function send_payment_completed_notification($order_id, $order = null)
    {
        try {
            $this->ensure_mailer_ready();
            do_action('walletup_payment_completed_notification', $order_id, $order);
            
        } catch (Exception $e) {
        }
    }

    /**
     * Send payment pending notification
     *
     * @param int $order_id Order ID
     * @param \WC_Order|null $order Order object
     */
    public function send_payment_pending_notification($order_id, $order = null)
    {
        try {
            $this->ensure_mailer_ready();
            do_action('walletup_payment_pending_notification', $order_id, $order);
            
        } catch (Exception $e) {
            $this->errorHandler->log('Failed to send payment pending notification: ' . $e->getMessage());
        }
    }

    /**
     * Send payment failed notification
     *
     * @param int $order_id Order ID
     * @param \WC_Order|null $order Order object
     */
    public function send_payment_failed_notification($order_id, $order = null)
    {
        try {
            $this->ensure_mailer_ready();
            do_action('walletup_payment_failed_notification', $order_id, $order);
            
        } catch (Exception $e) {
            $this->errorHandler->log('Failed to send payment failed notification: ' . $e->getMessage());
        }
    }
}