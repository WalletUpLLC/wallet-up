<?php
namespace WalletUpPro\Core\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class OnboardingHandler
{
    const OPTION_COMPLETED = 'wallet_up_onboarding_completed';
    const OPTION_STEP = 'wallet_up_onboarding_step';
    const OPTION_DATA = 'wallet_up_onboarding_data';
    const OPTION_PENDING = 'wallet_up_onboarding_pending';   // 'redirect' | 'banner' | unset
    const OPTION_MIGRATED = 'wallet_up_onboarding_migrated_v2';
    const TRANSIENT_REDIRECT = 'wallet_up_onboarding_redirect'; // back-compat read only

    const FREE_PROFILE_FIELDS = ['holder_owner', 'holder_email', 'holder_no'];
    const FREE_ACCOUNT_FIELDS = ['cashapp_holder', 'paypal_holder', 'venmo_holder', 'zelle_holder'];
    const FREE_METHOD_IDS = \WalletUpPro\Core\Config\PaymentMethodsConfig::FREE_METHOD_IDS;

    private static function isPremium(): bool
    {
        return defined('WALLET_UP_IS_PRO_BUILD') && WALLET_UP_IS_PRO_BUILD;
    }

    private static function isWPFormsActive(): bool
    {
        return defined('WPFORMS_VERSION') || class_exists('WPForms\\WPForms') || class_exists('WPForms');
    }

    private static function filterAccountsByTier(array $accounts): array
    {
        if (self::isPremium()) {
            return $accounts;
        }
        $allowed = array_merge(self::FREE_PROFILE_FIELDS, self::FREE_ACCOUNT_FIELDS);
        return array_intersect_key($accounts, array_flip($allowed));
    }

    private static function filterEnabledByTier(array $enabled): array
    {
        if (self::isPremium()) {
            return $enabled;
        }
        return array_intersect_key($enabled, array_flip(self::FREE_METHOD_IDS));
    }

    public static function init(): void
    {
        add_action('admin_menu', [__CLASS__, 'registerMenu'], 12);
        add_action('admin_init', [__CLASS__, 'migrateLegacyInstalls'], 5);
        add_action('admin_init', [__CLASS__, 'maybeRedirect'], 20);
        add_action('admin_post_wallet_up_fallback_setup', [__CLASS__, 'handleFallbackSubmit']);
        add_action('wp_ajax_wallet_up_onboarding_save_step', [__CLASS__, 'handleSaveStep']);
        add_action('wp_ajax_wallet_up_onboarding_complete', [__CLASS__, 'handleComplete']);
        add_action('wp_ajax_wallet_up_onboarding_skip', [__CLASS__, 'handleSkip']);
        add_action('wp_ajax_wallet_up_onboarding_get_state', [__CLASS__, 'handleGetState']);
    }

    public static function scheduleRedirectOnActivation(): void
    {
        if (get_option(self::OPTION_COMPLETED)) {
            return;
        }
        self::setPending('redirect');
    }

    public static function getPending(): string
    {
        $v = (string) get_option(self::OPTION_PENDING, '');
        if ($v !== '') {
            return $v;
        }
        if (get_transient(self::TRANSIENT_REDIRECT)) {
            delete_transient(self::TRANSIENT_REDIRECT);
            return 'redirect';
        }
        return '';
    }

    public static function setPending(string $mode = 'redirect'): void
    {
        if (!in_array($mode, ['redirect', 'banner'], true)) {
            $mode = 'banner';
        }
        update_option(self::OPTION_PENDING, $mode, false);
    }

    public static function clearPending(): void
    {
        delete_option(self::OPTION_PENDING);
        delete_transient(self::TRANSIENT_REDIRECT);
    }

    public static function migrateLegacyInstalls(): void
    {
        if (get_option(self::OPTION_MIGRATED)) {
            return;
        }
        update_option(self::OPTION_MIGRATED, 1, false);

        if (get_option(self::OPTION_COMPLETED) || get_option(self::OPTION_PENDING)) {
            return;
        }

        $accounts = (array) get_option('wallet_up_transfer_call', []);
        $hasHolders = !empty($accounts['cashapp_holder'])
            || !empty($accounts['paypal_holder'])
            || !empty($accounts['venmo_holder']);

        if ($hasHolders) {
            update_option(self::OPTION_COMPLETED, 1, false);
            return;
        }

        self::setPending('banner');
    }

    public static function registerMenu(): void
    {
        add_submenu_page(
            'wallet-up',
            esc_html__('Welcome', 'walletup'),
            esc_html__('Welcome', 'walletup'),
            'manage_options',
            'wallet-up-onboarding',
            [__CLASS__, 'render']
        );
        add_action('admin_head', [__CLASS__, 'hideMenuItemCss']);
    }

