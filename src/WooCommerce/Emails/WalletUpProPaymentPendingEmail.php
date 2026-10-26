<?php
namespace WalletUpPro\WooCommerce\Emails;

use WC_Email;
use WalletUpPro\Core\Error\ErrorHandler;
use Exception;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class WalletUpProPaymentPendingEmail extends WC_Email
{
    /**
     * Error handler instance
     *
     * @var ErrorHandler
     */
    private $errorHandler;

    public function __construct()
    {
        $this->id = 'walletup_payment_pending';
        $this->customer_email = true;
        
        $this->title = __('WalletUpPro Payment Pending', 'walletup');
        $this->description = __('Email sent to customers when their WalletUpPro payment is pending confirmation.', 'walletup');
        
        $this->template_html = 'emails/walletup-payment-pending.php';
        $this->template_plain = 'emails/plain/walletup-payment-pending.php';
        $this->template_base = WALLET_UP_PATH . 'templates/';
        
        $this->placeholders = [
            '{order_date}' => '',
            '{order_number}' => '',
            '{payment_method}' => '',
            '{site_title}' => '',
        ];

        $this->errorHandler = ErrorHandler::getInstance();

        add_action('walletup_payment_pending_notification', [$this, 'trigger'], 10, 2);
        add_action('woocommerce_order_status_pending', [$this, 'maybe_trigger_for_walletup_orders'], 10, 2);

        parent::__construct();
    }

    public function get_default_subject()
    {
        return __('Your {site_title} payment is being processed', 'walletup');
    }

    public function get_default_heading()
    {
        return __('Payment Being Processed', 'walletup');
    }

    /**
     * Maybe trigger email for WalletUpPro orders when they are pending
     *
     * @param int $order_id Order ID
     * @param \WC_Order $order Order object
     */
    public function maybe_trigger_for_walletup_orders($order_id, $order = null)
    {
        if (!$order) {
            $order = wc_get_order($order_id);
        }

        if ($order && $order->get_payment_method() === 'wallet_up') {
            $this->trigger($order_id, $order);
        }
    }

    /**
     * Trigger the sending of this email.
     *
     * @param int $order_id Order ID
     * @param \WC_Order|false $order Order object
     */
    public function trigger($order_id, $order = false)
    {
        try {
            $this->setup_locale();

            if ($order_id && !is_a($order, 'WC_Order')) {
                $order = wc_get_order($order_id);
            }

            if (is_a($order, 'WC_Order')) {
                if ($order->get_meta('_wallet_up_pending_email_sent')) {
                    $this->restore_locale();
                    return;
                }

                $this->object = $order;
                $this->recipient = $this->object->get_billing_email();

                $this->placeholders['{order_date}'] = wc_format_datetime($this->object->get_date_created());
                $this->placeholders['{order_number}'] = $this->object->get_order_number();
                $this->placeholders['{payment_method}'] = $this->get_payment_method_name($this->object);
                $this->placeholders['{site_title}'] = $this->get_blogname();
            }

            if ($this->is_enabled() && $this->get_recipient()) {
                $this->send(
                    $this->get_recipient(),
                    $this->get_subject(),
                    $this->get_content(),
                    $this->get_headers(),
                    $this->get_attachments()
                );

                if (is_a($order, 'WC_Order')) {
                    $order->update_meta_data('_wallet_up_pending_email_sent', current_time('mysql'));
                    $order->save_meta_data();
                }

                $this->errorHandler->log("WalletUpPro payment pending email sent for order {$order_id}");
            }

            $this->restore_locale();
        } catch (Exception $e) {
            $this->errorHandler->log('Failed to send payment pending email: ' . $e->getMessage());
        }
    }

    public function style_inline($content)
    {
        return $content;
    }

    public function get_content_html()
    {
        return wc_get_template_html(
            $this->template_html,
            array_merge(
                [
                    'order' => $this->object,
                    'email_heading' => $this->get_heading(),
                    'additional_content' => $this->get_additional_content(),
                    'payment_method' => $this->get_payment_method_name($this->object),
                    'sent_to_admin' => false,
                    'plain_text' => false,
                    'email' => $this,
                ],
                $this->get_merchant_profile()
            ),
            '',
            $this->template_base
        );
    }

    public function get_content_plain()
    {
        return wc_get_template_html(
            $this->template_plain,
            array_merge(
                [
                    'order' => $this->object,
                    'email_heading' => $this->get_heading(),
                    'additional_content' => $this->get_additional_content(),
                    'payment_method' => $this->get_payment_method_name($this->object),
                    'sent_to_admin' => false,
                    'plain_text' => true,
                    'email' => $this,
                ],
                $this->get_merchant_profile()
            ),
            '',
            $this->template_base
        );
    }

    private function get_merchant_profile(): array
    {
        $profile = get_option('wallet_up_transfer_call', []);
        return [
            'merchant_name'  => sanitize_text_field($profile['holder_owner'] ?? ''),
            'merchant_email' => sanitize_email($profile['holder_email'] ?? ''),
            'merchant_phone' => sanitize_text_field($profile['holder_no'] ?? ''),
        ];
    }

    /**
     * Default additional content.
     *
     * @return string
     */
    public function get_default_additional_content()
    {
        return __('We have received your payment and are processing it. You will receive another email once the payment is confirmed.', 'walletup');
    }

    private function get_payment_method_name($order)
    {
        if (!$order) {
            return '';
        }

        $method_id = $order->get_meta('_wallet_up_payment_method');
        if ($method_id) {
            return ucfirst($method_id);
        }

        return $order->get_payment_method_title();
    }

    public function init_form_fields()
    {
        /* translators: %s: list of placeholders */
        $placeholder_text = sprintf(__('Available placeholders: %s', 'walletup'), '<code>' . esc_html(implode('</code>, <code>', array_keys($this->placeholders))) . '</code>');

        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'walletup'),
                'type' => 'checkbox',
                'label' => __('Enable this email notification', 'walletup'),
                'default' => 'yes',
            ],
            'subject' => [
                'title' => __('Subject', 'walletup'),
                'type' => 'text',
                'desc_tip' => true,
                'description' => $placeholder_text,
                'placeholder' => $this->get_default_subject(),
                'default' => '',
            ],
            'heading' => [
                'title' => __('Email heading', 'walletup'),
                'type' => 'text',
                'desc_tip' => true,
                'description' => $placeholder_text,
                'placeholder' => $this->get_default_heading(),
                'default' => '',
            ],
            'additional_content' => [
                'title' => __('Additional content', 'walletup'),
                'description' => __('Text to appear below the main email content.', 'walletup') . ' ' . $placeholder_text,
                'css' => 'width:400px; height: 75px;',
                'placeholder' => __('N/A', 'walletup'),
                'type' => 'textarea',
                'default' => $this->get_default_additional_content(),
                'desc_tip' => true,
            ],
        ];
    }
}