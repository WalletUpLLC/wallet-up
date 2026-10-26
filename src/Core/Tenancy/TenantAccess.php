<?php
declare(strict_types=1);

namespace WalletUpPro\Core\Tenancy;

if (!defined('ABSPATH')) {
    exit;
}

final class TenantAccess
{
    public static function ensureRole(): void
    {
        if (get_role(Tenant::ROLE) === null) {
            add_role(Tenant::ROLE, __('Wallet Up tenant', 'walletup'), [
                'read' => true,
                'upload_files' => true,
                Tenant::CAP => true,
            ]);
        }
    }

    public static function userTenant(?int $userId = null): string
    {
        $userId = $userId ?? get_current_user_id();
        if ($userId < 1 || user_can($userId, 'manage_options')) {
            return '';
        }
        return Tenant::normalize(get_user_meta($userId, Tenant::META_USER, true));
    }

    public static function assign(int $userId, string $tenantId): void
    {
        $tenantId = Tenant::normalize($tenantId);
        if ($userId < 1) {
            return;
        }
        if ($tenantId === '') {
            delete_user_meta($userId, Tenant::META_USER);
            return;
        }
        update_user_meta($userId, Tenant::META_USER, $tenantId);
        $user = get_user_by('id', $userId);
        if ($user instanceof \WP_User && !in_array(Tenant::ROLE, (array) $user->roles, true) && !user_can($user, 'manage_options')) {
            $user->add_role(Tenant::ROLE);
        }
    }

    public static function usersOf(string $tenantId): array
    {
        $tenantId = Tenant::normalize($tenantId);
        if ($tenantId === '') {
            return [];
        }
        return array_map('intval', (array) get_users([
            'meta_key' => Tenant::META_USER,
            'meta_value' => $tenantId,
            'fields' => 'ID',
        ]));
    }
}
