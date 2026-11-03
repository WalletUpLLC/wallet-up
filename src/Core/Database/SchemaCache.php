<?php
namespace WalletUpPro\Core\Database;

if (!defined('ABSPATH')) {
    exit;
}

final class SchemaCache
{
    public const OPTION = 'wallet_up_known_tables';

    private static $known = null;

    public static function tableExists(string $table): bool
    {
        if ($table === '') {
            return false;
        }
        $known = self::load();
        if (isset($known[$table])) {
            return true;
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Result is cached here.
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        if (!$exists) {
            return false;
        }
        self::$known[$table] = true;
        update_option(self::OPTION, ['version' => self::version(), 'tables' => self::$known], true);
        return true;
    }

    public static function forget(?string $table = null): void
    {
        if ($table === null) {
            self::$known = [];
            delete_option(self::OPTION);
            return;
        }
        $known = self::load();
        if (isset($known[$table])) {
            unset(self::$known[$table]);
            update_option(self::OPTION, ['version' => self::version(), 'tables' => self::$known], true);
        }
    }

    private static function load(): array
    {
        if (self::$known !== null) {
            return self::$known;
        }
        $stored = get_option(self::OPTION, []);
        $valid = is_array($stored) && ($stored['version'] ?? '') === self::version() && is_array($stored['tables'] ?? null);
        self::$known = $valid ? $stored['tables'] : [];
        return self::$known;
    }

    private static function version(): string
    {
        return defined('WALLET_UP_VERSION') ? (string) WALLET_UP_VERSION : '';
    }
}
