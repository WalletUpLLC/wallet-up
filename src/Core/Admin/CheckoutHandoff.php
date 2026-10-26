<?php

namespace WalletUpPro\Core\Admin;

defined('ABSPATH') || exit;

final class CheckoutHandoff
{
    public const PARAM_CYCLE = 'wu_checkout';
    public const PARAM_LICENSES = 'wu_licenses';
    public const PARAM_TRIAL = 'wu_trial';
    public const PARAM_NOTICE = 'wu_checkout_notice';
    public const ACTION_NONE = 'none';
    public const ACTION_OWNED = 'owned';
    public const ACTION_WAIT = 'wait';
    public const ACTION_NETWORK = 'network';
    public const ACTION_CHECKOUT = 'checkout';
    private const META_INTENT = 'walletup_checkout_intent';
    private const INTENT_SECONDS = HOUR_IN_SECONDS;
    private const CAPABILITY = 'manage_options';
    private const NOTICE_CLASS = 'notice notice-info walletup-notice';

    private static string $notice = '';

    public static function init(): void
    {
        add_action('admin_init', [__CLASS__, 'handle'], 1);
        add_action('admin_notices', [__CLASS__, 'renderNotice']);
        $fs = function_exists('WalletUpPro\\wallet_up_fs') ? \WalletUpPro\wallet_up_fs() : null;
        if (is_object($fs) && method_exists($fs, 'add_action')) {
            $fs->add_action('connect/before_message', [__CLASS__, 'renderConnectMessage']);
        }
    }

    public static function cycles(): array
    {
        return array_values(array_filter([
            defined('WP_FS__PERIOD_MONTHLY') ? WP_FS__PERIOD_MONTHLY : null,
            defined('WP_FS__PERIOD_ANNUALLY') ? WP_FS__PERIOD_ANNUALLY : null,
            defined('WP_FS__PERIOD_LIFETIME') ? WP_FS__PERIOD_LIFETIME : null,
        ]));
    }

    public static function parseIntent(array $query, array $cycles): ?array
    {
        $raw = $query[self::PARAM_CYCLE] ?? '';
        $cycle = is_string($raw) ? strtolower(trim($raw)) : '';
        if ($cycle === '' || !in_array($cycle, $cycles, true)) {
            return null;
        }
        $intent = ['cycle' => $cycle, 'trial' => ($query[self::PARAM_TRIAL] ?? '') === 'true'];
        $licenses = $query[self::PARAM_LICENSES] ?? '';
        if (is_string($licenses) && ctype_digit($licenses) && (int) $licenses > 0) {
            $intent['licenses'] = (int) $licenses;
        }
        return $intent;
    }

    public static function decide(array $state): string
    {
        if (empty($state['can_manage'])) {
            return self::ACTION_NONE;
        }
        if (!empty($state['network'])) {
            return self::ACTION_NETWORK;
        }
        if (!empty($state['paying']) && empty($state['trial'])) {
            return self::ACTION_OWNED;
        }
        if (!empty($state['activation_mode']) || empty($state['pricing_reachable'])) {
            return self::ACTION_WAIT;
        }
        return self::ACTION_CHECKOUT;
    }

    public static function storedIntent(int $userId, int $now): ?array
    {
        $stored = get_user_meta($userId, self::META_INTENT, true);
        if (!is_array($stored) || empty($stored['cycle']) || empty($stored['at'])) {
            return null;
        }
        if ($now - (int) $stored['at'] > self::INTENT_SECONDS) {
            delete_user_meta($userId, self::META_INTENT);
            return null;
        }
        unset($stored['at']);
        return $stored;
    }

