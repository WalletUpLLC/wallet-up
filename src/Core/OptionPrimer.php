<?php
namespace WalletUpPro\Core;

if (!defined('ABSPATH')) {
    exit;
}

final class OptionPrimer
{
    public const HOT = [
        'fs_cache_12319',
        'fs_clone_management',
        'fs_storage_logger',
        'uninstall_plugins',
        'wallet_up_activity_rev',
        'wallet_up_admin_theme_enabled',
        'wallet_up_applepay_domain_registered',
        'wallet_up_cashapp_api_settings',
        'wallet_up_dismissed_patterns_global',
        'wallet_up_donation_schema_version',
        'wallet_up_enabled_backfilled',
        'wallet_up_encryption_key',
        'wallet_up_force_plugin_language',
        'wallet_up_industry',
        'wallet_up_log_config',
        'wallet_up_membership_access',
        'wallet_up_membership_schema_version',
        'wallet_up_mobile_money_settings',
        'wallet_up_nonprofit_status',
        'wallet_up_onboarding_completed',
        'wallet_up_onboarding_goal',
        'wallet_up_onboarding_migrated_v2',
        'wallet_up_onboarding_pending',
        'wallet_up_paused_until',
        'wallet_up_paypal_commerce_settings',
        'wallet_up_paypal_settings',
        'wallet_up_scan_rewrite_v1',
        'wallet_up_settings_v2',
        'wallet_up_support_access',
        'wallet_up_surface_methods',
        'wallet_up_tenancy_schema',
        'wallet_up_translation_batch_size',
        'wallet_up_translation_cache_enabled',
        'wallet_up_translation_provider',
        'wallet_up_venmo_api_settings',
        'wallet_up_was_free',
        'wallet_up_wpforms_settings',
        'wallet_up_wpforms_template_enabled',
        'wallet_up_wup_migration_complete',
        'wallet_up_zelle_default_bank',
        'walletup_fs_install_id',
        'walletup_fs_license_key',
        'walletup_fs_cache_signature',
        'walletup_pay_merchant_id',
        'walletup_pay_portal_url',
        'walletup_rules_version',
        'walletup_url_security_settings',
        'walletup_urlshortener_db_version',
        'woocommerce_wallet_up_cashapp_api_settings',
        'woocommerce_wallet_up_cashapp_settings',
        'woocommerce_wallet_up_paypal_commerce_settings',
        'woocommerce_wallet_up_paypal_settings',
        'woocommerce_wallet_up_venmo_api_settings',
        'woocommerce_wallet_up_venmo_settings',
        'woocommerce_wallet_up_zelle_settings',
        '_transient_wallet_up_business_card_data',
        '_transient_timeout_wallet_up_business_card_data',
        '_transient_wallet_up_preview_data',
        '_transient_timeout_wallet_up_preview_data',
    ];

    public static function prime(): void
    {
        if (function_exists('wp_prime_option_caches')) {
            wp_prime_option_caches(self::HOT);
        }
    }
}
