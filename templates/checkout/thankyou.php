<?php
/**
 * Order-received (thank-you) page for Wallet Up orders.
 *
 * Loaded through the order-aware `wc_get_template` override registered in
 * WooCommerceIntegration — so this file only renders for Wallet Up orders and
 * never touches the default page for other gateways. All markup comes from the
 * ConfirmationPage renderer (single source of truth); no output buffering,
 * no CSS hiding of the default template.
 *
 * @package WalletUpPro
 * @var WC_Order $order Passed in by wc_get_template().
 */

defined('ABSPATH') || exit;

if (empty($order) || !$order instanceof WC_Order) {
    return;
}

$walletup_confirmation = \WalletUpPro\WooCommerce\ConfirmationPage::buildFromOrder($order);

if ($walletup_confirmation !== '') {
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ConfirmationPage escapes every field; the return value is server-built markup.
    echo $walletup_confirmation;
    return;
}

include WC()->plugin_path() . '/templates/checkout/thankyou.php';
