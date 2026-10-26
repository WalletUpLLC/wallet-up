<?php
namespace WalletUpPro\WooCommerce\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use WalletUpPro\WooCommerce\VenmoApiGateway;

if (!defined('ABSPATH')) {
    exit;
}

final class VenmoApiBlocksSupport extends AbstractPaymentMethodType
{
    protected $name = 'wallet_up_venmo_api';

    private ?VenmoApiGateway $gateway = null;

    public function initialize(): void
    {
        $this->settings = (array) get_option('wallet_up_venmo_api_settings', []);
        $this->gateway = new VenmoApiGateway();
    }

    public function is_active(): bool
    {
        return $this->gateway !== null && $this->gateway->is_available();
    }

    public function get_payment_method_script_handles(): array
    {
        $handle = 'wc-walletup-venmo-api-blocks';

        // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- External Braintree SDK.
        wp_register_script(
            'braintree-client',
            'https://js.braintreegateway.com/web/3.97.0/js/client.min.js',
            [],
            null,
            true
        );

        // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- External Braintree SDK.
        wp_register_script(
            'braintree-venmo',
            'https://js.braintreegateway.com/web/3.97.0/js/venmo.min.js',
            ['braintree-client'],
            null,
            true
        );

        wp_register_script(
            $handle,
            WALLET_UP_URL . 'assets/js/venmo-api-block.js',
            ['braintree-client', 'braintree-venmo', 'wp-element', 'wc-blocks-registry', 'wc-settings'],
            WALLET_UP_VERSION,
            true
        );

        return [$handle];
    }

    public function get_payment_method_data(): array
    {
        $env = $this->settings['environment'] ?? 'sandbox';
        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD';

        return [
            'icon'             => \WalletUpPro\Core\Display\DisplayHandler::methodLogoUrl('venmo_api'),
            'title'            => $this->gateway ? $this->gateway->title : __('Venmo', 'walletup'),
            'description'      => $this->gateway ? $this->gateway->description : '',
            'supports'         => ['products'],
            'sandboxMode'      => $env === 'sandbox',
            'clientTokenUrl'   => admin_url('admin-ajax.php'),
            'clientTokenAction' => 'walletup_venmo_client_token',
            'clientTokenNonce' => wp_create_nonce('walletup_venmo_client_token'),
            'currency'         => $currency,
            'paymentField'     => 'walletup_venmo_nonce',
        ];
    }
}