    public static function pageUrl(string $page): string
    {
        return function_exists('WalletUpPro\\wallet_up_admin_page_url') ? \WalletUpPro\wallet_up_admin_page_url($page) : admin_url('admin.php?page=' . $page);
    }

    public static function hideMenuItemCss(): void
    {
        echo '<style>#toplevel_page_wallet-up .wp-submenu a[href*="page=wallet-up-onboarding"]{display:none !important;}</style>';
    }

    public static function maybeRedirect(): void
    {
        $pending = self::getPending();
        if ($pending === '') {
            return;
        }
        if (wp_doing_ajax() || wp_doing_cron() || ! is_admin()) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page check, no state mutation
        if (isset($_GET['activate-multi'])) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check for a licensing hand-off in the URL
        if (isset($_GET['fs_action']) || isset($_GET['user_secret_key']) || isset($_GET['install_secret_key'])) {
            return;
        }
        if (get_option(self::OPTION_COMPLETED)) {
            self::clearPending();
            return;
        }
        if ($pending !== 'redirect') {
            return;
        }
        if (function_exists('WalletUpPro\\wallet_up_in_activation_mode') && \WalletUpPro\wallet_up_in_activation_mode()) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page check
        if (isset($_GET['page']) && function_exists('WalletUpPro\\wallet_up_page_slug') && $_GET['page'] === \WalletUpPro\wallet_up_page_slug('pricing')) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page check
        if (isset($_GET['page']) && $_GET['page'] === 'wallet-up-onboarding') {
            self::clearPending();
            return;
        }
        self::clearPending();
        wp_safe_redirect(admin_url('admin.php?page=wallet-up-onboarding'));
        exit;
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page-mode toggle
        if (isset($_GET['fallback']) && $_GET['fallback'] === '1') {
            self::renderFallback();
            return;
        }
        $step = get_option(self::OPTION_STEP, 'welcome');
        ?>
        <div class="wrap">
            <hr class="wp-header-end" style="display:none;">
            <div id="wallet-up-admin-root"
                 data-admin-version="v2"
                 data-initial-page="onboarding"
                 data-onboarding-step="<?php echo esc_attr($step); ?>"></div>

            <?php // 6.7.0: the simple form is on the page already; it appears if the wizard has not mounted after 2.5 s. ?>
            <div id="wallet-up-onboarding-fallback" style="display:none;max-width:640px;">
                <h1><?php esc_html_e('Wallet Up setup', 'walletup'); ?></h1>
                <div class="wup-fallback-note" style="border-left:4px solid #dba617;background:#fff;padding:8px 12px;margin:8px 0;"><p><?php esc_html_e('The visual setup wizard has not loaded. You can finish setup with this form instead; it does the same thing.', 'walletup'); ?></p></div>
                <?php self::fallbackForm(); ?>
            </div>

            <noscript>
                <div style="max-width:640px;">
                    <h1><?php esc_html_e('Wallet Up setup', 'walletup'); ?></h1>
                    <div class="wup-fallback-note" style="border-left:4px solid #dba617;background:#fff;padding:8px 12px;margin:8px 0;"><p><?php esc_html_e('JavaScript is off, so here is the simple setup form.', 'walletup'); ?></p></div>
                    <?php self::fallbackForm(); ?>
                </div>
            </noscript>

            <?php echo MountHealth::watchScript('wallet-up-admin-root', 'wallet-up-onboarding-fallback', 'onboarding'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static script with JSON-encoded config ?>
        </div>
        <?php
    }

    private static function renderFallback(): void
    {
        ?>
        <div class="wrap" style="max-width:640px;">
            <h1><?php esc_html_e('Wallet Up — Simple Setup', 'walletup'); ?></h1>
            <p><?php esc_html_e('Fill in the basics and you\'re ready to accept payments.', 'walletup'); ?></p>
            <?php self::fallbackForm(); ?>
            <?php if (class_exists(SelfCheckPanel::class)) : ?>
                <h2 style="margin-top:32px;"><?php esc_html_e('Setup check', 'walletup'); ?></h2>
                <?php echo SelfCheckPanel::table(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /** The plain-HTML setup form: name, email, one method and its username. No JavaScript involved. */
    public static function fallbackForm(): void
    {
        $accounts = (array) get_option('wallet_up_transfer_call', []);
        $name = (string) ($accounts['holder_owner'] ?? '');
        $email = (string) ($accounts['holder_email'] ?? '');
        if ($email === '') {
            $email = (string) get_option('admin_email', '');
        }
        $methods = [
            'cashapp' => 'Cash App',
            'paypal'  => 'PayPal.me',
            'venmo'   => 'Venmo',
            'zelle'   => 'Zelle',
        ];
        ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('wallet_up_fallback_setup', 'wallet_up_fallback_nonce'); ?>
                <input type="hidden" name="action" value="wallet_up_fallback_setup">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="wup_owner"><?php esc_html_e('Your name or business', 'walletup'); ?></label></th>
                        <td><input type="text" id="wup_owner" name="holder_owner" value="<?php echo esc_attr($name); ?>" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wup_email"><?php esc_html_e('Email', 'walletup'); ?></label></th>
                        <td><input type="email" id="wup_email" name="holder_email" value="<?php echo esc_attr($email); ?>" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wup_method"><?php esc_html_e('Payment method', 'walletup'); ?></label></th>
                        <td>
                            <select id="wup_method" name="primary_method">
                                <?php foreach ($methods as $id => $label) : ?>
                                    <option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wup_holder"><?php esc_html_e('Your username', 'walletup'); ?></label></th>
                        <td>
                            <input type="text" id="wup_holder" name="method_holder" value="" class="regular-text" required>
                            <p class="description"><?php esc_html_e('Examples: $yourname (Cash App), yourname (PayPal.me), @yourname (Venmo), you@example.com or your phone (Zelle).', 'walletup'); ?></p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" class="button button-primary"><?php esc_html_e('Finish setup', 'walletup'); ?></button>
                </p>
            </form>
        <?php
    }

    public static function handleFallbackSubmit(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied', 'walletup'), '', ['response' => 403]);
        }
        if (!check_admin_referer('wallet_up_fallback_setup', 'wallet_up_fallback_nonce')) {
            wp_die(esc_html__('Invalid security token', 'walletup'), '', ['response' => 400]);
        }

        $name   = isset($_POST['holder_owner']) ? sanitize_text_field(wp_unslash($_POST['holder_owner'])) : '';
        $email  = isset($_POST['holder_email']) ? sanitize_email(wp_unslash($_POST['holder_email'])) : '';
        $method = isset($_POST['primary_method']) ? sanitize_key(wp_unslash($_POST['primary_method'])) : 'cashapp';
        $holder = isset($_POST['method_holder']) ? sanitize_text_field(wp_unslash($_POST['method_holder'])) : '';

        if (!in_array($method, self::FREE_METHOD_IDS, true)) {
            $method = 'cashapp';
        }

        $accounts = (array) get_option('wallet_up_transfer_call', []);
        $accounts['holder_owner'] = $name;
        $accounts['holder_email'] = $email;
        $accounts[$method . '_holder'] = $holder;
        update_option('wallet_up_transfer_call', $accounts);

        $enabled = (array) get_option('wallet_up_enabled_methods', []);
        $enabled[$method] = true;
        update_option('wallet_up_enabled_methods', $enabled);

        update_option(self::OPTION_COMPLETED, 1, false);
        self::clearPending();

        // 6.7.0: the simple form ends the same way the wizard does, with a live page to send.
        $existing = (int) get_option('wallet_up_payment_page_id', 0);
        if ($holder !== '' && (!$existing || get_post_status($existing) !== 'publish')) {
            try {
                \WalletUpPro\Core\Onboarding\PaymentPageGenerator::publishPage($method);
            } catch (\Throwable $e) {
                // The dashboard fallback still shows the link and QR; the page is optional.
            }
        }

        wp_safe_redirect(admin_url('admin.php?page=wallet-up&fallback_setup=done'));
        exit;
    }

    public static function handleSaveStep(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new \Exception(esc_html__('Invalid security token', 'walletup'));
            }
            if (!current_user_can('manage_options')) {
                throw new \Exception(esc_html__('Permission denied', 'walletup'));
            }

            $step = isset($_POST['step']) ? sanitize_key(wp_unslash($_POST['step'])) : '';
            $raw = isset($_POST['data']) ? wp_unslash($_POST['data']) : '{}'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON validated below.
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                $decoded = [];
            }

            $existing = get_option(self::OPTION_DATA, []);
            if (!is_array($existing)) {
                $existing = [];
            }
            $existing[$step] = self::sanitizeStepData($step, $decoded);

            update_option(self::OPTION_DATA, $existing, false);
            update_option(self::OPTION_STEP, $step, false);

            $notEnabled = self::applyStepToSettings($step, $existing[$step]);

            wp_send_json_success([
                'step' => $step,
                'saved' => true,
                'notEnabled' => array_values($notEnabled),
            ]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public static function handleComplete(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new \Exception(esc_html__('Invalid security token', 'walletup'));
            }
            if (!current_user_can('manage_options')) {
                throw new \Exception(esc_html__('Permission denied', 'walletup'));
            }
            update_option(self::OPTION_COMPLETED, 1, false);
            delete_option(self::OPTION_STEP);
            wp_send_json_success(['redirect' => admin_url('admin.php?page=wallet-up')]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public static function handleSkip(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new \Exception(esc_html__('Invalid security token', 'walletup'));
            }
            if (!current_user_can('manage_options')) {
                throw new \Exception(esc_html__('Permission denied', 'walletup'));
            }
            update_option(self::OPTION_COMPLETED, 1, false);
            wp_send_json_success(['redirect' => admin_url('admin.php?page=wallet-up')]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public static function handleGetState(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new \Exception(esc_html__('Invalid security token', 'walletup'));
            }
            if (!current_user_can('manage_options')) {
                throw new \Exception(esc_html__('Permission denied', 'walletup'));
            }
            $accounts = (array) get_option('wallet_up_transfer_call', []);
            $enabled = (array) get_option('wallet_up_enabled_methods', []);

            $wc = [
                'displayMode' => get_option('wallet_up_wc_display_mode', 'grouped'),
                'gatewayEnabled' => false,
                'methodsAtCheckout' => [],
            ];
            if (class_exists('WooCommerce')) {
                $gwSettings = (array) get_option('woocommerce_wallet_up_settings', []);
                $wc['gatewayEnabled'] = !empty($gwSettings['enabled']) && $gwSettings['enabled'] === 'yes';
                foreach ($gwSettings as $k => $v) {
                    if (strpos($k, 'enable_') === 0) {
                        $methodId = substr($k, strlen('enable_'));
                        $wc['methodsAtCheckout'][$methodId] = $v === 'yes';
                    }
                }
            }

            wp_send_json_success([
                'step' => get_option(self::OPTION_STEP, 'welcome'),
                'data' => get_option(self::OPTION_DATA, []),
                'completed' => (bool) get_option(self::OPTION_COMPLETED),
                'wooActive' => class_exists('WooCommerce'),
                'wpformsActive' => self::isWPFormsActive(),
                'isPremium' => self::isPremium(),
                'accounts' => self::filterAccountsByTier($accounts),
                'display' => (array) get_option('wallet_up_display_settings', []),
                'enabled' => self::filterEnabledByTier($enabled),
                'primaryMethod' => (string) get_option('wallet_up_primary_method', ''),
                'configuredAmount' => (string) get_option('wallet_up_default_amount', ''),
                'eventName' => (string) get_option('wallet_up_event_name', ''),
                'tipjarPresets' => (array) get_option('wallet_up_tipjar_presets', [3, 5, 10]),
                'savedGoal' => (string) get_option('wallet_up_onboarding_goal', ''),
                'wc' => $wc,
            ]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    private static function sanitizeStepData(string $step, array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            $k = preg_replace('/[^A-Za-z0-9_]/', '', (string) $key);
            if (is_array($value)) {
                $clean[$k] = array_map(function ($v) {
                    return is_scalar($v) ? sanitize_text_field((string) $v) : '';
                }, $value);
            } elseif (is_bool($value)) {
                $clean[$k] = $value;
            } elseif (is_scalar($value)) {
                $clean[$k] = sanitize_text_field((string) $value);
            }
        }
        return $clean;
    }

    private static function applyStepToSettings(string $step, array $data): array
    {
        $premium = self::isPremium();
        $notEnabled = [];

        switch ($step) {
            case 'profile':
                $accounts = (array) get_option('wallet_up_transfer_call', []);
                foreach (self::FREE_PROFILE_FIELDS as $field) {
                    if (isset($data[$field])) {
                        $accounts[$field] = $data[$field];
                    }
                }
                update_option('wallet_up_transfer_call', $accounts);
                break;

            case 'methods':
                if (!isset($data['enabled']) || !is_array($data['enabled'])) {
                    break;
                }
                $existing = (array) get_option('wallet_up_enabled_methods', []);
                foreach ($data['enabled'] as $id => $on) {
                    $key = sanitize_key($id);
                    if (!$premium && !in_array($key, self::FREE_METHOD_IDS, true)) {
                        continue;
                    }
                    if (!empty($on) && $on !== 'false' && $on !== '0') {
                        $existing[$key] = true;
                    } else {
                        unset($existing[$key]);
                    }
                }
                update_option('wallet_up_enabled_methods', $existing);
                break;

            case 'accounts':
                $accounts = (array) get_option('wallet_up_transfer_call', []);
                foreach ($data as $k => $v) {
                    if (!preg_match('/_holder$/', $k)) {
                        continue;
                    }
                    if (!$premium && !in_array($k, self::FREE_ACCOUNT_FIELDS, true)) {
                        continue;
                    }
                    if ($v === '' || $v === null) {
                        unset($accounts[$k]);
                    } else {
                        $accounts[$k] = $v;
                    }
                }
                update_option('wallet_up_transfer_call', $accounts);
                break;

            case 'industry':
                if (!empty($data['industry'])) {
                    update_option('wallet_up_industry', sanitize_key((string) $data['industry']), false);
                }
                break;

            case 'goal':
                if (!empty($data['goal'])) {
                    update_option('wallet_up_onboarding_goal', sanitize_key((string) $data['goal']), false);
                }
                break;

            case 'details':
                if (!empty($data['method'])) {
                    update_option('wallet_up_primary_method', sanitize_key((string) $data['method']), false);
                }
                if (isset($data['amount']) && is_numeric($data['amount']) && (float) $data['amount'] > 0) {
                    update_option('wallet_up_default_amount', (string) (float) $data['amount'], false);
                }
                if (isset($data['eventName']) && trim((string) $data['eventName']) !== '') {
                    update_option('wallet_up_event_name', sanitize_text_field((string) $data['eventName']), false);
                }
                if (isset($data['donationPresets']) && is_array($data['donationPresets'])) {
                    $clean = array_values(array_filter(
                        array_map('floatval', $data['donationPresets']),
                        function ($v) { return $v > 0; }
                    ));
                    if (!empty($clean)) {
                        update_option('wallet_up_tipjar_presets', $clean, false);
                    }
                }
                break;

            case 'wc':
                if (!class_exists('WooCommerce')) {
                    break;
                }
                if (isset($data['displayMode']) && in_array($data['displayMode'], ['grouped', 'individual', 'mixed'], true)) {
                    update_option('wallet_up_wc_display_mode', $data['displayMode']);
                }
                $gatewayKey = 'woocommerce_wallet_up_settings';
                $gatewaySettings = (array) get_option($gatewayKey, []);
                $gatewaySettings['enabled'] = (self::isPremium() && !empty($data['gatewayEnabled'])) ? 'yes' : 'no';
                if (!self::isPremium() && isset($data['methodsAtCheckout']) && is_array($data['methodsAtCheckout'])) {
                    // 6.6.0: the free plan places one method at checkout through FreeP2pGateway.
                    $chosen = '';
                    foreach ($data['methodsAtCheckout'] as $methodId => $on) {
                        $methodId = sanitize_key((string) $methodId);
                        $wants = !empty($on) && $on !== 'false' && $on !== '0';
                        if ($wants && in_array($methodId, self::FREE_METHOD_IDS, true) && \WalletUpPro\Core\Payments\MethodStatusResolver::isConfigured($methodId)) {
                            if ($chosen === '') {
                                $chosen = $methodId;
                            } else {
                                $notEnabled[] = $methodId; // second and later methods at checkout are Pro
                            }
                        } elseif ($wants) {
                            $notEnabled[] = $methodId;
                        }
                    }
                    $p2p = (array) get_option('woocommerce_wallet_up_p2p_settings', []);
                    $p2p['enabled'] = $chosen !== '' ? 'yes' : 'no';
                    if ($chosen !== '') {
                        $p2p['method'] = $chosen;
                    }
                    update_option('woocommerce_wallet_up_p2p_settings', $p2p);
                }
                if (isset($data['methodsAtCheckout']) && is_array($data['methodsAtCheckout'])) {
                    $canPlaceP2pAtCheckout = self::isPremium();
                    foreach ($data['methodsAtCheckout'] as $methodId => $on) {
                        $methodId = sanitize_key((string) $methodId);
                        $wants = $canPlaceP2pAtCheckout && !empty($on) && $on !== 'false' && $on !== '0';
                        if ($wants && !\WalletUpPro\Core\Payments\MethodStatusResolver::isConfigured($methodId)) {
                            $wants = false;
                            $notEnabled[] = $methodId;
                        }
                        $gatewaySettings['enable_' . $methodId] = $wants ? 'yes' : 'no';
                    }
                }
                update_option($gatewayKey, $gatewaySettings);
                break;
        }

        return $notEnabled;
    }
}
