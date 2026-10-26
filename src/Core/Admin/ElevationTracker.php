<?php
namespace WalletUpPro\Core\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class ElevationTracker
{
    public static function runChecks(): array
    {
        $checks = [];

        $ajaxEndpoints = [
            'wallet_up_save_settings',
            'wallet_up_get_analytics',
            'wallet_up_delete_analytics',
            'wallet_up_check_qr_status',
            'wallet_up_check_chart_status',
            'wallet_up_preview_shortcode',
            'wallet_up_download_preview',
            'wallet_up_shorten_url',
            'wallet_up_save_business_card_data',
            'wallet_up_get_business_card_data',
            'wallet_up_log_error',
            'wallet_up_wc_get_analytics',
        ];
        foreach ($ajaxEndpoints as $action) {
            $hookName = 'wp_ajax_' . $action;
            $checks['ajax_' . $action] = has_action($hookName) !== false;
        }

        global $wpdb;
        $analyticsTable = $wpdb->prefix . 'wallet_up_analytics';
        $donationsTable = $wpdb->prefix . 'wallet_up_donations';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fresh data required for accurate results.
        $checks['db_analytics_table'] = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $analyticsTable)) === $analyticsTable;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fresh data required for accurate results.
        $checks['db_donations_table'] = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $donationsTable)) === $donationsTable;

        $checks['option_transfer_call'] = is_array(get_option('wallet_up_transfer_call', []));
        $checks['option_display_settings'] = is_array(get_option('wallet_up_display_settings', []));
        $checks['option_enabled_methods'] = is_array(get_option('wallet_up_enabled_methods', []));

        $checks['wc_active'] = class_exists('WooCommerce');
        $checks['wc_gateway'] = false;
        if ($checks['wc_active'] && function_exists('WC')) {
            $gateways = WC()->payment_gateways()->payment_gateways();
            $display_mode = get_option('wallet_up_wc_display_mode', 'grouped');
            if ($display_mode === 'grouped') {
                $checks['wc_gateway'] = isset($gateways['wallet_up']);
            } else {
                foreach (array_keys($gateways) as $gw_id) {
                    if (strpos($gw_id, 'wallet_up_') === 0) {
                        $checks['wc_gateway'] = true;
                        break;
                    }
                }
            }
        }
        $checks['wc_order_bridge'] = has_action('walletup_payment_status_changed') !== false;

        $manifestPath = defined('WALLET_UP_BASE_DIR')
            ? WALLET_UP_BASE_DIR . 'walup-react/dist/.vite/manifest.json'
            : '';
        $manifest = [];
        if ($manifestPath && file_exists($manifestPath)) {
            $manifest = json_decode(file_get_contents($manifestPath), true) ?: [];
        }
        $checks['vite_main'] = isset($manifest['src/main.jsx']);
        $checks['vite_checkout'] = isset($manifest['src/checkout.jsx']);
        $checks['vite_checkoutBlock'] = isset($manifest['src/checkoutBlock.jsx']);

        $checks['image_library'] = extension_loaded('gd') || extension_loaded('imagick');

        $passed = count(array_filter($checks));
        $total = count($checks);

        return [
            'checks' => $checks,
            'passed' => $passed,
            'total'  => $total,
            'score'  => $passed . '/' . $total,
        ];
    }
}
