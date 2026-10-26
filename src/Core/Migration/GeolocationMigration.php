<?php
namespace WalletUpPro\Core\Migration;

if (!defined('ABSPATH')) {
    exit;
}

use WalletUpPro\Core\Error\ErrorHandler;

class GeolocationMigration
{
    private $db;
    private $errorHandler;
    private $tableName;
    
    public function __construct()
    {
        global $wpdb;
        $this->db = $wpdb;
        $this->errorHandler = ErrorHandler::getInstance();
        $this->tableName = $wpdb->prefix . 'walletup_url_clicks';
    }
    
    public function addEnhancedLocationFields(): bool
    {
        try {
            if (!$this->tableExists()) {
                $this->errorHandler->log('Geolocation migration skipped: url_clicks table does not exist');
                return true; // Not an error, table will be created with new schema
            }
            
            $fieldsAdded = [];
            
            $newFields = [
                'region' => 'VARCHAR(100) NULL COMMENT "State/Province/Region name"',
                'region_code' => 'VARCHAR(10) NULL COMMENT "State/Province/Region code"',
                'city' => 'VARCHAR(100) NULL COMMENT "City name"',
                'timezone' => 'VARCHAR(50) NULL COMMENT "Timezone identifier"',
                'isp' => 'VARCHAR(200) NULL COMMENT "Internet Service Provider"',
                'confidence' => 'TINYINT UNSIGNED NULL DEFAULT NULL COMMENT "Location confidence score 0-100"',
                'geo_source' => 'VARCHAR(20) NULL DEFAULT NULL COMMENT "Source of geolocation data"'
            ];
            
            foreach ($newFields as $fieldName => $fieldDefinition) {
                if (!$this->columnExists($fieldName)) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
                    $sql = "ALTER TABLE `{$this->tableName}` ADD COLUMN `{$fieldName}` {$fieldDefinition}";
                    
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Direct query required, caching not applicable.
                    $result = $this->db->query($sql);
                    
                    if ($result !== false) {
                        $fieldsAdded[] = $fieldName;
                        $this->errorHandler->log("Added geolocation field: {$fieldName}");
                    } else {
                        $this->errorHandler->log("Failed to add geolocation field: {$fieldName} - " . $this->db->last_error);
                    }
                } else {
                    $this->errorHandler->log("Geolocation field already exists: {$fieldName}");
                }
            }
            
            $this->addIndexesSafely();
            
            if (!empty($fieldsAdded)) {
            } else {
            }
            
            return true;
            
        } catch (\Exception $e) {
            $this->errorHandler->log('Geolocation migration error: ' . $e->getMessage());
            return false;
        }
    }
    
    private function addIndexesSafely(): void
    {
        $indexes = [
            'idx_country_region' => 'country, region',
            'idx_city' => 'city',
            'idx_confidence' => 'confidence'
        ];
        
        foreach ($indexes as $indexName => $columns) {
            if (!$this->indexExists($indexName)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
                $sql = "ALTER TABLE `{$this->tableName}` ADD INDEX `{$indexName}` ({$columns})";
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Direct query required, caching not applicable.
                $result = $this->db->query($sql);
                
                if ($result !== false) {
                    $this->errorHandler->log("Added index: {$indexName}");
                } else {
                    $this->errorHandler->log("Failed to add index: {$indexName} - " . $this->db->last_error);
                }
            }
        }
    }
    
    private function tableExists(): bool
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fresh data required for accurate results.
        $result = $this->db->get_var($this->db->prepare("SHOW TABLES LIKE %s", $this->tableName));
        return $result === $this->tableName;
    }
    
    private function columnExists(string $columnName): bool
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fresh data required for accurate results.
        $result = $this->db->get_var($this->db->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            DB_NAME,
            $this->tableName,
            $columnName
        ));
        
        return $result === $columnName;
    }
    
    private function indexExists(string $indexName): bool
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fresh data required for accurate results.
        $result = $this->db->get_var($this->db->prepare(
            "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS 
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s",
            DB_NAME,
            $this->tableName,
            $indexName
        ));
        
        return $result === $indexName;
    }
    
    public function rollback(): bool
    {
        try {
            if (!$this->tableExists()) {
                return true;
            }
            
            $fieldsToRemove = ['region', 'region_code', 'city', 'timezone', 'isp', 'confidence', 'geo_source'];
            $fieldsRemoved = [];
            
            foreach ($fieldsToRemove as $field) {
                if ($this->columnExists($field)) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
                    $sql = "ALTER TABLE `{$this->tableName}` DROP COLUMN `{$field}`";
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Direct query required, caching not applicable.
                    $result = $this->db->query($sql);
                    
                    if ($result !== false) {
                        $fieldsRemoved[] = $field;
                    }
                }
            }
            
            return true;
            
        } catch (\Exception $e) {
            $this->errorHandler->log('Geolocation migration rollback error: ' . $e->getMessage());
            return false;
        }
    }
}