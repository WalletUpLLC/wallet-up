<?php
namespace WalletUpPro\Core\Admin;

use WalletUpPro\Core\Config\PaymentMethodsConfig;
use WalletUpPro\Core\Geo\SiteLocale;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Free-plan additions to the admin app that live outside the React build (6.5.0):
 * the geography line on the Welcome step, the Zelle explainer and bank-QR upload,
 * and the "tell me when someone pays" opt-in offered after the first QR exists.
 */
final class FreeExperience
{
    private const HANDLE        = 'wallet-up-free-ui';
    public const OPTION_NOTIFY  = 'wallet_up_notify_email';
    private const AJAX_COUNTRY  = 'wallet_up_set_country';
    private const AJAX_OPTIN    = 'wallet_up_notify_optin';
    private const AJAX_SAVE_QR  = 'wallet_up_save_custom_qr';

    public static function init(): void
    {
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);
        add_action('wp_ajax_' . self::AJAX_COUNTRY, [__CLASS__, 'setCountry']);
        add_action('wp_ajax_' . self::AJAX_OPTIN, [__CLASS__, 'notifyOptIn']);
        add_action('wp_ajax_' . self::AJAX_SAVE_QR, [__CLASS__, 'saveCustomQr']);
        add_filter('wallet_up_admin_react_data', [__CLASS__, 'reactData']);
    }

    public static function reactData(array $data): array
    {
        $data['geoHint'] = SiteLocale::hint();
        $data['freeMethodIds'] = PaymentMethodsConfig::FREE_METHOD_IDS;
        return $data;
    }

    public static function enqueue(string $hook_suffix): void
    {
        // 6.7.0: every Wallet Up screen, so the setup check card and the Zelle upload work on
        // the Payments page when it is opened by its own URL, not only inside the app.
        if (strpos($hook_suffix, 'wallet-up') === false || !current_user_can('manage_options')) {
            return;
        }
        $rel  = 'assets/js/admin/free-ui.js';
        $path = WALLET_UP_PATH . $rel;
        wp_enqueue_script(self::HANDLE, WALLET_UP_URL . $rel, [], file_exists($path) ? (string) filemtime($path) : WALLET_UP_PRO_VERSION, true);
        $hint = SiteLocale::hint();
        $qr   = get_option('wallet_up_custom_qr_images', []);
        $names = [];
        foreach (PaymentMethodsConfig::FREE_METHOD_IDS as $id) {
            $names[$id] = PaymentMethodsConfig::getMethodName($id) ?: $id;
        }
        wp_localize_script(self::HANDLE, 'wupFreeUi', [
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('wallet_up_admin_nonce'),
            'page'         => $hook_suffix === 'wallet-up_page_wallet-up-onboarding' ? 'onboarding' : 'admin',
            'geo'          => $hint,
            'methodNames'  => $names,
            'zelleQrUrl'   => is_array($qr) ? (string) ($qr['zelle'] ?? '') : '',
            'notifyEmail'  => (string) get_option(self::OPTION_NOTIFY, ''),
            'counts'       => class_exists('\\WalletUpPro\\Core\\Retention\\FreeCounter') ? \WalletUpPro\Core\Retention\FreeCounter::dashboardData() : null,
            'isPremium'    => function_exists('WalletUpPro\\wallet_up_premium_active') && \WalletUpPro\wallet_up_premium_active(),
            'adminEmail'   => (string) get_option('admin_email', ''),
            'adminUrl'     => admin_url(),
            'countries'    => SiteLocale::pickList(),
            'checks'       => class_exists('\\WalletUpPro\\Core\\Retention\\SetupCheck') ? \WalletUpPro\Core\Retention\SetupCheck::checks() : null,
            'i18n'         => [
                'geoLine'      => __('Your site looks like it is in %s.', 'walletup'),
                'geoUnknown'   => __('Cash App, Venmo and Zelle pay out in the United States only; PayPal.me works almost everywhere.', 'walletup'),
                'geoChoose'    => __('Not in the US? Choose your country', 'walletup'),
                'geoSaved'     => __('Saved. Methods that work in %s are suggested first.', 'walletup'),
                'shareLabel'   => __('Also share this email with WalletUp (through Freemius, our licensing service) so we can tell you about updates. Off by default; nothing else is shared.', 'walletup'),
                'notifyHelp'   => __('Sent by your own site; turn them off any time under Settings → Notifications.', 'walletup'),
                'geoUsOnly'    => __('%s work in the United States only.', 'walletup'),
                'geoWorks'     => __('PayPal.me works where you are.', 'walletup'),
                'imInUs'       => __("I'm in the US", 'walletup'),
                'notUs'        => __('Change country', 'walletup'),
                'zelleHow'     => __('Zelle has no pay link. Visitors see your Zelle email or phone and the amount, with a copy button. If your bank app can show a Zelle QR code, upload it under Wallet Up → Payments and visitors can scan that instead.', 'walletup'),
                'zelleUpload'  => __('Upload your bank\'s Zelle QR (PNG or JPG)', 'walletup'),
                'zelleUploaded'=> __('Bank QR uploaded. Visitors can scan it from your page.', 'walletup'),
                'zelleRemove'  => __('Remove', 'walletup'),
                'zelleNone'    => __('No bank QR uploaded. Visitors see your Zelle email or phone with a copy button.', 'walletup'),
                'notifyTitle'  => __('Want to know when someone pays you?', 'walletup'),
                'notifyBody'   => __('Turn on notifications and we email you the first time your code is scanned or your link is tapped, and each time a visitor confirms a payment.', 'walletup'),
                'notifyButton' => __('Turn on notifications', 'walletup'),
                'notifyLater'  => __('Not now', 'walletup'),
                'notifyOn'     => __('Notifications are on. We will email %s.', 'walletup'),
                'error'        => __('Could not save. Please try again.', 'walletup'),
                'weekTitle'    => __('This week', 'walletup'),
                'views'        => __('views', 'walletup'),
                'taps'         => __('taps', 'walletup'),
                'confirmed'    => __('confirmed payments', 'walletup'),
                'recentTitle'  => __('Latest confirmations', 'walletup'),
                'noActivity'   => __('No visitor has opened your payment code yet. Three ways people get paid with Wallet Up:', 'walletup'),
                'tip1'         => __('Send the link in your next invoice or message.', 'walletup'),
                'tip2'         => __('Add the Payment Button block to a page people already visit.', 'walletup'),
                'tip3'         => __('Download the QR from the card above and print it for the counter.', 'walletup'),
                'viewOrder'    => __('View order', 'walletup'),
                'notifyOff'    => __('Email me when someone taps or confirms a payment', 'walletup'),
                'checkTitle'   => __('Setup check', 'walletup'),
                'checkPassed'  => __('%1$d of %2$d passed', 'walletup'),
                'checkFix'     => __('Fix', 'walletup'),
                'checkRerun'   => __('Run again', 'walletup'),
                'checkShow'    => __('Show details', 'walletup'),
                'checkHide'    => __('Hide details', 'walletup'),
            ],
        ]);
    }

    public static function setCountry(): void
    {
        if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false) || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'walletup')], 403);
        }
        $cc = isset($_POST['country']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['country']))) : '';
        if ($cc === '' || $cc === 'AUTO') {
            delete_option(SiteLocale::OPTION_OVERRIDE);
        } elseif (preg_match('/^[A-Z]{2}$/', $cc)) {
            update_option(SiteLocale::OPTION_OVERRIDE, $cc, false);
        } else {
            wp_send_json_error(['message' => __('Unknown country', 'walletup')], 400);
        }
        wp_send_json_success(['geo' => SiteLocale::hint()]);
    }

    /** Stores (or clears) the uploaded bank QR for a method that allows one. The URL must be a file in this site's uploads. */
    public static function saveCustomQr(): void
    {
        if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false) || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'walletup')], 403);
        }
        $method = isset($_POST['method']) ? sanitize_key(wp_unslash($_POST['method'])) : '';
        if (!PaymentMethodsConfig::allowsCustomQr($method)) {
            wp_send_json_error(['message' => __('This method does not take an uploaded code.', 'walletup')], 400);
        }
        $url = isset($_POST['url']) ? esc_url_raw(trim((string) wp_unslash($_POST['url']))) : '';
        $images = get_option('wallet_up_custom_qr_images', []);
        if (!is_array($images)) {
            $images = [];
        }
        if ($url === '') {
            unset($images[$method]);
        } else {
            $uploads = wp_get_upload_dir();
            if (strpos($url, (string) $uploads['baseurl']) !== 0 || !preg_match('/^[^?#]+\.(png|jpe?g)$/i', $url) || strpos($url, '..') !== false || attachment_url_to_postid($url) <= 0) {
                wp_send_json_error(['message' => __('The image must be one uploaded to this site.', 'walletup')], 400);
            }
            $images[$method] = $url;
        }
        update_option('wallet_up_custom_qr_images', $images);
        wp_send_json_success(['url' => $images[$method] ?? '']);
    }

    public static function notifyOptIn(): void
    {
        if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false) || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'walletup')], 403);
        }
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        if (!is_email($email)) {
            wp_send_json_error(['message' => __('Please enter a valid email address.', 'walletup')], 400);
        }
        update_option(self::OPTION_NOTIFY, $email, false);

        // 6.7.0: the Freemius registration is a separate, default-off choice ("share" ticked), so the
        // notifications button never sends anything off-site on its own.
        $share = isset($_POST['share']) && (string) wp_unslash($_POST['share']) === '1';
        $freemius = 'skipped';
        if ($share && function_exists('WalletUpPro\\wallet_up_fs')) {
            try {
                $fs = \WalletUpPro\wallet_up_fs();
                if (is_object($fs) && method_exists($fs, 'is_registered') && !$fs->is_registered() && method_exists($fs, 'opt_in')) {
                    $user = wp_get_current_user();
                    $res = $fs->opt_in($email, (string) $user->first_name, (string) $user->last_name);
                    $freemius = (is_object($res) && isset($res->error)) || is_wp_error($res) ? 'failed' : 'ok';
                } elseif (is_object($fs) && method_exists($fs, 'is_registered') && $fs->is_registered()) {
                    $freemius = 'already';
                }
            } catch (\Throwable $e) {
                $freemius = 'failed';
            }
        }
        wp_send_json_success(['email' => $email, 'freemius' => $freemius]);
    }
}
