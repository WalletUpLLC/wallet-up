<?php
/**
 * WalletUpPro Payment Completed Email (Plain Text)
 *
 * @var WC_Order $order
 * @var string $email_heading
 * @var string $additional_content
 * @var string $payment_method
 * @var bool $sent_to_admin
 * @var bool $plain_text
 * @var WC_Email $email
 * @var string $merchant_name
 * @var string $merchant_email
 * @var string $merchant_phone
 */

defined('ABSPATH') || exit;

echo "= " . esc_html(wp_strip_all_tags($email_heading)) . " =\n\n";

/* translators: %s: Customer first name */
printf(esc_html__('Hi %s,', 'walletup'), esc_html($order->get_billing_first_name()));
echo "\n\n";

esc_html_e('Your payment has been completed successfully. Here are the details of your order:', 'walletup');
echo "\n\n";

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
/* translators: %s: Order number */
printf(esc_html__('Order #%s', 'walletup'), esc_html($order->get_order_number()));
echo "\n";
echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

/* translators: %s: Payment method name */
printf(esc_html__('Payment Method: %s', 'walletup'), esc_html($payment_method));
echo "\n";
/* translators: %s: Order total */
printf(esc_html__('Order Total: %s', 'walletup'), esc_html(wp_strip_all_tags($order->get_formatted_order_total())));
echo "\n";
/* translators: %s: Order date */
printf(esc_html__('Date: %s', 'walletup'), esc_html(wc_format_datetime($order->get_date_created())));
echo "\n\n";

do_action('woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email);
echo "\n";
do_action('woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email);
echo "\n";
do_action('woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email);
echo "\n";

if (!empty($merchant_name) || !empty($merchant_email) || !empty($merchant_phone)) {
    echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
    esc_html_e('Merchant Contact', 'walletup');
    echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
    if (!empty($merchant_name)) {
        /* translators: %s: Merchant name */
        printf(esc_html__('Name: %s', 'walletup'), esc_html($merchant_name));
        echo "\n";
    }
    if (!empty($merchant_email)) {
        /* translators: %s: Merchant email */
        printf(esc_html__('Email: %s', 'walletup'), esc_html($merchant_email));
        echo "\n";
    }
    if (!empty($merchant_phone)) {
        /* translators: %s: Merchant phone */
        printf(esc_html__('Phone: %s', 'walletup'), esc_html($merchant_phone));
        echo "\n";
    }
    echo "\n";
}

if ($additional_content) {
    echo "---\n\n";
    echo esc_html(wp_strip_all_tags($additional_content));
    echo "\n\n";
}

echo esc_html(wp_strip_all_tags(apply_filters('woocommerce_email_footer_text', get_option('woocommerce_email_footer_text'))));
