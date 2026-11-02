<?php
declare(strict_types=1);

namespace WalletUpPro\Core\Admin;

use WalletUpPro\Core\Retention\SetupCheck;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 6.7.0: the nine setup checks, shown wherever an owner looks when "nothing happened":
 * the Plugins row, an inline notice on Wallet Up screens when a check that blocks everything
 * fails, the dashboard and Payments cards (free-ui.js), and the server-rendered fallbacks.
 */
final class SelfCheckPanel
{
    private const AJAX_RUN = 'wallet_up_self_check';

    public static function init(): void
    {
        add_filter('plugin_row_meta', [self::class, 'rowMeta'], 10, 2);
        add_action('admin_notices', [self::class, 'criticalNotice']);
        add_action('wp_ajax_' . self::AJAX_RUN, [self::class, 'handleRun']);
    }

    /** @param string[] $meta */
    public static function rowMeta(array $meta, string $file): array
    {
        if ($file !== plugin_basename(WALLET_UP_FILE) || !current_user_can('manage_options')) {
            return $meta;
        }
        $r = SetupCheck::checks();
        $url = admin_url('admin.php?page=wallet-up#wup-self-check');
        if ($r['failed'] === 0) {
            $meta[] = sprintf(
                '<a href="%s">%s</a>',
                esc_url($url),
                sprintf(/* translators: 1: passed, 2: total */ esc_html__('Setup check: %1$d of %2$d passed', 'walletup'), $r['passed'], $r['total'])
            );
            return $meta;
        }
        $first = '';
        foreach ($r['items'] as $i) {
            if ($i['ok'] === false) {
                $first = $i['label'];
                break;
            }
        }
        $meta[] = sprintf(
            '<a href="%s" style="color:#b32d2e;font-weight:600;">%s</a>',
            esc_url($url),
            sprintf(
                /* translators: 1: number failing, 2: first failing check */
                esc_html(_n('Setup check: %1$d thing needs attention (%2$s)', 'Setup check: %1$d things need attention (%2$s)', $r['failed'], 'walletup')),
                $r['failed'],
                esc_html($first)
            )
        );
        return $meta;
    }

    /** One inline notice on Wallet Up screens when PHP, the QR engine or the admin itself is the problem. */
    public static function criticalNotice(): void
    {
        if (!current_user_can('manage_options') || !function_exists('get_current_screen')) {
            return;
        }
        $screen = get_current_screen();
        if (!$screen || strpos((string) $screen->id, 'wallet-up') === false) {
            return;
        }
        $r = SetupCheck::checks();
        if (!$r['critical']) {
            return;
        }
        $lines = [];
        foreach ($r['items'] as $i) {
            if ($i['ok'] === false && in_array($i['id'], ['php', 'qr', 'admin'], true)) {
                $lines[] = '<strong>' . esc_html($i['label']) . ':</strong> ' . esc_html($i['detail']);
            }
        }
        echo '<div class="notice notice-warning walletup-notice" id="wup-critical-check" style="margin:12px 0 0;"><p>' . implode('<br>', $lines) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per line above
    }

    /** Server-rendered table, used by the dashboard fallback and the simple setup form. */
    public static function table(?array $r = null): string
    {
        $r = $r ?? SetupCheck::checks();
        $rows = '';
        foreach ($r['items'] as $i) {
            $mark = $i['ok'] === true ? '&#10003;' : ($i['ok'] === false ? '&#10007;' : '&#8211;');
            $color = $i['ok'] === true ? '#1e8449' : ($i['ok'] === false ? '#b32d2e' : '#8c8f94');
            $fix = $i['ok'] === false && $i['url'] !== ''
                ? sprintf(' <a href="%s">%s</a>', esc_url($i['url']), esc_html__('Fix', 'walletup'))
                : '';
            $rows .= sprintf(
                '<tr><td style="color:%s;font-weight:700;width:1.5em;">%s</td><td><strong>%s</strong><br><span style="color:#50575e;">%s</span>%s</td></tr>',
                $color,
                $mark,
                esc_html($i['label']),
                esc_html($i['detail']),
                $fix
            );
        }
        return sprintf(
            '<table class="widefat striped" id="wup-self-check-table" style="max-width:720px;"><thead><tr><th colspan="2">%s</th></tr></thead><tbody>%s</tbody></table>',
            sprintf(/* translators: 1: passed, 2: total */ esc_html__('Setup check: %1$d of %2$d passed', 'walletup'), $r['passed'], $r['total']),
            $rows
        );
    }

    public static function handleRun(): void
    {
        if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false) || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'walletup')], 403);
        }
        wp_send_json_success(SetupCheck::checks());
    }
}
