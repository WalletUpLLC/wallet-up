<?php
namespace WalletUpPro\WooCommerce\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Automattic\WooCommerce\Blocks\Assets\Api;
use WalletUpPro\Core\Error\ErrorHandler;
use WalletUpPro\Core\PaymentRegistry;
use WalletUpPro\WooCommerce\WooCommerceConfig;
use Exception;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

final class WalletUpProBlocksSupport_OFFICIAL extends AbstractPaymentMethodType
{
    /**
     * Payment method name defined by payment methods extending this class.
     *
     * @var string
     */
    protected $name = 'wallet_up';

    /**
     * An instance of the Asset Api
     *
     * @var Api
     */
    private $asset_api;

    /**
     * Error handler instance
     *
     * @var ErrorHandler
     */
    private $errorHandler;

    /**
     * Payment registry instance
     *
     * @var PaymentRegistry
     */
    private $registry;

    /**
     * Constructor following official WooCommerce pattern
     *
     * @param Api|null $asset_api An instance of Api.
     */
    public function __construct(?Api $asset_api = null) {
        $this->errorHandler = ErrorHandler::getInstance();
        $this->registry = PaymentRegistry::getInstance();
        
        if ($asset_api === null) {
            try {
                if (class_exists('\Automattic\WooCommerce\Blocks\Package')) {
                    $asset_api = \Automattic\WooCommerce\Blocks\Package::container()->get(
                        \Automattic\WooCommerce\Blocks\Assets\Api::class
                    );
                }
            } catch (Exception $e) {
                $this->errorHandler->log('Failed to get WooCommerce Asset API: ' . $e->getMessage());
                $asset_api = null;
            }
        }
        
        $this->asset_api = $asset_api;
    }

    public function initialize() {
        $this->settings = get_option('woocommerce_wallet_up_settings', []);
    }

    /**
     * Returns if this payment method should be active. If false, the scripts will not be enqueued.
     *
     * @return boolean
     */
    public function is_active() {
        try {

            $gateway_settings = get_option('woocommerce_wallet_up_settings', []);
            $gateway_enabled = !empty($gateway_settings['enabled']) && $gateway_settings['enabled'] === 'yes';

            if (!$gateway_enabled) {
                $this->errorHandler->log('WalletUpPro blocks not active - Gateway is disabled in WooCommerce settings');
                return false;
            }

            $display_mode = get_option('wallet_up_wc_display_mode', 'grouped');
            if ($display_mode === 'individual') {
                $this->errorHandler->log('WalletUpPro blocks not active - Individual display mode, each method has own gateway');
                return false;
            }

            $enabled_methods = $this->registry->getAllMethods();
            $has_enabled_method = !empty($enabled_methods);

            if (!$has_enabled_method) {
                $this->errorHandler->log('WalletUpPro blocks not active - No payment methods enabled');
                return false;
            }

            $this->errorHandler->log('WalletUpPro blocks active - Gateway enabled and methods available');
            return true;

        } catch (Exception $e) {
            $this->errorHandler->log('Error checking block active status: ' . $e->getMessage());
            return false;
        }
    }
    
