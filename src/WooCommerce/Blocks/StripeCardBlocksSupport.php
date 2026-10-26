<?php
namespace WalletUpPro\WooCommerce\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use WalletUpPro\WooCommerce\StripeCardGateway;

if (!defined('ABSPATH')) {
    exit;
}

final class StripeCardBlocksSupport extends AbstractPaymentMethodType
{
    protected $name = 'wallet_up_card';

    private ?StripeCardGateway $gateway = null;

    public function initialize(): void
    {
        $this->settings = get_option('woocommerce_wallet_up_card_settings', []);
        $this->gateway = new StripeCardGateway();
    }

    public function is_active(): bool
    {
        return $this->gateway !== null && $this->gateway->is_available();
    }

    private function blockAmountMinor(): int
    {
        $total = 0.0;
        if (function_exists('WC') && WC()->cart) {
            if (WC()->cart->get_total('edit') <= 0 && method_exists(WC()->cart, 'calculate_totals')) {
                WC()->cart->calculate_totals();
            }
            $total = (float) WC()->cart->get_total('edit');
        }
        $currency = strtoupper(get_woocommerce_currency());
        $zeroDecimal = ['BIF','CLP','DJF','GNF','JPY','KMF','KRW','MGA','PYG','RWF','UGX','VND','VUV','XAF','XOF','XPF'];
        return in_array($currency, $zeroDecimal, true) ? (int) round($total) : (int) round($total * 100);
    }

    public function get_payment_method_script_handles(): array
    {
        $handle = 'wc-walletup-stripe-blocks';

        // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- External Stripe.js
        wp_register_script(
            'stripe-js',
            'https://js.stripe.com/v3/',
            [],
            null,
            true
        );

        wp_register_script(
            $handle,
            WALLET_UP_URL . 'assets/js/stripe-checkout-block.js',
            ['stripe-js', 'wp-element', 'wc-blocks-registry', 'wc-settings'],
            \WalletUpPro\Core\Assets\AssetManager::fileVersion(WALLET_UP_PATH . 'assets/js/stripe-checkout-block.js'),
            true
        );

        return [$handle];
    }

    public function get_payment_method_data(): array
    {
        return [
            'icon'             => \WalletUpPro\Core\Display\DisplayHandler::methodLogoUrl('card'),
            'title'            => $this->gateway ? $this->gateway->title : __('Credit/Debit Card', 'walletup'),
            'description'      => $this->gateway ? $this->gateway->description : '',
            'publishableKey'   => $this->gateway ? $this->gateway->getEffectivePublishableKey() : '',
            'connectedAccount' => $this->gateway ? $this->gateway->getConnectedAccountId() : '',
            'supports'         => ['products', 'refunds'],
            'testMode'         => $this->gateway ? $this->gateway->is_test_mode() : true,
            'paymentElement'   => \WalletUpPro\WooCommerce\StripeCardGateway::paymentElementEnabled(),
            'amount'           => $this->blockAmountMinor(),
            'currency'         => strtolower(get_woocommerce_currency()),
            'ajaxUrl'          => admin_url('admin-ajax.php'),
            'confirmNonce'     => wp_create_nonce('wallet_up_block_intent'),
        ];
    }
}
