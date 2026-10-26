<?php

namespace WalletUpPro\Core\Admin;

defined('ABSPATH') || exit;

use WalletUpPro\Core\Payments\MerchantProvisioning;
use WalletUpPro\Core\Security\DataEncryption;

final class NonprofitOffer
{
    private const OPTION_APPLICATION = 'walletup_np_application';
    private const META_DISMISSED = 'walletup_np_offer_dismissed_at';
    private const TRANSIENT_TIPJAR = 'walletup_np_tipjar_published';
    private const DISMISS_SECONDS = 90 * DAY_IN_SECONDS;
    private const COMMERCIAL_INDUSTRIES = ['restaurant', 'retail', 'services', 'events', 'creator'];
    private const NONCE_ACTION = 'wallet_up_admin_nonce';
    private const TIMEOUT = 15;
    private const FLAG_FIELD = 'terms_published';
    private const READY_FIELD = 'licence_ready';
    private const STATUS_FIELDS = ['status' => 'status', 'next_step' => 'nextStep', self::READY_FIELD => 'licenceReady', self::FLAG_FIELD => 'termsPublished'];

    public static function init(): void
    {
        add_action('wp_ajax_wallet_up_np_offer_dismiss', [__CLASS__, 'ajaxDismiss']);
        add_action('wp_ajax_wallet_up_np_offer_apply', [__CLASS__, 'ajaxApply']);
        add_action('wp_ajax_wallet_up_np_offer_status', [__CLASS__, 'ajaxStatus']);
        add_action('wp_ajax_wallet_up_np_offer_accept', [__CLASS__, 'ajaxAccept']);
        add_action('wp_ajax_wallet_up_np_offer_activate', [__CLASS__, 'ajaxActivate']);
    }

    public static function signals(): array
    {
        $industry = (string) get_option('wallet_up_industry', '');
        $goal = (string) get_option('wallet_up_onboarding_goal', '');
        $org = get_option('wallet_up_organization_settings', []);
        $ein = is_array($org) ? trim((string) ($org['ein'] ?? '')) : '';
        $forms = get_option('wallet_up_donation_forms', []);
        $campaigns = get_option('wallet_up_campaigns', []);
        $hasForms = is_array($forms) && !empty($forms);
        $hasCampaigns = is_array($campaigns) && !empty($campaigns);
        $tipJar = self::tipJarPublished();

        $reasons = [];
        if ($industry === 'nonprofit') {
            $reasons[] = 'industry_nonprofit';
        }
        if ($ein !== '') {
            $reasons[] = 'charity_number';
        }
        if ($goal === 'donations' && ($tipJar || $hasForms || $hasCampaigns)) {
            $reasons[] = 'donations_in_use';
        }
        if (!in_array($industry, self::COMMERCIAL_INDUSTRIES, true) && ($hasForms || $hasCampaigns)) {
            $reasons[] = 'donation_forms';
        }
        return ['invite' => !empty($reasons), 'reasons' => $reasons];
    }

