<?php
namespace WalletUpPro\Core\Payments;

use WalletUpPro\Core\Security\DataEncryption;
use WalletUpPro\Core\Security\PlainText;
use WalletUpPro\Core\Error\ErrorHandler;
use WalletUpPro\Core\Utilities\SiteIdentity;

if (!defined('ABSPATH')) {
    exit;
}

class MerchantProvisioning
{
    private static ?self $instance = null;

    private const OPTION_MERCHANT_ID = 'walletup_pay_merchant_id';
    private const OPTION_API_KEY = 'walletup_pay_api_key';
    private const OPTION_API_SECRET = 'walletup_pay_api_secret';
    private const OPTION_PORTAL_URL = 'walletup_pay_portal_url';
    private const OPTION_PROVISIONED = 'walletup_pay_provisioned_at';

    private const BACKEND_URL = 'https://pay.walletup.app';
    private const MESSAGE_MAX = 500;
    private const CODE_MAX = 40;
    private const URL_MAX = 2048;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function publicUrl(): string
    {
        if (defined('WALLETUP_PAYMENT_BACKEND_URL') && WALLETUP_PAYMENT_BACKEND_URL) {
            $configured = rtrim((string) WALLETUP_PAYMENT_BACKEND_URL, '/');
            $host = (string) wp_parse_url($configured, PHP_URL_HOST);
            $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : '';
            $reachable = $host !== '' && $host !== 'localhost'
                && ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE));
            if ($reachable) {
                return $configured;
            }
        }
        return self::BACKEND_URL;
    }

    public static function brokerUrl(): string
    {
        if (defined('WALLETUP_PAYMENT_BACKEND_INTERNAL_URL') && WALLETUP_PAYMENT_BACKEND_INTERNAL_URL) {
            return rtrim((string) WALLETUP_PAYMENT_BACKEND_INTERNAL_URL, '/');
        }
        if (defined('WALLETUP_PAYMENT_BACKEND_URL') && WALLETUP_PAYMENT_BACKEND_URL) {
            return rtrim((string) WALLETUP_PAYMENT_BACKEND_URL, '/');
        }
        return self::BACKEND_URL;
    }

    public function init(): void
    {
        add_action('admin_init', [$this, 'maybeProvision'], 99);

        add_action('admin_bar_menu', [$this, 'addPortalLink'], 100);

        add_action('wp_ajax_walletup_provision_merchant', [$this, 'handleManualProvision']);

        $this->cacheFreemiusData();
    }

    public function maybeProvision(): void
    {
        if (get_option(self::OPTION_MERCHANT_ID)) {
            return;
        }

        if (!$this->hasMobileMoneyEnabled()) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = sanitize_text_field(wp_unslash($_GET['page'] ?? ''));
        if (strpos($page, 'wallet-up') === false && strpos($page, 'walletup') === false) {
            return;
        }

        $lastAttempt = get_transient('walletup_provision_attempt');
        if ($lastAttempt) {
            return;
        }

        set_transient('walletup_provision_attempt', time(), HOUR_IN_SECONDS);

        $this->provision();
    }

    private function hasMobileMoneyEnabled(): bool
    {
        $enabledMethods = get_option('wallet_up_enabled_methods', []);
        if (empty($enabledMethods) || !is_array($enabledMethods)) {
            return false;
        }

        $mobileMoneyIds = \WalletUpPro\Core\Config\PaymentMethodsConfig::getMobileMoneyMethods();

        foreach ($mobileMoneyIds as $id) {
            if (!empty($enabledMethods[$id])) {
                return true;
            }
        }

        return false;
    }

    public static function freemiusInstallId(): string
    {
        $fsAccounts = get_option('fs_accounts', []);
        $site = is_array($fsAccounts) ? ($fsAccounts['sites']['wallet-up'] ?? null) : null;
        return is_object($site) && !empty($site->id) ? (string) $site->id : '';
    }

    public function cacheFreemiusData(): void
    {
        $encryption = DataEncryption::getInstance();
        $fsAccounts = get_option('fs_accounts', []);

        $installId = self::freemiusInstallId();

        // First active license
        $licenses = $fsAccounts[12319] ?? ($fsAccounts['licenses']['wallet-up'] ?? []);
        $license = is_array($licenses) ? ($licenses[0] ?? null) : null;
        $licenseKey = $license ? $license->secret_key : '';

        if ($installId)
            update_option('walletup_fs_install_id', $encryption->encrypt($installId), false);
        if ($licenseKey)
            update_option('walletup_fs_license_key', $encryption->encrypt($licenseKey), false);
    }

    public function provision(): array
    {
        $adminEmail = get_option('admin_email', '');
        $siteName = SiteIdentity::name();
        $siteUrl = home_url();

        if (empty($adminEmail)) {
            return ['success' => false, 'error' => 'No admin email configured'];
        }

        $backendUrl = defined('WALLETUP_PAYMENT_BACKEND_URL')
            ? WALLETUP_PAYMENT_BACKEND_URL
            : self::BACKEND_URL;

        $provisionSecret = defined('WALLETUP_PROVISION_SECRET')
            ? WALLETUP_PROVISION_SECRET
            : '';

        $encryption = DataEncryption::getInstance();
        $fsInstallId = '';
        $fsLicenseKey = '';
        try {
            $encInstall = get_option('walletup_fs_install_id', '');
            $encLicense = get_option('walletup_fs_license_key', '');
            if ($encInstall)
                $fsInstallId = $encryption->decrypt($encInstall) ?: '';
            if ($encLicense)
                $fsLicenseKey = $encryption->decrypt($encLicense) ?: '';
        } catch (\Exception $e) {
        }

        $response = wp_remote_post($backendUrl . '/api/merchant/provision', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept-Language' => str_replace('_', '-', (string) get_user_locale()),
                'X-Provision-Secret' => $provisionSecret,
            ],
            'body' => wp_json_encode([
                'site_url' => $siteUrl,
                'site_name' => $siteName,
                'admin_email' => $adminEmail,
                'plugin_version' => defined('WALLET_UP_PRO_VERSION') ? WALLET_UP_PRO_VERSION : '0.0.0',
                'freemius_install_id' => $fsInstallId,
                'freemius_license_key' => $fsLicenseKey,
            ]),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            $this->log('Provisioning failed: ' . $response->get_error_message());
            return ['success' => false, 'error' => \WalletUpPro\Core\Payments\BackendErrors::forCustomer($response, 'provisioning')];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (($code !== 200 && $code !== 201) || empty($body['success'])) {
            $body = is_array($body) ? $body : [];
            $error = PlainText::text($body['error'] ?? '', self::MESSAGE_MAX);
            if ($error === '') {
                if ($code === 401 || $code === 403) {
                    $error = __('This site isn’t connected to a Wallet Up account yet. Finish activating the plugin, then try again.', 'walletup');
                } elseif ($code === 429) {
                    $error = __('Too many attempts. Please wait a minute and try again.', 'walletup');
                } elseif ($code >= 500) {
                    $error = __('The payments service is temporarily unavailable. Please try again shortly.', 'walletup');
                } else {
                    $error = __('The payments service couldn’t complete the request. Please try again.', 'walletup');
                }
            }
            $refusal = [
                'code'       => PlainText::line($body['code'] ?? '', self::CODE_MAX),
                'loginUrl'   => PlainText::url($body['login_url'] ?? '', self::URL_MAX),
                'supportUrl' => PlainText::url($body['support_url'] ?? '', self::URL_MAX),
            ];
            $this->log('Provisioning failed (HTTP ' . $code . ($refusal['code'] !== '' ? ', ' . $refusal['code'] : '') . '): ' . $error);
            return ['success' => false, 'error' => $error] + $refusal;
        }

        update_option(self::OPTION_MERCHANT_ID, $body['merchant_id'], false);
        update_option(self::OPTION_API_KEY, $encryption->encrypt($body['api_key']), false);
        update_option(self::OPTION_API_SECRET, $encryption->encrypt($body['api_secret']), false);
        update_option(self::OPTION_PORTAL_URL, $body['portal_url'] ?? (self::BACKEND_URL . '/merchant/login'), false);
        update_option(self::OPTION_PROVISIONED, current_time('mysql'), false);

        if (!empty($body['temporary_password'])) {
            set_transient('walletup_merchant_temp_password', $body['temporary_password'], DAY_IN_SECONDS);
            add_action('admin_notices', [$this, 'showTempPasswordNotice']);
        }

        $this->log('Merchant provisioned: ' . $body['merchant_id']);

        delete_transient('walletup_provision_attempt');

        return ['success' => true, 'merchant_id' => $body['merchant_id']];
    }

    public static function getApiKey(): string
    {
        $encrypted = get_option(self::OPTION_API_KEY, '');
        if (empty($encrypted)) {
            return '';
        }

        try {
            $decrypted = DataEncryption::getInstance()->decrypt($encrypted);
            return ($decrypted !== false && $decrypted !== '') ? $decrypted : '';
        } catch (\Exception $e) {
            return '';
        }
    }

    public static function getApiSecret(): string
    {
        $encrypted = get_option(self::OPTION_API_SECRET, '');
        if (empty($encrypted)) {
            return '';
        }

        try {
            $decrypted = DataEncryption::getInstance()->decrypt($encrypted);
            return ($decrypted !== false && $decrypted !== '') ? $decrypted : '';
        } catch (\Exception $e) {
            return '';
        }
    }

    public static function getPortalUrl(): string
    {
        return get_option(self::OPTION_PORTAL_URL, self::BACKEND_URL . '/merchant/login');
    }

    public static function isProvisioned(): bool
    {
        return !empty(get_option(self::OPTION_MERCHANT_ID));
    }

    public function showTempPasswordNotice(): void
    {
        $tempPass = get_transient('walletup_merchant_temp_password');
        if (!$tempPass) {
            return;
        }

        $portalUrl = esc_url(self::getPortalUrl());

        echo '<div class="notice notice-info is-dismissible">';
        echo '<p><strong>WalletUp Merchant Portal:</strong> Your account has been created on ';
        echo '<a href="' . $portalUrl . '" target="_blank">pay.walletup.app</a>.</p>';
        echo '<p>Sign in with your admin email and this temporary password: ';
        echo '<code style="background:#f0f0f0;padding:2px 8px;border-radius:3px;font-size:14px">' . esc_html($tempPass) . '</code></p>';
        echo '<p><em>Change your password immediately after signing in. This notice will not appear again.</em></p>';
        echo '</div>';

        delete_transient('walletup_merchant_temp_password');
    }

    public function addPortalLink(\WP_Admin_Bar $wp_admin_bar): void
    {
        if (!self::isProvisioned() || !current_user_can('manage_options')) {
            return;
        }

        $wp_admin_bar->add_node([
            'id' => 'walletup-portal',
            'title' => 'WalletUp Portal',
            'href' => self::getPortalUrl(),
            'meta' => ['target' => '_blank'],
        ]);
    }

    public function handleManualProvision(): void
    {
        check_ajax_referer('walletup_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        $result = $this->provision();
        if ($result['success']) {
            wp_send_json_success([
                'merchant_id' => $result['merchant_id'],
                'portal_url' => self::getPortalUrl(),
            ]);
        } else {
            wp_send_json_error(self::refusalPayload($result));
        }
    }

    public static function refusalPayload(array $result): array
    {
        $out = ['message' => (string) ($result['error'] ?? '')];
        foreach (['code', 'loginUrl', 'supportUrl'] as $k) {
            if (!empty($result[$k])) {
                $out[$k] = (string) $result[$k];
            }
        }
        return $out;
    }

    private function log(string $message): void
    {
        if (class_exists(ErrorHandler::class)) {
            ErrorHandler::getInstance()->log('[MerchantProvisioning] ' . $message);
        }
    }
}