    public static function handle(): void
    {
        if (wp_doing_ajax() || wp_doing_cron() || !function_exists('WalletUpPro\\wallet_up_fs') || !function_exists('WalletUpPro\\wallet_up_page_slug')) {
            return;
        }
        $fs = \WalletUpPro\wallet_up_fs();
        if (!is_object($fs)) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the link comes from walletup.app, so it cannot carry a nonce; it only opens a checkout page and changes nothing
        $query = wp_unslash($_GET);
        $request = [
            'method' => isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : '',
            'page' => isset($query['page']) && is_string($query['page']) ? $query['page'] : '',
            'query' => is_array($query) ? $query : [],
        ];
        $url = self::route($request, \WalletUpPro\wallet_up_page_slug(), get_current_user_id(), $fs, static function () use ($fs): array {
            return [
                'can_manage' => current_user_can(self::CAPABILITY),
                'network' => is_multisite() && method_exists($fs, 'is_network_active') && $fs->is_network_active(),
                'paying' => method_exists($fs, 'is_paying') && $fs->is_paying(),
                'trial' => method_exists($fs, 'is_trial') && $fs->is_trial(),
                'activation_mode' => \WalletUpPro\wallet_up_in_activation_mode($fs),
                'pricing_reachable' => \WalletUpPro\wallet_up_pricing_reachable($fs),
            ];
        });
        if ($url !== null) {
            wp_safe_redirect($url);
            exit;
        }
    }

    public static function route(array $request, string $main, int $userId, object $fs, callable $state): ?string
    {
        if ($request['method'] !== 'GET' || $main === '' || $request['page'] !== $main || $userId < 1) {
            return null;
        }
        $fromLink = self::parseIntent($request['query'], self::cycles());
        $intent = $fromLink ?: self::storedIntent($userId, time());
        if (!$intent) {
            return null;
        }
        $action = self::decide($state());
        if ($action === self::ACTION_NONE) {
            return null;
        }
        if ($action === self::ACTION_WAIT) {
            if ($fromLink) {
                update_user_meta($userId, self::META_INTENT, $intent + ['at' => time()]);
                self::$notice = method_exists($fs, 'is_premium') && $fs->is_premium() ? 'licence' : 'connect';
            }
            return null;
        }
        delete_user_meta($userId, self::META_INTENT);
        if ($action === self::ACTION_NETWORK) {
            self::$notice = $fromLink ? self::ACTION_NETWORK : '';
            return null;
        }
        if ($action === self::ACTION_OWNED) {
            if (!$fromLink) {
                return null;
            }
            $account = method_exists($fs, 'get_account_url') ? (string) $fs->get_account_url(false, [self::PARAM_NOTICE => self::ACTION_OWNED]) : '';
            return $account !== '' ? $account : admin_url('admin.php?page=' . $main . '&' . self::PARAM_NOTICE . '=' . self::ACTION_OWNED);
        }
        $extra = isset($intent['licenses']) ? ['licenses' => $intent['licenses']] : [];
        return (string) $fs->checkout_url($intent['cycle'], !empty($intent['trial']), $extra);
    }

    public static function noticeText(string $kind): string
    {
        switch ($kind) {
            case self::ACTION_OWNED:
                /* translators: %s: Pro product name */
                return sprintf(__('%s is already licensed on this site, so no second purchase was started. You can change your plan or add sites here.', 'walletup'), WALLET_UP_PRO_PRODUCT_NAME);
            case 'connect':
                return __('Choose an option below to continue. Your checkout opens right after.', 'walletup');
            case 'licence':
                return __('Enter your licence key below to finish. To buy one, go back to walletup.app and choose "Pay here".', 'walletup');
            case self::ACTION_NETWORK:
                return __('This site is part of a network, so Pro is bought for the whole network. Go back to walletup.app and choose "Pay here".', 'walletup');
        }
        return '';
    }

    public static function renderConnectMessage(): void
    {
        $text = in_array(self::$notice, ['connect', 'licence'], true) ? self::noticeText(self::$notice) : '';
        if ($text !== '') {
            echo '<p class="walletup-notice"><strong>' . esc_html($text) . '</strong></p>';
        }
    }

    public static function renderNotice(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flag
        $kind = self::$notice !== '' ? self::$notice : (isset($_GET[self::PARAM_NOTICE]) && $_GET[self::PARAM_NOTICE] === self::ACTION_OWNED ? self::ACTION_OWNED : '');
        $text = self::noticeText($kind);
        if ($text === '') {
            return;
        }
        echo '<div class="' . esc_attr(self::NOTICE_CLASS) . '"><p>' . esc_html($text) . '</p></div>';
    }
}
