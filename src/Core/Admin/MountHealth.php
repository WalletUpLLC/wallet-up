<?php
declare(strict_types=1);

namespace WalletUpPro\Core\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 6.7.0: the React admin is compiled; when its script is blocked or breaks, the screen used to
 * sit empty for eight seconds and then offer a link. Now each screen carries a server-rendered
 * fallback that appears after 2.5 seconds, and the failure is recorded so the setup check and
 * the uninstall survey can say "the admin did not load" instead of guessing.
 */
final class MountHealth
{
    public const OPTION_FAILED_AT  = 'wallet_up_admin_mount_failed_at';
    public const OPTION_FAILED_ON  = 'wallet_up_admin_mount_failed_on';
    private const AJAX_FAILED       = 'wallet_up_mount_failed';
    private const AJAX_OK           = 'wallet_up_mount_ok';
    public const TIMEOUT_MS         = 2500;
    /** A slow first load is not a failure; one is recorded only after this long, or when the bundle errors. */
    public const FAIL_MS            = 10000;

    public static function init(): void
    {
        add_action('wp_ajax_' . self::AJAX_FAILED, [self::class, 'handleFailed']);
        add_action('wp_ajax_' . self::AJAX_OK, [self::class, 'handleOk']);
    }

    public static function failedAt(): int
    {
        return (int) get_option(self::OPTION_FAILED_AT, 0);
    }

    /**
     * Inline watcher: shows $fallbackId when $rootId is still empty after TIMEOUT_MS, hides it the
     * moment the app mounts, and reports either outcome. Output inside the page markup.
     */
    public static function watchScript(string $rootId, string $fallbackId, string $screen): string
    {
        $cfg = wp_json_encode([
            'root'     => $rootId,
            'fallback' => $fallbackId,
            'screen'   => $screen,
            'timeout'  => self::TIMEOUT_MS,
            'failMs'   => self::FAIL_MS,
            'ajaxUrl'  => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('wallet_up_admin_nonce'),
            'failed'   => self::failedAt() > 0,
            'okAction' => self::AJAX_OK,
            'failAction' => self::AJAX_FAILED,
        ]);
        return '<script>(function(c){var r=document.getElementById(c.root),f=document.getElementById(c.fallback);if(!r||!f)return;'
            . 'function beacon(a){try{var d=new FormData();d.append("action",a);d.append("nonce",c.nonce);d.append("screen",c.screen);fetch(c.ajaxUrl,{method:"POST",credentials:"same-origin",body:d});}catch(e){}}'
            . 'var done=false,failed=false;function mounted(){return !!r.firstElementChild;}'
            . 'function onMount(){if(done)return;done=true;f.style.display="none";if(c.failed||failed)beacon(c.okAction);}'
            . 'function fail(){if(done||failed)return;failed=true;beacon(c.failAction);}'
            . 'if(window.MutationObserver){new MutationObserver(function(){if(mounted())onMount();}).observe(r,{childList:true});}'
            . 'window.addEventListener("error",function(e){var s=e&&e.target&&e.target.src;if(s&&/walup-react\\/dist/.test(s))fail();},true);'
            . 'setTimeout(function(){if(mounted()){onMount();return;}f.style.display="block";},c.timeout);'
            . 'setTimeout(function(){if(!mounted())fail();},c.failMs);'
            . '})(' . $cfg . ');</script>';
    }

    public static function handleFailed(): void
    {
        if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false) || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'walletup')], 403);
        }
        $screen = isset($_POST['screen']) ? sanitize_key(wp_unslash($_POST['screen'])) : 'unknown';
        update_option(self::OPTION_FAILED_AT, time(), false);
        update_option(self::OPTION_FAILED_ON, $screen, false);
        wp_send_json_success();
    }

    public static function handleOk(): void
    {
        if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false) || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'walletup')], 403);
        }
        delete_option(self::OPTION_FAILED_AT);
        delete_option(self::OPTION_FAILED_ON);
        wp_send_json_success();
    }
}
