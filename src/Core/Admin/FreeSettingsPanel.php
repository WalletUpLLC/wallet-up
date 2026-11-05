<?php
namespace WalletUpPro\Core\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Real content for the Settings screen.
 *
 * The admin app renders Settings as a single "About" card. This panel adds two
 * cards next to it without touching the React build: a Branding card with the
 * "Powered by WalletUp" toggle (switchable on Pro, visible but locked on the
 * free plan) and a Setup card that reruns the setup assistant.
 */
final class FreeSettingsPanel
{
    private const HANDLE      = 'wallet-up-free-settings';
    private const OPTION      = 'wallet_up_hide_branding';
    private const AJAX_BRAND  = 'wallet_up_save_branding';
    private const AJAX_RERUN  = 'wallet_up_rerun_setup';
    private const AJAX_LOCALE = 'wallet_up_save_locale';
    private const AJAX_NOTIFY = 'wallet_up_save_notify';

    public static function init(): void
    {
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);
        add_action('wp_ajax_' . self::AJAX_BRAND, [__CLASS__, 'saveBranding']);
        add_action('wp_ajax_' . self::AJAX_RERUN, [__CLASS__, 'rerunSetup']);
        add_action('wp_ajax_' . self::AJAX_LOCALE, [__CLASS__, 'saveLocale']);
        add_action('wp_ajax_' . self::AJAX_NOTIFY, [__CLASS__, 'saveNotify']);
    }

    private static function isPremium(): bool
    {
        return function_exists('WalletUpPro\\wallet_up_premium_active') && \WalletUpPro\wallet_up_premium_active();
    }

    public static function enqueue(string $hook_suffix): void
    {
        if (strpos($hook_suffix, 'wallet-up') === false || !current_user_can('manage_options')) {
            return;
        }
        $rel  = 'assets/js/admin/free-settings.js';
        $path = WALLET_UP_PATH . $rel;
        wp_enqueue_script(
            self::HANDLE,
            WALLET_UP_URL . $rel,
            [],
            file_exists($path) ? (string) filemtime($path) : WALLET_UP_PRO_VERSION,
            true
        );
        wp_localize_script(self::HANDLE, 'wupFreeSettings', [
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('wallet_up_admin_nonce'),
            'isPremium'    => self::isPremium(),
            'hideBranding' => get_option(self::OPTION, 'no') === 'yes',
            'geo'          => class_exists('\\WalletUpPro\\Core\\Geo\\SiteLocale') ? \WalletUpPro\Core\Geo\SiteLocale::hint() : [],
            'countryOverride' => (string) get_option('wallet_up_site_country', ''),
            'currency'     => \WalletUpPro\Core\Config\PaymentMethodsConfig::siteCurrency(),
            'notifyEmail'  => (string) get_option('wallet_up_notify_email', ''),
            'adminEmail'   => (string) get_option('admin_email', ''),
            'currencies'   => ['USD','EUR','GBP','CAD','AUD','INR','MXN','BRL','JPY','NGN','ZAR','PKR','BDT','IDR','PHP','KES','NZD','CHF','SEK','NOK','DKK','PLN','TRY','VND','THB','SGD','HKD'],
            'i18n'         => [
                'where'           => __('Where you are', 'walletup'),
                'whereHelp'       => __('Cash App, Venmo and Zelle pay out in the United States only. PayPal.me works almost everywhere. Setting your country here changes which methods the setup assistant suggests; it never blocks anything.', 'walletup'),
                'country'         => __('Country', 'walletup'),
                'countryAuto'     => __('Detect from site settings', 'walletup'),
                'currencyLabel'   => __('Currency shown with amounts (PayPal.me and card payments)', 'walletup'),
                'detected'        => __('Detected: %s', 'walletup'),
                'branding'        => __('Branding', 'walletup'),
                'brandingLabel'   => __('Show "Powered by WalletUp" under payment cards', 'walletup'),
                'brandingHelp'    => __('The small credit line under every QR code, button and pay link on your site.', 'walletup'),
                'saved'           => __('Saved.', 'walletup'),
                'setup'           => __('Setup assistant', 'walletup'),
                'setupHelp'       => __('Walk through the setup again to change your business type, payment methods or default amount. Nothing is deleted.', 'walletup'),
                'rerun'           => __('Run setup again', 'walletup'),
                'rerunConfirm'    => __('Open the setup assistant again? Your saved payment details stay as they are.', 'walletup'),
                'error'           => __('Could not save. Please try again.', 'walletup'),
                'pro'             => __('PRO', 'walletup'),
                'notify'          => __('Notifications', 'walletup'),
                'notifyHelp'      => __('Your own site emails you the first time someone taps your link or copies your details, each time a visitor confirms a payment (at most ten a day), and one summary after your first week. Nothing is sent anywhere else.', 'walletup'),
                'notifyEmailLabel'=> __('Send to', 'walletup'),
                'notifyOn'        => __('Turn on', 'walletup'),
                'notifyOff'       => __('Turn off', 'walletup'),
                'notifyStateOn'   => __('On. We email %s.', 'walletup'),
                'notifyStateOff'  => __('Off.', 'walletup'),
            ],
        ]);
    }

    public static function saveBranding(): void
    {
        if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false) || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'walletup')], 403);
        }
        if (!self::isPremium()) {
            wp_send_json_error(['message' => __('This setting is not available on this plan.', 'walletup'), 'code' => 'plan'], 403);
        }
        $hide = isset($_POST['hide']) && sanitize_key(wp_unslash($_POST['hide'])) === '1';
        update_option(self::OPTION, $hide ? 'yes' : 'no', false);
        wp_send_json_success(['hideBranding' => $hide]);
    }

    public static function saveLocale(): void
    {
        if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false) || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'walletup')], 403);
        }
        $cc = isset($_POST['country']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['country']))) : '';
        if ($cc === '' || $cc === 'AUTO') {
            delete_option('wallet_up_site_country');
        } elseif (preg_match('/^[A-Z]{2}$/', $cc)) {
            update_option('wallet_up_site_country', $cc, false);
        }
        $cur = isset($_POST['currency']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['currency']))) : '';
        if (preg_match('/^[A-Z]{3}$/', $cur)) {
            $settings = get_option('wallet_up_settings', []);
            $settings = is_array($settings) ? $settings : [];
            $settings['currency'] = $cur;
            $settings['currency_set_by_owner'] = 1;
            update_option('wallet_up_settings', $settings);
        }
        wp_send_json_success([
            'geo'      => class_exists('\\WalletUpPro\\Core\\Geo\\SiteLocale') ? \WalletUpPro\Core\Geo\SiteLocale::hint() : [],
            'currency' => \WalletUpPro\Core\Config\PaymentMethodsConfig::siteCurrency(),
        ]);
    }

    /** Notifications on (with an address) or off. The address is only ever used by this site's wp_mail. */
    public static function saveNotify(): void
    {
        if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false) || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'walletup')], 403);
        }
        $on    = isset($_POST['on']) && (string) wp_unslash($_POST['on']) === '1';
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        if (!$on) {
            delete_option('wallet_up_notify_email');
            wp_send_json_success(['email' => '']);
        }
        if (!is_email($email)) {
            wp_send_json_error(['message' => __('Please enter a valid email address.', 'walletup')], 400);
        }
        update_option('wallet_up_notify_email', $email, false);
        wp_send_json_success(['email' => $email]);
    }

    public static function rerunSetup(): void
    {
        if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false) || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'walletup')], 403);
        }
        delete_option(OnboardingHandler::OPTION_COMPLETED);
        delete_option(OnboardingHandler::OPTION_STEP);
        OnboardingHandler::clearPending();
        wp_send_json_success(['redirect' => admin_url('admin.php?page=wallet-up-onboarding')]);
    }
}