    private function should_load_checkout_scripts() {
        if (is_admin()) {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            if ($screen && $screen->is_block_editor()) {
                return true;
            }
            
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin page context check; no data processing.
            if (isset($_GET['page']) && strpos(sanitize_text_field(wp_unslash($_GET['page'])), 'wc-admin') !== false) {
                return true;
            }
            
            return false;
        }
        
        if (function_exists('is_checkout') && is_checkout()) {
            return true;
        }
        
        if (function_exists('is_cart') && is_cart()) {
            return true;
        }
        
        if (has_block('woocommerce/checkout')) {
            return true;
        }
        
        if (has_block('woocommerce/cart')) {
            return true;
        }
        
        if ($this->is_store_api_request()) {
            return true;
        }
        
        if (wp_doing_ajax()) {
            $ajax_actions = ['woocommerce_checkout', 'wc_ajax_checkout'];
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WooCommerce AJAX action detection; no data processing.
            $current_action = isset($_REQUEST['action']) ? sanitize_text_field(wp_unslash($_REQUEST['action'])) : '';
            if (in_array($current_action, $ajax_actions)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if current request is for WooCommerce Store API
     *
     * @return boolean
     */
    private function is_store_api_request() {
        if (!isset($_SERVER['REQUEST_URI'])) {
            return false;
        }
        
        $request_uri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));
        return strpos($request_uri, '/wp-json/wc/store/') !== false ||
               strpos($request_uri, '/wc/store/') !== false;
    }

    /**
     * Returns an array of scripts/handles to be registered for this payment method.
     *
     * @return array
     */
    public function get_payment_method_script_handles() {
        try {
            $script_handle = 'wc-walletup-blocks-integration';
            $script_asset = $this->getScriptAsset();
            
            if (!$script_asset) {
                $this->errorHandler->log('No script asset found for checkout block');
                return [];
            }

            $script_url = WALLET_UP_URL . $script_asset['path'];
            $this->errorHandler->log('Registering checkout block script: ' . $script_url);

            wp_register_script(
                $script_handle,
                $script_url,
                $script_asset['dependencies'],
                $script_asset['version'],
                true
            );

            return [$script_handle];
            
        } catch (Exception $e) {
            $this->errorHandler->log('Script registration failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Returns an array of key=>value pairs of data made available to the payment methods script.
     *
     * @return array
     */
    public function get_payment_method_data() {
        try {
            $methods = [];

            $enabled_methods = $this->registry->getAllMethods();

            $display_mode = get_option('wallet_up_wc_display_mode', 'grouped');
            $mixed_individual = [];
            if ($display_mode === 'mixed') {
                $mixed_config = get_option('wallet_up_wc_mixed_methods', []);
                $mixed_individual = $mixed_config['individual'] ?? [];
            }

            $walletSettings = get_option('wallet_up_transfer_call', []);
            $mmSettings = get_option('wallet_up_mobile_money_settings', []);

            foreach ($enabled_methods as $methodId => $method) {
                if (!$method) {
                    continue;
                }

                $holder = $walletSettings["{$methodId}_holder"] ?? '';
                $mmPhone = \WalletUpPro\Core\Payments\MethodStatusResolver::mobileMoneyPhone((string) $methodId, (array) $mmSettings);
                if (empty($holder) && empty($mmPhone)) {
                    continue;
                }
                if (!\WalletUpPro\Core\Payments\MethodStatusResolver::storeShows((string) $methodId)) {
                    continue;
                }
                if (!\WalletUpPro\Core\Payments\Availability\MethodAvailability::shows((string) $methodId, 'store')) {
                    continue;
                }

                if ($display_mode === 'mixed' && in_array($methodId, $mixed_individual, true)) {
                    continue;
                }

                $baseTitle = \WalletUpPro\WooCommerce\WooCommerceMethodSettings::getEffectiveTitle($methodId);
                $methods[$methodId] = [
                    'name' => \WalletUpPro\WooCommerce\WooCommerceMethodSettings::appendDiscountBadge($methodId, $baseTitle),
                    'icon' => $this->get_method_icon($methodId),
                ];
            }
            
            $config = WooCommerceConfig::getInstance();
            $settings = $config->getSettings();
            
            $cashappApiSettings = get_option('wallet_up_cashapp_api_settings', []);
            $venmoApiSettings = get_option('wallet_up_venmo_api_settings', []);

            return [
                'title' => $this->get_setting('title', __('WalletUpPro Pay', 'walletup')),
                'description' => $this->get_setting('description', ''),
                'supports' => $this->get_supported_features(),
                'methods' => $methods,
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonces' => [
                    'wcPaymentDisplay' => wp_create_nonce('wallet_up_wc_payment_display'),
                    'tracking' => wp_create_nonce('wallet_up_admin_nonce'),
                ],
                'wcQRSettings' => $settings['wcQRSettings'] ?? [],
                'automatedApis' => [
                    'cashapp' => [
                        'enabled' => !empty($cashappApiSettings['enabled']),
                        'sandbox' => ($cashappApiSettings['environment'] ?? 'sandbox') === 'sandbox',
                    ],
                    'venmo' => [
                        'enabled' => !empty($venmoApiSettings['enabled']),
                        'sandbox' => ($venmoApiSettings['environment'] ?? 'sandbox') === 'sandbox',
                    ],
                ],
            ];
        } catch (Exception $e) {
            $this->errorHandler->log('Payment method data error: ' . $e->getMessage());
            return [
                'title' => $this->get_setting('title', __('WalletUpPro Pay', 'walletup')),
                'description' => $this->get_setting('description', ''),
                'supports' => $this->get_supported_features(),
                'methods' => []
            ];
        }
    }

    /**
     * Returns an array of supported features.
     * Following official pattern from PayPal integration.
     *
     * @return string[]
     */
    public function get_supported_features() {
        try {
            $gateway_class = 'WalletUpPro\WooCommerce\WalletUpProGateway';
            if (!class_exists($gateway_class)) {
                return ['products'];
            }

            $gateway = new $gateway_class();
            $features = array_filter($gateway->supports ?? [], function($feature) use ($gateway) {
                return $gateway->supports($feature);
            });

            return apply_filters('__experimental_woocommerce_blocks_payment_gateway_features_list', $features, $this->get_name()); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce Blocks filter.
        } catch (Exception $e) {
            $this->errorHandler->log('Feature detection failed: ' . $e->getMessage());
            return ['products'];
        }
    }

    private function getScriptAsset(): ?array {
        try {
            $manifest_path = WALLET_UP_PATH . 'walup-react/dist/.vite/manifest.json';

            if (!file_exists($manifest_path)) {
                throw new Exception(esc_html('Manifest file not found'));
            }

            $manifest = json_decode(file_get_contents($manifest_path), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception(esc_html('Invalid manifest JSON'));
            }

            $entry_point = 'src/checkoutBlockSimple.js';
            if (!isset($manifest[$entry_point])) {
                $entry_point = 'src/checkoutBlock.jsx';
            }
            if (!isset($manifest[$entry_point])) {
                throw new Exception(esc_html('Checkout block entry not found in manifest'));
            }

            $entry = $manifest[$entry_point];
            
            $this->errorHandler->log('Using manifest entry: ' . $entry_point . ' with file: ' . $entry['file']);
            
            $file_path = $entry['file'];
            if (strpos($file_path, 'assets/') === 0) {
                $relative_path = 'walup-react/dist/' . $file_path;
            } else {
                $relative_path = 'walup-react/dist/assets/' . $file_path;
            }
            
            return [
                'path' => $relative_path,
                'dependencies' => [
                    'wp-element',
                    'wp-i18n',
                    'wc-blocks-registry',
                    'wc-blocks-checkout',
                    'wc-settings',
                    'wp-data',
                    'wp-components',
                    'lodash'
                ],
                'version' => WALLET_UP_VERSION
            ];

        } catch (Exception $e) {
            $this->errorHandler->log('Error getting script asset: ' . $e->getMessage());
            return null;
        }
    }

    private function get_method_icon(string $methodId): string {
        return \WalletUpPro\Core\Display\DisplayHandler::methodLogoUrl($methodId);
    }
}