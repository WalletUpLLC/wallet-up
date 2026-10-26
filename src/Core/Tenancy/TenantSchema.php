<?php
declare(strict_types=1);

namespace WalletUpPro\Core\Tenancy;

if (!defined('ABSPATH')) {
    exit;
}

final class TenantSchema
{
    public const VERSION_OPTION = 'wallet_up_tenancy_schema';
    public const VERSION        = '2';
    private const TABLES = [
        'wallet_up_donations',
        'walletup_payment_transactions',
        'wallet_up_payers',
        'wallet_up_payer_transactions',
        'wallet_up_analytics',
        'wallet_up_tax_receipts',
        'walletup_memberships',
        'walletup_membership_tiers',
        'walletup_subscriptions',
        'wallet_up_reviews',
    ];

    private static array $columns = [];
    private static array $repaired = [];

    public static function tables(): array
    {
        global $wpdb;
        return array_map(static fn(string $name): string => $wpdb->prefix . $name, self::TABLES);
    }

    public static function ensure(): void
    {
        if (get_option(self::VERSION_OPTION) === self::stamp()) {
            return;
        }
        foreach (self::tables() as $table) {
            if (self::exists($table)) {
                self::addColumn($table);
            }
        }
        update_option(self::VERSION_OPTION, self::stamp(), false);
    }

    private static function stamp(): string
    {
        return self::VERSION . ':' . (defined('WALLET_UP_PRO_VERSION') ? (string) WALLET_UP_PRO_VERSION : '');
    }

    private static function addColumn(string $table): bool
    {
        global $wpdb;
        if (self::hasColumn($table, Tenant::COLUMN)) {
            return true;
        }
        $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `" . Tenant::COLUMN . "` VARCHAR(" . Tenant::MAX_LENGTH . ") NOT NULL DEFAULT ''");
        $wpdb->query("ALTER TABLE `{$table}` ADD INDEX `" . Tenant::COLUMN . "` (`" . Tenant::COLUMN . "`)");
        unset(self::$columns[$table]);
        return self::hasColumn($table, Tenant::COLUMN);
    }

    public static function has(string $table): bool
    {
        if (self::hasColumn($table, Tenant::COLUMN)) {
            return true;
        }
        if (isset(self::$repaired[$table]) || !self::exists($table)) {
            return false;
        }
        self::$repaired[$table] = true;
        return self::addColumn($table);
    }

    public static function hasColumn(string $table, string $column): bool
    {
        global $wpdb;
        if (!isset(self::$columns[$table])) {
            $rows = $wpdb->get_col($wpdb->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table));
            self::$columns[$table] = array_fill_keys(array_map('strval', (array) $rows), true);
        }
        return isset(self::$columns[$table][$column]);
    }

    public static function exists(string $table): bool
    {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }
}