    public static function addScriptData(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return $data;
        }
        $application = self::application();
        $signals = self::signals();
        $onSetup = self::onSetupScreen();
        if (!$signals['invite'] && !$application && !$onSetup) {
            return $data;
        }
        $dismissedAt = (int) get_user_meta(get_current_user_id(), self::META_DISMISSED, true);
        $data['nonprofitOffer'] = [
            'show' => $application !== null || $onSetup || ($dismissedAt === 0 || (time() - $dismissedAt) > self::DISMISS_SECONDS),
            'applyMode' => self::applyMode(),
            'hostedUrl' => self::hostedUrl(),
            'termsUrl' => MerchantProvisioning::publicUrl() . '/merchant/terms/nonprofit',
            'adminEmail' => (string) get_option('admin_email', ''),
            'siteName' => wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES),
            'application' => $application ? self::publicApplication($application) : null,
            'proInstalled' => self::premiumCode(),
            'activation' => self::activationPlan($application !== null, self::licensed(), self::premiumCode(), self::awaitingConfirmation(), self::otherLicence()),
        ];
        return $data;
    }

    public static function activationPlan(bool $hasApplication, bool $licensed, bool $premiumCode, bool $awaitingConfirmation = false, bool $otherLicence = false): string
    {
        if (!$hasApplication) {
            return 'none';
        }
        if (!$licensed) {
            if ($otherLicence) {
                return 'existing';
            }
            return $awaitingConfirmation ? 'confirm' : 'activate';
        }
        return $premiumCode ? 'done' : 'install';
    }

    private static function freemius()
    {
        return function_exists('WalletUpPro\\wallet_up_fs') ? \WalletUpPro\wallet_up_fs() : null;
    }

    private static function licensed(): bool
    {
        $fs = self::freemius();
        if (!$fs || !method_exists($fs, 'can_use_premium_code') || !$fs->can_use_premium_code()) {
            return false;
        }
        if (method_exists($fs, 'is_trial') && $fs->is_trial()) {
            return false;
        }
        $licence = method_exists($fs, '_get_license') ? $fs->_get_license() : null;
        return is_object($licence) && method_exists($licence, 'is_lifetime') && $licence->is_lifetime();
    }

    private static function otherLicence(): bool
    {
        $fs = self::freemius();
        return $fs && method_exists($fs, 'can_use_premium_code') && $fs->can_use_premium_code() && !self::licensed();
    }

    private static function refusal(string $code): string
    {
        if ($code === 'NOT_APPROVED') {
            self::refresh();
            return __('This organization is no longer in the nonprofit program, so the licence cannot be activated.', 'walletup');
        }
        return __('Your licence is not ready yet. Please try again in a few minutes.', 'walletup');
    }

    private static function awaitingConfirmation(): bool
    {
        $fs = self::freemius();
        return $fs && method_exists($fs, 'is_pending_activation') && $fs->is_pending_activation();
    }

    private static function premiumCode(): bool
    {
        $fs = self::freemius();
        return $fs && method_exists($fs, 'is_premium') && $fs->is_premium();
    }

    private static function onSetupScreen(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check of which admin screen is loading
        return isset($_GET['page']) && sanitize_key(wp_unslash($_GET['page'])) === 'wallet-up-onboarding';
    }

    private static function tipJarPublished(): bool
    {
        $cached = get_transient(self::TRANSIENT_TIPJAR);
        if ($cached !== false) {
            return $cached === '1';
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cached in a transient.
        $found = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status = %s AND post_content LIKE %s LIMIT 1",
            'publish',
            '%' . $wpdb->esc_like('<!-- wp:walletup/tip-jar') . '%'
        ));
        set_transient(self::TRANSIENT_TIPJAR, $found ? '1' : '0', 12 * HOUR_IN_SECONDS);
        return $found;
    }

    private static function applyMode(): string
    {
        if (MerchantProvisioning::getApiKey() !== '') {
            return 'merchant';
        }
        return MerchantProvisioning::freemiusInstallId() !== '' ? 'install' : 'hosted';
    }

    private static function hostedUrl(): string
    {
        $lang = substr((string) get_user_locale(), 0, 2);
        return MerchantProvisioning::publicUrl() . '/merchant/nonprofit/apply?' . http_build_query([
            'site' => home_url('/'),
            'lang' => in_array($lang, ['en', 'fr', 'es'], true) ? $lang : 'en',
        ]);
    }

    private static function application(): ?array
    {
        $stored = get_option(self::OPTION_APPLICATION, null);
        return is_array($stored) && !empty($stored['token']) ? $stored : null;
    }

    private static function publicApplication(array $application): array
    {
        $out = ['updatedAt' => (int) ($application['updated_at'] ?? 0)];
        $application[self::READY_FIELD] = !empty($application[self::READY_FIELD]) || !empty($application['license_code']);
        foreach (self::STATUS_FIELDS as $stored => $public) {
            $out[$public] = in_array($stored, [self::FLAG_FIELD, self::READY_FIELD], true) ? !empty($application[$stored]) : (string) ($application[$stored] ?? '');
        }
        return $out;
    }

    private static function storeApplication(string $token, array $status): array
    {
        $existing = self::application();
        $record = ['token' => $token !== '' ? DataEncryption::getInstance()->encrypt($token) : ($existing['token'] ?? ''), 'updated_at' => time()];
        $status[self::READY_FIELD] = !empty($status['license_code']) || !empty($status[self::READY_FIELD]);
        foreach (array_keys(self::STATUS_FIELDS) as $field) {
            $record[$field] = in_array($field, [self::FLAG_FIELD, self::READY_FIELD], true) ? !empty($status[$field]) : (string) ($status[$field] ?? '');
        }
        if ($record['status'] === '') {
            $record['status'] = 'pending';
        }
        update_option(self::OPTION_APPLICATION, $record, false);
        return $record;
    }

    private static function token(): string
    {
        $application = self::application();
        if (!$application) {
            return '';
        }
        $plain = DataEncryption::getInstance()->decrypt((string) $application['token']);
        return is_string($plain) ? $plain : '';
    }

    private static function guard(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to do this.', 'walletup')], 403);
        }
    }

    private static function post(string $path, array $body, array $headers = [])
    {
        $response = wp_remote_post(MerchantProvisioning::brokerUrl() . $path, [
            'timeout' => self::TIMEOUT,
            'headers' => array_merge(['Content-Type' => 'application/json'], $headers),
            'body' => wp_json_encode($body),
        ]);
        if (is_wp_error($response)) {
            return null;
        }
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function errorMessage(string $code): string
    {
        $messages = [
            'LEGAL_NAME_REQUIRED' => __('Please enter the legal name of the organization.', 'walletup'),
            'LEGAL_NAME_TOO_LONG' => __('The legal name is too long.', 'walletup'),
            'EMAIL_INVALID' => __('Please enter a valid email address.', 'walletup'),
            'ALREADY_APPLIED' => __('This organization already has an application in progress.', 'walletup'),
            'CONSENT_REQUIRED' => __('Please confirm you are authorized to apply.', 'walletup'),
            'RATE_LIMITED' => __('Too many attempts. Please try again in an hour.', 'walletup'),
        ];
        return $messages[$code] ?? __('We could not send your application. Please try again, or apply on walletup.app.', 'walletup');
    }

    public static function ajaxDismiss(): void
    {
        self::guard();
        update_user_meta(get_current_user_id(), self::META_DISMISSED, time());
        wp_send_json_success(['dismissed' => true]);
    }

    public static function ajaxApply(): void
    {
        self::guard();
        if (sanitize_text_field(wp_unslash($_POST['consent'] ?? '')) !== '1') {
            wp_send_json_error(['message' => self::errorMessage('CONSENT_REQUIRED')], 400);
        }
        $fields = [
            'legal_name' => sanitize_text_field(wp_unslash($_POST['legal_name'] ?? '')),
            'org_type' => sanitize_text_field(wp_unslash($_POST['org_type'] ?? '')) === 'church' ? 'church' : 'nonprofit',
            'ein' => sanitize_text_field(wp_unslash($_POST['ein'] ?? '')),
            'contact_email' => sanitize_email(wp_unslash($_POST['contact_email'] ?? '')),
            'site_url' => home_url('/'),
            'lang' => substr((string) get_user_locale(), 0, 2),
        ];
        $mode = self::applyMode();
        if ($mode === 'hosted') {
            wp_send_json_success(['mode' => 'hosted', 'url' => self::hostedUrl()]);
        }
        $result = $mode === 'merchant'
            ? self::post('/api/merchant/nonprofit/apply', $fields, ['X-API-Key' => MerchantProvisioning::getApiKey()])
            : self::post('/api/merchant/nonprofit/apply-install', array_merge($fields, ['freemius_install_id' => MerchantProvisioning::freemiusInstallId()]));
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        if (empty($result['success']) || empty($data['status_token'])) {
            $code = (string) ($data['code'] ?? ($result['code'] ?? ''));
            if (in_array($code, ['INSTALL_NOT_VERIFIED', 'SITE_MISMATCH'], true)) {
                wp_send_json_success(['mode' => 'hosted', 'url' => self::hostedUrl()]);
            }
            wp_send_json_error(['message' => self::errorMessage($code)], 400);
        }
        $record = self::storeApplication((string) $data['status_token'], ['status' => $data['status'] ?? 'pending']);
        self::refresh();
        wp_send_json_success(['mode' => $mode, 'application' => self::publicApplication(self::application() ?? $record)]);
    }

    private static function refresh(): ?array
    {
        $token = self::token();
        if ($token === '') {
            return null;
        }
        $result = self::post('/api/merchant/nonprofit/application-status', ['token' => $token]);
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        if (empty($result['success'])) {
            return null;
        }
        return self::storeApplication('', $data);
    }

    public static function ajaxStatus(): void
    {
        self::guard();
        $record = self::refresh();
        $application = $record ?? self::application();
        if (!$application) {
            wp_send_json_error(['message' => __('There is no application on this site yet.', 'walletup')], 404);
        }
        wp_send_json_success(['application' => self::publicApplication($application)]);
    }

    private static function fetchLicence(): array
    {
        $token = self::token();
        if ($token === '') {
            return [];
        }
        $result = self::post('/api/merchant/nonprofit/licence-key', ['token' => $token]);
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        if (empty($result['success']) || empty($data['key'])) {
            return ['error' => (string) ($result['code'] ?? '')];
        }
        return ['key' => (string) $data['key']];
    }

    private static function downloadUrl(): string
    {
        $fs = self::freemius();
        return ($fs && method_exists($fs, 'get_account_url'))
            ? (string) $fs->get_account_url('download_latest')
            : admin_url('admin.php?page=wallet-up-account');
    }

    private static function activateHere(string $key): string
    {
        if (self::otherLicence()) {
            return 'failed';
        }
        $fs = self::freemius();
        if (!$fs || !method_exists($fs, 'activate_migrated_license')) {
            return 'failed';
        }
        $result = $fs->activate_migrated_license($key, false);
        if (self::licensed()) {
            return 'done';
        }
        return !empty($result['success']) && self::awaitingConfirmation() ? 'confirm' : 'failed';
    }

    private static function approved(): bool
    {
        $application = self::application();
        return $application !== null && ($application['status'] ?? '') === 'approved';
    }

    public static function ajaxActivate(): void
    {
        self::guard();
        if (!self::freemius()) {
            wp_send_json_error(['message' => __('Licensing is not available on this site.', 'walletup')], 503);
        }
        if (!self::approved()) {
            wp_send_json_error(['message' => __('Your application is not approved yet.', 'walletup')], 409);
        }
        if (self::otherLicence()) {
            wp_send_json_error(['message' => /* translators: %s: Pro product name */ sprintf(__('This site already uses a %s licence. Reply to your approval email and we will move it to your lifetime licence and stop the old plan, so you are not charged again.', 'walletup'), WALLET_UP_PRO_PRODUCT_NAME), 'activation' => 'existing'], 409);
        }
        if (self::premiumCode()) {
            if (!self::licensed()) {
                $licence = self::fetchLicence();
                if (empty($licence['key'])) {
                    wp_send_json_error(['message' => self::refusal($licence['error'] ?? '')], 409);
                }
                $outcome = self::activateHere($licence['key']);
                if ($outcome === 'failed') {
                    wp_send_json_error(['message' => __('We could not activate your licence. Please try again, or use the code from your email at walletup.app.', 'walletup')], 400);
                }
                wp_send_json_success(['activation' => $outcome]);
            }
            wp_send_json_success(['activation' => 'done']);
        }
        $licence = self::fetchLicence();
        if (empty($licence['key'])) {
            wp_send_json_error(['message' => self::refusal($licence['error'] ?? '')], 409);
        }
        $outcome = self::licensed() ? 'done' : self::activateHere($licence['key']);
        if ($outcome === 'failed') {
            wp_send_json_error(['message' => __('We could not activate your licence. Please try again, or use the code from your email at walletup.app.', 'walletup')], 400);
        }
        if ($outcome === 'confirm') {
            wp_send_json_success(['activation' => 'confirm']);
        }
        wp_send_json_success(['activation' => 'install', 'downloadUrl' => self::downloadUrl()]);
    }

    public static function ajaxAccept(): void
    {
        self::guard();
        $token = self::token();
        if ($token === '') {
            wp_send_json_error(['message' => __('There is no application on this site yet.', 'walletup')], 404);
        }
        $result = self::post('/api/merchant/nonprofit/accept-terms', ['token' => $token]);
        if (empty($result['success'])) {
            wp_send_json_error(['message' => __('We could not record your acceptance. Please try again.', 'walletup')], 400);
        }
        $record = self::refresh();
        wp_send_json_success(['application' => self::publicApplication($record ?? self::application() ?? [])]);
    }
}
