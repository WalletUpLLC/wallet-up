<?php
namespace WalletUpPro\WooCommerce;

use WalletUpPro\Core\Error\ErrorHandler;
use WalletUpPro\Core\BaseSingleton;
use WalletUpPro\Core\PaymentRegistry;
use WalletUpPro\Core\Config\PaymentMethodsConfig;
use Exception;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
class WooCommerceConfig extends BaseSingleton
{
    private $errorHandler;
    private $registry;
    private $settings_cache = [];
    protected $initialized = false;
    protected $initialization_error = null;
    private static $instance = null;
    protected function initialize(): void {
        try {
            $this->errorHandler = ErrorHandler::getInstance();
            $this->registry = PaymentRegistry::getInstance();

            if (!$this->checkWooCommerceAvailability()) {
                throw new Exception(esc_html('WooCommerce is not active'));
            }

            $this->initializeSettings();
            $this->initialized = true;

        } catch (Exception $e) {
            $this->initialization_error = $e->getMessage();
            $this->errorHandler->log('WooCommerce config initialization failed: ' . $e->getMessage());
        }
    }

    public function getSettings(): array {
        if (!empty($this->settings_cache)) {
            return $this->settings_cache;
        }

        try {
            $settings = [
                'wcSettings' => $this->getWooCommerceSettings(),
                'wcQRSettings' => $this->getQRSettings(),
                'methodSettings' => $this->getMethodStatuses(),
                'methodDetails' => $this->getMethodDetails()
            ];

            $this->validateSettings($settings);
            $this->settings_cache = $settings;

            return $settings;

        } catch (Exception $e) {
            $this->errorHandler->log('Failed to get settings: ' . $e->getMessage());
            return $this->getDefaultSettings();
        }
    }

    public const OPTION_KEYS = [
        'settings' => 'wallet_up_woocommerce_settings',
        'qr_settings' => 'wallet_up_woocommerce_qr_settings',
        'gateway_settings' => 'woocommerce_wallet_up_settings',
        'method_prefix' => 'wallet_up_method_'
    ];

    private const QR_DIMENSIONS = [
        'small' => 150,
        'medium' => 180,
        'large' => 250
    ];

    public const DEFAULT_SETTINGS = [
        'enabled' => 'yes',
        'title' => 'WalletUpPro Pay',
        'description' => 'Pay with your preferred mobile payment app',
        'instructions' => 'Please ensure you have completed your payment using the QR code above.',
        'display_mode' => 'qr'
    ];

    public const DEFAULT_QR_SETTINGS = [
        'size' => 'medium',
        'color' => '#674FBF',
        'background' => '#FFFFFF',
        'border' => false,
        'style' => 'square',
        'checkout_display' => 'below_payment',
        'show_on_checkout' => true,
        'show_on_confirmation' => true,
        'show_default_message' => true,
        'custom_message' => '',
        'hide_message' => false
    ];

    private const VALIDATION_RULES = [
        'qr' => [
            'size' => ['small', 'medium', 'large'],
            'color' => '/^#[a-fA-F0-9]{6}$/',
            'background' => '/^#[a-fA-F0-9]{6}$/',
            'style' => ['square', 'rounded', 'dot'],
            'border' => 'bool',
            'checkout_display' => ['below_payment', 'after_order', 'sidebar'],
            'show_on_checkout' => 'bool',
            'show_on_confirmation' => 'bool',
            'show_default_message' => 'bool',
            'custom_message' => 'string',
            'hide_message' => 'bool'
        ],
        'display_modes' => ['qr', 'button', 'link']
    ];

    public static function shouldShowQrOnCheckout(): bool
    {
        $qr = get_option(self::OPTION_KEYS['qr_settings'], []);
        if (is_array($qr) && array_key_exists('show_on_checkout', $qr) && !$qr['show_on_checkout']) {
            return false;
        }
        $gw = get_option('woocommerce_wallet_up_settings', []);
        if (is_array($gw) && ($gw['payment_display_timing'] ?? 'checkout') === 'order_received') {
            return false;
        }
        return true;
    }

    public static function getCheckoutDeferredNoticeHtml(): string
    {
        return '<div class="walletup-checkout-deferred-note" style="padding:14px 16px;background:#f5f3ff;border:1px solid #e9e5ff;border-radius:10px;color:#4c1d95;font-size:14px;line-height:1.5;">'
            . esc_html__('Your payment QR code and step-by-step instructions will appear right after you place your order.', 'walletup')
            . '</div>';
    }

    private function checkWooCommerceAvailability(): bool
    {
        return (
            class_exists('WooCommerce') &&
            class_exists('WC_Payment_Gateway') &&
            function_exists('WC') &&
            did_action('woocommerce_loaded') > 0
        );
    }

