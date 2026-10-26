<?php
/**
 * WalletUpPro Payment Pending Email (HTML).
 * Rendered through the shared EmailRenderer shell — see src/Core/Emails/EmailRenderer.php.
 *
 * @var WC_Order $order
 * @var string $email_heading
 * @var string $additional_content
 * @var string $payment_method
 * @var string $merchant_name
 * @var string $merchant_email
 * @var string $merchant_phone
 */

defined('ABSPATH') || exit;

use WalletUpPro\Core\Emails\EmailRenderer;

$content  = EmailRenderer::lead(sprintf(
    /* translators: %s: Customer first name */
    esc_html__('Hi %s,', 'walletup'),
    esc_html($order->get_billing_first_name())
));
$content .= EmailRenderer::paragraph(esc_html__('We have received your payment and it is being processed. You will get a confirmation email once it is verified.', 'walletup'));

$content .= EmailRenderer::detailTable([
    ['label' => __('Order', 'walletup'), 'value' => '#' . esc_html($order->get_order_number())],
    ['label' => __('Payment method', 'walletup'), 'value' => esc_html($payment_method)],
    ['label' => __('Status', 'walletup'), 'value' => esc_html__('Awaiting confirmation', 'walletup')],
    ['label' => __('Order total', 'walletup'), 'value' => wp_kses_post($order->get_formatted_order_total()), 'strong' => true],
]);

if (!empty($merchant_name) || !empty($merchant_email) || !empty($merchant_phone)) {
    $rows = [];
    if (!empty($merchant_name)) {
        $rows[] = ['label' => __('Merchant', 'walletup'), 'value' => esc_html($merchant_name)];
    }
    if (!empty($merchant_email)) {
        $rows[] = ['label' => __('Email', 'walletup'), 'value' => '<a href="mailto:' . esc_attr($merchant_email) . '" style="color:inherit;">' . esc_html($merchant_email) . '</a>'];
    }
    if (!empty($merchant_phone)) {
        $rows[] = ['label' => __('Phone', 'walletup'), 'value' => '<a href="tel:' . esc_attr($merchant_phone) . '" style="color:inherit;">' . esc_html($merchant_phone) . '</a>'];
    }
    $content .= EmailRenderer::detailTable($rows);
}

if (!empty($additional_content)) {
    $content .= EmailRenderer::paragraph(wp_kses_post($additional_content));
}

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmailRenderer escapes each field; returns server-built markup.
echo EmailRenderer::wrap(
    !empty($email_heading) ? $email_heading : __('Payment being processed', 'walletup'),
    $content,
    ['preheader' => __('We are processing your payment.', 'walletup'), 'accent' => '#f59e0b']
);
