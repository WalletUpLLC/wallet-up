<?php
namespace WalletUpPro\Core\Security;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
class AccessControl
{
    private const CAPABILITIES = [
        'process_payments' => 'edit_posts',
        'view_analytics' => 'manage_options',
        'manage_settings' => 'manage_options',
        'export_data' => 'manage_options'
    ];

    public function check(string $capability, ?int $userId = null): bool
    {
        if (!isset(self::CAPABILITIES[$capability])) {
            return false;
        }

        $wpCap = self::CAPABILITIES[$capability];
        $user = $userId ? get_user_by('id', $userId) : wp_get_current_user();

        if (!$user || !$user->exists()) {
            return false;
        }

        return $user->has_cap($wpCap);
    }

    public function getAllCapabilities(): array
    {
        return self::CAPABILITIES;
    }
}