    public function initializeSettings(): void
    {
        try {
            $wc_settings = get_option(self::OPTION_KEYS['settings'], []);
            $qr_settings = get_option(self::OPTION_KEYS['qr_settings'], []);
            $gateway_settings = $this->getWooCommerceGatewaySettings();

            $merged_settings = array_replace_recursive(
                self::DEFAULT_SETTINGS,
                $wc_settings,
                $gateway_settings
            );

            $merged_qr_settings = array_replace_recursive(
                self::DEFAULT_QR_SETTINGS,
                $qr_settings
            );

            $this->validateSettings([
                'wcSettings' => $merged_settings,
                'wcQRSettings' => $merged_qr_settings
            ]);

            update_option(self::OPTION_KEYS['settings'], $this->sanitizeSettings($merged_settings));
            update_option(self::OPTION_KEYS['qr_settings'], $this->sanitizeQRSettings($merged_qr_settings));

        } catch (Exception $e) {
            $this->errorHandler->log('Settings initialization failed: ' . $e->getMessage());
            throw $e;
        }
    }

    private function getWooCommerceGatewaySettings(): array
    {
        if (!$this->checkWooCommerceAvailability()) {
            return [];
        }

        if (!did_action('woocommerce_loaded')) {
            return [];
        }

        try {
            $gateways = WC()->payment_gateways()->payment_gateways();
            if (!isset($gateways['wallet_up'])) {
                return [];
            }

            $gateway = $gateways['wallet_up'];
            return [
                'enabled' => $gateway->enabled,
                'title' => $gateway->title,
                'description' => $gateway->description,
                'instructions' => $gateway->get_option('instructions', self::DEFAULT_SETTINGS['instructions']),
                'display_mode' => $gateway->get_option('display_mode', 'qr')
            ];
        } catch (Exception $e) {
            $this->errorHandler->log('Failed to get WC gateway settings: ' . $e->getMessage());
            return [];
        }
    }

    public function updateSettings(array $settings): bool
    {
        try {
            $existing_settings = get_option(self::OPTION_KEYS['settings'], []);

            if ($existing_settings === $settings) {
                return false; // No need to update if settings are unchanged
            }

            $this->validateSettings($settings);

            update_option(self::OPTION_KEYS['settings'], $this->sanitizeSettings($settings['wcSettings']));
            update_option(self::OPTION_KEYS['qr_settings'], $this->sanitizeQRSettings($settings['wcQRSettings']));

            if (isset($settings['methodSettings'])) {
                $this->updateMethodSettings($settings['methodSettings']);
            }

            $this->updateGatewaySettings($settings['wcSettings']);

            return true;

        } catch (Exception $e) {
            $this->errorHandler->log('Settings update failed: ' . $e->getMessage());
            throw $e;
        }
    }

    private function validateSettings(array $settings): void
    {
        if (!isset($settings['wcSettings'], $settings['wcQRSettings'])) {
            throw new Exception(esc_html('Invalid settings structure'));
        }

        foreach (array_keys(self::DEFAULT_SETTINGS) as $field) {
            if (!isset($settings['wcSettings'][$field])) {
                throw new Exception(esc_html("Missing required WooCommerce setting: {$field}"));
            }
        }

        if (!in_array($settings['wcSettings']['display_mode'], self::VALIDATION_RULES['display_modes'])) {
            throw new Exception(esc_html('Invalid display mode'));
        }

        if ($settings['wcSettings']['display_mode'] === 'qr') {
            foreach (self::VALIDATION_RULES['qr'] as $field => $validation) {
                if (!isset($settings['wcQRSettings'][$field])) {
                    continue; // Skip optional fields
                }

                if (is_array($validation)) {
                    if (!in_array($settings['wcQRSettings'][$field], $validation)) {
                        throw new Exception(esc_html("Invalid QR {$field} value"));
                    }
                } elseif ($validation === 'bool') {
                    if (
                        !is_bool($settings['wcQRSettings'][$field]) &&
                        !in_array($settings['wcQRSettings'][$field], [0, 1, '0', '1', 'true', 'false'], true)
                    ) {
                        throw new Exception(esc_html("Invalid boolean value for {$field}"));
                    }
                } elseif (is_string($validation) && $validation !== 'string') {
                    if (!preg_match($validation, $settings['wcQRSettings'][$field])) {
                        throw new Exception(esc_html("Invalid format for {$field}"));
                    }
                }
            }
        }
    }

    private function sanitizeSettings(array $settings): array
    {
        return [
            'enabled' => $settings['enabled'] === 'yes' ? 'yes' : 'no',
            'express_enabled' => (!isset($settings['express_enabled']) || $settings['express_enabled'] === 'yes' || $settings['express_enabled'] === true) ? 'yes' : 'no',
            'payment_element' => (isset($settings['payment_element']) && ($settings['payment_element'] === 'yes' || $settings['payment_element'] === true || $settings['payment_element'] === 1 || $settings['payment_element'] === '1')) ? 'yes' : 'no',
            'title' => sanitize_text_field($settings['title']),
            'description' => wp_kses_post($settings['description']),
            'instructions' => wp_kses_post($settings['instructions']),
            'display_mode' => in_array($settings['display_mode'], self::VALIDATION_RULES['display_modes'])
                ? $settings['display_mode']
                : 'qr'
        ];
    }

