<?php
namespace WalletUpPro\Core\Admin\Integrations;

if (!defined('ABSPATH')) {
    exit;
}

class OneClickAttach
{
    public static function init(): void
    {
        if (!defined('WALLET_UP_IS_PRO_BUILD') || !WALLET_UP_IS_PRO_BUILD) {
            return; // Free builds never serve these endpoints
        }
        add_action('wp_ajax_wallet_up_attach_wpforms', [__CLASS__, 'handleWPForms']);
        add_action('wp_ajax_wallet_up_enable_wc_gateway', [__CLASS__, 'handleWooCommerce']);
        add_action('wp_ajax_wallet_up_disable_wc_gateway', [__CLASS__, 'handleWooCommerceDisable']);
    }

    public static function handleWPForms(): void
    {
        try {
            self::assertAllowed();
            if (!self::wpformsActive()) {
                throw new \Exception(esc_html__('WPForms is not active.', 'walletup'));
            }
            wp_send_json_success([
                'redirect' => esc_url_raw(admin_url('admin.php?page=wpforms-builder&view=setup')),
                'message'  => esc_html__('Pick a Wallet Up template to start your form.', 'walletup'),
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public static function handleWooCommerceDisable(): void
    {
        try {
            self::assertAllowed();
            if (!class_exists('WooCommerce')) {
                throw new \Exception(esc_html__('WooCommerce is not active.', 'walletup'));
            }

            $grouped = (array) get_option('woocommerce_wallet_up_settings', []);
            $grouped['enabled'] = 'no';
            update_option('woocommerce_wallet_up_settings', $grouped);

            global $wpdb;
            $sql  = "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s";
            $rows = $wpdb->get_results($wpdb->prepare($sql, 'woocommerce_wallet_up_%_settings'));
            foreach ((array) $rows as $row) {
                if ($row->option_name === 'woocommerce_wallet_up_settings') {
                    continue; // already handled above
                }
                $v = (array) get_option($row->option_name, []);
                if (($v['enabled'] ?? 'no') === 'yes') {
                    $v['enabled'] = 'no';
                    update_option($row->option_name, $v);
                }
            }

            wp_cache_delete('woocommerce_payment_gateways', 'options');

            wp_send_json_success([
                'redirect' => esc_url_raw(admin_url('admin.php?page=wc-settings&tab=checkout')),
                'message'  => esc_html__('Wallet Up is now disabled at WooCommerce checkout.', 'walletup'),
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public static function handleWooCommerce(): void
    {
        try {
            self::assertAllowed();
            if (!class_exists('WooCommerce')) {
                throw new \Exception(esc_html__('WooCommerce is not active.', 'walletup'));
            }

            $settingsKey = 'woocommerce_wallet_up_settings';
            $settings = (array) get_option($settingsKey, []);

            $defaults = [
                'enabled'     => 'yes',
                'title'       => __('Wallet Up — Cash App, PayPal, Venmo', 'walletup'),
                'description' => __('Pay using your chosen app — Cash App, PayPal, Venmo, and more.', 'walletup'),
            ];
            foreach ($defaults as $k => $v) {
                if (!isset($settings[$k]) || $settings[$k] === '' || ($k === 'enabled' && $settings[$k] !== 'yes')) {
                    $settings[$k] = $v;
                }
            }

            $accounts = (array) get_option('wallet_up_transfer_call', []);
            foreach ((array) \WalletUpPro\Core\Config\PaymentMethodsConfig::FREE_METHOD_IDS as $id) {
                $hasHolder = !empty($accounts[$id . '_holder']);
                $key = 'enable_' . $id;
                if (!isset($settings[$key])) {
                    $settings[$key] = $hasHolder ? 'yes' : 'no';
                }
            }

            update_option($settingsKey, $settings);

            wp_send_json_success([
                'redirect' => esc_url_raw(admin_url('admin.php?page=wc-settings&tab=checkout&section=wallet_up')),
                'message'  => esc_html__('Wallet Up is now enabled at WooCommerce checkout.', 'walletup'),
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    private static function assertAllowed(): void
    {
        if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
            throw new \Exception(esc_html__('Invalid security token', 'walletup'));
        }
        if (!current_user_can('manage_options')) {
            throw new \Exception(esc_html__('Permission denied', 'walletup'));
        }
    }

    private static function wpformsActive(): bool
    {
        return defined('WPFORMS_VERSION') || class_exists('WPForms\\WPForms') || class_exists('WPForms');
    }
}
