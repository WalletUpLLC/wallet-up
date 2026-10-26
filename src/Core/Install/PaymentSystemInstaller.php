<?php
namespace WalletUpPro\Core\Install;

use WalletUpPro\Core\Error\ErrorHandler;
use Exception;

if (!defined('ABSPATH')) {
    exit;
}

class PaymentSystemInstaller
{
    private $db;
    private $charset_collate;
    private $errorHandler;
    private $tables = [];

    public function __construct()
    {
        global $wpdb;
        $this->db = $wpdb;
        $this->charset_collate = $wpdb->get_charset_collate();
        $this->errorHandler = ErrorHandler::getInstance();

        $this->tables = [
            'payment_transactions' => $this->db->prefix . 'walletup_payment_transactions',
            'payment_methods' => $this->db->prefix . 'walletup_payment_methods',
            'webhooks_log' => $this->db->prefix . 'walletup_webhooks_log',
            'crypto_addresses' => $this->db->prefix . 'walletup_crypto_addresses',
            'mobile_money_transactions' => $this->db->prefix . 'walletup_mobile_money_txns'
        ];

    }

    public function install(): void
    {
        try {
            $this->checkRequirements();
            $this->createPaymentTransactionsTable();
            $this->createPaymentMethodsTable();
            $this->createWebhooksLogTable();
            $this->createCryptoAddressesTable();
            $this->createMobileMoneyTransactionsTable();
            $this->verifyTables();
            $this->seedPaymentMethods();
            $this->updateVersion();

        } catch (Exception $e) {
            $this->errorHandler->log('Payment System installation failed: ' . $e->getMessage());
            throw $e;
        }
    }

    public function ensureSchema(): void
    {
        try {
            $target = defined('WALLET_UP_VERSION') ? (string) WALLET_UP_VERSION : '6.2.0';
            if (version_compare((string) get_option('walletup_payment_schema_version', '0'), $target, '>=')) {
                return;
            }

            global $wpdb;
            $table = $this->tables['payment_transactions'];
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema introspection.
            $tableExists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $table
            ));

