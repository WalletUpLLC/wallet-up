<?php
namespace WalletUpPro\WooCommerce\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use WalletUpPro\WooCommerce\CashAppApiGateway;

if (!defined('ABSPATH')) {
    exit;
}

final class CashAppApiBlocksSupport extends AbstractPaymentMethodType
{
    protected $name = 'wallet_up_cashapp_api';

    private ?CashAppApiGateway $gateway = null;

    public function initialize(): void
    {
        $this->settings = (array) get_option('wallet_up_cashapp_api_settings', []);
        $this->gateway = new CashAppApiGateway();
    }

    public function is_active(): bool
    {
        return $this->gateway !== null && $this->gateway->is_available();
    }

    public function get_payment_method_script_handles(): array
    {
        $env = $this->settings['environment'] ?? 'sandbox';
        $sdkUrl = $env === 'production'
            ? 'https://web.squarecdn.com/v1/square.js'
            : 'https://sandbox.web.squarecdn.com/v1/square.js';

        // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- External Square Web Payments SDK; version controlled by Square CDN.
        wp_register_script(
            'square-web-payments-sdk',
            $sdkUrl,
            [],
            null,
            true
        );

        $handle = 'wc-walletup-cashapp-api-blocks';

        wp_register_script(
            $handle,
            WALLET_UP_URL . 'assets/js/cashapp-api-block.js',
            ['square-web-payments-sdk', 'wp-element', 'wc-blocks-registry', 'wc-settings'],
            WALLET_UP_VERSION,
            true
        );

        return [$handle];
    }

    public function get_payment_method_data(): array
    {
        $env = $this->settings['environment'] ?? 'sandbox';
        $applicationId = $this->settings['client_id'] ?? '';
        $locationId = $this->settings['location_id'] ?? '';
        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD';

        return [
            'icon'             => \WalletUpPro\Core\Display\DisplayHandler::methodLogoUrl('cashapp_api'),
            'title'             => $this->gateway ? $this->gateway->title : __('Cash App Pay', 'walletup'),
            'description'       => $this->gateway ? $this->gateway->description : '',
            'supports'          => ['products'],
            'sandboxMode'       => $env === 'sandbox',
            'applicationId'     => $applicationId,
            'locationId'        => $locationId,
            'currency'          => strtoupper($currency),
            'createPaymentUrl'  => admin_url('admin-ajax.php'),
            'createPaymentNonce' => wp_create_nonce('wallet_up_cashapp_api_payment'),
        ];
    }
}
