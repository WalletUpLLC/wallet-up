<?php
namespace WalletUpPro\Core\Activation;

if (!defined('ABSPATH')) {
    exit;
}

class ActivationHandler
{
    private static $instance = null;
    private const VERSION_OPTION = 'wallet_up_version';
    private const DB_VERSION_OPTION = 'wallet_up_db_version';

    public static function getInstance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        register_activation_hook(WALLET_UP_FILE, [$this, 'activate']);
    }

    public function activate(): void
    {
        $current_version = defined('WALLET_UP_PRO_VERSION') ? WALLET_UP_PRO_VERSION : '1.0.0';
        $installed_version = get_option(self::VERSION_OPTION, '0.0.0');

        if (version_compare($installed_version, $current_version, '<')) {
            $this->setupDatabase();
            $this->updateOptions();
            update_option(self::VERSION_OPTION, $current_version);
        }

        if (class_exists('\WalletUpPro\Services\PaymentLinkStore')) {
            \WalletUpPro\Services\PaymentLinkStore::createTable();
        }

        flush_rewrite_rules();
    }

    private function setupDatabase(): void
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wallet_up_transactions';
        $charset_collate = $wpdb->get_charset_collate();
        $db_version = '1.0';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fresh data required for accurate results.
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Schema creation on plugin activation; table name from wpdb prefix.
            $sql = "CREATE TABLE $table_name (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT(20) UNSIGNED NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                transaction_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) $charset_collate;";

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
            update_option(self::DB_VERSION_OPTION, $db_version);
        }
    }

    private function updateOptions(): void
    {
        $default_options = [
            'currency' => 'USD',
            'default_amount' => 10.00,
            'enable_logging' => true,
        ];
        update_option('wallet_up_settings', $default_options);
    }
}
