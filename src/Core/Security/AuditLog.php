<?php

namespace WalletUpPro\Core\Security;

if (!defined('ABSPATH')) {
    exit;
}

class AuditLog
{
    private const TABLE = 'wallet_up_audit_log';
    private const SCHEMA_OPTION = 'wallet_up_audit_log_schema';
    private const SCHEMA_VERSION = '1';

    public static function init(): void
    {
        add_action('wp_ajax_wallet_up_get_audit_log', [self::class, 'handleGetAuditLog']);
    }

    public static function handleGetAuditLog(): void
    {
        if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false) && !check_ajax_referer('wallet_up_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token'], 403);
            return;
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
            return;
        }
        $limit = isset($_POST['limit']) ? absint(wp_unslash($_POST['limit'])) : 100;
        $action = isset($_POST['event']) ? sanitize_key(wp_unslash($_POST['event'])) : '';
        wp_send_json_success(['entries' => self::getEntries($limit, $action)]);
    }

    public static function record(string $action, string $detail = ''): void
    {
        $userId = function_exists('get_current_user_id') ? get_current_user_id() : 0;
        $login = 'guest';
        if (function_exists('wp_get_current_user')) {
            $user = wp_get_current_user();
            if ($user && $user->exists()) {
                $login = $user->user_login;
            }
        }
        $ip = self::ip();

        error_log(sprintf('[WalletUp AUDIT] user=%d(%s) ip=%s action=%s%s', $userId, $login, $ip, $action, $detail !== '' ? ' | ' . $detail : ''));

        try {
            self::ensureTable();
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Append-only audit write.
            $wpdb->insert(
                $wpdb->prefix . self::TABLE,
                [
                    'user_id'    => $userId,
                    'user_login' => $login,
                    'ip_address' => $ip,
                    'action'     => $action,
                    'detail'     => $detail,
                    'created_at' => current_time('mysql'),
                ],
                ['%d', '%s', '%s', '%s', '%s', '%s']
            );
        } catch (\Throwable $e) {
        }

        do_action('wallet_up_audit', $action, $detail, $userId);
    }

    /**
     * Recent entries for the admin Audit Log view.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function getEntries(int $limit = 100, string $action = ''): array
    {
        try {
            self::ensureTable();
            global $wpdb;
            $table = $wpdb->prefix . self::TABLE;
            $limit = max(1, min(500, $limit));

            if ($action !== '') {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from wpdb prefix; values prepared.
                $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE action = %s ORDER BY id DESC LIMIT %d", $action, $limit), ARRAY_A);
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from wpdb prefix; value prepared.
                $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit), ARRAY_A);
            }
            return is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function ensureTable(): void
    {
        if (get_option(self::SCHEMA_OPTION) === self::SCHEMA_VERSION) {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            user_login VARCHAR(60) NOT NULL DEFAULT '',
            ip_address VARCHAR(45) NOT NULL DEFAULT '',
            action VARCHAR(64) NOT NULL DEFAULT '',
            detail TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY action (action),
            KEY created_at (created_at)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION);
    }

    private static function ip(): string
    {
        if (empty($_SERVER['REMOTE_ADDR'])) {
            return 'unknown';
        }
        $ip = filter_var(sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])), FILTER_VALIDATE_IP);
        return $ip !== false ? $ip : 'unknown';
    }
}
