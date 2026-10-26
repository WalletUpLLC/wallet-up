<?php
namespace WalletUpPro\Core\Migration;

use WalletUpPro\Core\Database\DatabaseManager;
use WalletUpPro\Core\Error\ErrorHandler;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class MigrationManager
{
    private $db;
    private $errorHandler;
    private $tableName;

    private const CURRENT_VERSION = '5.7.15';
    private const MIGRATIONS = [
        '4.0.0' => 'createAnalyticsTable',
        '4.0.1' => 'addAnalyticsIndexes',
        '4.0.2' => 'addMetadataColumn',
        '4.0.3' => 'addReferrerTracking',
        '4.0.4' => 'addDeviceTracking',
        '4.0.5' => 'addUtmTracking',
        '4.0.6' => 'optimizeIndexes',
        '4.1.0' => 'addWooCommerceSupport',
        '4.1.1' => 'createTransactionsTable',
        '4.1.2' => 'addCommerceSupport',
        '4.2.3' => 'addEnhancedGeolocation',
        '5.7.16' => 'allowNullInPaymentTransactions'

    ];

    public function __construct(DatabaseManager $db, ErrorHandler $errorHandler, string $tableName)
    {
        $this->db = $db;
        $this->errorHandler = $errorHandler;
        $this->tableName = $tableName;
    }

    public function runMigrations(): void
    {
        try {
            $installedVersion = get_option('wallet_up_version', '0');
            $hasExistingTables = $this->db->tableExists();

            if ($installedVersion === '0') {
                if ($hasExistingTables) {
                    $this->verifyTableStructure();
                } else {
                    $this->createAnalyticsTable();
                }
                update_option('wallet_up_version', self::CURRENT_VERSION);
                return;
            }

            if (version_compare($installedVersion, self::CURRENT_VERSION, '<')) {
                $this->backupIfNewVersion($installedVersion);
                $tableState = $this->analyzeTableState();

                foreach (self::MIGRATIONS as $version => $method) {
                    if (version_compare($installedVersion, $version, '<')) {
                        try {
                            if ($this->isMigrationNeeded($method, $tableState)) {
                                $this->runMigration($version, $method);
                                $this->logMigration($version);
                                update_option('wallet_up_version', $version);
                            }
                        } catch (\Exception $e) {
                            $this->errorHandler->log("Migration {$version} failed: " . $e->getMessage());
                        }
                    }
                }
            }

            if (class_exists('WooCommerce')) {
                try {
                    $this->ensureWooCommerceState();
                } catch (\Exception $e) {
                    $this->errorHandler->log("WooCommerce setup failed: " . $e->getMessage());
                }
            }

        } catch (\Exception $e) {
            $this->errorHandler->handleException($e);
        }
    }

    private function backupIfNewVersion(string $installedVersion): void
    {
        if (version_compare($installedVersion, self::CURRENT_VERSION, '<')) {
            $this->backup();
            $this->errorHandler->log("Backup created due to version upgrade from {$installedVersion} to " . self::CURRENT_VERSION);
        }
    }

    private function analyzeTableState(): array
    {
        $columns = $this->getTableColumns();
        $indexes = $this->getExistingIndexes($this->db->getTableName());
        $wooTables = $this->getWooCommerceTableState();

        return [
            'columns' => $columns,
            'indexes' => $indexes,
            'woo_state' => $wooTables
        ];
    }

    private function isMigrationNeeded(string $method, array $tableState): bool
    {
        switch ($method) {
            case 'createAnalyticsTable':
                return !$this->db->tableExists();

            case 'addAnalyticsIndexes':
                $requiredIndexes = ['idx_event_method', 'idx_created_at', 'idx_post_id'];
                return count(array_intersect($requiredIndexes, $tableState['indexes'])) !== count($requiredIndexes);

            case 'addMetadataColumn':
                return !in_array('metadata', $tableState['columns']);

            case 'addReferrerTracking':
                return !in_array('referer', $tableState['columns']) || !in_array('campaign', $tableState['columns']);

            case 'addDeviceTracking':
                $deviceColumns = ['device_type', 'browser', 'operating_system', 'screen_resolution'];
                return count(array_intersect($deviceColumns, $tableState['columns'])) !== count($deviceColumns);

            case 'addUtmTracking':
                $utmColumns = ['utm_source', 'utm_medium', 'utm_campaign'];
                return count(array_intersect($utmColumns, $tableState['columns'])) !== count($utmColumns);

            case 'optimizeIndexes':
                $optimizedIndexes = ['idx_method_date', 'idx_event_date', 'idx_post_date'];
                return count(array_intersect($optimizedIndexes, $tableState['indexes'])) !== count($optimizedIndexes);

            case 'addWooCommerceSupport':
                return class_exists('WooCommerce') && !$tableState['woo_state']['complete'];
                
            case 'createTransactionsTable':
                global $wpdb;
                $transactionTable = $wpdb->prefix . 'wallet_up_transactions';
                return !$this->tableExists($transactionTable);
                
            case 'addCommerceSupport':
                global $wpdb;
                $transactionTable = $wpdb->prefix . 'wallet_up_transactions';
                return $this->tableExists($transactionTable) && !$this->columnExists($transactionTable, 'commerce_order_id');

            case 'allowNullInPaymentTransactions':
                global $wpdb;
                $paymentTable = $wpdb->prefix . 'walletup_payment_transactions';
                return $this->tableExists($paymentTable);

            default:
                return true;
        }
    }
    
    private function addColumn(string $columnName, string $columnDefinition = 'VARCHAR(255) DEFAULT NULL'): void
    {
        $table = esc_sql($this->db->getTableName());
        $validColumnName = preg_replace('/[^a-z0-9_]/i', '', $columnName); // Simple sanitation for column name

        if (!$this->db->columnExists($table, $validColumnName)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
            $sql = "ALTER TABLE `{$table}` ADD COLUMN `{$validColumnName}` {$columnDefinition}";
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Direct query required, caching not applicable.
            if (!$this->db->query($sql)) {
                throw new \Exception(esc_html("Failed to add column {$validColumnName}: " . $this->db->getLastError()));
            }
        } else {
            $this->errorHandler->log("Column {$validColumnName} already exists, skipping addition");
        }
    }
    
    private function verifyTableStructure(): void
    {
        $requiredColumns = [
            'id',
            'event_type',
            'payment_method',
            'post_id',
            'post_type',
            'post_title',
            'user_id',
            'user_role',
            'user_ip',
            'device_type',
            'browser',
            'operating_system',
            'screen_resolution',
            'referer',
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'created_at',
            'metadata'
        ];

        $existingColumns = $this->getTableColumns();
        $missingColumns = array_diff($requiredColumns, $existingColumns);

        if (!empty($missingColumns)) {
            foreach ($missingColumns as $column) {
                $this->addColumn($column);
            }
        }
    }
    private function getWooCommerceState(): array
    {
        global $wpdb;
        $table = $this->db->getTableName();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fresh data required for accurate results.
        $existing_columns = $wpdb->get_col("SHOW COLUMNS FROM `$table`");

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fresh data required for accurate results.
        $existing_indexes = $wpdb->get_col("SHOW INDEX FROM `$table`");

        $meta_table = $table . '_woo_meta';
        $meta_table_exists = $this->tableExists($meta_table);

        $required = [
            'columns' => [
                'order_id',
                'order_status',
                'order_total',
                'currency',
                'wc_metadata'
            ],
            'indexes' => [
                'idx_order_status',
                'idx_wc_order',
                'idx_wc_created'
            ],
            'metadata' => [
                'table_exists' => $meta_table_exists
            ]
        ];

        $complete = true;

        foreach ($required['columns'] as $column) {
            if (!in_array($column, $existing_columns)) {
                $complete = false;
                break;
            }
        }

        if ($complete) {
            foreach ($required['indexes'] as $index) {
                if (!in_array($index, $existing_indexes)) {
                    $complete = false;
                    break;
                }
            }
        }

        if ($complete && !$meta_table_exists) {
            $complete = false;
        }

        return [
            'complete' => $complete,
            'existing_columns' => $existing_columns,
            'existing_indexes' => $existing_indexes,
            'meta_table_exists' => $meta_table_exists
        ];
    }

    private function getWooCommerceTableState(): array
    {
        if (!class_exists('WooCommerce')) {
            return ['complete' => false, 'tables' => []];
        }

        $wooTables = [
            $this->db->getTableName() . '_woo_orders',
            $this->db->getTableName() . '_woo_meta'
        ];

        $existingTables = [];
        foreach ($wooTables as $table) {
            if ($this->tableExists($table)) {
                $existingTables[] = $table;
            }
        }

        return [
            'complete' => count($existingTables) === count($wooTables),
            'tables' => $existingTables
        ];
    }

    private function ensureWooCommerceState(): void
    {
        if (!class_exists('WooCommerce')) {
            return;
        }
        
        $state = $this->getWooCommerceState();
        if (!$state['complete']) {
            try {
                $this->addWooCommerceSupport();
            } catch (\Exception $e) {
                $this->errorHandler->log("WooCommerce setup failed: " . $e->getMessage());
            }
        }
    }
    private function backup(): bool
    {
        try {
            $table = esc_sql($this->db->getTableName());
            $backup_table = esc_sql($this->generateBackupTableName($table));
    
            $this->cleanUpBackups();
    
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Direct query required, caching not applicable.
            $drop_result = $this->db->query("DROP TABLE IF EXISTS `{$backup_table}`");
            if ($drop_result === false) {
                throw new \Exception(esc_html("Failed to drop existing backup table"));
            }
    
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Direct query required, caching not applicable.
            $create_result = $this->db->query("CREATE TABLE `{$backup_table}` LIKE `{$table}`");
            if ($create_result === false) {
                throw new \Exception(esc_html("Failed to create backup table structure"));
            }
    
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Direct query required, caching not applicable.
            $copy_result = $this->db->query("INSERT INTO `{$backup_table}` SELECT * FROM `{$table}`");
            if ($copy_result === false) {
                throw new \Exception(esc_html("Failed to copy data to backup table"));
            }
    
            return true;
    
        } catch (\Exception $e) {
            $this->errorHandler->log('Backup failed: ' . $e->getMessage());
            return false;
        }
    }

    private function generateBackupTableName(string $table): string
    {
        $base_name = preg_replace('/^([a-zA-Z0-9]+_)/', '', $table);
        
        $timestamp = gmdate('ymd_His');
        $unique = substr(md5(uniqid()), 0, 8);
        
        $backup_name = "{$base_name}_bak_{$timestamp}_{$unique}";
        
        $backup_name = preg_replace('/[^a-zA-Z0-9_]/', '', $backup_name);
        if (strlen($backup_name) > 64) {
            $backup_name = substr($backup_name, 0, 60) . substr(md5($backup_name), 0, 4);
        }
        
        if (preg_match('/^([a-zA-Z0-9]+_)/', $table, $matches)) {
            $prefix = $matches[1];
            $backup_name = $prefix . $backup_name;
        }
        
        return $backup_name;
    }

    private function cleanUpBackups(): void
    {
        global $wpdb;
        $table = $this->db->getTableName();
        $backupPrefix = $wpdb->esc_like($table . '_bak_') . '%'; // Match our new naming convention
        $maxBackups = 2; // Keep only the last 2 backups
    
        $backupTables = $this->getBackupTables($backupPrefix);
        if (empty($backupTables)) {
            return;
        }
    
        rsort($backupTables); // Sort by timestamp descending
    
        foreach (array_slice($backupTables, $maxBackups) as $oldBackup) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Pruning old migration backup tables.
            $wpdb->query("DROP TABLE IF EXISTS `" . esc_sql($oldBackup) . "`");
        }
    }

    private function getBackupTables(string $prefix): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared query with safe table name.
        $query = $wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $prefix
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fresh data required for accurate results.
        $tables = $wpdb->get_col($query);
        
        return array_filter($tables, function($table) {
            return preg_match('/_bak_\d{6}_\d{6}_[a-f0-9]{8}$/', $table);
        });
    }

    private function logMigration(string $version): void
    {
        $log = get_option('wallet_up_migration_log', []);
        $log[] = [
            'version' => $version,
            'date' => current_time('mysql'),
            'status' => 'success'
        ];
        update_option('wallet_up_migration_log', $log);
    }

    private function runMigration(string $version, string $method): void
    {
        $this->db->beginTransaction();

        try {
            if (!method_exists($this, $method)) {
                throw new \Exception(esc_html("Migration method {$method} not found"));
            }

            $this->{$method}();
            $this->logMigration($version);
            $this->db->commit();

        } catch (\Exception $e) {
            $this->db->rollback();
            $this->errorHandler->log("Failed migration {$version}: " . $e->getMessage());
            throw $e; // Re-throw so the calling method knows it failed
        }
    }

    private function shouldSkipMigration(string $method, array $existingColumns): bool
    {
        $columnChecks = [
            'addMetadataColumn' => ['metadata'],
            'addReferrerTracking' => ['referer', 'campaign'],
            'addDeviceTracking' => ['device_type', 'browser', 'operating_system', 'screen_resolution'],
            'addUtmTracking' => ['utm_source', 'utm_medium', 'utm_campaign']
        ];

        if (!isset($columnChecks[$method])) {
            return false;
        }

        return count(array_intersect($columnChecks[$method], $existingColumns)) === count($columnChecks[$method]);
    }

    private function getTableColumns(): array
    {
        $table = $this->db->getTableName();
        
        if (!$this->db->tableExists()) {
            return [];
        }
        
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name/variable is safely derived.
        $results = $this->db->getResults("SHOW COLUMNS FROM `" . esc_sql($table) . "`");
        
        if (!$results) {
            return [];
        }
        
        return array_map(function ($col) {
            return $col->Field;
        }, $results);
    }

    private function createAnalyticsTable(): bool
    {
        global $wpdb;
        $table_name = esc_sql($this->db->getTableName());
        $charset_collate = $wpdb->get_charset_collate();

        $this->errorHandler->log('WalletUp Pro Analytics: Starting table installation');

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
        $sql = "CREATE TABLE IF NOT EXISTS `{$table_name}` (
        `id` bigint(20) NOT NULL AUTO_INCREMENT,
        `event_type` varchar(50) NOT NULL,
        `payment_method` varchar(50) NOT NULL,
        `post_id` bigint(20) DEFAULT NULL,
        `post_type` varchar(50) DEFAULT NULL,
        `post_title` varchar(255) DEFAULT NULL,
        `user_id` bigint(20) DEFAULT NULL,
        `user_role` varchar(50) DEFAULT NULL,
        `user_ip` varchar(45) DEFAULT NULL,
        `device_type` varchar(50) DEFAULT NULL,
        `browser` varchar(50) DEFAULT NULL,
        `operating_system` varchar(50) DEFAULT NULL,
        `screen_resolution` varchar(50) DEFAULT NULL,
        `referer` text DEFAULT NULL,
        `utm_source` varchar(100) DEFAULT NULL,
        `utm_medium` varchar(100) DEFAULT NULL,
        `utm_campaign` varchar(100) DEFAULT NULL,
        `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
        `metadata` text DEFAULT NULL,
        PRIMARY KEY (`id`)
    ) {$charset_collate}";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        if ($this->db->tableExists()) {
            try {
                $indexes = [
                    'idx_method_date' => ['payment_method', 'created_at'],
                    'idx_event_date' => ['event_type', 'created_at'],
                    'idx_post_date' => ['post_id', 'created_at']
                ];

                foreach ($indexes as $name => $columns) {
                    $this->db->createIndexSafely($name, $columns);
                }
                return true;
            } catch (\Exception $e) {
                $this->errorHandler->log('Error adding indexes: ' . $e->getMessage());
                return false;
            }
        }

        return false;
    }
    
    private function getIndexableLength(string $column): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fresh data required for accurate results.
        $columnInfo = $wpdb->get_row($wpdb->prepare(
            "SELECT CHARACTER_MAXIMUM_LENGTH, DATA_TYPE 
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND COLUMN_NAME = %s",
            DB_NAME,
            $this->db->getTableName(),
            $column
        ));

        if (!$columnInfo) {
            return 0;
        }

        if (in_array(strtolower($columnInfo->DATA_TYPE), ['text', 'varchar', 'char'])) {
            return min(191, (int) $columnInfo->CHARACTER_MAXIMUM_LENGTH);
        }

        return 0; // No length specification needed for non-string types
    }
    
    private function addMetadataColumn(): void
    {
        $table = esc_sql($this->tableName);

        if (!$this->db->columnExists($table, 'metadata')) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared query with safe table name.
            $sql = $this->db->prepare("ALTER TABLE %s ADD COLUMN metadata TEXT DEFAULT NULL", $table);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Direct query required, caching not applicable.
            if (!$this->db->query($sql)) {
                throw new \Exception(esc_html($this->db->getLastError()));
            }
        }
    }
    
    private function addWooCommerceSupport(): void
    {
        if (!class_exists('WooCommerce')) {
            return;
        }

        try {
            $this->db->beginTransaction();

            $this->errorHandler->log('Starting WooCommerce migration...');

            if (!$this->db->tableExists()) {
                $this->createAnalyticsTable();
            }

            $this->ensureWooCommerceColumns();

            $this->createWooCommerceMetadataTable();

            $this->initializeWooCommerceSettings();

            update_option('wallet_up_wc_version', self::CURRENT_VERSION);

            $this->db->commit();

        } catch (\Exception $e) {
            $this->db->rollback();
            $this->errorHandler->log('WooCommerce migration failed: ' . $e->getMessage());
            throw $e;
        }
    }

    private function ensureWooCommerceColumns(): void
    {
        $existingColumns = $this->getTableColumns();
        
        $requiredColumns = [
            'order_id' => 'bigint(20) DEFAULT NULL',
            'order_status' => 'varchar(100) DEFAULT NULL',
            'order_total' => 'decimal(10,2) DEFAULT NULL',
            'currency' => 'varchar(10) DEFAULT NULL',
            'wc_metadata' => 'TEXT DEFAULT NULL'
        ];
        
        foreach ($requiredColumns as $column => $definition) {
            if (!in_array($column, $existingColumns)) {
                $this->addColumn($column, $definition);
                $this->errorHandler->log("Added WooCommerce column: {$column}");
            }
        }
        
        $updatedColumns = $this->getTableColumns();
        
        $tableName = $this->db->getTableName();
        $existingIndexes = $this->getExistingIndexes($tableName);
        
        if (in_array('order_id', $updatedColumns)) {
            if (!in_array('idx_wc_order', $existingIndexes)) {
                $this->db->createIndexSafely('idx_wc_order', ['order_id']);
            }
            
            if (in_array('order_status', $updatedColumns) && !in_array('idx_order_status', $existingIndexes)) {
                $this->db->createIndexSafely('idx_order_status', ['order_id', 'order_status']);
            }
            
            if (in_array('created_at', $updatedColumns) && !in_array('idx_wc_created', $existingIndexes)) {
                $this->db->createIndexSafely('idx_wc_created', ['order_id', 'created_at']);
            }
        }
    }

    private function createWooCommerceMetadataTable(): void
    {
        global $wpdb;
        $metaTable = $this->db->getTableName() . '_woo_meta';
        
        if ($this->tableExists($metaTable)) {
            return;
        }
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
        $sql = "CREATE TABLE `{$metaTable}` (
            `meta_id` bigint(20) NOT NULL AUTO_INCREMENT,
            `analytics_id` bigint(20) NOT NULL,
            `meta_key` varchar(255) DEFAULT NULL,
            `meta_value` longtext DEFAULT NULL,
            PRIMARY KEY (`meta_id`),
            KEY `analytics_id` (`analytics_id`),
            KEY `meta_key` (`meta_key`(191))
        ) {$charset_collate}";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        if (!$this->tableExists($metaTable)) {
            throw new \Exception(esc_html("Failed to create WooCommerce metadata table"));
        }
        
        $this->errorHandler->log("Created WooCommerce metadata table: {$metaTable}");
    }

    private function initializeWooCommerceSettings(): void
    {
        $default_settings = [
            'enabled' => 'yes',
            'title' => 'WalletUp Pay',
            'description' => 'Pay with your preferred mobile payment app',
            'instructions' => 'Please complete your payment using the QR code or payment link.',
            'display_mode' => 'qr',
            'qr_settings' => [
                'size' => 'medium',
                'color' => '#674FBF',
                'background' => '#F4F4FF',
                'border' => false,
                'style' => 'rounded'
            ]
        ];

        if (false === get_option('wallet_up_wc_settings')) {
            add_option('wallet_up_wc_settings', $default_settings);
        }
    }
    
    private function tableExists(string $table): bool
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fresh data required for accurate results.
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) 
            FROM information_schema.TABLES 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s",
            DB_NAME,
            $table
        ));
    }

    private function addAnalyticsIndexes(): void
    {
        global $wpdb;
        $table = esc_sql($this->db->getTableName());

        $existing_indexes = $this->getExistingIndexes($table);

        $indexes = [
            'idx_event_method' => ['event_type', 'payment_method'],
            'idx_created_at' => ['created_at'],
            'idx_post_id' => ['post_id']
        ];

        foreach ($indexes as $name => $columns) {
            if (in_array($name, $existing_indexes)) {
                continue;
            }

            $columns_sql = implode('`,`', array_map('esc_sql', $columns));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
            $sql = "CREATE INDEX `" . esc_sql($name) . "` ON `{$table}` (`{$columns_sql}`)";

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Direct query required, caching not applicable.
            if (!$this->db->query($sql)) {
                throw new \Exception(esc_html($this->db->getLastError()));
            }
        }
    }

    private function getExistingIndexes(string $table): array
    {
        global $wpdb;
        $indexes = [];

        if (!$this->tableExists($table)) {
            return [];
        }

        $escaped_table = esc_sql($table);
        
        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
            $results = $wpdb->get_results("SHOW INDEX FROM `{$escaped_table}`");

            if ($results) {
                foreach ($results as $index) {
                    $indexes[] = $index->Key_name;
                }
            }
        } catch (\Exception $e) {
            $this->errorHandler->log("Error getting indexes for {$table}: " . $e->getMessage());
            return [];
        }

        return $indexes;
    }
    
    private function addReferrerTracking(): void
    {
        $table = esc_sql($this->db->getTableName());

        if (!$this->db->columnExists($table, 'referer')) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Direct query required, caching not applicable.
            $this->db->query("ALTER TABLE `{$table}` ADD COLUMN `referer` text DEFAULT NULL");
        }

        if (!$this->db->columnExists($table, 'campaign')) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Direct query required, caching not applicable.
            $this->db->query("ALTER TABLE `{$table}` ADD COLUMN `campaign` varchar(100) DEFAULT NULL");
        }
    }

    private function addDeviceTracking(): void
    {
        $table = esc_sql($this->db->getTableName());
        $columns = $this->getTableColumns();

        $newColumns = [
            'device_type' => 'varchar(50) DEFAULT NULL',
            'browser' => 'varchar(50) DEFAULT NULL',
            'operating_system' => 'varchar(50) DEFAULT NULL',
            'screen_resolution' => 'varchar(50) DEFAULT NULL'
        ];

        foreach ($newColumns as $column => $definition) {
            if (!in_array($column, $columns)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
                $sql = "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}";
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Direct query required, caching not applicable.
                if (!$this->db->query($sql)) {
                    throw new \Exception(esc_html($this->db->getLastError()));
                }
            }
        }
    }

    private function addUtmTracking(): void
    {
        $table = $this->db->getTableName();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
        $sql = "ALTER TABLE $table 
            ADD COLUMN utm_source varchar(100) DEFAULT NULL,
            ADD COLUMN utm_medium varchar(100) DEFAULT NULL,
            ADD COLUMN utm_campaign varchar(100) DEFAULT NULL";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Direct query required, caching not applicable.
        if (!$this->db->query($sql)) {
            throw new \Exception(esc_html($this->db->getLastError()));
        }
    }

    private function optimizeIndexes(): void
    {
        $table = $this->db->getTableName();
        $existing_indexes = $this->getExistingIndexes($table);

        $indexesToDrop = ['idx_user_id', 'event_method', 'created_at', 'post_id', 'device_type'];
        foreach ($indexesToDrop as $index) {
            if (in_array($index, $existing_indexes)) {
                $sql = "ALTER TABLE `" . esc_sql($table) . "` DROP INDEX `" . esc_sql($index) . "`";
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Direct query required, caching not applicable.
                $this->db->query($sql);
            }
        }

        $indexes = [
            'idx_method_date' => ['payment_method', 'created_at'],
            'idx_event_date' => ['event_type', 'created_at'],
            'idx_post_date' => ['post_id', 'created_at']
        ];

        foreach ($indexes as $name => $columns) {
            if (!in_array($name, $existing_indexes)) {
                $this->db->createIndexSafely($name, $columns);
            }
        }
    }
    
    private function createTransactionsTable(): void
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wallet_up_transactions';
        $charset_collate = $wpdb->get_charset_collate();
        
        $this->errorHandler->log('Creating transactions table for payment tracking');
        
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
        $sql = "CREATE TABLE IF NOT EXISTS `{$table_name}` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `transaction_id` varchar(64) NOT NULL,
            `payment_method` varchar(32) NOT NULL,
            `amount` decimal(10,2) NOT NULL,
            `currency` varchar(3) DEFAULT 'USD',
            `status` enum('pending','completed','failed','cancelled','refunded','reversed','expired') DEFAULT 'pending',
            `order_id` bigint(20) DEFAULT NULL,
            `note` text DEFAULT NULL,
            `paypal_txn_id` varchar(64) DEFAULT NULL,
            `webhook_data` longtext DEFAULT NULL,
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_transaction_id` (`transaction_id`),
            KEY `idx_status` (`status`),
            KEY `idx_payment_method` (`payment_method`),
            KEY `idx_order_id` (`order_id`),
            KEY `idx_created_at` (`created_at`)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        if ($this->tableExists($table_name)) {
            update_option('wallet_up_transactions_table_version', '1.0');
        } else {
            throw new \Exception(esc_html('Failed to create transactions table'));
        }
    }
    
    private function addCommerceSupport(): void
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wallet_up_transactions';
        
        $this->errorHandler->log('Adding PayPal Commerce Platform support columns');
        
        if (!$this->tableExists($table_name)) {
            throw new \Exception(esc_html('Transactions table does not exist'));
        }
        
        $commerce_columns = [
            'commerce_order_id' => 'varchar(64) DEFAULT NULL COMMENT "PayPal Commerce order ID"',
            'capture_id' => 'varchar(64) DEFAULT NULL COMMENT "PayPal Commerce capture ID"',
            'webhook_event_type' => 'varchar(64) DEFAULT NULL COMMENT "Webhook event type (IPN/Commerce)"',
            'signature_verified' => 'tinyint(1) DEFAULT 0 COMMENT "Webhook signature verification status"',
            'client_metadata' => 'text DEFAULT NULL COMMENT "Client metadata and tracking info"',
            'retry_count' => 'tinyint(3) DEFAULT 0 COMMENT "Webhook processing retry count"'
        ];
        
        $columns_added = 0;
        foreach ($commerce_columns as $column_name => $column_definition) {
            if (!$this->columnExists($table_name, $column_name)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
                $sql = "ALTER TABLE `{$table_name}` ADD COLUMN `{$column_name}` {$column_definition}";
                
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Direct query required, caching not applicable.
                if ($wpdb->query($sql) !== false) {
                    $this->errorHandler->log("Added column: {$column_name}");
                    $columns_added++;
                } else {
                    $this->errorHandler->log("Failed to add column: {$column_name} - " . $wpdb->last_error);
                }
            } else {
                $this->errorHandler->log("Column already exists: {$column_name}");
            }
        }
        
        $commerce_indexes = [
            'idx_commerce_order_id' => 'commerce_order_id',
            'idx_capture_id' => 'capture_id',
            'idx_webhook_event_type' => 'webhook_event_type',
            'idx_signature_verified' => 'signature_verified'
        ];
        
        foreach ($commerce_indexes as $index_name => $column_name) {
            if (!$this->indexExists($table_name, $index_name)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
                $sql = "ALTER TABLE `{$table_name}` ADD INDEX `{$index_name}` (`{$column_name}`)";
                
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Direct query required, caching not applicable.
                if ($wpdb->query($sql) !== false) {
                    $this->errorHandler->log("Added index: {$index_name}");
                } else {
                    $this->errorHandler->log("Failed to add index: {$index_name} - " . $wpdb->last_error);
                }
            }
        }
        
        update_option('wallet_up_transactions_table_version', '1.1');
        
    }
    
    private function allowNullInPaymentTransactions(): void
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'walletup_payment_transactions';
        
        $this->errorHandler->log('Starting Migration 5.7.16: Updating payment transactions table to allow NULLs');
        
        if (!$this->tableExists($table_name)) {
            $this->errorHandler->log('Table does not exist, skipping NULL modification.');
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $sql = "ALTER TABLE `{$table_name}` 
            MODIFY `provider_transaction_id` varchar(255) DEFAULT NULL,
            MODIFY `customer_email` varchar(255) DEFAULT NULL,
            MODIFY `wc_order_id` bigint(20) DEFAULT NULL,
            MODIFY `post_id` bigint(20) DEFAULT NULL";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        if ($wpdb->query($sql) !== false) {
            $this->errorHandler->log('Successfully updated columns to allow NULLs.');
        } else {
            $this->errorHandler->log('Failed to update columns: ' . $wpdb->last_error);
            throw new \Exception(esc_html('Failed to alter walletup_payment_transactions table'));
        }
    }

    private function columnExists(string $table_name, string $column_name): bool
    {
        global $wpdb;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fresh data required for accurate results.
        $column = $wpdb->get_results($wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            DB_NAME,
            $table_name,
            $column_name
        ));
        
        return !empty($column);
    }
    
    private function indexExists(string $table_name, string $index_name): bool
    {
        global $wpdb;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fresh data required for accurate results.
        $index = $wpdb->get_results($wpdb->prepare(
            "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS 
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s",
            DB_NAME,
            $table_name,
            $index_name
        ));
        
        return !empty($index);
    }
    
    private function addEnhancedGeolocation(): void
    {
        try {
            $this->errorHandler->log('Starting enhanced geolocation migration (4.2.3)');
            
            $geolocationMigration = new GeolocationMigration();
            $success = $geolocationMigration->addEnhancedLocationFields();
            
            if ($success) {
            } else {
            }
            
        } catch (\Exception $e) {
            $this->errorHandler->log('Enhanced geolocation migration error: ' . $e->getMessage());
        }
    }
}