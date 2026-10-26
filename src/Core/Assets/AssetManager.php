<?php
namespace WalletUpPro\Core\Assets;

use WalletUpPro\Core\Error\ErrorHandler;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
class AssetManager
{
    public const STATE_ACTION = 'wallet_up_admin_state';


    public static function fileVersion(string $absPath, string $fallback = ''): string
    {
        if ($fallback === '' && defined('WALLET_UP_VERSION')) {
            $fallback = (string) WALLET_UP_VERSION;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions -- local file stat, not a remote fetch
        return file_exists($absPath) ? (string) filemtime($absPath) : $fallback;
    }
    private static $instance = null;
    private $version;
    private $errorHandler;
    private $scripts = [];
    private $styles = [];

    private static $ADMIN_DEPS = null;

    private static function getAdminDeps(): array
    {
        if (self::$ADMIN_DEPS === null) {
            self::$ADMIN_DEPS = [
                'scripts' => [
                    'wp-element',
                    'wp-components',
                    'wp-i18n',
                    ...(self::isWooCommerceActive() ? ['wc-blocks-registry'] : []),
                ],
                'styles' => ['wp-components']
            ];
        }
        return self::$ADMIN_DEPS;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function resetInstance()
    {
        static::$instance = null;
    }

    private function __construct()
    {
        $this->version = WALLET_UP_PRO_VERSION;
        $this->errorHandler = ErrorHandler::getInstance(); // Initialize errorHandler
        $this->registerHooks();

    }

    private function registerHooks(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        add_action('wp_ajax_' . self::STATE_ACTION, [$this, 'handleAdminState']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueFrontendQRProtect']);

        add_action('wp_enqueue_scripts', [$this, 'enqueueClickTracking']);

        add_action('admin_enqueue_scripts', [$this, 'enqueueFreemiusOverride'], 9999);
    }

    public function enqueueFreemiusOverride(string $hook_suffix): void
    {
        if (strpos($hook_suffix, 'wallet-up') === false) {
            return;
        }

        $deps = [];
        foreach (['fs_common', 'fs_account', 'fs_checkout'] as $handle) {
            if (wp_style_is($handle, 'registered') || wp_style_is($handle, 'enqueued')) {
                $deps[] = $handle;
            }
        }

        wp_enqueue_style(
            'walletup-freemius-override',
            WALLET_UP_URL . 'assets/css/walletup-freemius.css',
            $deps,
            WALLET_UP_VERSION,
            'all'
        );
    }

    public function enqueueFrontendQRProtect(): void
    {
        $is_wc_page = function_exists('is_checkout') && (
            is_checkout()
            || (function_exists('is_cart') && is_cart()) // WalletUp Pay express lane renders in the cart express area too
            || is_wc_endpoint_url('order-received')
        );
        $has_shortcode = $this->pageHasWalletUpShortcode();

        if ($is_wc_page || $has_shortcode) {
            wp_enqueue_style(
                'walletup-qr-protect',
                WALLET_UP_URL . 'assets/css/walletup-qr-protect.css',
                [],
                $this->version
            );
            $checkout_css = WALLET_UP_PATH . 'assets/css/wc-checkout-icons.css';
            wp_enqueue_style(
                'walletup-wc-checkout-icons',
                WALLET_UP_URL . 'assets/css/wc-checkout-icons.css',
                [],
                file_exists( $checkout_css ) ? (string) filemtime( $checkout_css ) : $this->version
            );
            wp_enqueue_style(
                'walletup-pay',
                WALLET_UP_URL . 'assets/css/walletup-pay.css',
                [],
                $this->version
            );
            if (!wp_script_is('stripe-js', 'enqueued')) {
                wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/', [], null, true);
            }
        }
    }

    public function enqueueClickTracking(): void
    {
        if (is_admin() || !$this->isWalletUpFrontendSurface()) {
            return;
        }

        wp_enqueue_script(
            'walletup-events',
            WALLET_UP_URL . 'assets/js/wup-events.js',
            [],
            $this->version . '.2',
            true
        );
        wp_localize_script('walletup-events', 'wupEvt', [
            'u' => admin_url('admin-ajax.php'),
            'n' => wp_create_nonce('wallet_up_admin_nonce'),
        ]);
    }

    private function pageHasWalletUpShortcode(): bool
    {
        global $post;
        if (!is_object($post) || empty($post->post_content)) {
            return false;
        }
        $content = (string) $post->post_content;

        if (function_exists('has_block') && class_exists('WP_Block_Type_Registry')) {
            foreach (\WP_Block_Type_Registry::get_instance()->get_all_registered() as $blockName => $blockType) {
                if (strpos((string) $blockName, 'walletup/') === 0 && has_block($blockName, $post)) {
                    return true;
                }
            }
        }

        global $shortcode_tags;
        if (is_array($shortcode_tags)) {
            foreach (array_keys($shortcode_tags) as $tag) {
                $tag = (string) $tag;
                if ((strpos($tag, 'wallet_up') === 0 || strpos($tag, 'walletup') === 0)
                    && has_shortcode($content, $tag)) {
                    return true;
                }
            }
        }

        if (class_exists('\WalletUpPro\Core\Config\PaymentMethodsConfig')) {
            foreach (\WalletUpPro\Core\Config\PaymentMethodsConfig::getAllMethodIds() as $methodId) {
                if (has_shortcode($content, (string) $methodId)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isWalletUpFrontendSurface(): bool
    {
        if (function_exists('is_checkout') && (
            is_checkout()
            || (function_exists('is_cart') && is_cart())
            || (function_exists('is_account_page') && is_account_page())
            || (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received'))
        )) {
            return true;
        }
        if ($this->isWalletUpRewriteEndpoint()) {
            return true;
        }
        return $this->pageHasWalletUpShortcode();
    }

    private function isWalletUpRewriteEndpoint(): bool
    {
        if (!function_exists('get_query_var')) {
            return false;
        }
        foreach (\WalletUpPro\Core\RewriteRulesManager::queryVars() as $queryVar) {
            $value = get_query_var($queryVar);
            if ($value !== '' && $value !== null && $value !== false) {
                return true;
            }
        }
        return false;
    }
    public static function isWooCommerceActive(): bool
    {
        $checks = [
            defined('WC_VERSION'),
            class_exists('WooCommerce'),
            class_exists('WC_Payment_Gateway'),
            function_exists('WC')
        ];
        if (!in_array(false, $checks, true)) {
            return true;
        }
        return false;
    }
    public function enqueueAdminAssets($hook_suffix): void
    {
        if (strpos($hook_suffix, 'wallet-up') === false) {
            return;
        }

        if (function_exists('wp_enqueue_media')) {
            wp_enqueue_media();
        }

        foreach (self::getAdminDeps()['scripts'] as $dep) {
            if (wp_script_is($dep, 'registered')) {
                wp_enqueue_script($dep);
            } else {
            }
        }

        foreach (self::getAdminDeps()['styles'] as $dep) {
            if (wp_style_is($dep, 'registered')) {
                wp_enqueue_style($dep);
            } else {
            }
        }

        foreach ($this->scripts['admin'] ?? [] as $handle => $script) {
            wp_enqueue_script($handle, $script['src'], $script['deps'], $script['ver'], $script['in_footer']);
        }

        foreach ($this->styles['admin'] ?? [] as $handle => $style) {
            wp_enqueue_style($handle, $style['src'], $style['deps'], $style['ver'], $style['media']);
        }

        $this->enqueueReactApp();

    }

    private function enqueueReactApp(): void
    {
        try {
            $manifest_path = WALLET_UP_BASE_DIR . 'walup-react/dist/.vite/manifest.json';
            if (!file_exists($manifest_path)) {
                throw new \Exception(esc_html('React build manifest not found'));
            }

            $manifest = json_decode(file_get_contents($manifest_path), true);
            if (!isset($manifest['src/main.jsx'])) {
                throw new \Exception(esc_html('React entry point not found in manifest'));
            }

            $entry = $manifest['src/main.jsx'];
            $dist_url = WALLET_UP_BASE_URL . 'walup-react/dist/';

            $script_handle = 'wallet-up-react-admin';
            wp_register_script(
                $script_handle,
                $dist_url . $entry['file'],
                self::getAdminDeps()['scripts'],
                $this->version,
                true
            );

            add_filter('script_loader_tag', function ($tag, $handle) use ($script_handle) {
                if ($script_handle === $handle) {
                    return str_replace('<script ', '<script type="module" crossorigin data-cfasync="false" ', $tag);
                }
                return $tag;
            }, 10, 2);

            if (isset($entry['css']) && is_array($entry['css'])) {
                foreach ($entry['css'] as $index => $css_file) {
                    $style_handle = $script_handle . '-' . $index;
                    wp_register_style(
                        $style_handle,
                        $dist_url . $css_file,
                        self::getAdminDeps()['styles'],
                        $this->version,
                        'all'
                    );
                    wp_enqueue_style($style_handle);
                }
            }

            $data = $this->getAdminDataForReact();
            $data['nonces'] = array_merge($data['nonces'] ?? [], [
                'wallet_up_admin' => wp_create_nonce('wallet_up_admin_nonce'),
                'classManager' => wp_create_nonce('wp_rest'),
                'wallet_up_save_settings' => wp_create_nonce('wallet_up_save_settings'),
                'wallet_up_check_qr_status' => wp_create_nonce('wallet_up_check_qr_status'),
                'wallet_up_stripe_connect' => wp_create_nonce('wallet_up_stripe_connect'),
            ]);
            $data['ajaxUrl'] = admin_url('admin-ajax.php');
            $data['restUrl'] = esc_url_raw(rest_url());
            $data['restNonce'] = wp_create_nonce('wp_rest');

            $freemiusData = $this->getFreemiusData();
            $data['isProBuild'] = $freemiusData['isProBuild'] ?? false;
            $data['upgradeUrl'] = $freemiusData['upgradeUrl'];
            $data['checkoutUrl'] = $freemiusData['checkoutUrl'];

            $premiumManager = \WalletUpPro\Core\Manager\PremiumFeaturesManager::getInstance();
            $data = $premiumManager->addScriptData($data);
            $data = apply_filters('wallet_up_admin_react_data', $data);

            wp_localize_script($script_handle, 'walletUpData', $data);

            if (wp_script_is('wallet-up-qr-generator', 'registered')) {
                wp_localize_script(
                    'wallet-up-qr-generator',
                    'walletUpData',
                    $data
                );
            }

            wp_enqueue_script($script_handle);

            $js_url = esc_url($dist_url . $entry['file']);
            $this->addAssetLoadFallback($script_handle, $js_url);

        } catch (\Exception $e) {
            $this->errorHandler->log('Failed to load React admin assets: ' . $e->getMessage());
            add_action('admin_notices', function () use ($e) {
                if (strpos($GLOBALS['hook_suffix'] ?? '', 'wallet-up') === false) {
                    return;
                }
                echo '<div class="notice notice-error"><p><strong>WalletUp Pro:</strong> ';
                echo esc_html('Unable to load admin interface. ');
                if (strpos($e->getMessage(), 'manifest') !== false) {
                    echo esc_html('Build files are missing. Please re-upload the plugin or run the build pipeline.');
                } else {
                    echo esc_html('Error: ' . $e->getMessage());
                }
                echo '</p></div>';
            });
        }
        add_action('admin_footer', function () {
            global $hook_suffix;
            if (strpos($hook_suffix, 'wallet-up') === false) {
                return;
            }

            $fs = function_exists('WalletUpPro\\wallet_up_fs') ? \WalletUpPro\wallet_up_fs() : null;
            $can_use_premium = \WalletUpPro\wallet_up_premium_active();
            $is_paying = is_object($fs) && !$fs->is_not_paying();
            $is_trial = is_object($fs) && $fs->is_trial();

            $trial_days = 0;
            if ($is_trial && method_exists($fs, 'get_trial_days_left')) {
                $trial_days = $fs->get_trial_days_left();
            }

            $plan_id = '';
            if (is_object($fs)) {
                $license = $fs->_get_license();
                if (is_object($license)) {
                    $plan_id = $license->plan_id;
                }
            }

            ?>
                        <script type="text/javascript">
                            (function () {
                                window.walletUpData = window.walletUpData || {};
                                window.walletUpData.premium_status = {
                                    can_use_premium_code: <?php echo wp_json_encode($can_use_premium); ?>,
                                    is_paying: <?php echo wp_json_encode($is_paying); ?>,
                                    is_trial: <?php echo wp_json_encode($is_trial); ?>,
                                    trial_days_left: <?php echo wp_json_encode($trial_days); ?>,
                                    plan_id: <?php echo wp_json_encode($plan_id); ?>,
                                    last_check: <?php echo esc_js(intval(time())); ?>
                                };

                                if (window.walletUpData.debug) {
                                }

                                document.dispatchEvent(new CustomEvent('walletUpPremiumStatusUpdated', {
                                    detail: window.walletUpData.premium_status
                                }));
                            })();
                        </script>
                        <?php
        });
    }
    private function getFreemiusData(): array
    {
        $cachedData = get_transient('wallet_up_premium_status_check');
        if (is_array($cachedData) && !empty($cachedData['timestamp']) && (time() - $cachedData['timestamp'] < 300) && array_key_exists('checkoutUrl', $cachedData) && !array_key_exists('isPremium', $cachedData)) {
            return $cachedData;
        }

        $upgradeUrl = function_exists('WalletUpPro\\wallet_up_upgrade_url') ? \WalletUpPro\wallet_up_upgrade_url() : admin_url('admin.php?page=wallet-up');
        $checkoutUrl = '';
        $sdk = function_exists('WalletUpPro\\wallet_up_fs') ? \WalletUpPro\wallet_up_fs() : null;
        if (is_object($sdk) && function_exists('WalletUpPro\\wallet_up_pricing_reachable') && \WalletUpPro\wallet_up_pricing_reachable($sdk) && method_exists($sdk, 'checkout_url')) {
            $checkoutUrl = $sdk->checkout_url();
        }

        $result = [
            'isProBuild' => defined('WALLET_UP_IS_PRO_BUILD') && WALLET_UP_IS_PRO_BUILD,
            'upgradeUrl' => $upgradeUrl,
            'checkoutUrl' => $checkoutUrl,
            'timestamp' => time(),
        ];

        set_transient('wallet_up_premium_status_check', $result, 300);

        return $result;
    }
    private static function stripeCardsLive(): bool
    {
        if (!class_exists('\\WalletUpPro\\Core\\Payments\\StripeConnect')) {
            return false;
        }
        $status = \WalletUpPro\Core\Payments\StripeConnect::getCachedStatus();
        return !empty($status['connected']) && !empty($status['charges_enabled']);
    }

    private function getAdminDataForReact(): array
    {
        $siteUrl = rtrim(get_home_url(), '/');

        $customPaths = [
            'payer' => [
                'path' => 'pay',
                'shortcode' => 'wallet_up_payer'
            ],
            'donation' => [
                'path' => 'donate',
                'shortcode' => 'wallet_up_donation'
            ]

        ];
        return [
            'nonce' => wp_create_nonce('wallet_up_admin_nonce'),
            'dismissNonce' => wp_create_nonce('wallet_up_dismiss_notice'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'locale' => get_locale(),
            'pluginUrl' => WALLET_UP_URL,
            'version' => defined('WALLET_UP_VERSION') ? WALLET_UP_VERSION : '',
            'pluginDescription' => (defined('WALLET_UP_PRO_FILE') && file_exists(WALLET_UP_PRO_FILE)) ? trim(get_file_data(WALLET_UP_PRO_FILE, ['d' => 'Description'])['d'] ?? '') : '',
            'demoUrl' => apply_filters('wallet_up_demo_url', 'https://demo.walletup.app'),
            'activateUrl' => apply_filters('wallet_up_activate_url', 'https://walletup.app/activate'),
            'siteUrl' => $siteUrl,
            'customPaths' => $customPaths,
            'paths' => [
                'assets' => esc_url_raw(WALLET_UP_BASE_URL . 'assets/'),
            ],
            'i18n' => [
                'saveSuccess' => __('Settings saved successfully', 'walletup'),
                'saveError' => __('Error saving settings', 'walletup'),
                'confirmReset' => __('Are you sure you want to reset all settings?', 'walletup'),
                'loading' => __('Loading...', 'walletup'),
                'validationErrors' => [
                    'email' => __('Please enter a valid email address', 'walletup'),
                    'required' => __('This field is required', 'walletup'),
                    'phoneNumber' => __('Please enter a valid phone number', 'walletup')
                ]
            ],
            'woocommerce' => [
                'nonces' => [
                    'wcSettings' => wp_create_nonce('wallet_up_wc_save_settings'),
                    'wcGetSettings' => wp_create_nonce('wallet_up_wc_settings'),
                    'wcPaymentDisplay' => wp_create_nonce('wallet_up_wc_payment_display'),
                    'wcMethodSettings' => wp_create_nonce('wallet_up_admin_nonce'),
                    'wcDisplayMode' => wp_create_nonce('wallet_up_admin_nonce'),
                ]
            ],

            'payment' => [
                'nonces' => [
                    'payment' => wp_create_nonce('wallet_up_payment_nonce'),
                    'generate' => wp_create_nonce('wallet_up_generate_payment')
                ],
                'settings' => [
                    'rateLimit' => defined('WALLET_UP_RATE_LIMIT') ? WALLET_UP_RATE_LIMIT : 10,
                    'ratePeriod' => defined('WALLET_UP_RATE_PERIOD') ? WALLET_UP_RATE_PERIOD : 300
                ]
            ],
            'qrExtensionsAvailable' => $this->checkQRStatus()['qr_available'],
            'gdAvailable' => $this->checkQRStatus()['gd_available'],
            'imagickAvailable' => $this->checkQRStatus()['imagick_available'],
            'wooCommerceActive' => class_exists('WooCommerce'),
            'healthCheck' => \WalletUpPro\Core\Admin\ElevationTracker::runChecks(),
            'adminV2' => get_option('wallet_up_admin_v2', 'yes') === 'yes',
            'menuModules' => class_exists(\WalletUpPro\Core\Admin\AdminV2MenuRegistrar::class)
                ? array_map(static fn($m) => [
                    'id'       => $m['id'],
                    'labelKey' => $m['labelKey'] ?? '',
                    'label'    => $m['label'] ?? $m['id'],
                    'alwaysOn' => !empty($m['always_on']),
                ], \WalletUpPro\Core\Admin\AdminV2MenuRegistrar::modules())
                : [],
            'moreProducts' => class_exists(\WalletUpPro\Core\Config\MoreProductsConfig::class) ? \WalletUpPro\Core\Config\MoreProductsConfig::getAll() : [],
            'isPreview' => function_exists('\WalletUpPro\wallet_up_is_preview_sandbox') ? \WalletUpPro\wallet_up_is_preview_sandbox() : false,

            'nonces' => [
                'wallet_up_admin' => wp_create_nonce('wallet_up_admin_nonce'),
                'wallet_up_save_settings' => wp_create_nonce('wallet_up_save_settings'),
                'wallet_up_check_qr_status' => wp_create_nonce('wallet_up_check_qr_status'),
                'wallet_up_toggle_wpforms' => wp_create_nonce('wallet_up_toggle_wpforms'),
                'wallet_up_check_wpforms' => wp_create_nonce('wallet_up_check_wpforms'),
                'walup_submit_donation' => wp_create_nonce('walup_submit_donation'),
                'walup_get_form_config' => wp_create_nonce('walup_get_form_config'),
                'wallet_up_complete_donation' => wp_create_nonce('wallet_up_complete_donation'),
                'wallet_up_create_donation_payment' => wp_create_nonce('wallet_up_create_donation_payment'),
                'wallet_up_stripe_connect' => wp_create_nonce('wallet_up_stripe_connect'),
                'wallet_up_platform_fees' => wp_create_nonce('wallet_up_admin_nonce'),
            ]
        ] + $this->mutableAdminData();

    }

    private function mutableAdminData(): array
    {
        return [
            'stripeCardsLive' => self::stripeCardsLive(),
            'settings' => array_merge(
                get_option('wallet_up_donation_advanced', []) ?: [],
                $this->mergeCustomQrImages(get_option('wallet_up_transfer_call', [])) ?: []
            ),
            'customQrImages' => array_intersect_key(
                (array) get_option('wallet_up_custom_qr_images', []),
                array_flip(\WalletUpPro\Core\Config\PaymentMethodsConfig::CUSTOM_QR_METHOD_IDS)
            ),
            'displaySettings' => get_option('wallet_up_display_settings', []),
            'mobileMoneySettings' => get_option('wallet_up_mobile_money_settings', []),
            'stripeConfig' => $this->getSafeStripeConfig(),
            'paypalCommerceSettings' => $this->getSafePaypalCommerceConfig(),
            'enabledMethods' => get_option('wallet_up_enabled_methods', []),
            'methodStatus' => \WalletUpPro\Core\Payments\MethodStatusResolver::snapshot(),
            'availability' => [
                'enabled'  => \WalletUpPro\Core\Settings\SettingsRegistry::isAvailabilityV2(),
                'policy'   => \WalletUpPro\Core\Settings\SettingsRegistry::isAvailabilityV2() ? \WalletUpPro\Core\Settings\SettingsRegistry::get(\WalletUpPro\Core\Settings\SettingsRegistry::SURFACE_POLICY) : null,
            ],
            'allPaymentMethods' => $this->getAllPaymentMethodsForReact(),
            'menuVisibility' => class_exists(\WalletUpPro\Core\Admin\MenuVisibility::class) ? \WalletUpPro\Core\Admin\MenuVisibility::all() : [],
            'cashappApi' => class_exists(\WalletUpPro\Payments\CashAppPayAPI::class) ? \WalletUpPro\Payments\CashAppPayAPI::getCredentials() : [],
            'venmoApi' => class_exists(\WalletUpPro\Payments\VenmoBraintreeAPI::class) ? \WalletUpPro\Payments\VenmoBraintreeAPI::getCredentials() : [],
            'wpforms' => $this->getWPFormsData(),
        ];
    }

    public function handleAdminState(): void
    {
        if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false) || !current_user_can('manage_options')) {
            wp_send_json_error(['code' => 'FORBIDDEN'], 403);
        }
        $data = apply_filters('wallet_up_admin_react_data', $this->mutableAdminData());
        wp_send_json_success(self::localizeShape($data));
    }

    private static function localizeShape(array $data): array
    {
        foreach ($data as $key => $value) {
            if (!is_array($value) && !is_object($value)) {
                $data[$key] = html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8');
            }
        }
        return $data;
    }

    private function getAllPaymentMethodsForReact(): array
    {
        $colorMap = [
            'green-600' => '#00D632', 'blue-600' => '#2563EB', 'purple-600' => '#6D1ED4',
            'orange-500' => '#F7931A', 'indigo-600' => '#627EEA', 'green-500' => '#26A17B',
            'blue-500' => '#2775CA', 'yellow-500' => '#F3BA2F', 'yellow-600' => '#C3A634',
            'orange-500' => '#FF6600', 'red-600' => '#ED1C24',
        ];

        $enabledMethods = get_option('wallet_up_enabled_methods', []);

        $methods = [];
        if (class_exists('\WalletUpPro\Core\Config\PaymentMethodsConfig')) {
            $allIds = \WalletUpPro\Core\Config\PaymentMethodsConfig::getAllMethodIds();
            foreach ($allIds as $id) {
                $config = \WalletUpPro\Core\Config\PaymentMethodsConfig::getMethodConfig($id);
                $twColor = $config['icon_color'] ?? '';
                $methods[] = [
                    'id'        => $id,
                    'name'      => $config['name'] ?? $id,
                    'full_name' => \WalletUpPro\Core\Config\PaymentMethodsConfig::getMethodFullName($id),
                    'type'      => $config['type'] ?? 'traditional',
                    'color'     => $colorMap[$twColor] ?? '#6b7280',
                    'available' => !empty($enabledMethods[$id]),
                ];
            }
        }

        if (class_exists('\WalletUpPro\Core\Config\AutomatedApiConfig')) {
            foreach (\WalletUpPro\Core\Config\AutomatedApiConfig::getCatalog() as $id => $info) {
                $methods[] = [
                    'id'         => $id,
                    'name'       => $info['name'],
                    'type'       => 'automated',
                    'color'      => '#635BFF',
                    'configured' => (bool) $info['configured'],
                    'available'  => (bool) $info['configured'],
                ];
            }
        }

        return $methods;
    }

    private function mergeCustomQrImages(array $transferCall): array
    {
        $images = get_option('wallet_up_custom_qr_images', []);
        if (is_array($images)) {
            foreach ($images as $methodId => $url) {
                if (!\WalletUpPro\Core\Config\PaymentMethodsConfig::allowsCustomQr((string) $methodId)) {
                    continue;
                }
                $transferCall[$methodId . '_qr_image'] = $url;
            }
        }
        $zelleName = get_option('wallet_up_zelle_name', '');
        if ($zelleName !== '') {
            $transferCall['zelle_name'] = $zelleName;
        }
        $transferCall['zelle_hide_contact'] = (bool) get_option('wallet_up_zelle_hide_contact', false);
        return $transferCall;
    }

    private function getSafeStripeConfig(): array
    {
        if (class_exists('\\WalletUpPro\\Core\\Payments\\PaymentGatewayHandler')) {
            $config = \WalletUpPro\Core\Payments\PaymentGatewayHandler::getStripeConfig();
            $testMode = !empty($config['test_mode']);
            $pk = $testMode ? ($config['test_publishable_key'] ?? '') : ($config['live_publishable_key'] ?? '');
            return ['publishable_key' => $pk, 'mode' => $testMode ? 'test' : 'live'];
        }
        return ['publishable_key' => '', 'mode' => 'test'];
    }

    private function getSafePaypalCommerceConfig(): array
    {
        $config = get_option('wallet_up_paypal_commerce_settings', []);
        return [
            'client_id' => $config['client_id'] ?? '',
            'mode' => $config['mode'] ?? 'sandbox',
        ];
    }

    private function getWPFormsData(): array
    {
        if (!class_exists('WalletUpPro\WpForms\Forms\WPFormsService')) {
            return ['available' => false];
        }

        $wpformsService = \WalletUpPro\WpForms\Forms\WPFormsService::getInstance($this->errorHandler);

        $templateStatus = $wpformsService->getTemplateStatus();

        return [
            'available' => $wpformsService->isActive(),
            'enabled' => $wpformsService->isTemplateEnabled(),
            'version' => $wpformsService->getVersion(),
            'template_status' => $templateStatus,
            'initialized' => $wpformsService->isInitialized()
        ];
    }
    private function checkQRStatus(): array
    {
        $has_gd = extension_loaded('gd');
        $has_imagick = extension_loaded('imagick');

        return [
            'qr_available' => $has_gd || $has_imagick,
            'gd_available' => $has_gd,
            'imagick_available' => $has_imagick
        ];
    }

    private function addAssetLoadFallback(string $scriptHandle, string $jsUrl): void
    {
        add_action('admin_footer', function () use ($scriptHandle, $jsUrl) {
            if (strpos($GLOBALS['hook_suffix'] ?? '', 'wallet-up') === false) {
                return;
            }
            $nonce = wp_create_nonce('walletup_asset_fallback');
            ?>
            <script data-cfasync="false" data-walletup-fallback="<?php echo esc_attr($nonce); ?>">
            (function() {
                var rootEl = document.getElementById('walletup-admin-root') || document.getElementById('wallet-up-admin-root');
                if (!rootEl) return;

                setTimeout(function() {
                    if (rootEl.children.length > 0) return; // React loaded fine

                    var container = document.createElement('div');
                    container.setAttribute('role', 'alert');
                    container.style.cssText = 'max-width:600px;margin:40px auto;padding:24px;background:#fff3cd;border:1px solid #ffc107;border-radius:8px;font-family:-apple-system,sans-serif;color:#664d03;';

                    var h3 = document.createElement('h3');
                    h3.style.cssText = 'margin:0 0 12px';
                    h3.textContent = 'WalletUp Pro: Admin interface failed to load';
                    container.appendChild(h3);

                    var p1 = document.createElement('p');
                    p1.style.cssText = 'margin:0 0 8px';
                    p1.textContent = 'The main JavaScript bundle could not be loaded. This is usually caused by:';
                    container.appendChild(p1);

                    var ul = document.createElement('ul');
                    ul.style.cssText = 'margin:0 0 12px;padding-left:20px';
                    var causes = [
                        ['Cloudflare cache', 'Purge cache in your Cloudflare dashboard'],
                        ['Cloudflare Rocket Loader', 'Disable it or add an exception for admin pages'],
                        ['File permissions', 'Ensure plugin files are readable by the web server (chown to correct user)'],
                        ['Browser extension', 'Disable ad blockers or privacy extensions on this page']
                    ];
                    causes.forEach(function(c) {
                        var li = document.createElement('li');
                        var b = document.createElement('strong');
                        b.textContent = c[0];
                        li.appendChild(b);
                        li.appendChild(document.createTextNode(' \u2014 ' + c[1]));
                        ul.appendChild(li);
                    });
                    container.appendChild(ul);

                    var p2 = document.createElement('p');
                    p2.style.cssText = 'margin:0;font-size:13px;color:#856404';
                    p2.textContent = 'If the issue persists after clearing cache and fixing permissions, contact support.';
                    container.appendChild(p2);

                    rootEl.appendChild(container);
                }, 8000);
            })();
            </script>
            <?php
        }, 99);
    }

    public function registerScript(string $handle, string $src, array $deps, bool $inFooter, string $context): void
    {
        $this->scripts[$context][$handle] = [
            'src' => $src,
            'deps' => $deps,
            'ver' => $this->version,
            'in_footer' => $inFooter
        ];
    }

    public function registerStyle(string $handle, string $src, array $deps, string $media, string $context): void
    {
        $this->styles[$context][$handle] = [
            'src' => $src,
            'deps' => $deps,
            'ver' => $this->version,
            'media' => $media
        ];
    }
}