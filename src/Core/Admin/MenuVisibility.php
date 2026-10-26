<?php
namespace WalletUpPro\Core\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class MenuVisibility
{
    const OPTION = 'wallet_up_menu_visibility';

    public static function init(): void
    {
        add_action('wp_ajax_wallet_up_get_menu_visibility', [__CLASS__, 'handleGet']);
        add_action('wp_ajax_wallet_up_save_menu_visibility', [__CLASS__, 'handleSave']);
    }

    public static function handleGet(): void
    {
        check_ajax_referer('wallet_up_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'walletup'), 403);
        }
        wp_send_json_success(['visibility' => self::all()]);
    }

    public static function handleSave(): void
    {
        check_ajax_referer('wallet_up_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'walletup'), 403);
        }
        $raw = wp_unslash($_POST['visibility'] ?? '');
        $map = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : []);
        if (!is_array($map)) {
            wp_send_json_error(__('Invalid data', 'walletup'));
        }
        wp_send_json_success(['visibility' => self::save($map), 'message' => __('Menu updated', 'walletup')]);
    }

    public static function alwaysOn(): array
    {
        $ids = [];
        foreach (self::modules() as $m) {
            if (!empty($m['always_on'])) {
                $ids[] = $m['id'];
            }
        }
        return $ids ?: ['dashboard', 'settings'];
    }

    private static function modules(): array
    {
        if (class_exists('\\WalletUpPro\\Core\\Admin\\AdminV2MenuRegistrar')) {
            return AdminV2MenuRegistrar::modules();
        }
        return [
            ['id' => 'dashboard', 'always_on' => true],
            ['id' => 'settings', 'always_on' => true],
        ];
    }

    public static function ids(): array
    {
        return array_map(static fn($m) => $m['id'], self::modules());
    }

    public static function toggleable(): array
    {
        $always = self::alwaysOn();
        return array_values(array_filter(self::ids(), static fn($id) => !in_array($id, $always, true)));
    }

    public static function all(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $always = self::alwaysOn();
        $out = [];
        foreach (self::ids() as $id) {
            $out[$id] = in_array($id, $always, true) ? true : (bool) ($stored[$id] ?? true);
        }
        return $out;
    }

    public static function isVisible(string $id): bool
    {
        if (in_array($id, self::alwaysOn(), true)) {
            return true;
        }
        return (bool) (self::all()[$id] ?? true);
    }

    public static function save(array $input): array
    {
        $vis = [];
        foreach (self::toggleable() as $id) {
            if (array_key_exists($id, $input)) {
                $vis[$id] = filter_var($input[$id], FILTER_VALIDATE_BOOLEAN);
            }
        }
        $current = get_option(self::OPTION, []);
        if (!is_array($current)) {
            $current = [];
        }
        update_option(self::OPTION, array_merge($current, $vis));
        return self::all();
    }
}