            if ($tableExists > 0) {
                $nullable = [
                    'provider_transaction_id' => 'varchar(255)',
                    'customer_email'          => 'varchar(255)',
                    'wc_order_id'             => 'bigint(20)',
                    'post_id'                 => 'bigint(20)',
                ];
                foreach ($nullable as $column => $type) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema introspection.
                    $isNullable = $wpdb->get_var($wpdb->prepare(
                        "SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                        DB_NAME,
                        $table,
                        $column
                    ));
                    if ($isNullable !== null && strtoupper((string) $isNullable) === 'NO') {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL on plugin-owned table; identifiers are constant.
                        $wpdb->query("ALTER TABLE `{$table}` MODIFY `{$column}` {$type} DEFAULT NULL");
                    }
                }

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL on plugin-owned table; identifier is constant.
                $wpdb->query("UPDATE `{$table}` SET provider_transaction_id = NULL WHERE provider_transaction_id = ''");

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema introspection.
                $hasUnique = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s",
                    DB_NAME,
                    $table,
                    'provider_txn_unique'
                ));
                if ($hasUnique === 0) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- schema introspection on plugin-owned table.
                    $dupes = (int) $wpdb->get_var("SELECT COUNT(*) FROM (SELECT provider_transaction_id FROM `{$table}` WHERE provider_transaction_id IS NOT NULL GROUP BY provider_transaction_id HAVING COUNT(*) > 1) d");
                    if ($dupes === 0) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL on plugin-owned table; identifiers are constant.
                        $added = $wpdb->query("ALTER TABLE `{$table}` ADD UNIQUE INDEX provider_txn_unique (provider_transaction_id)");
                        if ($added !== false) {
                            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL on plugin-owned table; identifiers are constant.
                            $wpdb->query("ALTER TABLE `{$table}` DROP INDEX provider_txn");
                        }
                    } else {
                        $this->errorHandler->log("Payment schema: provider_transaction_id has {$dupes} duplicate value groups; UNIQUE index skipped, mutex guard remains");
                    }
                }
            }

            update_option('walletup_payment_schema_version', $target);
        } catch (\Throwable $e) {
            $this->errorHandler->log('Payment schema ensure failed: ' . $e->getMessage());
        }
    }

    private function checkRequirements(): void
    {
    }

    private function createPaymentTransactionsTable(): void
    {
        $table = $this->tables['payment_transactions'];

        $this->errorHandler->log('Creating payment_transactions table: ' . $table);

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            payment_id varchar(64) NOT NULL,
            payment_hash varchar(64) NOT NULL,
            session_id varchar(64) DEFAULT NULL,
            customer_email varchar(255) NOT NULL,
            customer_first_name varchar(100) DEFAULT NULL,
            customer_last_name varchar(100) DEFAULT NULL,
            customer_phone varchar(50) DEFAULT NULL,
            customer_id bigint(20) unsigned DEFAULT NULL,
            payment_method varchar(50) NOT NULL,
            payment_provider varchar(50) DEFAULT NULL,
            amount decimal(15,2) NOT NULL,
            refunded_amount decimal(15,2) NOT NULL DEFAULT 0.00,
            currency varchar(10) DEFAULT 'USD',
            status varchar(50) NOT NULL DEFAULT 'pending',
            provider_transaction_id varchar(255) DEFAULT NULL,
            provider_payment_data text DEFAULT NULL,
            provider_response text DEFAULT NULL,
            crypto_address varchar(255) DEFAULT NULL,
            crypto_txn_hash varchar(255) DEFAULT NULL,
            crypto_confirmations int unsigned DEFAULT 0,
            crypto_network varchar(50) DEFAULT NULL,
            mobile_money_number varchar(50) DEFAULT NULL,
            mobile_money_network varchar(50) DEFAULT NULL,
            mobile_money_reference varchar(100) DEFAULT NULL,
            fee_amount decimal(15,2) DEFAULT 0.00,
            net_amount decimal(15,2) DEFAULT NULL,
            exchange_rate decimal(20,8) DEFAULT NULL,
            metadata text DEFAULT NULL,
            description varchar(500) DEFAULT NULL,
            transaction_type varchar(50) DEFAULT NULL,
            wp_user_id bigint(20) unsigned DEFAULT NULL,
            wc_order_id bigint(20) unsigned DEFAULT NULL,
            wpforms_entry_id bigint(20) unsigned DEFAULT NULL,
            post_id bigint(20) unsigned DEFAULT NULL,
            verification_method varchar(50) DEFAULT NULL,
            verified_by bigint(20) unsigned DEFAULT NULL,
            verified_at datetime DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            user_agent text DEFAULT NULL,
            webhook_received_at datetime DEFAULT NULL,
            webhook_attempts int unsigned DEFAULT 0,
            webhook_data text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            expires_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY payment_id (payment_id),
            KEY payment_hash (payment_hash),
            KEY customer_email (customer_email),
            KEY payment_method (payment_method),
            KEY status (status),
            KEY created_at (created_at),
            KEY provider_txn (provider_transaction_id),
            KEY crypto_address (crypto_address),
            KEY mobile_number (mobile_money_number),
            KEY wc_order (wc_order_id)
        ) {$this->charset_collate}";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Direct query required, caching not applicable.
        $result = $this->db->query($sql);

        if ($result === false) {
            $error = $this->db->last_error;
            $this->errorHandler->log('Failed to create payment_transactions table: ' . $error);
            throw new Exception(esc_html('Failed to create payment_transactions table: ' . $error));
        }

    }

    private function createPaymentMethodsTable(): void
    {
        $table = $this->tables['payment_methods'];

        $this->errorHandler->log('Creating payment_methods table: ' . $table);

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            method_id varchar(50) NOT NULL,
            method_name varchar(100) NOT NULL,
            method_type varchar(50) NOT NULL,
            is_enabled tinyint(1) DEFAULT 1,
            is_automated tinyint(1) DEFAULT 0,
            requires_manual_verification tinyint(1) DEFAULT 0,
            provider_name varchar(100) DEFAULT NULL,
            provider_api_enabled tinyint(1) DEFAULT 0,
            api_credentials text DEFAULT NULL,
            api_endpoint varchar(255) DEFAULT NULL,
            icon_url varchar(255) DEFAULT NULL,
            description varchar(500) DEFAULT NULL,
            fee_type varchar(20) DEFAULT 'percentage',
            fee_fixed decimal(10,2) DEFAULT 0.00,
            fee_percentage decimal(5,2) DEFAULT 0.00,
            fee_cap decimal(10,2) DEFAULT NULL,
            min_amount decimal(15,2) DEFAULT NULL,
            max_amount decimal(15,2) DEFAULT NULL,
            supported_countries text DEFAULT NULL,
            supported_currencies text DEFAULT NULL,
            settings text DEFAULT NULL,
            display_order int DEFAULT 999,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY method_id (method_id),
            KEY is_enabled (is_enabled),
            KEY display_order (display_order)
        ) {$this->charset_collate}";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Direct query required, caching not applicable.
        $result = $this->db->query($sql);

        if ($result === false) {
            $error = $this->db->last_error;
            $this->errorHandler->log('Failed to create payment_methods table: ' . $error);
            throw new Exception(esc_html('Failed to create payment_methods table: ' . $error));
        }

    }

    private function createWebhooksLogTable(): void
    {
        $table = $this->tables['webhooks_log'];

        $this->errorHandler->log('Creating webhooks_log table: ' . $table);

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            webhook_id varchar(100) NOT NULL,
            source varchar(50) NOT NULL,
            request_method varchar(10) DEFAULT NULL,
            request_headers text DEFAULT NULL,
            request_body text DEFAULT NULL,
            status varchar(20) DEFAULT 'received',
            response_status int DEFAULT NULL,
            processing_time_ms int DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            error_message text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            processed_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY webhook_id (webhook_id),
            KEY source (source),
            KEY status (status),
            KEY created_at (created_at)
        ) {$this->charset_collate}";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Direct query required, caching not applicable.
        $result = $this->db->query($sql);

        if ($result === false) {
            $error = $this->db->last_error;
            $this->errorHandler->log('Failed to create webhooks_log table: ' . $error);
            throw new Exception(esc_html('Failed to create webhooks_log table: ' . $error));
        }

    }

    private function createCryptoAddressesTable(): void
    {
        $table = $this->tables['crypto_addresses'];

        $this->errorHandler->log('Creating crypto_addresses table: ' . $table);

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            payment_id varchar(64) NOT NULL,
            currency varchar(10) NOT NULL,
            address varchar(255) NOT NULL,
            network varchar(50) DEFAULT NULL,
            address_type varchar(50) DEFAULT NULL,
            is_monitored tinyint(1) DEFAULT 1,
            last_checked_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY payment_id (payment_id),
            KEY address (address),
            KEY currency (currency),
            KEY is_monitored (is_monitored)
        ) {$this->charset_collate}";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Direct query required, caching not applicable.
        $result = $this->db->query($sql);

        if ($result === false) {
            $error = $this->db->last_error;
            $this->errorHandler->log('Failed to create crypto_addresses table: ' . $error);
            throw new Exception(esc_html('Failed to create crypto_addresses table: ' . $error));
        }

    }

    private function createMobileMoneyTransactionsTable(): void
    {
        $table = $this->tables['mobile_money_transactions'];

        $this->errorHandler->log('Creating mobile_money_transactions table: ' . $table);

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            payment_id varchar(64) NOT NULL,
            transaction_id varchar(255) DEFAULT NULL,
            mobile_number varchar(50) NOT NULL,
            network varchar(50) NOT NULL,
            country varchar(10) DEFAULT NULL,
            amount decimal(15,2) NOT NULL,
            currency varchar(10) DEFAULT NULL,
            status varchar(50) NOT NULL,
            reference_number varchar(100) DEFAULT NULL,
            operator_reference varchar(100) DEFAULT NULL,
            provider_response text DEFAULT NULL,
            callback_received_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY payment_id (payment_id),
            KEY mobile_number (mobile_number),
            KEY transaction_id (transaction_id),
            KEY status (status),
            KEY network (network)
        ) {$this->charset_collate}";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Direct query required, caching not applicable.
        $result = $this->db->query($sql);

        if ($result === false) {
            $error = $this->db->last_error;
            $this->errorHandler->log('Failed to create mobile_money_transactions table: ' . $error);
            throw new Exception(esc_html('Failed to create mobile_money_transactions table: ' . $error));
        }

    }

    private function verifyTables(): void
    {
        foreach ($this->tables as $key => $table) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fresh data required for accurate results.
            if ($this->db->get_var("SHOW TABLES LIKE '$table'") != $table) {
                throw new Exception(esc_html("Failed to create table: $table"));
            }
        }
    }

    private function seedPaymentMethods(): void
    {
        $table = $this->tables['payment_methods'];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fresh data required for accurate results.
        $existingCount = $this->db->get_var("SELECT COUNT(*) FROM $table");
        if ($existingCount > 0) {
            return;
        }

        $methods = [
            [
                'method_id' => 'paypal',
                'method_name' => 'PayPal',
                'method_type' => 'online_payment',
                'is_enabled' => 1,
                'is_automated' => 1,
                'provider_name' => 'PayPal',
                'provider_api_enabled' => 1,
                'fee_type' => 'hybrid',
                'fee_fixed' => 0.30,
                'fee_percentage' => 2.90,
                'supported_countries' => wp_json_encode(['*']),
                'supported_currencies' => wp_json_encode(['USD', 'EUR', 'GBP']),
                'display_order' => 1
            ],
            [
                'method_id' => 'bitcoin',
                'method_name' => 'Bitcoin',
                'method_type' => 'cryptocurrency',
                'is_enabled' => 1,
                'is_automated' => 1,
                'provider_name' => 'Bitcoin Network',
                'fee_type' => 'percentage',
                'fee_percentage' => 0.50,
                'supported_countries' => wp_json_encode(['*']),
                'supported_currencies' => wp_json_encode(['BTC', 'USD']),
                'display_order' => 2
            ],
            [
                'method_id' => 'mpesa_ke',
                'method_name' => 'M-Pesa (Kenya)',
                'method_type' => 'mobile_money',
                'is_enabled' => 1,
                'is_automated' => 1,
                'provider_name' => 'Safaricom',
                'provider_api_enabled' => 1,
                'fee_type' => 'percentage',
                'fee_percentage' => 1.50,
                'supported_countries' => wp_json_encode(['KE']),
                'supported_currencies' => wp_json_encode(['KES', 'USD']),
                'display_order' => 3
            ],
            [
                'method_id' => 'orange_money_ci',
                'method_name' => 'Orange Money (Côte d\'Ivoire)',
                'method_type' => 'mobile_money',
                'is_enabled' => 1,
                'is_automated' => 1,
                'provider_name' => 'Orange Côte d\'Ivoire',
                'provider_api_enabled' => 1,
                'fee_type' => 'percentage',
                'fee_percentage' => 1.50,
                'supported_countries' => wp_json_encode(['CI']),
                'supported_currencies' => wp_json_encode(['XOF', 'USD']),
                'display_order' => 4
            ],
            [
                'method_id' => 'mtn_momo_ci',
                'method_name' => 'MTN Mobile Money (Côte d\'Ivoire)',
                'method_type' => 'mobile_money',
                'is_enabled' => 1,
                'is_automated' => 1,
                'provider_name' => 'MTN Côte d\'Ivoire',
                'provider_api_enabled' => 1,
                'fee_type' => 'percentage',
                'fee_percentage' => 1.50,
                'supported_countries' => wp_json_encode(['CI']),
                'supported_currencies' => wp_json_encode(['XOF', 'USD']),
                'display_order' => 5
            ],
            [
                'method_id' => 'moov_money_ci',
                'method_name' => 'Moov Money (Côte d\'Ivoire)',
                'method_type' => 'mobile_money',
                'is_enabled' => 1,
                'is_automated' => 1,
                'provider_name' => 'Moov Africa',
                'provider_api_enabled' => 1,
                'fee_type' => 'percentage',
                'fee_percentage' => 1.50,
                'supported_countries' => wp_json_encode(['CI']),
                'supported_currencies' => wp_json_encode(['XOF', 'USD']),
                'display_order' => 6
            ],
            [
                'method_id' => 'mtn_momo_zm',
                'method_name' => 'MTN Mobile Money (Zambia)',
                'method_type' => 'mobile_money',
                'is_enabled' => 1,
                'is_automated' => 1,
                'provider_name' => 'MTN Zambia',
                'provider_api_enabled' => 1,
                'fee_type' => 'percentage',
                'fee_percentage' => 1.50,
                'supported_countries' => wp_json_encode(['ZM']),
                'supported_currencies' => wp_json_encode(['ZMW', 'USD']),
                'display_order' => 7
            ],
            [
                'method_id' => 'airtel_money_zm',
                'method_name' => 'Airtel Money (Zambia)',
                'method_type' => 'mobile_money',
                'is_enabled' => 1,
                'is_automated' => 1,
                'provider_name' => 'Airtel Zambia',
                'provider_api_enabled' => 1,
                'fee_type' => 'percentage',
                'fee_percentage' => 1.50,
                'supported_countries' => wp_json_encode(['ZM']),
                'supported_currencies' => wp_json_encode(['ZMW', 'USD']),
                'display_order' => 8
            ],
            [
                'method_id' => 'zamtel_kwacha',
                'method_name' => 'Zamtel Kwacha',
                'method_type' => 'mobile_money',
                'is_enabled' => 1,
                'is_automated' => 1,
                'provider_name' => 'Zamtel',
                'provider_api_enabled' => 1,
                'fee_type' => 'percentage',
                'fee_percentage' => 1.50,
                'supported_countries' => wp_json_encode(['ZM']),
                'supported_currencies' => wp_json_encode(['ZMW', 'USD']),
                'display_order' => 9
            ],
            [
                'method_id' => 'wave_ci',
                'method_name' => 'Wave',
                'method_type' => 'mobile_money',
                'is_enabled' => 1,
                'is_automated' => 1,
                'provider_name' => 'Wave',
                'provider_api_enabled' => 1,
                'fee_type' => 'percentage',
                'fee_percentage' => 1.00,
                'supported_countries' => wp_json_encode(['CI', 'SN']),
                'supported_currencies' => wp_json_encode(['XOF']),
                'display_order' => 10
            ]
        ];

        foreach ($methods as $method) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Write operation, caching not applicable.
            $this->db->insert($table, $method);
        }

    }

    private function updateVersion(): void
    {
        update_option('walletup_payment_system_version', '1.0.0');
        update_option('walletup_payment_system_installed_at', current_time('mysql'));
    }
}
