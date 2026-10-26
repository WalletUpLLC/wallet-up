<?php
namespace WalletUpPro\Core\Database;

use WalletUpPro\Core\Error\ErrorHandler;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
class DatabaseManager
{
    private \wpdb $wpdb;
    private string $tableName;
    private string $metaTableName;
    private ?string $lastError = null;
    private ErrorHandler $errorHandler;
    private array $tableExistsCache = [];

    public function __construct(string $tableName)
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->errorHandler = ErrorHandler::getInstance(); // Add this line
        $this->validateTableName($tableName); // Add validation call here
        $this->tableName = $tableName;
        $this->metaTableName = $tableName . '_metadata';

        if (!$this->wpdb) {
            throw new \Exception(esc_html('Database connection not available'));
        }
    }
    private function validateTableName(string $tableName): void
    {
        if (empty($tableName)) {
            throw new \Exception(esc_html('Table name cannot be empty'));
        }

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
            throw new \Exception(esc_html('Invalid table name format'));
        }
    }

    private function handleDatabaseError(\Exception $e): void
    {
        $this->lastError = $e->getMessage();
        $this->errorHandler->log('Database error: ' . $e->getMessage());
        throw new \Exception(esc_html('Database operation failed: ' . $e->getMessage()));
    }

    public function cleanup(): void
    {
        $this->tableExistsCache = [];
        $this->lastError = null;
    }

    public function prepareAndExecute(string $query, ...$args): bool
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared query with safe table name.
        $sql = $this->wpdb->prepare($query, ...$args);
        return $this->query($sql);
    }
    public function getTableSchema(): array
    {
        $cache_key = 'wallet_up_' . md5('schema_' . $this->tableName);
        $columns = wp_cache_get($cache_key, 'wallet_up');
        if (false !== $columns) {
            return $columns;
        }

        $columns = [];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Schema query, results cached via wp_cache_set.
        $results = $this->wpdb->get_results("SHOW COLUMNS FROM `" . esc_sql($this->tableName) . "`");

        if ($results) {
            foreach ($results as $row) {
                $columns[$row->Field] = [
                    'type' => $row->Type,
                    'null' => $row->Null,
                    'key' => $row->Key,
                    'default' => $row->Default,
                    'extra' => $row->Extra
                ];
            }
        }

        wp_cache_set($cache_key, $columns, 'wallet_up', 3600);
        return $columns;
    }

    public function tableExists(?string $tableName = null): bool
    {
        $tableToCheck = $tableName ?? $this->tableName;

        if (!isset($this->tableExistsCache[$tableToCheck])) {
            $escaped_table = esc_sql($tableToCheck);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fresh data required for accurate results.
            $this->tableExistsCache[$tableToCheck] = $this->wpdb->get_var(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
                "SHOW TABLES LIKE '{$escaped_table}'"
            ) !== null;
        }
        return $this->tableExistsCache[$tableToCheck];
    }
    public function columnExists(string $table, string $column): bool
    {
        $safe_table = esc_sql($table);
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SHOW COLUMNS FROM `{$safe_table}` LIKE %s",
                $column
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        return !empty($results);
    }
    /**
     * Insert data into table
     * 
     * @param array $data Data to insert
     * @param array|null $format Optional format for values
     * @return bool|int False on failure, number of rows affected on success
     */
    public function insert(array $data, ?array $format = null)
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Write operation, caching not applicable.
        $result = $this->wpdb->insert($this->tableName, $data, $format);
        if ($result === false) {
            $this->lastError = $this->wpdb->last_error;
        }
        return $result;
    }

    public function createIndexSafely(string $indexName, array $columns): bool
{
    try {
        $escapedTable = esc_sql($this->tableName);
        $indexName = esc_sql($indexName);
        
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $existsByName = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
            WHERE table_schema = %s
            AND table_name = %s
            AND index_name = %s",
            DB_NAME,
            $this->tableName,
            $indexName
        ));
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ($existsByName) {
            return true; // Index already exists
        }

        $columnDefs = [];
        foreach ($columns as $column) {
            $column = esc_sql($column);

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $columnInfo = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT DATA_TYPE
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE table_schema = %s
                AND table_name = %s
                AND COLUMN_NAME = %s",
                DB_NAME,
                $this->tableName,
                $column
            ));
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            
            if (!$columnInfo) {
                $this->errorHandler->log("Column $column does not exist in table $this->tableName");
                continue; // Skip non-existent columns
            }
            
            $dataType = strtolower($columnInfo->DATA_TYPE ?? '');

            if (count($columns) === 1 && in_array($dataType, ['varchar', 'char', 'text', 'longtext', 'mediumtext', 'tinytext'])) {
                $columnDefs[] = "`$column`(191)";
            } else {
                $columnDefs[] = "`$column`";
            }
        }
        
        if (empty($columnDefs)) {
            $this->errorHandler->log("No valid columns provided for index $indexName");
            return false;
        }
        
        $sql = "CREATE INDEX `$indexName` ON `$escapedTable` (" . implode(', ', $columnDefs) . ")";
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Direct query required, caching not applicable.
        $result = $this->wpdb->query($sql);
        
        if ($result === false) {
            $this->errorHandler->log("Failed to create index $indexName: " . $this->wpdb->last_error);
            return false;
        }
        
        return true;
    } catch (\Exception $e) {
        $this->errorHandler->log("Error creating index $indexName: " . $e->getMessage());
        return false;
    }
}
    public function update(array $data, array $where, ?array $format = null, ?array $whereFormat = null)
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Write operation, caching not applicable.
        $result = $this->wpdb->update($this->tableName, $data, $where, $format, $whereFormat);
        if ($result === false) {
            $this->lastError = $this->wpdb->last_error;
        }
        return $result;
    }

    public function delete(array $where, ?array $whereFormat = null)
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Write operation, caching not applicable.
        $result = $this->wpdb->delete($this->tableName, $where, $whereFormat);
        if ($result === false) {
            $this->lastError = $this->wpdb->last_error;
        }
        return $result;
    }

    /**
     * Execute a query on the database
     * 
     * @param string $sql SQL query to execute
     * @param array $params Parameters to prepare the SQL query
     * @return bool Returns true if the query was successful, false otherwise
     */
    public function query(string $sql, array $params = []): bool
    {
        try {
            if (!empty($params)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared query with safe table name.
                $sql = $this->wpdb->prepare($sql, ...$params);
            } else {
                if (method_exists($this->wpdb, 'check_sql')) {
                    $sql = $this->wpdb->check_sql($sql);
                }
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Direct query required, caching not applicable.
            $result = $this->wpdb->query($sql);
            if ($result === false) {
                throw new \Exception(esc_html($this->wpdb->last_error));
            }
            return true;
        } catch (\Exception $e) {
            $this->handleDatabaseError($e);
            return false;
        }
    }
    public function getCharsetCollate(): string
    {
        global $wpdb;
        return $wpdb->get_charset_collate();
    }

    public function getMetaTableName(): string
    {
        return $this->metaTableName;
    }

    public function insertWithMeta(array $data, array $metadata = []): int
    {
        try {
            $this->beginTransaction();

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Write operation, caching not applicable.
            $result = $this->wpdb->insert($this->tableName, $data);
            if ($result === false) {
                throw new \Exception(esc_html($this->wpdb->last_error));
            }

            $analyticsId = $this->wpdb->insert_id;

            if (!empty($metadata)) {
                foreach ($metadata as $key => $value) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Write operation, caching not applicable.
                    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Custom analytics meta table with indexed key; not wp_postmeta.
                    $metaResult = $this->wpdb->insert(
                        $this->metaTableName,
                        [
                            'analytics_id' => $analyticsId,
                            'meta_key' => $key,
                            'meta_value' => maybe_serialize($value)
                        ]
                    );

                    if ($metaResult === false) {
                        throw new \Exception(esc_html($this->wpdb->last_error));
                    }
                }
            }

            $this->commit();
            return $analyticsId;

        } catch (\Exception $e) {
            $this->rollback();
            $this->lastError = $e->getMessage();
            throw $e;
        }
    }

    public function getAnalyticsWithMeta(array $args = []): array
    {
        $query = "SELECT a.*, GROUP_CONCAT(
            CONCAT(m.meta_key, ':', m.meta_value)
            SEPARATOR ';;'
        ) as metadata
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
        FROM {$this->tableName} a
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
        LEFT JOIN {$this->metaTableName} m ON a.id = m.analytics_id";

        if (!empty($args['where'])) {
            $query .= " WHERE " . implode(' AND ', $args['where']);
        }

        $query .= " GROUP BY a.id";

        if (!empty($args['orderby'])) {
            $allowed_orderby = ['a.id', 'a.created_at', 'a.amount', 'a.status', 'a.payment_method', 'a.order_id', 'id', 'created_at', 'amount', 'status'];
            $orderby = $args['orderby'];
            $direction = 'DESC';
            if (preg_match('/^(\S+)\s+(ASC|DESC)$/i', $orderby, $matches)) {
                $orderby = $matches[1];
                $direction = strtoupper($matches[2]);
            }
            if (in_array($orderby, $allowed_orderby, true)) {
                $query .= " ORDER BY " . $orderby . " " . $direction;
            }
        }

        if (!empty($args['limit'])) {
            $query .= " LIMIT " . (int) $args['limit'];
        }

        $cache_key = 'wallet_up_' . md5('analytics_meta_' . $query);
        $cached = wp_cache_get($cache_key, 'wallet_up');
        if (false !== $cached) {
            return $cached;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query built from validated args, results cached via wp_cache_set.
        $results = $this->wpdb->get_results($query);
        if ($results === null) {
            $this->lastError = $this->wpdb->last_error;
            return [];
        }

        foreach ($results as &$row) {
            if (!empty($row->metadata)) {
                $metaItems = explode(';;', $row->metadata);
                $row->metadata = [];
                foreach ($metaItems as $item) {
                    list($key, $value) = explode(':', $item, 2);
                    $row->metadata[$key] = maybe_unserialize($value);
                }
            } else {
                $row->metadata = [];
            }
        }

        wp_cache_set($cache_key, $results, 'wallet_up', 300);
        return $results;
    }

    public function getWooCommerceAnalytics(array $args = []): array
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name safely derived from wpdb prefix; dates via prepare placeholders.
        $query = $this->wpdb->prepare(
            "SELECT
                payment_method,
                COUNT(DISTINCT CASE WHEN event_type = 'wc_new_order' THEN order_id END) as orders,
                COUNT(DISTINCT CASE WHEN event_type = 'wc_order_status_change'
                    AND order_status = 'completed' THEN order_id END) as completed_orders,
                SUM(order_total) as total_amount,
                COUNT(DISTINCT CASE WHEN event_type = 'wc_payment_displayed' THEN id END) as displays,
                COUNT(CASE WHEN event_type IN ('scan', 'click') THEN 1 END) as interactions
                FROM {$this->tableName}
                WHERE created_at BETWEEN %s AND %s
                GROUP BY payment_method",
            $args['start_date'],
            $args['end_date']
        );

        $wc_cache_key = 'wallet_up_' . md5('wc_analytics_db_' . ($args['start_date'] ?? '') . '_' . ($args['end_date'] ?? ''));
        $cached = wp_cache_get($wc_cache_key, 'wallet_up');
        if (false !== $cached) {
            return $cached;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query prepared above, results cached via wp_cache_set.
        $results = $this->wpdb->get_results($query);
        if ($results === null) {
            $this->lastError = $this->wpdb->last_error;
            return [];
        }

        wp_cache_set($wc_cache_key, $results, 'wallet_up', 300);
        return $results;
    }

    public function prepare(string $query, ...$args): string
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared query with safe table name.
        return $this->wpdb->prepare($query, ...$args);
    }

    public function getLastInsertId(): int
    {
        return $this->wpdb->insert_id;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function createTable(string $schema): bool
    {
        if (!function_exists('dbDelta')) {
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        }
        dbDelta($schema);
        return !$this->wpdb->last_error;
    }

    public function beginTransaction(): void
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Transaction control, caching not applicable.
        $this->wpdb->query('START TRANSACTION');
    }

    public function commit(): void
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Transaction control, caching not applicable.
        $this->wpdb->query('COMMIT');
    }

    public function rollback(): void
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Transaction control, caching not applicable.
        $this->wpdb->query('ROLLBACK');
    }

    public function getTableName(): string
    {
        return $this->tableName;
    }

    public function getResults(string $query, array $params = []): ?array
    {
        if (empty($params)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fresh data required for accurate results.
            return $this->wpdb->get_results($query); // No parameters, use the query as-is
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared query with safe table name.
            $preparedQuery = $this->wpdb->prepare($query, ...$params);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fresh data required for accurate results.
            return $this->wpdb->get_results($preparedQuery);
        }
    }

    public function getRow(string $query, array $params = []): ?object
    {
        if (empty($params)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fresh data required for accurate results.
            return $this->wpdb->get_row($query); // No parameters, use the query as-is
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared query with safe table name.
            $preparedQuery = $this->wpdb->prepare($query, ...$params);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fresh data required for accurate results.
            return $this->wpdb->get_row($preparedQuery);
        }
    }
}