    private function sanitizeQRSettings(array $settings): array
    {
        $sanitized = [];
        foreach (self::VALIDATION_RULES['qr'] as $field => $validation) {
            if (!isset($settings[$field])) {
                $sanitized[$field] = self::DEFAULT_QR_SETTINGS[$field] ?? null;
                continue;
            }

            if (is_array($validation)) {
                $sanitized[$field] = in_array($settings[$field], $validation)
                    ? $settings[$field]
                    : self::DEFAULT_QR_SETTINGS[$field];
            } elseif ($validation === 'bool') {
                $sanitized[$field] = (bool) $settings[$field];
            } elseif ($validation === 'string') {
                $sanitized[$field] = sanitize_text_field($settings[$field]);
            } else {
                $sanitized[$field] = preg_match($validation, $settings[$field])
                    ? $settings[$field]
                    : self::DEFAULT_QR_SETTINGS[$field];
            }
        }
        return $sanitized;
    }

    private function getMethodStatuses(): array
    {
        $statuses = [];
        foreach (PaymentMethodsConfig::getAllMethodIds() as $method_id) {
            $statuses[$method_id] = $this->registry->isMethodEnabled($method_id) && \WalletUpPro\Core\Payments\MethodStatusResolver::storeShows($method_id);
        }
        return $statuses;
    }

    private function getMethodDetails(): array
    {
        $details = [];
        foreach (PaymentMethodsConfig::getAllMethodIds() as $method_id) {
            $details[$method_id] = [
                'title' => PaymentMethodsConfig::getMethodName($method_id),
                'description' => ''
            ];
        }
        return $details;
    }

    private function updateMethodSettings(array $method_settings): void
    {
        if (!$this->checkWooCommerceAvailability()) {
            return;
        }
        $gateways = WC()->payment_gateways()->payment_gateways();
        if (!isset($gateways['wallet_up'])) {
            return;
        }
        foreach ($method_settings as $method_id => $status) {
            $method_id = sanitize_key((string) $method_id);
            $show = \WalletUpPro\Core\Payments\MethodStatusResolver::flag($status);
            if ($show && !$this->registry->isMethodEnabled($method_id)) {
                $this->registry->enableMethod($method_id);
            }
            $gateways['wallet_up']->update_option('enable_' . $method_id, $show ? 'yes' : 'no');
        }
        $this->clearCache();
    }

    public function clearCache(): void
    {
        $this->settings_cache = [];
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    public function getInitializationError(): ?string
    {
        return $this->initialization_error;
    }

    private function getWooCommerceSettings(): array
    {
        $stored_settings = get_option(self::OPTION_KEYS['settings'], []);
        
        $gateway_settings = $this->getWooCommerceGatewaySettings();
        
        return array_replace_recursive(
            self::DEFAULT_SETTINGS,
            $stored_settings,
            $gateway_settings
        );
    }

    public function getQRSettings(): array
    {
        return get_option(self::OPTION_KEYS['qr_settings'], self::DEFAULT_QR_SETTINGS);
    }

    private function updateWooCommerceSettings(array $settings): void
    {
        update_option(self::OPTION_KEYS['settings'], $this->sanitizeSettings($settings));
    }

    private function updateQRSettings(array $settings): void
    {
        update_option(self::OPTION_KEYS['qr_settings'], $this->sanitizeQRSettings($settings));
    }

    private function updateGatewaySettings(array $settings): void
    {
        if ($this->checkWooCommerceAvailability()) {
            $gateways = WC()->payment_gateways()->payment_gateways();
            if (isset($gateways['wallet_up'])) {
                $gateway = $gateways['wallet_up'];
                foreach ($settings as $key => $value) {
                    $gateway->update_option($key, $value);
                }
            }
        }
    }

    private function getDefaultSettings(): array
    {
        return [
            'wcSettings' => self::DEFAULT_SETTINGS,
            'wcQRSettings' => self::DEFAULT_QR_SETTINGS,
            'methodSettings' => [],
            'methodDetails' => []
        ];
    }
    public static function getOptionKey(string $key): ?string
    {
        $instance = self::getInstance();
        if (!isset(self::OPTION_KEYS[$key])) {
            return null;
        }
        return self::OPTION_KEYS[$key];
    }

    public function getSetting(string $key, $default = null)
    {
        $optionKey = self::getOptionKey($key);
        if ($optionKey) {
            return get_option($optionKey, $default);
        }
        return $default;
    }

    public function getGatewaySettings()
    {
        return $this->getSetting('settings', self::DEFAULT_SETTINGS);
    }

}