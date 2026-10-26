<?php
namespace WalletUpPro\Core\Migration;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
trait WooCommerceMigrations
{
    private function addWooCommerceColumns(): void
    {
        $table = $this->db->getTableName();

        $columns = [
            'woo_order_id' => 'bigint(20) DEFAULT NULL',
            'woo_product_id' => 'bigint(20) DEFAULT NULL',
            'woo_order_status' => 'varchar(100) DEFAULT NULL',
            'woo_order_total' => 'decimal(10,2) DEFAULT NULL',
            'woo_currency' => 'varchar(10) DEFAULT NULL',
            'woo_payment_status' => 'varchar(50) DEFAULT NULL',
            'wc_metadata' => 'TEXT DEFAULT NULL'
        ];

        foreach ($columns as $column => $definition) {
            try {
                $exists = $this->db->columnExists($table, $column);

                if (!$exists) {
                    $sql = sprintf(
                        "ALTER TABLE `%s` ADD COLUMN `%s` %s",
                        esc_sql($table),
                        esc_sql($column),
                        $definition
                    );

                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Direct query required, caching not applicable.
                    $result = $this->db->query($sql);

                    if ($result === false) {
                        throw new \Exception(esc_html($this->db->getLastError()));
                    }

                } else {
                }
            } catch (\Exception $e) {
                $this->errorHandler->log("Failed to add WooCommerce column {$column}: " . $e->getMessage());
                throw $e; // Re-throw to handle in transaction
            }
        }

        $this->addWooCommerceIndexes();
    }

    private function addWooCommerceIndexes(): void
    {
        $table = $this->db->getTableName();

        $indexes = [
            'idx_woo_order' => ['woo_order_id'],
            'idx_woo_product' => ['woo_product_id'],
            'idx_woo_status' => ['woo_order_status', 'woo_payment_status'],
            'idx_woo_created' => ['woo_order_id', 'created_at']
        ];

        foreach ($indexes as $name => $columns) {
            try {
                if (!$this->indexExists($table, $name)) {
                    $columns_sql = implode('`, `', array_map('esc_sql', $columns));
                    $sql = sprintf(
                        "CREATE INDEX `%s` ON `%s` (`%s`)",
                        esc_sql($name),
                        esc_sql($table),
                        $columns_sql
                    );

                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Direct query required, caching not applicable.
                    if (!$this->db->query($sql)) {
                        throw new \Exception(esc_html($this->db->getLastError()));
                    }

                }
            } catch (\Exception $e) {
                $this->errorHandler->log("Failed to add WooCommerce index {$name}: " . $e->getMessage());
                throw $e;
            }
        }
    }

    private function addWooCommerceMetadata(): void
    {
        try {
            $table = $this->db->getTableName();
            $metaTable = $table . '_woo_meta';

            if (!$this->db->tableExists($metaTable)) {
                global $wpdb;
                $charset_collate = $wpdb->get_charset_collate();

                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
                $sql = "CREATE TABLE IF NOT EXISTS `{$metaTable}` (
                    `meta_id` bigint(20) NOT NULL AUTO_INCREMENT,
                    `woo_order_id` bigint(20) NOT NULL,
                    `meta_key` varchar(255) DEFAULT NULL,
                    `meta_value` longtext,
                    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`meta_id`),
                    KEY `woo_order_id` (`woo_order_id`),
                    KEY `meta_key` (`meta_key`(191))
                ) {$charset_collate}";

                require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
                dbDelta($sql);

                if (!$this->db->tableExists($metaTable)) {
                    throw new \Exception(esc_html("Failed to create WooCommerce metadata table"));
                }

                if (!$this->constraintExists($metaTable, 'fk_woo_order_meta')) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
                    $constraintSql = "ALTER TABLE `{$metaTable}`
                        ADD CONSTRAINT `fk_woo_order_meta`
                        FOREIGN KEY (`woo_order_id`)
                        REFERENCES `{$table}` (`woo_order_id`)
                        ON DELETE CASCADE";

                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Direct query required, caching not applicable.
                    if (!$this->db->query($constraintSql)) {
                        throw new \Exception(esc_html("Failed to add WooCommerce metadata foreign key: " . $this->db->getLastError()));
                    }
                }
            }

        } catch (\Exception $e) {
            $this->errorHandler->log('WooCommerce metadata setup failed: ' . $e->getMessage());
            throw $e;
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fresh data required for accurate results.
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND COLUMN_NAME = %s",
            DB_NAME,
            $table,
            $column
        ));
        return (bool) $result;
    }

    private function indexExists(string $table, string $indexName): bool
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fresh data required for accurate results.
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND INDEX_NAME = %s",
            DB_NAME,
            $table,
            $indexName
        ));
        return (bool) $result;
    }

    private function constraintExists(string $table, string $constraintName): bool
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fresh data required for accurate results.
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND CONSTRAINT_NAME = %s",
            DB_NAME,
            $table,
            $constraintName
        ));
        return (bool) $result;
    }
}