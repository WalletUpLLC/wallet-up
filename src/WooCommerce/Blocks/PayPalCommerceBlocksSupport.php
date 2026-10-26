<?php
namespace WalletUpPro\WooCommerce\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use WalletUpPro\WooCommerce\PayPalCommerceGateway;

if (!defined('ABSPATH')) {
    exit;
}

final class PayPalCommerceBlocksSupport extends AbstractPaymentMethodType
{
    protected $name = 'wallet_up_paypal_commerce';

    private ?PayPalCommerceGateway $gateway = null;

    public function initialize(): void
    {
        $this->settings = (array) get_option('wallet_up_paypal_commerce_settings', []);
        $this->gateway = new PayPalCommerceGateway();
    }

    public function is_active(): bool
    {
        return $this->gateway !== null && $this->gateway->is_available();
    }

    public function get_payment_method_script_handles(): array
    {
        $handle = 'wc-walletup-paypal-commerce-blocks';
        $sdkHandle = 'walletup-paypal-sdk';

        $clientId = $this->gateway ? $this->gateway->getActiveClientId() : '';
        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD';

        $deps = ['wp-element', 'wc-blocks-registry', 'wc-settings'];

        if (!empty($clientId)) {
            $sdkArgs = [
                'client-id'  => $clientId,
                'components' => 'buttons,card-fields',
                'currency'   => $currency,
                'intent'     => 'capture',
            ];
            if ($this->gateway && $this->gateway->isSandbox()) {
                $sdkArgs['buyer-country'] = 'US';
            }
            $sdkUrl = add_query_arg($sdkArgs, 'https://www.paypal.com/sdk/js');

            // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- External PayPal SDK; version controlled by PayPal.
            wp_register_script(
                $sdkHandle,
                $sdkUrl,
                [],
                null,
                true
            );
            $deps[] = $sdkHandle;
        }

        wp_register_script(
            $handle,
            WALLET_UP_URL . 'assets/js/paypal-commerce-block.js',
            $deps,
            WALLET_UP_VERSION,
            true
        );

        return [$handle];
    }

    public function get_payment_method_data(): array
    {
        $clientId = $this->gateway ? $this->gateway->getActiveClientId() : '';
        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD';
        $sandbox = $this->gateway ? $this->gateway->isSandbox() : true;

        return [
            'icon'             => \WalletUpPro\Core\Display\DisplayHandler::methodLogoUrl('paypal_commerce'),
            'title'             => $this->gateway ? $this->gateway->title : __('PayPal', 'walletup'),
            'description'       => $this->gateway ? $this->gateway->description : '',
            'supports'          => ['products'],
            'sandboxMode'       => $sandbox,
            'clientId'          => $clientId,
            'currency'          => $currency,
            'createOrderUrl'    => admin_url('admin-ajax.php'),
            'captureOrderUrl'   => admin_url('admin-ajax.php'),
            'createOrderNonce'  => wp_create_nonce('wallet_up_paypal_create_order'),
            'captureOrderNonce' => wp_create_nonce('wallet_up_paypal_capture_order'),
            'paymentField'      => 'walletup_paypal_order_id',
        ];
    }
}
