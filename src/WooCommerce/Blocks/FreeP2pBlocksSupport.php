<?php
namespace WalletUpPro\WooCommerce\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use WalletUpPro\WooCommerce\FreeP2pGateway;

if (!defined('ABSPATH')) {
    exit;
}

/** Block-checkout registration for the free one-method gateway (6.6.0). */
final class FreeP2pBlocksSupport extends AbstractPaymentMethodType
{
    protected $name = FreeP2pGateway::ID;

    private ?FreeP2pGateway $gateway = null;

    public function initialize(): void
    {
        $this->settings = get_option('woocommerce_' . FreeP2pGateway::ID . '_settings', []);
        if (class_exists(FreeP2pGateway::class)) {
            $this->gateway = new FreeP2pGateway();
        }
    }

    public function is_active(): bool
    {
        return $this->gateway !== null && $this->gateway->is_available();
    }

    public function get_payment_method_script_handles(): array
    {
        $handle = 'wc-walletup-free-p2p-blocks';
        $path = WALLET_UP_PATH . 'assets/js/free-p2p-checkout-block.js';
        wp_register_script(
            $handle,
            WALLET_UP_URL . 'assets/js/free-p2p-checkout-block.js',
            ['wp-element', 'wp-html-entities', 'wc-blocks-registry', 'wc-settings'],
            \WalletUpPro\Core\Assets\AssetManager::fileVersion($path),
            true
        );
        return [$handle];
    }

    public function get_payment_method_data(): array
    {
        return [
            'title'       => $this->gateway ? $this->gateway->title : __('Wallet Up', 'walletup'),
            'description' => $this->gateway ? $this->gateway->description : '',
            'icon'        => $this->gateway ? (string) $this->gateway->icon : '',
            'supports'    => ['products'],
        ];
    }
}
