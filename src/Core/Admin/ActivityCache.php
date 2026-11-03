<?php
namespace WalletUpPro\Core\Admin;

if (!defined('ABSPATH')) {
    exit;
}

final class ActivityCache
{
    public const REV_OPTION = 'wallet_up_activity_rev';
    public const TTL = 5 * MINUTE_IN_SECONDS;
    public const EVENTS = [
        'woocommerce_order_status_changed',
        'woocommerce_new_order',
        'walletup_payment_status_changed',
        'walletup_payment_resolved',
        'walletup_backend_payment_completed',
    ];

    public static function register(): void
    {
        foreach (self::EVENTS as $event) {
            add_action($event, [self::class, 'bump']);
        }
    }

    public static function rev(): string
    {
        return (string) get_option(self::REV_OPTION, '0');
    }

    public static function key(string $prefix): string
    {
        return $prefix . '_' . self::rev();
    }

    public static function bump(): void
    {
        update_option(self::REV_OPTION, (string) microtime(true), true);
    }
}
