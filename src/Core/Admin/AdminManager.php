<?php
namespace WalletUpPro\Core\Admin;

use WalletUpPro\Core\PaymentRegistry;
use WalletUpPro\Core\Config\PaymentMethodsConfig;
use WalletUpPro\Core\Assets\AssetManager;
use WalletUpPro\Core\Database\DatabaseManager;
use WalletUpPro\Core\Error\ErrorHandler;
use WalletUpPro\Services\QRCodeService;
use WalletUpPro\Services\ChartService;
use WalletUpPro\Services\BusinessCardService;
use WalletUpPro\Core\Analytics\AnalyticsHandler;
use WalletUpPro\Services\URLShortenerService;
use WalletUpPro\Core\Donations\DonationHandler;
use WalletUpPro\Core\Payers\PayerHandler;
use Exception;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class AdminManager
{
    private $registry;
    private $assetManager;
    private $version;
    private $db;
    private $errorHandler;
    private $qrcodeservice;
    private $chartService;
    private $shortener;
    private $donationservice;
    private $payerservice;

    public function __construct(
        PaymentRegistry $registry,
        AssetManager $assetManager,
        DatabaseManager $db,
        QRCodeService $qrcodeservice
    ) {
        $this->registry = $registry;
        $this->assetManager = $assetManager;
        $this->db = $db;
        $this->errorHandler = ErrorHandler::getInstance();
        $this->qrcodeservice = $qrcodeservice;
        $this->chartService = ChartService::getInstance();
        $this->version = WALLET_UP_PRO_VERSION;

        if (class_exists(URLShortenerService::class)) {
            $this->shortener = new URLShortenerService();
        }

        if (class_exists(DonationHandler::class)) {
            $this->donationservice = DonationHandler::getInstance();
        }
        if (class_exists(PayerHandler::class)) {
            $this->payerservice = PayerHandler::getInstance();
        }
        

        $this->registerAjaxHandlers();

        $this->initializeHooks();
    }

    /**
     * Initialize WordPress hooks for admin functionality
     * 
     * @throws Exception If hook registration fails
     * @return void
     */
    private function initializeHooks(): void
    {
        try {
            add_action('init', [$this, 'registerSettings'], 5);

            add_action('init', [$this, 'loadTextDomain'], 20);

            add_action('admin_menu', [$this, 'registerAdminMenu'], 9);
            add_action('admin_enqueue_scripts', [$this->assetManager, 'enqueueAdminAssets']);

            add_filter(
                'plugin_action_links_' . WALLET_UP_BASENAME,
                [$this, 'addSettingsLink']
            );

            add_action('admin_bar_menu', [$this, 'addAdminBarMenu'], 100);

            add_action('wp_enqueue_scripts', [$this, 'enqueueAdminBarAssets']);
            add_action('admin_enqueue_scripts', [$this, 'enqueueAdminBarAssets']);

            add_filter('show_admin_bar', [$this, 'ensureAdminBarForAdmins'], 999);

            add_action('wp', function () {
                if (is_page() || is_singular()) {
                    add_action('the_content', [$this, 'maybeAddPaymentDisplay']);

                    add_filter('the_content', [$this, 'processInlineShortcodes'], 20);
                }

            });

            add_action('init', function () {
                add_action('wp_ajax_wallet_up_preview_shortcode', [$this, 'processShortcodePreview']);
                add_action('wp_ajax_wallet_up_download_preview', [$this, 'handlePreviewDownload']);

                add_action('wp_ajax_wallet_up_generate_business_qr', [$this, 'handleGenerateBusinessCard']);
                add_action('wp_ajax_wallet_up_save_business_card_data', [$this, 'handleSaveBusinessCardData']);
                add_action('wp_ajax_wallet_up_get_business_card_data', [$this, 'handleGetBusinessCardData']);

                add_action('wp_ajax_wallet_up_get_analytics', [$this, 'handleGetAnalytics']);
                add_action('wp_ajax_wallet_up_delete_analytics', [$this, 'handleDeleteAnalytics']);

                add_action('wp_ajax_wallet_up_save_settings', [$this, 'handleSaveSettings']);
                add_action('wp_ajax_wallet_up_upload_zelle_qr', [$this, 'handleUploadZelleQr']);

                add_action('wp_ajax_walletup_get_api_connection', [$this, 'handleGetApiConnection']);
                add_action('wp_ajax_walletup_save_api_key', [$this, 'handleSaveApiKey']);
                add_action('wp_ajax_walletup_test_api_connection', [$this, 'handleTestApiConnection']);
                add_action('wp_ajax_walletup_disconnect_api', [$this, 'handleDisconnectApi']);
                add_action('wp_ajax_walletup_toggle_api_mode', [$this, 'handleToggleApiMode']);
                add_action('wp_ajax_walletup_check_connection_health', [$this, 'handleCheckConnectionHealth']);

                if (!class_exists('\\WalletUpPro\\Core\\Payments\\StripeConnect', false)) {
                    require_once dirname(__DIR__) . '/Payments/StripeConnect.php';
                }
                \WalletUpPro\Core\Payments\StripeConnect::register();

                add_action('wp_ajax_wallet_up_get_shortcode_usage', [$this, 'handleGetShortcodeUsage']);

                add_action('wp_ajax_wallet_up_check_qr_status', [$this, 'handleCheckQRStatus']);
                add_action('wp_ajax_wallet_up_check_chart_status', [$this, 'handleCheckChartStatus']);

                add_action('wp_ajax_wallet_up_get_link_options', [$this, 'handleGetLinkOptions']);
                add_action('wp_ajax_wallet_up_save_link_option', [$this, 'handleSaveLinkOption']);
                add_action('wp_ajax_wallet_up_delete_link_option', [$this, 'handleDeleteLinkOption']);
            }, 10);

            add_action('admin_init', function () {
                $this->registerSettingsSections();
                $this->registerSettingsFields();

                if ($this->registry && method_exists($this->registry, 'initializeAdmin')) {
                    $this->registry->initializeAdmin();
                }
            });

            add_action('shutdown', [$this, 'cleanup']);

            add_action('admin_notices', [$this, 'maybeShowReviewPrompt']);
            add_action('wp_ajax_wallet_up_dismiss_review', [$this, 'handleDismissReview']);

        } catch (Exception $e) {
            $this->errorHandler->log('Failed to initialize admin hooks: ' . $e->getMessage());
            throw $e;
        }
    }

    public function loadTextDomain(): void
    {
        $domain = 'walletup';
        $locale = apply_filters('plugin_locale', determine_locale(), $domain); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter.

        load_textdomain(
            $domain,
            WALLET_UP_PATH . 'languages/' . $domain . '-' . $locale . '.mo'
        );

        load_textdomain(
            $domain,
            WP_LANG_DIR . '/plugins/' . $domain . '-' . $locale . '.mo'
        );
    }

    /**
     * Cleanup resources on shutdown
     * 
     * @return void
     */
    public function cleanup(): void
    {
        try {
            delete_transient('wallet_up_preview_data');
            delete_transient('wallet_up_business_card_data');

            $upload_dir = wp_upload_dir();
            $cache_dir = $upload_dir['basedir'] . '/wallet-up-cache';
            if (is_dir($cache_dir)) {
                array_map('unlink', glob("$cache_dir/*.*"));
            }

        } catch (Exception $e) {
            $this->errorHandler->log('Admin cleanup failed: ' . $e->getMessage());
        }
    }

    public function registerAdminMenu(): void
    {
        $capability = 'manage_options';

        if (!current_user_can($capability)) {
            return;
        }

        if (get_option('wallet_up_admin_v2', 'yes') === 'yes') {
            add_menu_page(
                __('Wallet Up', 'walletup'),
                __('Wallet Up', 'walletup'),
                $capability,
                'wallet-up',
                [$this, 'renderAdminPage'],
                'dashicons-bank',
                20
            );
            return;
        }

        add_menu_page(
            __('Wallet Up', 'walletup'),
            __('Wallet Up', 'walletup'),
            $capability,
            'wallet-up',
            [$this, 'renderAdminPage'],
            'dashicons-bank',
            20
        );
        $submenus = [
            [
                'parent' => 'wallet-up',
                'page_title' => __('Accounts', 'walletup'),
                'menu_title' => __('Accounts', 'walletup'),
                'capability' => $capability,
                'menu_slug' => 'wallet-up',
                'callback' => [$this, 'renderAdminPage']
            ],
            [
                'parent' => 'wallet-up',
                'page_title' => __('Payment Settings', 'walletup'),
                'menu_title' => __('Payment Settings', 'walletup'),
                'capability' => $capability,
                'menu_slug' => 'wallet-up-settings',
                'callback' => [$this, 'renderSettingsPage']
            ]
        ];

        foreach ($submenus as $submenu) {
            add_submenu_page(
                $submenu['parent'],
                $submenu['page_title'],
                $submenu['menu_title'],
                $submenu['capability'],
                $submenu['menu_slug'],
                $submenu['callback']
            );
        }
    }
    public function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        echo '<div class="wrap">';
        echo '<div id="wallet-up-admin-root" 
            data-initial-tab="accounts" 
            data-initial-section="payments" 
            data-from-settings="true">';
        echo '</div>';
        echo '</div>';
    }
    /**
     * Check if WooCommerce is active
     * 
     * @return bool True if WooCommerce is active, false otherwise
     */
    public function logFrontendError(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new Exception(esc_html('Invalid nonce'));
            }

            $error_message = isset($_POST['message']) ? sanitize_text_field(wp_unslash($_POST['message'])) : 'Unknown error';
            $this->errorHandler->log('Frontend error: ' . $error_message);

            wp_send_json_success(['message' => 'Error logged']);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    private function registerAjaxHandlers(): void
    {
        $handlers = [
            'wallet_up_save_settings' => 'handleSaveSettings',
            'wallet_up_get_analytics' => 'handleGetAnalytics',
            'wallet_up_delete_analytics' => 'handleDeleteAnalytics',
            'wallet_up_check_qr_status' => 'handleCheckQRStatus',
            'wallet_up_check_chart_status' => 'handleCheckChartStatus',
            'wallet_up_preview_shortcode' => 'processShortcodePreview',
            'wallet_up_download_preview' => 'handlePreviewDownload'
        ];

        foreach ($handlers as $action => $method) {
            add_action("wp_ajax_$action", [$this, $method]);
        }
        add_action('wp_ajax_wallet_up_save_business_card_data', [$this, 'handleSaveBusinessCardData']);
        add_action('wp_ajax_wallet_up_get_business_card_data', [$this, 'handleGetBusinessCardData']);
        add_action('wp_ajax_wallet_up_shorten_url', [$this, 'handleURLShortening']);

        add_action('wp_ajax_wallet_up_wc_get_analytics', [$this, 'handleGetWCAnalytics']);
        add_action('wp_ajax_wallet_up_toggle_admin_v2', [$this, 'handleToggleAdminV2']);
        add_action('wp_ajax_wallet_up_wc_method_status', [$this, 'handleWcMethodStatus']);
        add_action('wp_ajax_wallet_up_wc_quick_enable', [$this, 'handleWcQuickEnable']);
        add_action('wp_ajax_wallet_up_activity_counts', [$this, 'handleActivityCounts']);
        add_action('wp_ajax_wallet_up_mark_seen', [$this, 'handleMarkSeen']);
        add_action('wp_ajax_wallet_up_notifications', [$this, 'handleNotifications']);
        add_action('wp_ajax_wallet_up_notifications_read', [$this, 'handleNotificationsRead']);

        add_action('wp_ajax_wallet_up_log_error', [$this, 'logFrontendError']);

        add_action('wp_ajax_wallet_up_save_cashapp_api_settings', [$this, 'handleSaveCashAppAPISettings']);
        add_action('wp_ajax_wallet_up_get_cashapp_api_settings', [$this, 'handleGetCashAppAPISettings']);
        add_action('wp_ajax_wallet_up_cashapp_api_create_request', [$this, 'handleCashAppCreateRequest']);
        add_action('wp_ajax_nopriv_wallet_up_cashapp_api_create_request', [$this, 'handleCashAppCreateRequest']);
        add_action('wp_ajax_wallet_up_cashapp_api_capture', [$this, 'handleCashAppCapture']);
        add_action('wp_ajax_nopriv_wallet_up_cashapp_api_capture', [$this, 'handleCashAppCapture']);
        add_action('wp_ajax_wallet_up_cashapp_api_check_status', [$this, 'handleCashAppCheckStatus']);
        add_action('wp_ajax_nopriv_wallet_up_cashapp_api_check_status', [$this, 'handleCashAppCheckStatus']);

        add_action('wp_ajax_wallet_up_save_venmo_api_settings', [$this, 'handleSaveVenmoAPISettings']);
        add_action('wp_ajax_wallet_up_get_venmo_api_settings', [$this, 'handleGetVenmoAPISettings']);
        add_action('wp_ajax_wallet_up_venmo_client_token', [$this, 'handleVenmoClientToken']);
        add_action('wp_ajax_nopriv_wallet_up_venmo_client_token', [$this, 'handleVenmoClientToken']);
        add_action('wp_ajax_wallet_up_venmo_process_payment', [$this, 'handleVenmoProcessPayment']);
        add_action('wp_ajax_nopriv_wallet_up_venmo_process_payment', [$this, 'handleVenmoProcessPayment']);

        add_action('wp_ajax_wallet_up_wc_get_method_settings', [$this, 'handleGetWCMethodSettings']);
        add_action('wp_ajax_wallet_up_wc_save_method_settings', [$this, 'handleSaveWCMethodSettings']);
        add_action('wp_ajax_wallet_up_wc_get_display_mode', [$this, 'handleGetWCDisplayMode']);
        add_action('wp_ajax_wallet_up_wc_save_display_mode', [$this, 'handleSaveWCDisplayMode']);
        add_action('wp_ajax_wallet_up_wc_get_stripe_card_settings', [$this, 'handleGetStripeCardSettings']);
        add_action('wp_ajax_wallet_up_wc_save_stripe_card_settings', [$this, 'handleSaveStripeCardSettings']);
        if (class_exists('\\WalletUpPro\\Core\\Config\\AutomatedApiConfig')) {
            add_action('admin_init', ['\\WalletUpPro\\Core\\Config\\AutomatedApiConfig', 'migrateLegacyCardKeys']);
        }

        add_action('wp_ajax_wallet_up_wc_get_paypal_commerce_status', [$this, 'handleGetPayPalCommerceStatus']);
        add_action('wp_ajax_wallet_up_wc_save_paypal_commerce_status', [$this, 'handleSavePayPalCommerceStatus']);
        add_action('wp_ajax_wallet_up_wc_get_cashapp_api_status', [$this, 'handleGetCashAppApiStatus']);
        add_action('wp_ajax_wallet_up_wc_save_cashapp_api_status', [$this, 'handleSaveCashAppApiStatus']);
        add_action('wp_ajax_wallet_up_wc_get_venmo_api_status', [$this, 'handleGetVenmoApiStatus']);
        add_action('wp_ajax_wallet_up_wc_save_venmo_api_status', [$this, 'handleSaveVenmoApiStatus']);

        add_action('wp_ajax_wallet_up_test_paypal_commerce', [$this, 'handleTestPayPalCommerce']);
        add_action('wp_ajax_wallet_up_save_paypal_commerce_settings', [$this, 'handleSavePayPalCommerceSettings']);
        add_action('wp_ajax_wallet_up_get_paypal_commerce_settings', [$this, 'handleGetPayPalCommerceSettings']);
    }

    private function getAdminData(): array
    {
        $qrStatus = ChartService::check_qr_service();

        return [
            'nonce' => wp_create_nonce('wallet_up_admin_nonce'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'settings' => get_option('wallet_up_transfer_call', []),
            'displaySettings' => get_option('wallet_up_display_settings', []),
            'paths' => [
                'assets' => WALLET_UP_BASE_URL . 'src/',
            ],
            'qrExtensionsAvailable' => $qrStatus,
            'gdAvailable' => extension_loaded('gd'),
            'imagickAvailable' => extension_loaded('imagick'),
        ];
    }

    public function renderAdminPage(): void
    {

        if (!current_user_can('manage_options')) {
            $this->errorHandler->log('Access denied - insufficient capabilities');
            wp_die(esc_html__('Oops! Looks like you do not have sufficient permissions to access this page.', 'walletup'));
            return;
        }

        echo '<div class="wrap">';
        echo '<!-- WalletUpPro Admin Mount Point -->';
        $v2Attrs = '';
        if (get_option('wallet_up_admin_v2', 'yes') === 'yes') {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin page callback; WP core verifies page access.
            $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : 'wallet-up';
            $pageMap = [
                'wallet-up' => 'dashboard',
                'wallet-up-payments' => 'payments',
                'wallet-up-donations' => 'donations',
                'wallet-up-qr-studio' => 'qr-studio',
                'wallet-up-short-url' => 'short-url',
                'wallet-up-activity' => 'activity',
                'wallet-up-settings-v2' => 'settings',
            ];
            $initialPage = $pageMap[$page] ?? 'dashboard';
            $v2Attrs = sprintf(' data-admin-version="v2" data-initial-page="%s"', esc_attr($initialPage));
        }
        echo wp_kses_post('<div id="wallet-up-admin-root"' . $v2Attrs . '></div>');

        echo '<noscript>';
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__('Oops! For a better experience, JavaScript is required for the Wallet Up admin interface. Please enable Javascript.', 'walletup')
        );
        echo '</noscript>';

        echo '<div style="display:none;">';
        echo '<form method="post" action="options.php" id="wallet-up-settings-form">';
        settings_fields('wallet_up_transfer_settings');
        do_settings_sections('wallet-up');
        submit_button();
        echo '</form>';
        echo '</div>';

        echo '</div>';

    }

    public function handleSaveBusinessCardData(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new Exception(esc_html('Invalid security token'));
            }

            if (!current_user_can('manage_options')) {
                throw new Exception(esc_html('Permission denied'));
            }

            $raw_data = isset($_POST['data']) ? wp_unslash($_POST['data']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON/complex data; validated and sanitized in validateBusinessCardData below.
            if (empty($raw_data)) {
                throw new Exception(esc_html('No data provided'));
            }

            $data = json_decode($raw_data, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception(esc_html('Invalid JSON data provided'));
            }

            $validation_errors = $this->validateBusinessCardData($data);
            if (!empty($validation_errors)) {
                throw new Exception(esc_html(implode(', ', $validation_errors)));
            }

            $sanitized_data = $this->sanitizeBusinessCardData($data);

            $transient_key = 'wallet_up_business_card_save_' . get_current_user_id();
            set_transient($transient_key, $sanitized_data, 30);

            $saved = update_option('wallet_up_business_card_data', $sanitized_data);
            delete_transient($transient_key);

            wp_cache_delete('wallet_up_business_card_data');

            if ($saved) {
                wp_send_json_success([
                    'message' => 'Business card data saved successfully',
                    'data' => $sanitized_data
                ]);
            } else {
                $existing_data = get_option('wallet_up_business_card_data', []);
                if ($existing_data === $sanitized_data) {
                    wp_send_json_success([
                        'message' => 'Data unchanged',
                        'data' => $sanitized_data
                    ]);
                } else {
                    throw new Exception(esc_html('Failed to save business card data'));
                }
            }

        } catch (Exception $e) {
            $this->errorHandler->log('Business Card Save Error: ' . $e->getMessage());
            wp_send_json_error([
                'message' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
        }
    }
    private function sanitizeBusinessCardData(array $data): array
    {
        return [
            'name' => sanitize_text_field($data['name'] ?? ''),
            'email' => sanitize_email($data['email'] ?? ''),
            'phone' => sanitize_text_field($data['phone'] ?? ''),
            'company' => sanitize_text_field($data['company'] ?? ''),
            'title' => sanitize_text_field($data['title'] ?? ''),
            'website' => esc_url_raw($data['website'] ?? ''),
            'street' => sanitize_text_field($data['street'] ?? ''),
            'city' => sanitize_text_field($data['city'] ?? ''),
            'state' => sanitize_text_field($data['state'] ?? ''),
            'zipCode' => sanitize_text_field($data['zipCode'] ?? ''),
            'country' => sanitize_text_field($data['country'] ?? ''),
            'photo' => $data['photo'] ?? null // Base64 image data
        ];
    }
    private function validatePhoneNumber(string $phone): bool
    {
        return preg_match('/^(\+?\d{1,4}[\s-]?)?\(?([0-9]{3})\)?[-\s]?([0-9]{3})[-\s]?([0-9]{4})$/', $phone);
    }

    private function validateEmailAddress(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function validateBusinessCardData(array $data): array
    {
        $errors = [];

        if (empty($data['name'])) {
            $errors[] = 'Name is required';
        }

        if (empty($data['email']) || !$this->validateEmailAddress($data['email'])) {
            $errors[] = 'Valid email is required';
        }

        if (empty($data['phone']) || !$this->validatePhoneNumber($data['phone'])) {
            $errors[] = 'Valid phone number is required';
        }

        if (!empty($data['photo'])) {
            $photo_data = base64_decode(explode(',', $data['photo'])[1] ?? '');
            if (strlen($photo_data) > 5000000) { // 5MB limit
                $errors[] = 'Photo must be less than 5MB';
            }
        }

        return $errors;
    }
    public function handleGetBusinessCardData(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new Exception(esc_html('Invalid security token'));
            }

            if (!current_user_can('manage_options')) {
                throw new Exception(esc_html('Permission denied'));
            }

            $cached_data = wp_cache_get('wallet_up_business_card_data');
            if (false !== $cached_data) {
                wp_send_json_success(['data' => $cached_data]);
                return;
            }

            $data = get_option('wallet_up_business_card_data', []);

            wp_cache_set('wallet_up_business_card_data', $data);

            wp_send_json_success(['data' => $data]);

        } catch (Exception $e) {
            $this->errorHandler->log('Business Card Data Fetch Error: ' . $e->getMessage());
            wp_send_json_error([
                'message' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
        }
    }

    public function handleGenerateBusinessCard(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new Exception(esc_html('Invalid Card security token'));
            }

            if (!current_user_can('manage_options')) {
                throw new Exception(esc_html('Permission denied'));
            }

            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON string; decoded and validated in validateBusinessCardData below.
            $data = isset($_POST['data']) ? wp_unslash($_POST['data']) : '';
            $type = isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : '';
            $settings = [];
            if (isset($_POST['settings'])) {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON string; decoded and sanitized via array_walk_recursive below.
                $settings = json_decode(wp_unslash($_POST['settings']), true) ?: [];
                array_walk_recursive($settings, function(&$value) {
                    if (is_string($value)) {
                        $value = sanitize_text_field($value);
                    }
                });
            }

            if (empty($data)) {
                throw new Exception(esc_html('No data provided for business card'));
            }

            $contactData = json_decode($data, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception(esc_html('Invalid contact data format'));
            }

            if (empty($contactData['name']) || empty($contactData['email']) || empty($contactData['phone'])) {
                throw new Exception(esc_html('Missing required contact information'));
            }

            $photo = isset($contactData['photo']) ? $contactData['photo'] : null;
            unset($contactData['photo']); // Remove photo from contact data for QR generation

            switch ($type) {
                case 'vcard':
                    $businessCardService = BusinessCardService::getInstance();
                    $vCard = $businessCardService->generateVCard($contactData);

                    if ($this->isDownloadRequest()) {
                        header('Content-Type: text/vcard; charset=utf-8');
                        header('Content-Disposition: attachment; filename="' . $this->generateVCFFilename($contactData['name']) . '.vcf"');
                        header('Cache-Control: no-cache, no-store, must-revalidate');
                        header('Pragma: no-cache');
                        header('Expires: 0');
                        echo esc_html($vCard);
                        exit;
                    } else {
                        $savedData = array_merge($contactData, ['photo' => $photo]);
                        $saved = update_option('wallet_up_business_card_data', $savedData);

                        if (!$saved) {
                            $existing_data = get_option('wallet_up_business_card_data', []);
                            if ($existing_data !== $savedData) {
                                throw new Exception(esc_html('Failed to save business card data'));
                            }
                        }
                        $qrCode = $this->qrcodeservice->generateQR($vCard, array_merge([
                            'qrSize' => 'large',
                            'qrColor' => '#674FBF',
                            'qrBackground' => '#FFFFFF',
                            'qrBorder' => true,
                            'qrStyle' => 'square'
                        ], $settings));

                        wp_send_json_success([
                            'qr' => $qrCode,
                            'photo' => $photo // Return photo separately
                        ]);
                    }
                    break;

                default:
                    throw new Exception(esc_html('Invalid type provided'));
            }

        } catch (Exception $e) {
            $this->errorHandler->log('Business Card Generation Error: ' . $e->getMessage());
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    private function isDownloadRequest(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in calling AJAX handler (handleGenerateBusinessCard).
        return isset($_POST['download']) && sanitize_text_field(wp_unslash($_POST['download'] ?? '')) === 'true';
    }
    private function parseVCardData(string $data): array
    {
        $lines = explode("\n", $data);
        $parsed = [];

        foreach ($lines as $line) {
            if (preg_match('/^(FN|N|EMAIL|TEL|ADR|ORG|TITLE|URL):(.+)$/', $line, $matches)) {
                $key = strtolower($matches[1]);
                $value = trim($matches[2]);
                switch ($key) {
                    case 'fn':
                        $parsed['name'] = $value;
                        break;
                    case 'email':
                        $parsed['email'] = $value;
                        break;
                    case 'tel':
                        $parsed['phone'] = $value;
                        break;
                    case 'adr':
                        $parsed['address'] = str_replace(';;', '', $value);
                        break;
                    case 'org':
                        $parsed['company'] = $value;
                        break;
                    case 'title':
                        $parsed['title'] = $value;
                        break;
                    case 'url':
                        $parsed['website'] = $value;
                        break;
                }
            }
        }

        return $parsed;
    }
    private function generateVCard(string $data): void
    {
        $businessCardService = BusinessCardService::getInstance();
        $vCardData = $data;

        if (stripos($vCardData, 'BEGIN:VCARD') === false || stripos($vCardData, 'END:VCARD') === false) {
            throw new Exception(esc_html('Invalid vCard format provided'));
        }

        $filename = $this->generateVCFFilename('contact'); // You might want to parse the data for a name here
        header('Content-Type: text/vcard; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.vcf"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        echo esc_html($vCardData);
        exit;
    }

    private function generateVCFFilename(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9 ]/', '', $name); // Sanitize filename
        return str_replace(' ', '_', $name);
    }
    public function handleURLShortening(): void
    {
        try {
            $this->errorHandler->log('URL shortening request received');

            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new Exception(esc_html('Invalid security token'));
            }

            $url = isset($_POST['url']) ? sanitize_url(wp_unslash($_POST['url'])) : '';
            if (empty($url)) {
                throw new Exception(esc_html('URL is required'));
            }

            $this->errorHandler->log('Processing URL: ' . $url);

            $metadata = [];
            if (isset($_POST['metadata'])) {
                $metadata = json_decode(wp_unslash($_POST['metadata']), true) ?: []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON decoded; values sanitized via array_walk_recursive below.
                array_walk_recursive($metadata, function(&$value) {
                    if (is_string($value)) {
                        $value = sanitize_text_field($value);
                    }
                });
            }

            $options = [
                'expires_in' => 365 * 24 * 60 * 60, // 1 year expiration
                'metadata' => $metadata
            ];

            $shortUrl = $this->shortener->shortenURL($url, $options);

            wp_send_json_success([
                'shortUrl' => $shortUrl,
                'originalUrl' => $url
            ]);

        } catch (Exception $e) {
            $this->errorHandler->log('URL shortening error: ' . $e->getMessage());
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }
    public function processShortcodePreview(): void
    {
        try {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }

            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new Exception(esc_html('Invalid security token'));
            }

            if (!current_user_can('manage_options')) {
                throw new Exception(esc_html('Permission denied'));
            }

            $shortcode = isset($_POST['shortcode']) ? sanitize_text_field(wp_unslash($_POST['shortcode'])) : '';
            if (empty($shortcode)) {
                throw new Exception(esc_html('No shortcode provided'));
            }

            if (!preg_match('/\[([a-zA-Z0-9_]+)/', $shortcode, $matches)) {
                throw new Exception(esc_html('Invalid shortcode format'));
            }

            $methodId = $matches[1];
            $method = $this->registry->getMethod($methodId);

            $displaySettings = get_option('wallet_up_display_settings', [
                'defaultMode' => 'qr',
                'qrSize' => 'medium',
                'qrColor' => '#674FBF',
                'qrBackground' => '#FFFFFF',
                'qrBorder' => true,
                'qrStyle' => 'square',
                'showDefaultMessage' => true,
                'customMessage' => '',
                'hideMessage' => false
            ]);

            if (!$method) {
                if ($methodId === 'wallet_up') {
                    $attributes = $this->parseShortcodeAttributes($shortcode);
                    if (!isset($attributes['scan'])) {
                        $attributes['scan'] = $displaySettings['defaultMode'] === 'qr' ? 'yes' :
                            ($displaySettings['defaultMode'] === 'link' ? 'link' : 'no');
                    }
                    $output = $this->registry->handleGenericShortcode(
                        array_merge($attributes, ['display_settings' => $displaySettings])
                    );
                } else {
                    $known = \WalletUpPro\Core\Config\PaymentMethodsConfig::getAllMethodIds();
                    $isKnownProOnly = in_array($methodId, $known, true)
                        && !in_array($methodId, \WalletUpPro\Core\Config\PaymentMethodsConfig::FREE_METHOD_IDS, true);
                    if ($isKnownProOnly) {
                        $name = \WalletUpPro\Core\Config\PaymentMethodsConfig::getMethodName($methodId);
                        throw new Exception(esc_html(sprintf(
                            /* translators: 1: payment method name, 2: Pro product name */
                            __('%1$s requires %2$s. The free version supports Cash App, PayPal, and Venmo.', 'walletup'),
                            $name,
                            WALLET_UP_PRO_PRODUCT_NAME
                        )));
                    }
                    throw new Exception(esc_html(sprintf(
                        __('Payment method "%s" is not available. Configure it under Wallet Up → Payments first.', 'walletup'),
                        $methodId
                    )));
                }
            } else {
                $attributes = $this->parseShortcodeAttributes($shortcode);

                if (!isset($attributes['scan'])) {
                    $attributes['scan'] = $displaySettings['defaultMode'] === 'qr' ? 'yes' :
                        ($displaySettings['defaultMode'] === 'link' ? 'link' : 'no');
                }

                $processedAttributes = array_merge(
                    $attributes,
                    ['display_settings' => $displaySettings]
                );

                $output = $method->processShortcode($processedAttributes);
            }

            if (empty($output)) {
                throw new Exception(esc_html('No output generated from shortcode'));
            }

            wp_send_json_success([
                'html' => $output,
                'meta' => [
                    'method' => $methodId,
                    'timestamp' => current_time('mysql')
                ]
            ]);

        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    private function parseShortcodeAttributes(string $shortcode): array
    {
        try {
            $this->errorHandler->log('Parsing shortcode attributes for: ' . $shortcode);

            $displaySettings = get_option('wallet_up_display_settings', [
                'defaultMode' => 'qr'
            ]);

            $pattern = '/(\w+)=(["\'])((?:(?!\2).)*)\2|(\w+)=([^\s\]]*)/';
            $attributes = [];

            $attributes['scan'] = $displaySettings['defaultMode'] === 'qr' ? 'yes' :
                ($displaySettings['defaultMode'] === 'link' ? 'link' : 'no');

            if (preg_match_all($pattern, $shortcode, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    if (!empty($match[1])) {
                        $key = $match[1];
                        $value = $match[3];
                        $attributes[$key] = $value;
                    } elseif (!empty($match[4])) {
                        $key = $match[4];
                        $value = $match[5];
                        $attributes[$key] = $value;
                    }
                }

                if (isset($attributes['note'])) {
                    $attributes['note'] = html_entity_decode($attributes['note']);
                }
            }

            foreach ($attributes as $key => &$value) {
                if ($key !== 'note') {
                    $value = str_replace(['\\"', "\\'"], '', $value);
                    $value = stripslashes($value);
                    $value = trim($value);
                }
            }

            return $attributes;

        } catch (Exception $e) {
            $this->errorHandler->log('Attribute parsing error: ' . $e->getMessage());
            return [
                'scan' => $displaySettings['defaultMode'] === 'qr' ? 'yes' :
                    ($displaySettings['defaultMode'] === 'link' ? 'link' : 'no')
            ];
        }
    }

    public function handlePreviewDownload()
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new Exception(esc_html('Invalid Download Preview security token'));
            }

            if (!current_user_can('manage_options')) {
                throw new Exception(esc_html('Permission denied'));
            }

            $html = isset($_POST['html']) ? wp_kses_post(wp_unslash($_POST['html'])) : '';
            if (empty($html)) {
                throw new Exception(esc_html('No content provided'));
            }

            if (extension_loaded('gd')) {
                $image = $this->createImageFromHtml($html);

                header('Content-Type: image/png');
                header('Content-Disposition: attachment; filename="shortcode-preview.png"');

                imagepng($image);
                imagedestroy($image);
                exit;
            } else {
                throw new Exception(esc_html('GD library not available'));
            }

        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    public function handleQRGeneration()
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new Exception(esc_html('Invalid QR Generation security token'));
            }

            if (!current_user_can('manage_options')) {
                throw new Exception(esc_html('Permission denied'));
            }

            $data = isset($_POST['data']) ? sanitize_textarea_field(wp_unslash($_POST['data'])) : '';
            $type = isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : '';

            if (empty($data)) {
                throw new Exception(esc_html('No data provided'));
            }

            $qrService = new QRCodeService();
            $qrImage = $qrService->generateQR($data, [
                'qrSize' => 300,
                'qrColor' => '#674FBF',
                'qrBackground' => '#FFFFFF',
                'qrBorder' => true,
                'qrStyle' => 'square'
            ]);

            wp_send_json_success([
                'qr' => $qrImage
            ]);

        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    private function createImageFromHtml($html)
    {
        $width = 800;
        $height = 400;
        $image = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $white);

        $black = imagecolorallocate($image, 0, 0, 0);
        $font = WALLET_UP_PATH . 'assets/fonts/OpenSans-Regular.ttf';

        $text = wp_strip_all_tags($html);
        $wrapped_text = wordwrap($text, 50, "\n", true);

        $font_size = 12;
        $lines = explode("\n", $wrapped_text);
        $y = 50;

        foreach ($lines as $line) {
            imagettftext($image, $font_size, 0, 50, $y, $black, $font, $line);
            $y += 20;
        }

        return $image;
    }

    public function addSettingsLink(array $links): array
    {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url('admin.php?page=wallet-up'),
            __('Settings', 'walletup')
        );
        array_unshift($links, $settings_link);
        return $links;
    }

    public function handleCheckChartStatus(): void
    {

        $chartService = new ChartService();
        $chartService->handleQRStatusCheck();
    }

    public function handleGetAnalytics(): void
    {
        $analyticsHandler = AnalyticsHandler::getInstance();
        $analyticsHandler->getAnalytics();
    }

    public function handleDeleteAnalytics()
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new Exception(esc_html('Invalid security token'));
            }

            if (!current_user_can('manage_options')) {
                throw new Exception(esc_html('Permission denied'));
            }

            $mode = sanitize_text_field(wp_unslash($_POST['mode'] ?? ''));
            $analyticsHandler = AnalyticsHandler::getInstance();

            if ($mode === 'all') {
                $result = $analyticsHandler->deleteAllAnalytics();
            } elseif ($mode === 'range') {
                $startDate = sanitize_text_field(wp_unslash($_POST['start_date'] ?? ''));
                $endDate = sanitize_text_field(wp_unslash($_POST['end_date'] ?? ''));

                if (!$startDate || !$endDate) {
                    throw new Exception(esc_html('Invalid date range provided'));
                }

                $result = $analyticsHandler->deleteAnalyticsByRange($startDate, $endDate);
            } else {
                throw new Exception(esc_html('Invalid deletion mode'));
            }

            if ($result) {
                wp_send_json_success([
                    'message' => 'Analytics data deleted successfully'
                ]);
            } else {
                throw new Exception(esc_html('Failed to delete analytics data'));
            }

        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    public function handleGetApiConnection(): void
    {
        check_ajax_referer('wallet_up_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(['message' => 'Permission denied']); }

        $isConnected = \WalletUpPro\Core\Payments\MerchantProvisioning::isProvisioned();
        $maskedKey = '';
        $maskedSecret = '';
        if ($isConnected) {
            $key = \WalletUpPro\Core\Payments\MerchantProvisioning::getApiKey();
            $maskedKey = $key ? substr($key, 0, 12) . '…' . substr($key, -4) : '';

            $encryption = new \WalletUpPro\Core\Security\DataEncryption();
            $encSecret = get_option('walletup_pay_api_secret', '');
            if (!empty($encSecret)) {
                try {
                    $secret = $encryption->decrypt($encSecret);
                    $maskedSecret = substr($secret, 0, 14) . '…' . substr($secret, -4);
                } catch (\Exception $e) {
                    $maskedSecret = '(stored)';
                }
            }
        }

        wp_send_json_success([
            'is_connected' => $isConnected,
            'masked_key' => $maskedKey,
            'masked_secret' => $maskedSecret,
            'merchant_name' => \WalletUpPro\Core\Utilities\SiteIdentity::asText((string) get_option('walletup_pay_merchant_name', '')),
            'mode' => self::effectiveMode(),
        ]);
    }

    public function handleSaveApiKey(): void
    {
        check_ajax_referer('wallet_up_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(['message' => 'Permission denied']); }

        $apiKey = isset($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : '';
        $apiSecret = isset($_POST['api_secret']) ? sanitize_text_field(wp_unslash($_POST['api_secret'])) : '';

        if (empty($apiKey) || strpos($apiKey, 'wup_') !== 0) {
            wp_send_json_error(['message' => 'Invalid API key format. Keys start with wup_live_ or wup_test_']);
            return;
        }

        $backendUrl = \WalletUpPro\Core\Payments\MerchantProvisioning::brokerUrl();
        $response = wp_remote_get($backendUrl . '/api/merchant/verify', [
            'headers' => ['X-API-Key' => $apiKey],
            'timeout' => 10,
            'sslverify' => true,
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => \WalletUpPro\Core\Payments\BackendErrors::forCustomer($response, 'admin backend check')]);
            return;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($body['success'])) {
            wp_send_json_error(['message' => $body['error']['message'] ?? 'API key verification failed (HTTP ' . $code . ')']);
            return;
        }

        $encryption = new \WalletUpPro\Core\Security\DataEncryption();
        update_option('walletup_pay_api_key', $encryption->encrypt($apiKey), false);
        update_option('walletup_pay_merchant_id', $body['merchant_id'] ?? '', false);
        update_option('walletup_pay_merchant_name', $body['merchant_name'] ?? '', false);

        if (!empty($apiSecret) && strpos($apiSecret, '…') === false) {
            update_option('walletup_pay_api_secret', $encryption->encrypt($apiSecret), false);
        }

        $maskedKey = substr($apiKey, 0, 12) . '…' . substr($apiKey, -4);
        $maskedSecret = '';
        if (!empty($apiSecret) && strpos($apiSecret, '…') === false) {
            $maskedSecret = substr($apiSecret, 0, 14) . '…' . substr($apiSecret, -4);
        }

        $webhookUrl = rest_url('walletup/v1/webhook/backend-notification');
        wp_remote_post($backendUrl . '/api/merchant/verify', [
            'headers' => ['X-API-Key' => $apiKey, 'Content-Type' => 'application/json'],
            'body' => wp_json_encode(['webhook_url' => $webhookUrl]),
            'timeout' => 5, 'sslverify' => true,
        ]);

        wp_send_json_success([
            'masked_key' => $maskedKey,
            'masked_secret' => $maskedSecret,
            'merchant_name' => \WalletUpPro\Core\Utilities\SiteIdentity::asText((string) ($body['merchant_name'] ?? '')),
        ]);
    }

    public function handleTestApiConnection(): void
    {
        check_ajax_referer('wallet_up_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(['message' => 'Permission denied']); }

        $apiKey = \WalletUpPro\Core\Payments\MerchantProvisioning::getApiKey();
        if (empty($apiKey)) {
            wp_send_json_error(['message' => 'No API key configured']);
            return;
        }

        $backendUrl = \WalletUpPro\Core\Payments\MerchantProvisioning::brokerUrl();
        $response = wp_remote_get($backendUrl . '/api/merchant/verify', [
            'headers' => ['X-API-Key' => $apiKey],
            'timeout' => 10,
            'sslverify' => true,
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => \WalletUpPro\Core\Payments\BackendErrors::forCustomer($response, 'admin connection test')]);
            return;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (wp_remote_retrieve_response_code($response) === 200 && !empty($body['success'])) {
            update_option('walletup_pay_merchant_name', $body['merchant_name'] ?? '', false);
            wp_send_json_success(['merchant_name' => \WalletUpPro\Core\Utilities\SiteIdentity::asText((string) ($body['merchant_name'] ?? ''))]);
        } else {
            wp_send_json_error(['message' => $body['error']['message'] ?? 'Verification failed']);
        }
    }

    public function handleDisconnectApi(): void
    {
        check_ajax_referer('wallet_up_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(['message' => 'Permission denied']); }

        delete_option('walletup_pay_api_key');
        delete_option('walletup_pay_api_secret');
        delete_option('walletup_pay_merchant_id');
        delete_option('walletup_pay_merchant_name');
        delete_option('walletup_pay_portal_url');

        wp_send_json_success();
    }

    public function handleToggleApiMode(): void
    {
        check_ajax_referer('wallet_up_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(['message' => 'Permission denied']); }

        $mode = isset($_POST['mode']) ? sanitize_text_field(wp_unslash($_POST['mode'])) : 'live';
        if (!in_array($mode, ['live', 'test'], true)) {
            wp_send_json_error(['message' => 'Invalid mode']);
            return;
        }

        update_option('walletup_pay_mode', $mode, false);

        if ($mode === 'test' && empty(get_option('walletup_pay_api_key_test'))) {
            $apiKey = \WalletUpPro\Core\Payments\MerchantProvisioning::getApiKey();
            if (!empty($apiKey)) {
                $backendUrl = \WalletUpPro\Core\Payments\MerchantProvisioning::brokerUrl();
                $response = wp_remote_post($backendUrl . '/api/merchant/test-keys', [
                    'headers' => ['X-API-Key' => $apiKey, 'Content-Type' => 'application/json'],
                    'timeout' => 10, 'sslverify' => true,
                ]);
                if (!is_wp_error($response)) {
                    $body = json_decode(wp_remote_retrieve_body($response), true);
                    if (!empty($body['success']) && !empty($body['data']['api_key_test'])) {
                        $encryption = new \WalletUpPro\Core\Security\DataEncryption();
                        update_option('walletup_pay_api_key_test', $encryption->encrypt($body['data']['api_key_test']), false);
                    }
                }
            }
        }

        wp_send_json_success(['mode' => $mode]);
    }

    private static function effectiveMode(): string
    {
        if (class_exists('\\WalletUpPro\\Core\\Config\\AutomatedApiConfig')) {
            return \WalletUpPro\Core\Config\AutomatedApiConfig::stripeTestMode() ? 'test' : 'live';
        }
        return get_option('walletup_pay_mode', 'test');
    }

    private static function brokerIsLocal(string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return true;
        }
        if (in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)) {
            return true;
        }
        return (bool) preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $host)
            || substr($host, -6) === '.local';
    }

    public function handleCheckConnectionHealth(): void
    {
        check_ajax_referer('wallet_up_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(['message' => 'Permission denied']); }

        $mode = self::effectiveMode();
        $backendUrl = \WalletUpPro\Core\Payments\MerchantProvisioning::brokerUrl();
        $brokerLocal = self::brokerIsLocal((string) (wp_parse_url($backendUrl, PHP_URL_HOST) ?: $backendUrl));
        $apiKey = \WalletUpPro\Core\Payments\MerchantProvisioning::getApiKey();

        if (empty($apiKey)) {
            wp_send_json_success(['status' => 'disconnected', 'mode' => $mode, 'broker_local' => $brokerLocal]);
            return;
        }

        $start = microtime(true);
        $response = wp_remote_get($backendUrl . '/api/merchant/verify', [
            'headers' => ['X-API-Key' => $apiKey],
            'timeout' => 5, 'sslverify' => true,
        ]);
        $latency = round((microtime(true) - $start) * 1000);

        if (is_wp_error($response)) {
            wp_send_json_success([
                'status' => 'error', 'mode' => $mode, 'latency_ms' => $latency,
                'broker_local' => $brokerLocal, 'error_detail' => $response->get_error_message(),
            ]);
            return;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200 && !empty($body['success'])) {
            $cardReady = false;
            $connectStatus = 'none';
            if (class_exists('\\WalletUpPro\\Core\\Payments\\StripeConnect')) {
                $connect = \WalletUpPro\Core\Payments\StripeConnect::getStatus();
                $cardReady = \WalletUpPro\Core\Payments\StripeConnect::isActive();
                if (!empty($connect['connected'])) {
                    $connectStatus = $cardReady
                        ? 'enabled'
                        : ((($connect['status'] ?? '') === 'pending_review' || !empty($connect['details_submitted'])) ? 'pending_review' : 'pending');
                }
            }
            wp_send_json_success([
                'status' => $latency > 2000 ? 'degraded' : 'healthy',
                'mode' => $mode,
                'latency_ms' => $latency,
                'broker_local' => $brokerLocal,
                'merchant_name' => \WalletUpPro\Core\Utilities\SiteIdentity::asText((string) ($body['merchant_name'] ?? get_option('walletup_pay_merchant_name', ''))),
                'card_ready' => $cardReady,
                'connect_status' => $connectStatus,
            ]);
        } else {
            wp_send_json_success([
                'status' => 'error', 'mode' => $mode, 'latency_ms' => $latency,
                'broker_local' => $brokerLocal,
                /* translators: %d is an HTTP status code. */
                'error_detail' => sprintf(__('Service responded with HTTP %d', 'walletup'), (int) $code),
            ]);
        }
    }

    public function handleSaveSettings(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new Exception(esc_html('Invalid HandleSave Settings security token'));
            }

            if (!current_user_can('manage_options')) {
                throw new Exception(esc_html('Permission denied'));
            }

            $raw_settings = isset($_POST['settings']) ? wp_unslash($_POST['settings']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON decoded and validated on next line.
            $settings = json_decode($raw_settings, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception(esc_html('Invalid JSON data provided'));
            }

            if (!is_array($settings)) {
                throw new Exception(esc_html('Settings must be an object'));
            }

            $account_settings = array_intersect_key($settings, array_flip(
                PaymentMethodsConfig::getAllHolderFields()
            ));

            foreach ($account_settings as $field => $value) {
                if (is_string($value) && substr($field, -7) === '_holder') {
                    $account_settings[$field] = PaymentMethodsConfig::normalizeHolder(
                        substr($field, 0, -7),
                        $value
                    );
                }
            }

            $display_settings = array_intersect_key($settings, array_flip([
                'defaultMode',
                'qrSize',
                'qrColor',
                'qrBackground',
                'qrBorder',
                'qrStyle',
                'showDefaultMessage',
                'customMessage',
                'hideMessage',
                'showMethodLogo',
                'showAccentBar',
                'showPoweredBy'
            ]));

            $sanitized_display = $this->sanitizeDisplaySettings($display_settings);
            $sanitized_account = $this->sanitizeSettings($account_settings);

            if (array_key_exists('zelle_name', $settings)) {
                update_option('wallet_up_zelle_name', sanitize_text_field((string) $settings['zelle_name']));
            }

            if (array_key_exists('zelle_hide_contact', $settings)) {
                update_option('wallet_up_zelle_hide_contact', filter_var($settings['zelle_hide_contact'], FILTER_VALIDATE_BOOLEAN));
            }

            $advancedKeys = [
                'test_mode', 'donation_currency', 'min_donation', 'max_donation',
                'recurring_enabled', 'recurring_intervals', 'email_receipts',
                'donor_anonymity', 'fee_passthrough', 'double_opt_in',
                'fraud_protection', 'rate_limit', 'webhook_url', 'webhook_secret',
            ];
            $advancedIncoming = array_intersect_key($settings, array_flip($advancedKeys));
            if (!empty($advancedIncoming)) {
                $existingAdvanced = get_option('wallet_up_donation_advanced', []);
                if (!is_array($existingAdvanced)) {
                    $existingAdvanced = [];
                }
                $sanitizedAdvanced = [];
                foreach ($advancedIncoming as $key => $value) {
                    if ($key === 'recurring_intervals' && is_array($value)) {
                        $sanitizedAdvanced[$key] = array_map(
                            fn($v) => filter_var($v, FILTER_VALIDATE_BOOLEAN),
                            $value
                        );
                    } elseif ($key === 'webhook_url') {
                        $sanitizedAdvanced[$key] = esc_url_raw((string) $value);
                    } else {
                        $sanitizedAdvanced[$key] = sanitize_text_field((string) $value);
                    }
                }
                update_option('wallet_up_donation_advanced', array_merge($existingAdvanced, $sanitizedAdvanced));
            }

            $mobile_money_settings = [];
            if (isset($settings['mobile_money']) && is_array($settings['mobile_money'])) {
                foreach ($settings['mobile_money'] as $provider_id => $provider_settings) {
                    if (!PaymentMethodsConfig::isMobileMoney($provider_id)) {
                        continue; // Skip invalid providers
                    }

                    $mobile_money_settings[$provider_id] = [
                        'enabled' => !empty($provider_settings['enabled']),
                        'merchant_phone' => sanitize_text_field($provider_settings['merchant_phone'] ?? ''),
                        'api_credentials' => []
                    ];

                    if (isset($provider_settings['api_credentials']) && is_array($provider_settings['api_credentials'])) {
                        foreach ($provider_settings['api_credentials'] as $key => $value) {
                            $mobile_money_settings[$provider_id]['api_credentials'][sanitize_key($key)] =
                                sanitize_text_field($value);
                        }
                    }
                }
            }

            $mobile_money_updated = false;
            if (!empty($mobile_money_settings)) {
                $mobile_money_updated = update_option('wallet_up_mobile_money_settings', $mobile_money_settings);
            }

            $display_updated = update_option('wallet_up_display_settings', $sanitized_display);
            $account_updated = update_option('wallet_up_transfer_call', $sanitized_account);

            $this->syncEnabledMethodsWithHolders($sanitized_account, $mobile_money_settings);

            $customQr = get_option('wallet_up_custom_qr_images', []);
            if (!is_array($customQr)) {
                $customQr = [];
            }
            $qrChanged = false;
            foreach ($customQr as $existingMethodId => $existingUrl) {
                if (!PaymentMethodsConfig::allowsCustomQr((string) $existingMethodId)) {
                    unset($customQr[$existingMethodId]);
                    $qrChanged = true;
                }
            }
            foreach ($settings as $settingKey => $settingVal) {
                if (!preg_match('/^(.+)_qr_image$/', (string) $settingKey, $qm)) {
                    continue;
                }
                $qrMethodId = $qm[1];
                if (!PaymentMethodsConfig::allowsCustomQr($qrMethodId)) {
                    continue;
                }
                $qrUrl = is_scalar($settingVal) ? esc_url_raw(trim((string) $settingVal)) : '';
                if ($qrUrl === '') {
                    if (isset($customQr[$qrMethodId])) {
                        unset($customQr[$qrMethodId]);
                        $qrChanged = true;
                    }
                } elseif (($customQr[$qrMethodId] ?? '') !== $qrUrl) {
                    $customQr[$qrMethodId] = $qrUrl;
                    $qrChanged = true;
                }
            }
            if ($qrChanged) {
                update_option('wallet_up_custom_qr_images', $customQr);
            }

            if (!$display_updated && !$account_updated) {
                wp_send_json_success([
                    'message' => 'Settings are already up to date',
                    'settings' => [
                        'account' => $sanitized_account,
                        'display' => $sanitized_display
                    ]
                ]);
                return;
            }

            wp_send_json_success([
                'message' => 'Settings saved successfully',
                'settings' => [
                    'account' => $sanitized_account,
                    'display' => $sanitized_display
                ]
            ]);

        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
        }
    }

    public function handleUploadZelleQr(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new Exception(esc_html('Invalid security token'));
            }
            if (!current_user_can('manage_options')) {
                throw new Exception(esc_html('Permission denied'));
            }

            $raw = isset($_POST['image']) ? wp_unslash($_POST['image']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- base64 image validated below.
            if (!preg_match('#^data:image/(png|jpeg);base64,#', $raw, $m)) {
                throw new Exception(esc_html('Unsupported image format'));
            }
            $ext = $m[1] === 'jpeg' ? 'jpg' : 'png';
            $data = base64_decode(substr($raw, strpos($raw, ',') + 1), true);
            if ($data === false || strlen($data) < 64) {
                throw new Exception(esc_html('Could not read the image'));
            }
            if (strlen($data) > 5 * 1024 * 1024) {
                throw new Exception(esc_html('Image is too large'));
            }
            $info = @getimagesizefromstring($data);
            if ($info === false || empty($info[0]) || empty($info[1])) {
                throw new Exception(esc_html('That file is not a valid image'));
            }

            if (!function_exists('wp_upload_bits')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            $filename = 'zelle-qr-' . gmdate('Ymd-His') . '.' . $ext;
            $upload = wp_upload_bits($filename, null, $data);
            if (!empty($upload['error'])) {
                throw new Exception(esc_html($upload['error']));
            }

            $attachment = [
                'post_mime_type' => $info['mime'] ?? ('image/' . $m[1]),
                'post_title'     => 'Zelle QR',
                'post_content'   => '',
                'post_status'    => 'inherit',
            ];
            $attachId = wp_insert_attachment($attachment, $upload['file']);
            if (!is_wp_error($attachId) && $attachId) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
                wp_update_attachment_metadata($attachId, wp_generate_attachment_metadata($attachId, $upload['file']));
            }

            wp_send_json_success(['url' => $upload['url']]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Reconcile wallet_up_enabled_methods with the accounts a merchant has filled in.
     * Traditional/crypto methods follow their holder in wallet_up_transfer_call; mobile
     * money follows its own per-provider flag and is only touched when this save included
     * mobile money data, so an unrelated save never disables it.
     *
     * @param array $account The holder values just written to wallet_up_transfer_call.
     * @param array $mobileMoney The mobile money providers included in this save (may be empty).
     */
    private function syncEnabledMethodsWithHolders(array $account, array $mobileMoney): void
    {
        $enabled = (array) get_option('wallet_up_enabled_methods', []);
        $before = $enabled;

        foreach (PaymentMethodsConfig::getAvailableMethodIds() as $id) {
            if (PaymentMethodsConfig::isMobileMoney($id)) {
                if (array_key_exists($id, $mobileMoney)) {
                    $enabled[$id] = !empty($mobileMoney[$id]['enabled'])
                        && trim((string) ($mobileMoney[$id]['merchant_phone'] ?? '')) !== '';
                }
                continue;
            }

            $hasHolder = trim((string) ($account[$id . '_holder'] ?? '')) !== '';
            $isOn = !empty($enabled[$id]) && $enabled[$id] !== 'false' && $enabled[$id] !== '0';

            if ($hasHolder && !$isOn) {
                $enabled[$id] = true;
            }
        }

        if ($enabled !== $before) {
            update_option('wallet_up_enabled_methods', $enabled);
        }
    }

    private function getAssetVersion(): string
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            return (string) time();
        }
        return WALLET_UP_PRO_VERSION;
    }

    private function getLocalizedData(): array
    {
        return [
            'nonce' => wp_create_nonce('wallet_up_admin_nonce'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'settings' => get_option('wallet_up_transfer_call', []),
            'displaySettings' => get_option('wallet_up_display_settings', []),
            'paths' => [
                'assets' => WALLET_UP_BASE_URL . 'assets/'
            ]
        ];
    }
    /**
     * Summary of displayAdminError
     * @param string $message
     * @return void
     */
    private function displayAdminError(string $message): void
    {
        add_action('admin_notices', function () use ($message) {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html('WalletUpPro: ' . $message)
            );
        });
    }

    public function handleCheckQRStatus(): void
    {
        try {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }

            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new \Exception(esc_html('Invalid security token'));
            }

            if (!current_user_can('manage_options')) {
                throw new \Exception(esc_html('Permission denied'));
            }

            $status = $this->checkQRStatus();

            if (!headers_sent()) {
                header('Content-Type: application/json');
                header('Cache-Control: no-cache, must-revalidate');
            }

            echo wp_json_encode([
                'success' => true,
                'data' => $status
            ]);
            exit;

        } catch (\Exception $e) {
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            echo wp_json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
            exit;
        }
    }

    private function checkQRStatus(): array
    {
        return ChartService::check_qr_service();
    }

    public function sanitizeSettings($input)
    {
        if (!is_array($input)) {
            return array();
        }

        $sanitized = array();
        $fields = PaymentMethodsConfig::getAllHolderFields();

        foreach ($fields as $field) {
            if (isset($input[$field])) {
                $sanitized[$field] = sanitize_text_field($input[$field]);
            }
        }

        return $sanitized;
    }

    public function sanitizeDisplaySettings($input)
    {
        if (!is_array($input)) {
            return array(
                'defaultMode' => 'qr',
                'qrSize' => 'medium',
                'qrColor' => '#674FBF',
                'qrBackground' => '#FFFFFF',
                'qrBorder' => true,
                'qrStyle' => 'square',
                'showDefaultMessage' => true,
                'customMessage' => '',
                'hideMessage' => false
            );
        }

        $sanitized = array();

        $sanitized['defaultMode'] = isset($input['defaultMode']) && in_array($input['defaultMode'], ['qr', 'button', 'link'])
            ? $input['defaultMode'] : 'qr';
        $sanitized['qrSize'] = isset($input['qrSize']) && in_array($input['qrSize'], ['small', 'medium', 'large'])
            ? $input['qrSize'] : 'medium';

        $sanitized['qrColor'] = isset($input['qrColor']) && preg_match('/^#[a-f0-9]{6}$/i', $input['qrColor'])
            ? $input['qrColor'] : '#674FBF';
        $sanitized['qrBackground'] = isset($input['qrBackground']) && preg_match('/^#[a-f0-9]{6}$/i', $input['qrBackground'])
            ? $input['qrBackground'] : '#FFFFFF';

        $sanitized['qrBorder'] = isset($input['qrBorder']) ? (bool) $input['qrBorder'] : true;
        $sanitized['showDefaultMessage'] = isset($input['showDefaultMessage']) ? (bool) $input['showDefaultMessage'] : true;
        $sanitized['hideMessage'] = isset($input['hideMessage']) ? (bool) $input['hideMessage'] : false;

        $sanitized['qrStyle'] = isset($input['qrStyle']) && in_array($input['qrStyle'], ['square', 'rounded', 'dot'])
            ? $input['qrStyle'] : 'square';
        $sanitized['customMessage'] = isset($input['customMessage']) ? sanitize_text_field($input['customMessage']) : '';

        $sanitized['showMethodLogo'] = isset($input['showMethodLogo']) ? (bool) $input['showMethodLogo'] : true;
        $sanitized['showAccentBar'] = isset($input['showAccentBar']) ? (bool) $input['showAccentBar'] : true;
        $sanitized['showPoweredBy'] = isset($input['showPoweredBy']) ? (bool) $input['showPoweredBy'] : true;

        return $sanitized;
    }
    public function registerSettings(): void
    {
        if (!is_admin()) {
            return;
        }

        try {
            if (!function_exists('register_setting')) {
                require_once(ABSPATH . 'wp-admin/includes/plugin.php');
            }

            register_setting(
                'wallet_up_transfer_settings',
                'wallet_up_transfer_call',
                [
                    'sanitize_callback' => [$this, 'sanitizeSettings'],
                    'show_in_rest' => true,
                    'default' => []
                ]
            );

            register_setting(
                'wallet_up_display_settings',
                'wallet_up_display_settings',
                [
                    'sanitize_callback' => [$this, 'sanitizeDisplaySettings'],
                    'show_in_rest' => true,
                    'default' => [
                        'defaultMode' => 'qr',
                        'qrSize' => 'medium',
                        'qrColor' => '#674FBF',
                        'qrBackground' => '#FFFFFF',
                        'qrBorder' => true,
                        'qrStyle' => 'square',
                        'showDefaultMessage' => true,
                        'customMessage' => '',
                        'hideMessage' => false
                    ]
                ]
            );

            add_action('admin_init', function () {
                $this->registerSettingsSections();
                $this->registerSettingsFields();
            });

        } catch (Exception $e) {
            $this->errorHandler->log('Failed to register settings: ' . $e->getMessage());
            throw $e;
        }
    }

    private function registerSettingsSections(): void
    {
        if (!is_admin()) {
            return;
        }

        if (!function_exists('add_settings_section')) {
            require_once(ABSPATH . 'wp-admin/includes/template.php');
        }

        try {
            add_settings_section(
                'wallet_up_profile_section',
                __('Profile Information', 'walletup'),
                [$this, 'renderProfileSection'],
                'wallet-up'
            );

            add_settings_section(
                'wallet_up_payment_section',
                __('Payment Methods', 'walletup'),
                [$this, 'renderPaymentSection'],
                'wallet-up'
            );

            add_settings_section(
                'wallet_up_display_section',
                __('Display Settings', 'walletup'),
                [$this, 'renderDisplaySection'],
                'wallet-up'
            );

        } catch (Exception $e) {
            $this->errorHandler->log('Failed to register settings sections: ' . $e->getMessage());
            throw $e;
        }
    }

    private function registerSettingsFields(): void
    {
        $profileFields = [
            'holder_owner' => __('Full Name', 'walletup'),
            'holder_email' => __('Email', 'walletup'),
            'holder_no' => __('Phone Number', 'walletup')
        ];

        foreach ($profileFields as $id => $label) {
            add_settings_field(
                $id,
                $label,
                [$this, 'renderProfileField'],
                'wallet-up',
                'wallet_up_profile_section',
                ['field' => $id]
            );
        }

        foreach (PaymentMethodsConfig::getPaymentHolderFields() as $fieldId) {
            $label = PaymentMethodsConfig::getFieldLabel($fieldId);
            add_settings_field(
                $fieldId,
                $label,
                [$this, 'renderPaymentField'],
                'wallet-up',
                'wallet_up_payment_section',
                ['field' => $fieldId]
            );
        }

        $displayFields = [
            'defaultMode' => __('Default Display Mode', 'walletup'),
            'qrSize' => __('QR Code Size', 'walletup'),
            'qrColor' => __('QR Code Color', 'walletup'),
            'qrBackground' => __('Background Color', 'walletup'),
            'qrBorder' => __('Show Border', 'walletup'),
            'qrStyle' => __('QR Style', 'walletup')
        ];

        foreach ($displayFields as $id => $label) {
            add_settings_field(
                $id,
                $label,
                [$this, 'renderDisplayField'],
                'wallet-up',
                'wallet_up_display_section',
                ['field' => $id]
            );
        }
    }

    public function renderProfileSection(): void
    {
        echo '<p class="description">' .
            esc_html__('Enter your profile information that will be used across payment methods.', 'walletup') .
            '</p>';
    }

    public function renderPaymentSection(): void
    {
        echo '<p class="description">' .
            esc_html__('Configure your payment service accounts.', 'walletup') .
            '</p>';
    }

    public function renderDisplaySection(): void
    {
        echo '<p class="description">' .
            esc_html__('Customize the appearance of payment methods on your site.', 'walletup') .
            '</p>';
    }

    public function renderProfileField($args): void
    {
        $field = $args['field'];
        $value = $this->getSettingValue($field);
        $type = $field === 'holder_email' ? 'email' : 'text';

        printf(
            '<input type="%s" id="%s" name="wallet_up_transfer_call[%s]" value="%s" class="regular-text">',
            esc_attr($type),
            esc_attr($field),
            esc_attr($field),
            esc_attr($value)
        );
    }

    public function renderPaymentField($args): void
    {
        $field = $args['field'];
        $value = $this->getSettingValue($field);

        printf(
            '<input type="text" id="%s" name="wallet_up_transfer_call[%s]" value="%s" class="regular-text">',
            esc_attr($field),
            esc_attr($field),
            esc_attr($value)
        );
    }

    public function renderDisplayField($args): void
    {
        $field = $args['field'];
        $value = $this->getDisplaySettingValue($field);

        switch ($field) {
            case 'defaultMode':
                $this->renderModeSelect($value);
                break;
            case 'qrSize':
                $this->renderSizeSelect($value);
                break;
            case 'qrColor':
            case 'qrBackground':
                $this->renderColorInput($field, $value);
                break;
            case 'qrBorder':
                $this->renderCheckbox($field, $value);
                break;
            case 'qrStyle':
                $this->renderStyleSelect($value);
                break;
        }
    }

    private function getSettingValue(string $field): string
    {
        $settings = get_option('wallet_up_transfer_call', []);
        return $settings[$field] ?? '';
    }

    private function getDisplaySettingValue(string $field)
    {
        $settings = get_option('wallet_up_display_settings', []);
        return $settings[$field] ?? '';
    }

    public function handleWcMethodStatus(): void
    {
        if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => esc_html__('Invalid security token', 'walletup')]);
        }
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => esc_html__('Permission denied', 'walletup')]);
        }
        if (!class_exists('WooCommerce')) {
            wp_send_json_success(['wc_active' => false, 'gateway_enabled' => false, 'enabled' => []]);
        }
        $s = get_option('woocommerce_wallet_up_settings', []);
        $gatewayEnabled = is_array($s) && ($s['enabled'] ?? 'no') === 'yes';
        $enabled = [];
        if ($this->registry && method_exists($this->registry, 'getAllMethods')) {
            foreach (array_keys($this->registry->getAllMethods()) as $id) {
                $enabled[$id] = $gatewayEnabled && is_array($s) && ($s["enable_{$id}"] ?? 'no') === 'yes';
            }
        }
        wp_send_json_success(['wc_active' => true, 'gateway_enabled' => $gatewayEnabled, 'enabled' => $enabled]);
    }

    /**
     * One-click publish of a single already-configured method to WooCommerce checkout: ensures the
     * WalletUp gateway is on and flips just that method's toggle. Scoped to the one method so it can
     * never disturb other checkout settings (unlike the full-blob save, which requires wcSettings).
     */
    public function handleWcQuickEnable(): void
    {
        if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => esc_html__('Invalid security token', 'walletup')]);
        }
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => esc_html__('Permission denied', 'walletup')]);
        }
        if (!class_exists('WooCommerce')) {
            wp_send_json_error(['message' => esc_html__('WooCommerce is not active', 'walletup'), 'wc_active' => false]);
        }
        $method = isset($_POST['method']) ? sanitize_key(wp_unslash($_POST['method'])) : '';
        if ($method === '') {
            wp_send_json_error(['message' => esc_html__('Missing method', 'walletup')]);
        }
        update_option("wallet_up_method_{$method}_enabled", 'yes');
        if ($this->registry && method_exists($this->registry, 'enableMethod')) {
            $this->registry->enableMethod($method);
        }
        $s = get_option('woocommerce_wallet_up_settings', []);
        if (!is_array($s)) {
            $s = [];
        }
        $s['enabled'] = 'yes';
        $s["enable_{$method}"] = 'yes';
        update_option('woocommerce_wallet_up_settings', $s);
        if (function_exists('WC') && WC()->payment_gateways()) {
            $gateways = WC()->payment_gateways()->payment_gateways();
            if (isset($gateways['wallet_up'])) {
                $gateways['wallet_up']->update_option('enabled', 'yes');
                $gateways['wallet_up']->update_option("enable_{$method}", 'yes');
            }
        }
        wp_send_json_success(['message' => esc_html__('Enabled at checkout', 'walletup'), 'method' => $method]);
    }

    public function handleActivityCounts(): void
    {
        if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => esc_html__('Invalid security token', 'walletup')]);
        }
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => esc_html__('Permission denied', 'walletup')]);
        }
        wp_send_json_success(\WalletUpPro\Core\Admin\AdminV2MenuRegistrar::activityCounts());
    }

    public function handleMarkSeen(): void
    {
        if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => esc_html__('Invalid security token', 'walletup')]);
        }
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => esc_html__('Permission denied', 'walletup')]);
        }
        $area = isset($_POST['area']) ? sanitize_key(wp_unslash($_POST['area'])) : '';
        \WalletUpPro\Core\Admin\AdminV2MenuRegistrar::markSeen($area);
        wp_send_json_success(['area' => $area]);
    }

    public function handleNotifications(): void
    {
        if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => esc_html__('Invalid security token', 'walletup')]);
        }
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => esc_html__('Permission denied', 'walletup')]);
        }
        wp_send_json_success(\WalletUpPro\Core\Admin\AdminV2MenuRegistrar::notifications());
    }

    public function handleNotificationsRead(): void
    {
        if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => esc_html__('Invalid security token', 'walletup')]);
        }
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => esc_html__('Permission denied', 'walletup')]);
        }
        \WalletUpPro\Core\Admin\AdminV2MenuRegistrar::markNotificationsRead();
        wp_send_json_success(['ok' => true]);
    }

    private function renderModeSelect($value)
    {
        ?>
        <select name="wallet_up_display_settings[defaultMode]">
            <option value="qr" <?php selected($value, 'qr'); ?>><?php esc_html_e('QR Code', 'walletup'); ?></option>
            <option value="button" <?php selected($value, 'button'); ?>><?php esc_html_e('Logo', 'walletup'); ?></option>
            <option value="link" <?php selected($value, 'link'); ?>><?php esc_html_e('Link', 'walletup'); ?></option>
        </select>
        <?php
    }

    private function renderSizeSelect($value)
    {
        ?>
        <select name="wallet_up_display_settings[qrSize]">
            <option value="small" <?php selected($value, 'small'); ?>><?php esc_html_e('Small (150x150)', 'walletup'); ?>
            </option>
            <option value="medium" <?php selected($value, 'medium'); ?>><?php esc_html_e('Medium (180x180)', 'walletup'); ?>
            </option>
            <option value="large" <?php selected($value, 'large'); ?>><?php esc_html_e('Large (250x250)', 'walletup'); ?>
            </option>
        </select>
        <?php
    }

    private function renderColorInput($field, $value)
    {
        ?>
        <input type="color" name="wallet_up_display_settings[<?php echo esc_attr($field); ?>]"
            value="<?php echo esc_attr($value); ?>">
        <?php
    }

    private function renderCheckbox($field, $value)
    {
        ?>
        <input type="checkbox" name="wallet_up_display_settings[<?php echo esc_attr($field); ?>]" value="1" <?php checked($value); ?>>
        <?php
    }

    private function renderStyleSelect($value)
    {
        ?>
        <select name="wallet_up_display_settings[qrStyle]">
            <option value="square" <?php selected($value, 'square'); ?>><?php esc_html_e('Square', 'walletup'); ?></option>
            <option value="rounded" <?php selected($value, 'rounded'); ?>><?php esc_html_e('Rounded', 'walletup'); ?></option>
            <option value="dot" <?php selected($value, 'dot'); ?>><?php esc_html_e('Dot', 'walletup'); ?></option>
        </select>
        <?php
    }

    public function handleGetShortcodeUsage(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new Exception(esc_html(__('Invalid security token', 'walletup')));
            }

            if (!current_user_can('manage_options')) {
                throw new Exception(esc_html(__('Insufficient permissions', 'walletup')));
            }

            $payment_methods = \WalletUpPro\Core\Config\PaymentMethodsConfig::getAllMethodIds();

            $usage_data = $this->scanShortcodeUsage($payment_methods);

            $analytics = $this->getShortcodeAnalytics($usage_data);

            $combined_data = $this->combineUsageAndAnalytics($usage_data, $analytics);

            wp_send_json_success([
                'usage' => $combined_data,
                'total' => count($combined_data),
                'summary' => $this->generateUsageSummary($combined_data)
            ]);

        } catch (Exception $e) {
            $this->errorHandler->log('Error getting shortcode usage: ' . $e->getMessage());
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    private function scanShortcodeUsage(array $payment_methods): array
    {
        $usage_data = [];

        $args = [
            'post_type' => ['post', 'page'],
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ];
        
        $query = new \WP_Query($args);
        
        if ($query->have_posts()) {
            foreach ($query->posts as $post_id) {
                $post = get_post($post_id);
                if (!$post) continue;
                
                foreach ($payment_methods as $method) {
                    $pattern = '/\[' . preg_quote($method, '/') . '(?:\s[^\]]+)?\]/';
                    
                    if (preg_match_all($pattern, $post->post_content, $matches)) {
                        foreach ($matches[0] as $shortcode) {
                            $usage_data[] = [
                                'id' => 'usage-' . $post_id . '-' . $method . '-' . md5($shortcode),
                                'page' => [
                                    'id' => $post_id,
                                    'title' => $post->post_title,
                                    'type' => $post->post_type,
                                    'editUrl' => get_edit_post_link($post_id, 'raw'),
                                    'viewUrl' => get_permalink($post_id)
                                ],
                                'shortcode' => $shortcode,
                                'method' => $method,
                                'status' => $this->checkShortcodeStatus($method),
                                'lastModified' => strtotime($post->post_modified) * 1000, // Convert to JS timestamp
                                'clicks' => 0 // Will be populated from analytics
                            ];
                        }
                    }
                }
            }
        }
        
        wp_reset_postdata();
        
        return $usage_data;
    }

    private function checkShortcodeStatus(string $method): string
    {
        $settings = get_option('wallet_up_transfer_call', []);
        $holder_key = $method . '_holder';
        
        if (empty($settings[$holder_key])) {
            return 'broken';
        }
        
        return 'active';
    }

    private function getShortcodeAnalytics(array $usage_data): array
    {
        if (empty($usage_data)) {
            return [];
        }

        global $wpdb;
        $analytics_table = $wpdb->prefix . 'wallet_up_analytics';
        
        $table_check_key = 'wallet_up_' . md5('table_exists_' . $analytics_table);
        $table_exists = wp_cache_get($table_check_key, 'wallet_up');
        if (false === $table_exists) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$analytics_table'") === $analytics_table;
            wp_cache_set($table_check_key, $table_exists, 'wallet_up', 3600);
        }

        if (!$table_exists) {
            return [];
        }

        $post_ids = array_unique(array_column(array_column($usage_data, 'page'), 'id'));

        if (empty($post_ids)) {
            return [];
        }

        $placeholders = implode(',', array_map('intval', $post_ids));

        $sc_cache_key = 'wallet_up_' . md5('shortcode_analytics_' . $placeholders);
        $cached_analytics = wp_cache_get($sc_cache_key, 'wallet_up');
        if (false !== $cached_analytics) {
            return $cached_analytics;
        }

        $query = "SELECT post_id, payment_method, COUNT(*) as clicks
                 // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
                 FROM $analytics_table
                 WHERE post_id IN ($placeholders)
                 AND event_type = 'click'
                 GROUP BY post_id, payment_method";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Results cached at method level via wp_cache_set.
        $results = $wpdb->get_results($query, ARRAY_A);

        $orphan_query = "SELECT payment_method, COUNT(*) as clicks
                        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
                        FROM $analytics_table
                        WHERE post_id = 0
                        AND event_type = 'click'
                        GROUP BY payment_method";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Results cached at method level via wp_cache_set.
        $orphan_results = $wpdb->get_results($orphan_query, ARRAY_A);
        
        
        $analytics = [];
        foreach ($results as $row) {
            $key = $row['post_id'] . '-' . $row['payment_method'];
            $analytics[$key] = (int) $row['clicks'];
        }
        
        $orphan_clicks = [];
        foreach ($orphan_results as $row) {
            $orphan_clicks[$row['payment_method']] = (int) $row['clicks'];
        }

        if (!empty($orphan_clicks) && empty($analytics)) {
            $method_counts = [];
            foreach ($usage_data as $usage) {
                $method = $usage['method'];
                if (!isset($method_counts[$method])) {
                    $method_counts[$method] = 0;
                }
                $method_counts[$method]++;
            }

            foreach ($usage_data as $usage) {
                $method = $usage['method'];
                $post_id = $usage['page']['id'];
                $key = $post_id . '-' . $method;

                if (isset($orphan_clicks[$method]) && $method_counts[$method] > 0) {
                    $analytics[$key] = intval($orphan_clicks[$method] / $method_counts[$method]);
                }
            }
        }
        
        wp_cache_set($sc_cache_key, $analytics, 'wallet_up', 300);
        return $analytics;
    }

    private function combineUsageAndAnalytics(array $usage_data, array $analytics): array
    {
        foreach ($usage_data as &$usage) {
            $key = $usage['page']['id'] . '-' . $usage['method'];
            $usage['clicks'] = $analytics[$key] ?? 0;
        }
        
        return $usage_data;
    }

    private function generateUsageSummary(array $usage_data): array
    {
        $summary = [
            'total' => count($usage_data),
            'active' => 0,
            'broken' => 0,
            'byMethod' => [],
            'totalClicks' => 0
        ];
        
        foreach ($usage_data as $usage) {
            if ($usage['status'] === 'active') {
                $summary['active']++;
            } else if ($usage['status'] === 'broken') {
                $summary['broken']++;
            }

            if (!isset($summary['byMethod'][$usage['method']])) {
                $summary['byMethod'][$usage['method']] = 0;
            }
            $summary['byMethod'][$usage['method']]++;

            $summary['totalClicks'] += $usage['clicks'];
        }
        
        return $summary;
    }
    
    public function handleGetShortcodeUsageNoPriv(): void
    {
        wp_send_json_error([
            'message' => __('You must be logged in to access this feature', 'walletup')
        ], 403);
    }

    /**
     * Add WalletUp menu to admin bar - Secure implementation following WP 2025 standards
     * 
     * @param \WP_Admin_Bar $wp_admin_bar WordPress admin bar object
     * @return void
     */
    public function addAdminBarMenu(\WP_Admin_Bar $wp_admin_bar): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $current_user = wp_get_current_user();
        
        $settings = get_option('wallet_up_transfer_call', []);
        $has_payment_methods = false;
        $payment_methods = PaymentMethodsConfig::PAYMENT_METHODS;
        
        foreach ($payment_methods as $method_id => $method) {
            if (!empty($settings[$method_id . '_holder'])) {
                $has_payment_methods = true;
                break;
            }
        }

        $is_v2 = get_option('wallet_up_admin_v2', 'yes') === 'yes';
        $dashboard_slug = 'wallet-up';
        $payments_slug  = $is_v2 ? 'wallet-up-payments' : 'wallet-up-settings';
        $donations_slug = 'wallet-up-donations';
        $analytics_slug = $is_v2 ? 'wallet-up-activity' : 'wallet-up-qr-analytics';

        $wp_admin_bar->add_node([
            'id'    => 'walletup',
            'title' => '<span class="ab-icon dashicons dashicons-bank"></span><span class="ab-label">WalletUp</span>',
            'href'  => esc_url(admin_url('admin.php?page=' . $dashboard_slug)),
            'meta'  => [
                'class' => 'walletup-admin-bar-menu'
            ]
        ]);

        // Free version: the main WalletUp icon already links to the dashboard; a two-item
        $hasV2Pages = class_exists('\\WalletUpPro\\Core\\Admin\\AdminV2MenuRegistrar');
        $items = [];

        if ($hasV2Pages) {
            $items[] = ['wallet-up-bar-dashboard', $dashboard_slug, __('Dashboard', 'walletup')];
            $items[] = ['wallet-up-bar-payments', $payments_slug, __('Payments', 'walletup')];
            if ($has_payment_methods) {
                $items[] = ['wallet-up-bar-donations', $donations_slug, __('Donations', 'walletup')];
            }
            $items[] = ['wallet-up-bar-analytics', $analytics_slug, __('Analytics', 'walletup')];
            if ($is_v2) {
                $items[] = ['wallet-up-bar-settings', 'wallet-up-settings-v2', __('Settings', 'walletup')];
            }
        }

        foreach ($items as [$id, $slug, $label]) {
            $wp_admin_bar->add_node([
                'id'     => $id,
                'parent' => 'walletup',
                'title'  => esc_html($label),
                'href'   => esc_url(admin_url('admin.php?page=' . $slug)),
            ]);
        }
    }

    /**
     * Enqueue admin bar specific styles and scripts
     * 
     * @return void
     */
    public function enqueueAdminBarAssets(): void
    {
        if (!is_admin_bar_showing() || !current_user_can('manage_options')) {
            return;
        }

        $custom_css = '
                font-family: dashicons !important;
                font-size: 20px !important;
                width: 20px !important;
                margin-right: 6px !important;
                color: #a7aaad !important;
                vertical-align: middle !important;
            }
                color: #72aee6 !important;
            }
                font-weight: 400 !important;
            }
                min-width: 160px !important;
            }
                padding: 0 12px !important;
                line-height: 28px !important;
                height: 28px !important;
                font-size: 13px !important;
                color: #c3c4c7 !important;
            }
                background: #32373c !important;
                color: #72aee6 !important;
            }
            @media screen and (max-width: 782px) {
                    font-size: 24px !important;
                    line-height: 46px !important;
                    height: 46px !important;
                    margin-right: 0 !important;
                }
                    display: none !important;
                }
                    line-height: 44px !important;
                    height: 44px !important;
                }
            }
        ';

        wp_add_inline_style('admin-bar', $custom_css);
        
        $inline_js = '
            (function() {
                document.addEventListener("DOMContentLoaded", function() {
                    const walletUpMenu = document.getElementById("wp-admin-bar-wallet-up-pro");
                    if (walletUpMenu) {
                        walletUpMenu.setAttribute("role", "navigation");
                        walletUpMenu.setAttribute("aria-label", "WalletUp Quick Access Menu");
                    }
                });
            })();
        ';
        
        wp_add_inline_script('admin-bar', $inline_js);
    }

    public function ensureAdminBarForAdmins($show): bool
    {
        if (current_user_can('manage_options')) {
            return true;
        }

        return $show;
    }

    public function handleGetWCAnalytics(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new Exception(esc_html('Invalid security token'));
            }

            if (!current_user_can('manage_options')) {
                throw new Exception(esc_html('Permission denied'));
            }

            if (!class_exists('WooCommerce')) {
                throw new Exception(esc_html('WooCommerce is not active'));
            }

            $startDate = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : gmdate('Y-m-d', strtotime('-30 days'));
            $endDate = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : gmdate('Y-m-d');

            global $wpdb;
            $ordersTable = $wpdb->prefix . 'wc_orders';
            $useHpos = class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
                && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

            $start = $startDate . ' 00:00:00';
            $end = $endDate . ' 23:59:59';
            if ($useHpos) {
                $ordersMetaTable = $wpdb->prefix . 'wc_orders_meta';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT o.total_amount AS total, REPLACE(o.status, 'wc-', '') AS status,
                            DATE(o.date_created_gmt) AS day, o.payment_method AS gateway, m.meta_value AS wu_method
                     FROM {$ordersTable} o
                     LEFT JOIN {$ordersMetaTable} m ON m.order_id = o.id AND m.meta_key = '_wallet_up_payment_method'
                     WHERE o.payment_method LIKE %s AND o.type = 'shop_order'
                       AND o.status NOT IN ('wc-checkout-draft','auto-draft','wc-failed','wc-trash','trash')
                       AND o.date_created_gmt BETWEEN %s AND %s",
                    'wallet_up%', $start, $end
                ), ARRAY_A);
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT CAST(COALESCE(tot.meta_value, '0') AS DECIMAL(20,2)) AS total,
                            REPLACE(p.post_status, 'wc-', '') AS status, DATE(p.post_date) AS day,
                            gw.meta_value AS gateway, wm.meta_value AS wu_method
                     FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->postmeta} gw ON gw.post_id = p.ID AND gw.meta_key = '_payment_method' AND gw.meta_value LIKE %s
                     LEFT JOIN {$wpdb->postmeta} tot ON tot.post_id = p.ID AND tot.meta_key = '_order_total'
                     LEFT JOIN {$wpdb->postmeta} wm ON wm.post_id = p.ID AND wm.meta_key = '_wallet_up_payment_method'
                     WHERE p.post_type = 'shop_order'
                       AND p.post_status NOT IN ('wc-checkout-draft','auto-draft','wc-failed','wc-trash','trash')
                       AND p.post_date BETWEEN %s AND %s",
                    'wallet_up%', $start, $end
                ), ARRAY_A);
            }
            $rows = is_array($rows) ? $rows : [];

            $totalRevenue = 0;
            $completedRevenue = 0;
            $totalOrders = count($rows);
            $completedOrders = 0;
            $statusCounts = [];
            $dailyData = [];
            $methodStats = [];

            foreach ($rows as $row) {
                $amount = (float) $row['total'];
                $status = (string) $row['status'];
                $day = (string) $row['day'];

                $walletupMethod = (string) ($row['wu_method'] ?? '');
                if ($walletupMethod === '') {
                    $gatewayId = (string) ($row['gateway'] ?? '');
                    $walletupMethod = str_starts_with($gatewayId, 'wallet_up_') ? substr($gatewayId, 10) : 'unknown';
                }
                $methodName = \WalletUpPro\Core\Config\PaymentMethodsConfig::getMethodName($walletupMethod) ?: ucfirst(str_replace('_', ' ', $walletupMethod));

                if (in_array($status, ['completed', 'processing'], true)) {
                    $completedRevenue += $amount;
                    $completedOrders++;
                }
                $totalRevenue += $amount;

                $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;

                if (!isset($dailyData[$day])) {
                    $dailyData[$day] = ['orders' => 0, 'revenue' => 0];
                }
                $dailyData[$day]['orders']++;
                $dailyData[$day]['revenue'] += $amount;

                if (!isset($methodStats[$walletupMethod])) {
                    $methodStats[$walletupMethod] = [
                        'id' => $walletupMethod,
                        'name' => $methodName,
                        'orders' => 0,
                        'completed' => 0,
                        'revenue' => 0,
                    ];
                }
                $methodStats[$walletupMethod]['orders']++;
                if (in_array($status, ['completed', 'processing'], true)) {
                    $methodStats[$walletupMethod]['completed']++;
                    $methodStats[$walletupMethod]['revenue'] += $amount;
                }
            }

            ksort($dailyData);
            $timeline = [];
            foreach ($dailyData as $date => $data) {
                $timeline[] = [
                    'date' => $date,
                    'orders' => $data['orders'],
                    'revenue' => round($data['revenue'], 2),
                ];
            }

            $statusBreakdown = [];
            foreach ($statusCounts as $status => $count) {
                $statusBreakdown[] = ['name' => ucfirst($status), 'value' => $count];
            }

            $methods = array_values($methodStats);
            usort($methods, function ($a, $b) {
                return $b['orders'] - $a['orders'];
            });
            foreach ($methods as &$m) {
                $m['revenue'] = round($m['revenue'], 2);
            }

            $completionRate = $totalOrders > 0 ? round(($completedOrders / $totalOrders) * 100, 1) : 0;

            wp_send_json_success([
                'total_orders'     => $totalOrders,
                'completed_orders' => $completedOrders,
                'completion_rate'  => $completionRate,
                'total_revenue'    => round($completedRevenue, 2),
                'average_order'    => $totalOrders > 0 ? round($totalRevenue / $totalOrders, 2) : 0,
                'currency'         => get_woocommerce_currency(),
                'methods'          => $methods,
                'status_breakdown' => $statusBreakdown,
                'timeline'         => $timeline,
            ]);

        } catch (Exception $e) {
            $this->errorHandler->log('WC Analytics Error: ' . $e->getMessage());
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function handleToggleAdminV2(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new Exception(esc_html('Invalid security token'));
            }
            if (!current_user_can('manage_options')) {
                throw new Exception(esc_html('Permission denied'));
            }

            $enabled = isset($_POST['enabled']) && sanitize_text_field(wp_unslash($_POST['enabled'] ?? '')) === 'yes' ? 'yes' : 'no';
            update_option('wallet_up_admin_v2', $enabled);

            wp_send_json_success(['enabled' => $enabled]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function handleSaveCashAppAPISettings(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new Exception(esc_html('Invalid security token'));
            }
            if (!current_user_can('manage_options')) {
                throw new Exception(esc_html('Permission denied'));
            }

            $credentials = [
                'client_id' => sanitize_text_field(wp_unslash($_POST['client_id'] ?? '')),
                'api_key' => sanitize_text_field(wp_unslash($_POST['api_key'] ?? '')),
                'environment' => sanitize_text_field(wp_unslash($_POST['environment'] ?? 'sandbox')),
                'enabled' => !empty($_POST['enabled']) && sanitize_text_field(wp_unslash($_POST['enabled'] ?? '')) !== 'false',
            ];

            if (strpos($credentials['api_key'], '...') !== false) {
                $existing = get_option('wallet_up_cashapp_api_settings', []);
                $credentials['api_key'] = $existing['api_key'] ?? '';
            }

            \WalletUpPro\Payments\CashAppPayAPI::saveCredentials($credentials);

            wp_send_json_success([
                'message' => 'Cash App Pay API settings saved',
                'settings' => \WalletUpPro\Payments\CashAppPayAPI::getCredentials(),
            ]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function handleGetCashAppAPISettings(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new Exception(esc_html('Invalid security token'));
            }
            if (!current_user_can('manage_options')) {
                throw new Exception(esc_html('Permission denied'));
            }

            wp_send_json_success([
                'settings' => \WalletUpPro\Payments\CashAppPayAPI::getCredentials(),
            ]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function handleCashAppCreateRequest(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_cashapp_nonce', 'nonce', false)) {
                throw new Exception(esc_html('Invalid security token'));
            }

            $api = \WalletUpPro\Payments\CashAppPayAPI::getInstance();
            if (!$api->isConfigured()) {
                throw new Exception(esc_html('Cash App Pay API is not configured'));
            }

            // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer.
            $amount = floatval(wp_unslash($_POST['amount'] ?? 0));
            $currency = sanitize_text_field(wp_unslash($_POST['currency'] ?? 'USD'));
            $referenceId = sanitize_text_field(wp_unslash($_POST['reference_id'] ?? ''));
            $redirectUrl = sanitize_url(wp_unslash($_POST['redirect_url'] ?? home_url()));
            // phpcs:enable WordPress.Security.NonceVerification.Missing

            if ($amount <= 0) {
                throw new Exception(esc_html('Invalid amount'));
            }

            $request = $api->createCustomerRequest([
                'amount' => $amount,
                'currency' => $currency,
                'reference_id' => $referenceId,
                'redirect_url' => $redirectUrl,
            ]);

            wp_send_json_success($request);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    private function settleProviderLedger(string $paymentId, string $status, float $capturedAmount, string $capturedCurrency, array $data): void
    {
        if ($paymentId === '') {
            return;
        }
        $processor = \WalletUpPro\Services\PaymentProcessor::getInstance();
        if ($status !== 'completed') {
            $processor->updatePaymentStatus($paymentId, $status, $data);
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'walletup_payment_transactions';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Read on plugin-owned table.
        $row = $wpdb->get_row($wpdb->prepare("SELECT amount, currency FROM {$table} WHERE payment_id = %s", $paymentId), ARRAY_A);
        if (!$row) {
            return;
        }
        $rowCurrency = strtolower((string) ($row['currency'] ?: $capturedCurrency));
        $expectedMinor = \WalletUpPro\Core\Payments\PaymentGatewayHandler::toMinorUnits((float) $row['amount'], $rowCurrency);
        $capturedMinor = \WalletUpPro\Core\Payments\PaymentGatewayHandler::toMinorUnits($capturedAmount, $capturedCurrency);
        if ($expectedMinor <= 0 || $capturedMinor < $expectedMinor || $rowCurrency !== strtolower($capturedCurrency)) {
            return;
        }
        $processor->updatePaymentStatus($paymentId, 'completed', $data);
    }

    public function handleCashAppCapture(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_cashapp_nonce', 'nonce', false)) {
                throw new Exception(esc_html('Invalid security token'));
            }

            $api = \WalletUpPro\Payments\CashAppPayAPI::getInstance();
            if (!$api->isConfigured()) {
                throw new Exception(esc_html('Cash App Pay API is not configured'));
            }

            // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer.
            $grantId = sanitize_text_field(wp_unslash($_POST['grant_id'] ?? ''));
            $amount = floatval(wp_unslash($_POST['amount'] ?? 0));
            $currency = sanitize_text_field(wp_unslash($_POST['currency'] ?? 'USD'));
            $referenceId = sanitize_text_field(wp_unslash($_POST['reference_id'] ?? ''));
            // phpcs:enable WordPress.Security.NonceVerification.Missing

            if (empty($grantId)) {
                throw new Exception(esc_html('Grant ID is required'));
            }

            $result = $api->capturePayment($grantId, [
                'amount' => $amount,
                'currency' => $currency,
                'reference_id' => $referenceId,
            ]);

            if (!empty($referenceId)) {
                $capStatus = strtoupper((string) ($result['status'] ?? ''));
                $ledgerStatus = in_array($capStatus, ['COMPLETED', 'APPROVED', 'CAPTURED', 'PAID'], true) ? 'completed' : 'pending';
                $this->settleProviderLedger(
                    $referenceId,
                    $ledgerStatus,
                    (float) ($result['amount'] ?? 0),
                    (string) ($result['currency'] ?? $currency),
                    [
                        'provider_transaction_id' => $result['payment_id'],
                        'payment_provider' => 'cashapp_api',
                        'payment_method' => 'cashapp',
                        'provider_response' => $result,
                    ]
                );
            }

            wp_send_json_success($result);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function handleCashAppCheckStatus(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_cashapp_nonce', 'nonce', false)) {
                throw new Exception(esc_html('Invalid security token'));
            }

            $api = \WalletUpPro\Payments\CashAppPayAPI::getInstance();
            if (!$api->isConfigured()) {
                throw new Exception(esc_html('Cash App Pay API is not configured'));
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer.
            $requestId = sanitize_text_field(wp_unslash($_POST['request_id'] ?? ''));
            if (empty($requestId)) {
                throw new Exception(esc_html('Request ID is required'));
            }

            $result = $api->getCustomerRequest($requestId);
            wp_send_json_success($result);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function handleSaveVenmoAPISettings(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new Exception(esc_html('Invalid security token'));
            }
            if (!current_user_can('manage_options')) {
                throw new Exception(esc_html('Permission denied'));
            }

            $credentials = [
                'merchant_id' => sanitize_text_field(wp_unslash($_POST['merchant_id'] ?? '')),
                'public_key' => sanitize_text_field(wp_unslash($_POST['public_key'] ?? '')),
                'private_key' => sanitize_text_field(wp_unslash($_POST['private_key'] ?? '')),
                'venmo_profile_id' => sanitize_text_field(wp_unslash($_POST['venmo_profile_id'] ?? '')),
                'environment' => sanitize_text_field(wp_unslash($_POST['environment'] ?? 'sandbox')),
                'enabled' => !empty($_POST['enabled']) && sanitize_text_field(wp_unslash($_POST['enabled'] ?? '')) !== 'false',
            ];

            $existing = get_option('wallet_up_venmo_api_settings', []);
            if (strpos($credentials['public_key'], '...') !== false) {
                $credentials['public_key'] = $existing['public_key'] ?? '';
            }
            if (strpos($credentials['private_key'], '...') !== false) {
                $credentials['private_key'] = $existing['private_key'] ?? '';
            }

            \WalletUpPro\Payments\VenmoBraintreeAPI::saveCredentials($credentials);

            wp_send_json_success([
                'message' => 'Venmo/Braintree API settings saved',
                'settings' => \WalletUpPro\Payments\VenmoBraintreeAPI::getCredentials(),
            ]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function handleGetVenmoAPISettings(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new Exception(esc_html('Invalid security token'));
            }
            if (!current_user_can('manage_options')) {
                throw new Exception(esc_html('Permission denied'));
            }

            wp_send_json_success([
                'settings' => \WalletUpPro\Payments\VenmoBraintreeAPI::getCredentials(),
            ]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function handleVenmoClientToken(): void
    {
        try {
            $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
            $rate_key = 'walletup_venmo_token_' . md5($ip);
            $attempts = (int) get_transient($rate_key);
            if ($attempts >= 10) {
                throw new Exception(esc_html('Too many requests. Please try again later.'));
            }
            set_transient($rate_key, $attempts + 1, 60);

            $api = \WalletUpPro\Payments\VenmoBraintreeAPI::getInstance();
            if (!$api->isConfigured()) {
                throw new Exception(esc_html('Venmo/Braintree API is not configured'));
            }

            $token = $api->generateClientToken();
            wp_send_json_success(['client_token' => $token]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function handleVenmoProcessPayment(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_venmo_nonce', 'nonce', false)) {
                throw new Exception(esc_html('Invalid security token'));
            }

            $api = \WalletUpPro\Payments\VenmoBraintreeAPI::getInstance();
            if (!$api->isConfigured()) {
                throw new Exception(esc_html('Venmo/Braintree API is not configured'));
            }

            // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer.
            $nonce = sanitize_text_field(wp_unslash($_POST['payment_nonce'] ?? ''));
            $amount = floatval(wp_unslash($_POST['amount'] ?? 0));
            $orderId = sanitize_text_field(wp_unslash($_POST['order_id'] ?? ''));
            $deviceData = sanitize_text_field(wp_unslash($_POST['device_data'] ?? ''));
            $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
            $firstName = sanitize_text_field(wp_unslash($_POST['first_name'] ?? ''));
            $lastName = sanitize_text_field(wp_unslash($_POST['last_name'] ?? ''));
            // phpcs:enable WordPress.Security.NonceVerification.Missing

            if (empty($nonce)) {
                throw new Exception(esc_html('Payment nonce is required'));
            }
            if ($amount <= 0) {
                throw new Exception(esc_html('Invalid amount'));
            }

            $result = $api->processPayment([
                'payment_nonce' => $nonce,
                'amount' => $amount,
                'order_id' => $orderId,
                'device_data' => $deviceData,
                'email' => $email,
                'first_name' => $firstName,
                'last_name' => $lastName,
            ]);

            if (!empty($orderId)) {
                $this->settleProviderLedger(
                    $orderId,
                    strtolower((string) ($result['status'] ?? '')) ?: 'pending',
                    (float) ($result['amount'] ?? 0),
                    (string) ($result['currency'] ?? 'USD'),
                    [
                        'provider_transaction_id' => $result['transaction_id'],
                        'payment_provider' => 'venmo_braintree',
                        'payment_method' => 'venmo',
                        'provider_response' => $result,
                    ]
                );
            }

            wp_send_json_success($result);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function handleGetWCMethodSettings(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new Exception(esc_html('Invalid security token'));
            }
            if (!current_user_can('manage_options')) {
                throw new Exception(esc_html('Insufficient permissions'));
            }

            $settings = \WalletUpPro\WooCommerce\WooCommerceMethodSettings::getAll();
            $displayMode = get_option('wallet_up_wc_display_mode', 'grouped');
            $mixedMethods = get_option('wallet_up_wc_mixed_methods', []);

            wp_send_json_success([
                'methodSettings' => $settings,
                'displayMode' => $displayMode,
                'mixedMethods' => $mixedMethods,
            ]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function handleSaveWCMethodSettings(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new Exception(esc_html('Invalid security token'));
            }
            if (!current_user_can('manage_options')) {
                throw new Exception(esc_html('Insufficient permissions'));
            }

            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON decoded below; individual fields sanitized by WooCommerceMethodSettings::saveAll()
            $raw = isset($_POST['settings']) ? wp_unslash($_POST['settings']) : '';
            $settings = json_decode($raw, true);

            if (!is_array($settings) || json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception(esc_html('Invalid settings data'));
            }

            \WalletUpPro\WooCommerce\WooCommerceMethodSettings::saveAll($settings);

            if (class_exists('\WalletUpPro\WooCommerce\WooCommerceConfig')) {
                \WalletUpPro\WooCommerce\WooCommerceConfig::getInstance()->clearCache();
            }

            wp_send_json_success(['message' => 'Method settings saved successfully']);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function handleGetWCDisplayMode(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new Exception(esc_html('Invalid security token'));
            }
            if (!current_user_can('manage_options')) {
                throw new Exception(esc_html('Insufficient permissions'));
            }

            $wcSettings = get_option('wallet_up_wc_settings', []);
            $isPro = function_exists('WalletUpPro\\wallet_up_fs') && \WalletUpPro\wallet_up_fs()->can_use_premium_code();

            wp_send_json_success([
                'displayMode' => get_option('wallet_up_wc_display_mode', 'grouped'),
                'mixedMethods' => get_option('wallet_up_wc_mixed_methods', []),
                'showDiscountInLabel' => !empty($wcSettings['show_discount_in_label']),
                'showOrderIdInInstructions' => !array_key_exists('show_order_id_in_instructions', $wcSettings)
                    || !empty($wcSettings['show_order_id_in_instructions']),
                'isPro' => $isPro,
            ]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function handleSaveWCDisplayMode(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new Exception(esc_html('Invalid security token'));
            }
            if (!current_user_can('manage_options')) {
                throw new Exception(esc_html('Insufficient permissions'));
            }

            $mode = sanitize_text_field(wp_unslash($_POST['display_mode'] ?? 'grouped'));
            if (!in_array($mode, ['grouped', 'individual', 'mixed'], true)) {
                $mode = 'grouped';
            }

            update_option('wallet_up_wc_display_mode', $mode);

            if ($mode === 'mixed' && isset($_POST['mixed_methods'])) {
                $raw = sanitize_text_field(wp_unslash($_POST['mixed_methods']));
                $mixedMethods = json_decode($raw, true);
                if (is_array($mixedMethods)) {
                    $validIds = \WalletUpPro\Core\Config\PaymentMethodsConfig::getAllMethodIds();
                    $sanitized = [
                        'individual' => array_values(array_intersect($mixedMethods['individual'] ?? [], $validIds)),
                    ];
                    update_option('wallet_up_wc_mixed_methods', $sanitized);
                }
            }

            if (isset($_POST['show_discount_in_label'])) {
                $isPro = function_exists('WalletUpPro\\wallet_up_fs') && \WalletUpPro\wallet_up_fs()->can_use_premium_code();
                $wcSettings = get_option('wallet_up_wc_settings', []);
                if (!is_array($wcSettings)) {
                    $wcSettings = [];
                }
                $wcSettings['show_discount_in_label'] = $isPro && !empty($_POST['show_discount_in_label']) && $_POST['show_discount_in_label'] !== 'false' && $_POST['show_discount_in_label'] !== '0';
                update_option('wallet_up_wc_settings', $wcSettings);
            }

            if (isset($_POST['show_order_id_in_instructions'])) {
                $isPro = function_exists('WalletUpPro\\wallet_up_fs') && \WalletUpPro\wallet_up_fs()->can_use_premium_code();
                $wcSettings = get_option('wallet_up_wc_settings', []);
                if (!is_array($wcSettings)) {
                    $wcSettings = [];
                }
                $wcSettings['show_order_id_in_instructions'] = $isPro && !empty($_POST['show_order_id_in_instructions']) && $_POST['show_order_id_in_instructions'] !== 'false' && $_POST['show_order_id_in_instructions'] !== '0';
                update_option('wallet_up_wc_settings', $wcSettings);
            }

            wp_send_json_success(['message' => 'Display mode saved successfully']);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function handleGetStripeCardSettings(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new \Exception(esc_html('Invalid security token'));
            }
            if (!current_user_can('manage_options')) {
                throw new \Exception(esc_html('Insufficient permissions'));
            }

            $settings = get_option('woocommerce_wallet_up_card_settings', []);

            $mainConfig = get_option('wallet_up_stripe_config', []);
            $mainTestConfigured = !empty($mainConfig['test_publishable_key']) && !empty($mainConfig['test_secret_key']);
            $mainLiveConfigured = !empty($mainConfig['live_publishable_key']) && !empty($mainConfig['live_secret_key']);
            $mainHasKeys = $mainTestConfigured || $mainLiveConfigured;
            $mainEnabled = !empty($mainConfig['enabled']);
            $mainTestMode = !empty($mainConfig['test_mode']);

            $modePair = class_exists('\\WalletUpPro\\Core\\Config\\AutomatedApiConfig')
                ? \WalletUpPro\Core\Config\AutomatedApiConfig::stripeKeyPair()
                : ['publishable' => '', 'secret' => ''];

            $connectClass = '\\WalletUpPro\\Core\\Payments\\StripeConnect';
            $connect = class_exists($connectClass) ? $connectClass::getStatus() : ['connected' => false];
            $connectConnected = !empty($connect['connected']);
            $connectChargesEnabled = !empty($connect['charges_enabled']);
            $connectActive = $connectConnected && $connectChargesEnabled && !empty($connect['account_id']);
            $connectMode = ($connectActive && class_exists($connectClass) && $connectClass::isLive()) ? 'live' : ($connectActive ? 'test' : '');

            wp_send_json_success([
                'enabled' => $settings['enabled'] ?? 'no',
                'test_mode' => (class_exists('\\WalletUpPro\\Core\\Config\\AutomatedApiConfig')
                    ? (\WalletUpPro\Core\Config\AutomatedApiConfig::stripeTestMode() ? 'yes' : 'no')
                    : ($settings['test_mode'] ?? 'yes')),
                'publishable_key' => $modePair['publishable'],
                'secret_key' => $modePair['secret'] !== '' ? '••••••••' : '',
                'webhook_secret' => !empty($settings['webhook_secret']) ? '••••••••' : '',
                'title' => $settings['title'] ?? 'Credit/Debit Card',
                'description' => $settings['description'] ?? '',
                'webhook_url' => rest_url('wallet-up-pro/v1/stripe/webhook'),
                'main_stripe_configured' => $mainHasKeys,
                'main_test_configured' => $mainTestConfigured,
                'main_live_configured' => $mainLiveConfigured,
                'main_stripe_enabled' => $mainEnabled,
                'main_stripe_test_mode' => $mainTestMode,
                'using_main_keys' => $mainHasKeys,
                'connect_active' => $connectActive,
                'connect_connected' => $connectConnected,
                'connect_charges_enabled' => $connectChargesEnabled,
                'connect_mode' => $connectMode,
            ]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function handleSaveStripeCardSettings(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new \Exception(esc_html('Invalid security token'));
            }
            if (!current_user_can('manage_options')) {
                throw new \Exception(esc_html('Insufficient permissions'));
            }

            $settings = get_option('woocommerce_wallet_up_card_settings', []);

            $incomingEnabled = sanitize_text_field(wp_unslash($_POST['enabled'] ?? 'no'));
            $incomingTestMode = sanitize_text_field(wp_unslash($_POST['test_mode'] ?? 'yes'));
            $config = '\\WalletUpPro\\Core\\Config\\AutomatedApiConfig';

            $incomingPub = isset($_POST['publishable_key']) ? trim(sanitize_text_field(wp_unslash($_POST['publishable_key']))) : '';
            $incomingSecret = isset($_POST['secret_key']) ? trim(sanitize_text_field(wp_unslash($_POST['secret_key']))) : '';
            if (strpos($incomingSecret, '*') !== false || strpos($incomingSecret, '•') !== false || str_starts_with($incomingSecret, 'walletup_enc:')) {
                $incomingSecret = '';
            }
            if (($incomingPub !== '' || $incomingSecret !== '') && class_exists($config)) {
                $pubMode = $incomingPub !== '' ? $config::stripeKeyMode($incomingPub) : '';
                $secretMode = $incomingSecret !== '' ? $config::stripeKeyMode($incomingSecret) : '';
                if (($incomingPub !== '' && $pubMode === '') || ($incomingSecret !== '' && $secretMode === '')) {
                    throw new \Exception(esc_html__('That does not look like a Stripe key. Paste the publishable key (pk_...) and the secret key (sk_... or rk_...) from your Stripe dashboard.', 'walletup'));
                }
                if ($pubMode !== '' && $secretMode !== '' && $pubMode !== $secretMode) {
                    throw new \Exception(esc_html__('Both keys must come from the same Stripe mode: two live keys, or two test keys.', 'walletup'));
                }
                $keyMode = $pubMode !== '' ? $pubMode : $secretMode;
                $patch = [];
                if ($incomingPub !== '') {
                    $patch[$keyMode . '_publishable_key'] = $incomingPub;
                }
                if ($incomingSecret !== '') {
                    $patch[$keyMode . '_secret_key'] = $incomingSecret;
                }
                \WalletUpPro\Core\Payments\PaymentGatewayHandler::updateStripeConfig($patch);
            }

            if ($incomingEnabled === 'yes') {
                $connectActive = false;
                if (class_exists($config)) {
                    $c = $config::getStripeConnectStatus();
                    $connectActive = !empty($c['active']) && ($c['publishable_key'] ?? '') !== '';
                }
                if (!$connectActive && class_exists($config) && $config::stripeKeyPair($incomingTestMode !== 'no')['secret'] === '') {
                    throw new \Exception(esc_html(sprintf(
                        /* translators: %s: Stripe mode (test|live) */
                        __('Cannot enable Card Payments: %s-mode Stripe keys are missing. Add them in Wallet Up → Payments → Stripe first.', 'walletup'),
                        $incomingTestMode !== 'no' ? __('test', 'walletup') : __('live', 'walletup')
                    )));
                }
            }

            $settings['enabled'] = $incomingEnabled;
            $settings['test_mode'] = $incomingTestMode;
            if (class_exists('\\WalletUpPro\\Core\\Config\\AutomatedApiConfig')) {
                \WalletUpPro\Core\Config\AutomatedApiConfig::setStripeTestMode($incomingTestMode !== 'no');
            }

            if (isset($_POST['webhook_secret']) && !empty($_POST['webhook_secret'])) {
                $raw_wh = sanitize_text_field(wp_unslash($_POST['webhook_secret']));
                if (!str_starts_with($raw_wh, 'walletup_enc:') && strpos($raw_wh, '*') === false) {
                    $encryption = \WalletUpPro\Core\Security\DataEncryption::getInstance();
                    $settings['webhook_secret'] = 'walletup_enc:' . $encryption->encrypt($raw_wh);
                } elseif (strpos($raw_wh, '*') !== false) {
                } else {
                    $settings['webhook_secret'] = $raw_wh;
                }

                $syncSecret = $settings['webhook_secret'];
                if (str_starts_with($syncSecret, 'walletup_enc:')) {
                    try {
                        $decrypted = \WalletUpPro\Core\Security\DataEncryption::getInstance()->decrypt(substr($syncSecret, strlen('walletup_enc:')));
                        if ($decrypted !== false && $decrypted !== '') {
                            $syncSecret = $decrypted;
                        }
                    } catch (\Exception $e) {
                    }
                }
                \WalletUpPro\Core\Payments\PaymentGatewayHandler::updateStripeConfig(['webhook_endpoint_secret' => $syncSecret]);
            }

            update_option('woocommerce_wallet_up_card_settings', $settings);
            \WalletUpPro\Core\Security\AuditLog::record('stripe_card_settings_saved');

            wp_send_json_success(['message' => 'Stripe card settings saved']);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function handleGetPayPalCommerceStatus(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new \Exception(esc_html('Invalid security token'));
            }
            if (!current_user_can('manage_options')) {
                throw new \Exception(esc_html('Insufficient permissions'));
            }

            $settings = (array) get_option('wallet_up_paypal_commerce_settings', []);
            $mode = $settings['mode'] ?? 'sandbox';
            $sandboxConfigured = !empty($settings['sandbox_client_id']) && !empty($settings['sandbox_secret']);
            $liveConfigured = !empty($settings['live_client_id']) && !empty($settings['live_secret']);

            wp_send_json_success([
                'enabled' => !empty($settings['enabled']),
                'mode' => in_array($mode, ['sandbox', 'live'], true) ? $mode : 'sandbox',
                'sandbox_configured' => $sandboxConfigured,
                'live_configured' => $liveConfigured,
                'main_configured' => $sandboxConfigured || $liveConfigured,
                'active_mode_configured' => ($mode === 'live' ? $liveConfigured : $sandboxConfigured),
                'webhook_url' => rest_url('walletup/v1/webhook/paypal'),
                'webhook_id' => (string) ($settings['webhook_id'] ?? ''),
                'webhook_id_set' => !empty($settings['webhook_id']),
            ]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function handleSavePayPalCommerceStatus(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new \Exception(esc_html('Invalid security token'));
            }
            if (!current_user_can('manage_options')) {
                throw new \Exception(esc_html('Insufficient permissions'));
            }

            $settings = (array) get_option('wallet_up_paypal_commerce_settings', []);

            $rawEnabled = sanitize_text_field(wp_unslash($_POST['enabled'] ?? ''));
            $wantEnabled = $rawEnabled !== '' && $rawEnabled !== 'false' && $rawEnabled !== 'no' && $rawEnabled !== '0';
            $mode = isset($_POST['mode']) ? sanitize_text_field(wp_unslash($_POST['mode'])) : 'sandbox';
            $mode = in_array($mode, ['sandbox', 'live'], true) ? $mode : 'sandbox';

            if ($wantEnabled) {
                $hasKeys = $mode === 'live'
                    ? (!empty($settings['live_client_id']) && !empty($settings['live_secret']))
                    : (!empty($settings['sandbox_client_id']) && !empty($settings['sandbox_secret']));
                if (!$hasKeys) {
                    throw new \Exception(esc_html(sprintf(
                        /* translators: %s: PayPal mode (sandbox|live) */
                        __('Cannot enable PayPal Commerce: %s credentials are missing. Add them in Wallet Up → Payments → PayPal Commerce first.', 'walletup'),
                        $mode
                    )));
                }
            }

            $settings['enabled'] = $wantEnabled;
            $settings['mode'] = $mode;
            if (isset($_POST['webhook_id'])) {
                $rawWebhookId = sanitize_text_field(wp_unslash($_POST['webhook_id']));
                if ($rawWebhookId !== '' && strpos($rawWebhookId, '•') === false) {
                    $settings['webhook_id'] = $rawWebhookId;
                }
            }
            update_option('wallet_up_paypal_commerce_settings', $settings);

            wp_send_json_success(['message' => 'PayPal Commerce settings saved']);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function handleGetCashAppApiStatus(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new \Exception(esc_html('Invalid security token'));
            }
            if (!current_user_can('manage_options')) {
                throw new \Exception(esc_html('Insufficient permissions'));
            }

            $settings = (array) get_option('wallet_up_cashapp_api_settings', []);
            $env = $settings['environment'] ?? 'sandbox';
            $credsConfigured = !empty($settings['client_id']) && !empty($settings['api_key']);

            wp_send_json_success([
                'enabled' => !empty($settings['enabled']),
                'environment' => in_array($env, ['sandbox', 'production'], true) ? $env : 'sandbox',
                'main_configured' => $credsConfigured,
                'using_main_keys' => $credsConfigured,
            ]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function handleSaveCashAppApiStatus(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new \Exception(esc_html('Invalid security token'));
            }
            if (!current_user_can('manage_options')) {
                throw new \Exception(esc_html('Insufficient permissions'));
            }

            $settings = (array) get_option('wallet_up_cashapp_api_settings', []);
            $raw = isset($_POST['enabled']) ? sanitize_text_field(wp_unslash($_POST['enabled'])) : '';
            $wantEnabled = $raw !== '' && $raw !== 'false' && $raw !== 'no' && $raw !== '0';
            $env = isset($_POST['environment']) ? sanitize_text_field(wp_unslash($_POST['environment'])) : 'sandbox';
            $env = in_array($env, ['sandbox', 'production'], true) ? $env : 'sandbox';

            if ($wantEnabled) {
                if (empty($settings['client_id']) || empty($settings['api_key'])) {
                    throw new \Exception(esc_html__('Cannot enable Cash App Pay API: Client ID or API Key is missing. Configure them in Wallet Up → Payments → Automated Payments first.', 'walletup'));
                }
            }

            $settings['enabled'] = $wantEnabled;
            $settings['environment'] = $env;

            $envChanged = ($settings['location_env'] ?? '') !== $env;
            if (!empty($settings['api_key']) && (empty($settings['location_id']) || $envChanged)) {
                $discoveredId = $this->discoverSquareLocationId($settings['api_key'], $env);
                if (!empty($discoveredId)) {
                    $settings['location_id'] = $discoveredId;
                    $settings['location_env'] = $env;
                } elseif ($envChanged) {
                    $settings['location_id'] = '';
                    $settings['location_env'] = $env;
                }
            }

            update_option('wallet_up_cashapp_api_settings', $settings);

            wp_send_json_success(['message' => 'Cash App Pay API settings saved']);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    private function discoverSquareLocationId(string $accessToken, string $env): string
    {
        $base = $env === 'production' ? 'https://connect.squareup.com' : 'https://connect.squareupsandbox.com';
        $response = wp_remote_get($base . '/v2/locations', [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Square-Version' => '2024-01-18',
                'Accept' => 'application/json',
            ],
            'timeout' => 30,
        ]);
        if (is_wp_error($response)) {
            return '';
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['locations']) || !is_array($body['locations'])) {
            return '';
        }
        foreach ($body['locations'] as $loc) {
            if (!is_array($loc)) {
                continue;
            }
            $status = strtoupper((string) ($loc['status'] ?? ''));
            if ($status === 'ACTIVE' && !empty($loc['id'])) {
                return (string) $loc['id'];
            }
        }
        $first = $body['locations'][0] ?? [];
        return is_array($first) && !empty($first['id']) ? (string) $first['id'] : '';
    }

    public function handleGetVenmoApiStatus(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new \Exception(esc_html('Invalid security token'));
            }
            if (!current_user_can('manage_options')) {
                throw new \Exception(esc_html('Insufficient permissions'));
            }

            $settings = (array) get_option('wallet_up_venmo_api_settings', []);
            $env = $settings['environment'] ?? 'sandbox';
            $credsConfigured = !empty($settings['merchant_id'])
                && !empty($settings['public_key'])
                && !empty($settings['private_key']);

            wp_send_json_success([
                'enabled' => !empty($settings['enabled']),
                'environment' => in_array($env, ['sandbox', 'production'], true) ? $env : 'sandbox',
                'main_configured' => $credsConfigured,
                'using_main_keys' => $credsConfigured,
            ]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function handleSaveVenmoApiStatus(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new \Exception(esc_html('Invalid security token'));
            }
            if (!current_user_can('manage_options')) {
                throw new \Exception(esc_html('Insufficient permissions'));
            }

            $settings = (array) get_option('wallet_up_venmo_api_settings', []);
            $raw = isset($_POST['enabled']) ? sanitize_text_field(wp_unslash($_POST['enabled'])) : '';
            $wantEnabled = $raw !== '' && $raw !== 'false' && $raw !== 'no' && $raw !== '0';
            $env = isset($_POST['environment']) ? sanitize_text_field(wp_unslash($_POST['environment'])) : 'sandbox';
            $env = in_array($env, ['sandbox', 'production'], true) ? $env : 'sandbox';

            if ($wantEnabled) {
                if (empty($settings['merchant_id']) || empty($settings['public_key']) || empty($settings['private_key'])) {
                    throw new \Exception(esc_html__('Cannot enable Venmo API: Merchant ID, Public Key, and Private Key are all required. Configure them in Wallet Up → Payments → Automated Payments first.', 'walletup'));
                }
            }

            $settings['enabled'] = $wantEnabled;
            $settings['environment'] = $env;
            update_option('wallet_up_venmo_api_settings', $settings);

            wp_send_json_success(['message' => 'Venmo API settings saved']);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function handleTestPayPalCommerce(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new Exception(esc_html('Invalid security token'));
            }
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => 'Unauthorized']);
                return;
            }

            $mode = sanitize_text_field(wp_unslash($_POST['mode'] ?? 'sandbox'));

            $saved = get_option('wallet_up_paypal_commerce_settings', []);
            $encryption = new \WalletUpPro\Core\Security\DataEncryption();

            $client_id = $mode === 'live'
                ? ($saved['live_client_id'] ?? '')
                : ($saved['sandbox_client_id'] ?? '');

            $secretKey = $mode === 'live' ? 'live_secret' : 'sandbox_secret';
            $secret = $saved[$secretKey] ?? '';
            if (!empty($secret)) {
                $dec = $encryption->decrypt($secret);
                if ($dec !== false && $dec !== '') {
                    $secret = $dec;
                }
            }

            if (empty($client_id) || empty($secret)) {
                wp_send_json_error(['message' => 'Please save your PayPal Commerce credentials first, then test the connection.']);
                return;
            }

            $base_url = $mode === 'live'
                ? 'https://api-m.paypal.com'
                : 'https://api-m.sandbox.paypal.com';

            $response = wp_remote_post($base_url . '/v1/oauth2/token', [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($client_id . ':' . $secret),
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => 'grant_type=client_credentials',
                'timeout' => 15,
            ]);

            if (is_wp_error($response)) {
                wp_send_json_error([
                    'message' => \WalletUpPro\Core\Payments\BackendErrors::forCustomer($response, 'admin connection test')
                ]);
                return;
            }

            $code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            if ($code === 200 && !empty($body['access_token'])) {
                wp_send_json_success([
                    'message' => 'PayPal Commerce connected successfully',
                    'mode' => $mode,
                    'app_id' => $body['app_id'] ?? '',
                    'scope' => $body['scope'] ?? '',
                ]);
            } else {
                $error_desc = $body['error_description'] ?? $body['message'] ?? 'Invalid credentials';
                wp_send_json_error([
                    'message' => 'Authentication failed: ' . $error_desc,
                    'error' => $body['error'] ?? 'unknown',
                ]);
            }
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function handleSavePayPalCommerceSettings(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new Exception(esc_html('Invalid security token'));
            }
            if (!current_user_can('manage_options')) {
                throw new Exception(esc_html('Permission denied'));
            }

            $encryption = new \WalletUpPro\Core\Security\DataEncryption();
            $existing = get_option('wallet_up_paypal_commerce_settings', []);

            $existingDecrypted = $existing;
            foreach (['sandbox_secret', 'live_secret'] as $sf) {
                if (!empty($existingDecrypted[$sf])) {
                    $dec = $encryption->decrypt($existingDecrypted[$sf]);
                    if ($dec !== false && $dec !== '') {
                        $existingDecrypted[$sf] = $dec;
                    }
                }
            }

            $settings = [
                'mode' => sanitize_text_field(wp_unslash($_POST['paypal_commerce_mode'] ?? 'sandbox')),
                'sandbox_client_id' => sanitize_text_field(wp_unslash($_POST['paypal_commerce_sandbox_client_id'] ?? '')),
                'sandbox_secret' => sanitize_text_field(wp_unslash($_POST['paypal_commerce_sandbox_secret'] ?? '')),
                'live_client_id' => sanitize_text_field(wp_unslash($_POST['paypal_commerce_live_client_id'] ?? '')),
                'live_secret' => sanitize_text_field(wp_unslash($_POST['paypal_commerce_live_secret'] ?? '')),
                'enabled' => !empty($_POST['paypal_commerce_enabled']) && sanitize_text_field(wp_unslash($_POST['paypal_commerce_enabled'] ?? '')) !== 'false',
            ];

            if (strpos($settings['sandbox_secret'], '...') !== false) {
                $settings['sandbox_secret'] = $existing['sandbox_secret'] ?? '';
            } else if (!empty($settings['sandbox_secret'])) {
                $settings['sandbox_secret'] = $encryption->encrypt($settings['sandbox_secret']);
            }
            if (strpos($settings['live_secret'], '...') !== false) {
                $settings['live_secret'] = $existing['live_secret'] ?? '';
            } else if (!empty($settings['live_secret'])) {
                $settings['live_secret'] = $encryption->encrypt($settings['live_secret']);
            }

            update_option('wallet_up_paypal_commerce_settings', $settings);

            delete_transient('walletup_paypal_token_sandbox');
            delete_transient('walletup_paypal_token_live');

            $masked = $settings;
            if (!empty($masked['sandbox_secret'])) {
                $masked['sandbox_secret'] = substr($masked['sandbox_secret'], 0, 6) . '...' . substr($masked['sandbox_secret'], -4);
            }
            if (!empty($masked['live_secret'])) {
                $masked['live_secret'] = substr($masked['live_secret'], 0, 6) . '...' . substr($masked['live_secret'], -4);
            }

            wp_send_json_success([
                'message' => 'PayPal Commerce settings saved',
                'settings' => $masked,
            ]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function handleGetPayPalCommerceSettings(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new Exception(esc_html('Invalid security token'));
            }
            if (!current_user_can('manage_options')) {
                throw new Exception(esc_html('Permission denied'));
            }

            $settings = get_option('wallet_up_paypal_commerce_settings', [
                'mode' => 'sandbox',
                'sandbox_client_id' => '',
                'sandbox_secret' => '',
                'live_client_id' => '',
                'live_secret' => '',
                'enabled' => false,
            ]);

            $encryption = new \WalletUpPro\Core\Security\DataEncryption();
            foreach (['sandbox_secret', 'live_secret'] as $sf) {
                if (!empty($settings[$sf])) {
                    $dec = $encryption->decrypt($settings[$sf]);
                    if ($dec !== false && $dec !== '') {
                        $settings[$sf] = $dec;
                    }
                }
            }
            if (!empty($settings['sandbox_secret'])) {
                $settings['sandbox_secret'] = substr($settings['sandbox_secret'], 0, 6) . '...' . substr($settings['sandbox_secret'], -4);
            }
            if (!empty($settings['live_secret'])) {
                $settings['live_secret'] = substr($settings['live_secret'], 0, 6) . '...' . substr($settings['live_secret'], -4);
            }

            wp_send_json_success(['settings' => $settings]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function handleGetLinkOptions(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                wp_send_json_error(['message' => esc_html__('Invalid security token', 'walletup')], 403);
                return;
            }

            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => esc_html__('Unauthorized', 'walletup')], 403);
                return;
            }

            $options = get_option('wallet_up_link_custom_options', [
                'categories' => [],
                'transactionTypes' => [],
            ]);

            wp_send_json_success($options);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function handleSaveLinkOption(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                wp_send_json_error(['message' => esc_html__('Invalid security token', 'walletup')], 403);
                return;
            }

            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => esc_html__('Unauthorized', 'walletup')], 403);
                return;
            }

            $optionType = isset($_POST['option_type']) ? sanitize_text_field(wp_unslash($_POST['option_type'])) : '';
            $optionValue = isset($_POST['option_value']) ? sanitize_key(wp_unslash($_POST['option_value'])) : '';
            $optionLabel = isset($_POST['option_label']) ? sanitize_text_field(wp_unslash($_POST['option_label'])) : '';

            if (!in_array($optionType, ['category', 'transaction_type'], true)) {
                wp_send_json_error(['message' => esc_html__('Invalid option type', 'walletup')], 400);
                return;
            }

            if (empty($optionValue) || empty($optionLabel)) {
                wp_send_json_error(['message' => esc_html__('Value and label are required', 'walletup')], 400);
                return;
            }

            if (strlen($optionLabel) > 50) {
                wp_send_json_error(['message' => esc_html__('Label too long (max 50 characters)', 'walletup')], 400);
                return;
            }

            $options = get_option('wallet_up_link_custom_options', [
                'categories' => [],
                'transactionTypes' => [],
            ]);

            $key = $optionType === 'category' ? 'categories' : 'transactionTypes';

            if (count($options[$key]) >= 20) {
                wp_send_json_error(['message' => esc_html__('Maximum custom options reached (20)', 'walletup')], 400);
                return;
            }

            foreach ($options[$key] as $existing) {
                if ($existing['value'] === $optionValue) {
                    wp_send_json_error(['message' => esc_html__('This option already exists', 'walletup')], 400);
                    return;
                }
            }

            $options[$key][] = [
                'value' => $optionValue,
                'label' => $optionLabel,
            ];

            update_option('wallet_up_link_custom_options', $options);

            wp_send_json_success(['message' => esc_html__('Option saved', 'walletup')]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function handleDeleteLinkOption(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                wp_send_json_error(['message' => esc_html__('Invalid security token', 'walletup')], 403);
                return;
            }

            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => esc_html__('Unauthorized', 'walletup')], 403);
                return;
            }

            $optionType = isset($_POST['option_type']) ? sanitize_text_field(wp_unslash($_POST['option_type'])) : '';
            $optionValue = isset($_POST['option_value']) ? sanitize_key(wp_unslash($_POST['option_value'])) : '';

            if (!in_array($optionType, ['category', 'transaction_type'], true)) {
                wp_send_json_error(['message' => esc_html__('Invalid option type', 'walletup')], 400);
                return;
            }

            $options = get_option('wallet_up_link_custom_options', [
                'categories' => [],
                'transactionTypes' => [],
            ]);

            $key = $optionType === 'category' ? 'categories' : 'transactionTypes';
            $options[$key] = array_values(array_filter(
                $options[$key],
                static fn($item) => $item['value'] !== $optionValue
            ));

            update_option('wallet_up_link_custom_options', $options);

            wp_send_json_success(['message' => esc_html__('Option removed', 'walletup')]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function maybeShowReviewPrompt(): void
    {
        if (class_exists('\\WalletUpPro\\Core\\Retention\\DeactivationOffer') && \WalletUpPro\Core\Retention\DeactivationOffer::isPaused()) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || (strpos($screen->id, 'wallet-up') === false && strpos($screen->id, 'walletup') === false)) {
            return;
        }

        $dismissed = get_option('wallet_up_review_dismissed', '');
        if ($dismissed === 'permanent') {
            return;
        }

        if ($dismissed && is_numeric($dismissed)) {
            if (time() < (int) $dismissed) {
                return;
            }
        }

        $activated_at = get_option('wallet_up_activated_at', 0);
        if (!$activated_at) {
            $activated_at = time();
            update_option('wallet_up_activated_at', $activated_at);
            return; // Don't show on the same request we set the timestamp
        }

        $days_since = (time() - (int) $activated_at) / DAY_IN_SECONDS;
        if ($days_since < 7) {
            return;
        }

        $review_url = 'https://wordpress.org/support/plugin/wallet-up/reviews/#new-post';
        $nonce = wp_create_nonce('wallet_up_admin_nonce');
        ?>
        <div class="notice notice-info wallet-up-review-prompt" id="wallet-up-review-prompt" style="padding:12px 16px;border-left-color:#6366f1;display:flex;align-items:flex-start;gap:12px;">
            <span style="font-size:28px;line-height:1;" aria-hidden="true">&#9733;</span>
            <div style="flex:1;">
                <p style="margin:0 0 10px;font-size:14px;">
                    <?php echo esc_html__('Enjoying Wallet Up? You\'ve been using it for over a week! If it\'s helped your business accept payments, a quick review would mean the world to us.', 'walletup'); ?>
                </p>
                <p style="margin:0;display:flex;gap:8px;flex-wrap:wrap;">
                    <a href="<?php echo esc_url($review_url); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary" style="display:inline-flex;align-items:center;gap:4px;">
                        <?php echo esc_html__('Leave a Review', 'walletup'); ?> &#9733;
                    </a>
                    <button type="button" class="button wallet-up-review-dismiss" data-action="snooze">
                        <?php echo esc_html__('Remind Me Later', 'walletup'); ?>
                    </button>
                    <button type="button" class="button wallet-up-review-dismiss" data-action="permanent">
                        <?php echo esc_html__('I Already Did', 'walletup'); ?>
                    </button>
                </p>
            </div>
        </div>
        <script>
        (function() {
            document.querySelectorAll('.wallet-up-review-dismiss').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var action = this.dataset.action;
                    var notice = document.getElementById('wallet-up-review-prompt');
                    if (notice) notice.style.display = 'none';

                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', '<?php echo esc_url(admin_url('admin-ajax.php')); ?>');
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.send(
                        'action=wallet_up_dismiss_review' +
                        '&nonce=<?php echo esc_js($nonce); ?>' +
                        '&dismiss_type=' + encodeURIComponent(action)
                    );
                });
            });
        })();
        </script>
        <?php
    }

    /**
     * AJAX handler for dismissing the review prompt.
     *
     * @return void
     */
    public function handleDismissReview(): void
    {
        if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token']);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $dismiss_type = isset($_POST['dismiss_type']) ? sanitize_text_field(wp_unslash($_POST['dismiss_type'])) : '';

        if ($dismiss_type === 'permanent') {
            update_option('wallet_up_review_dismissed', 'permanent');
        } elseif ($dismiss_type === 'snooze') {
            update_option('wallet_up_review_dismissed', (string) (time() + 14 * DAY_IN_SECONDS));
        }

        wp_send_json_success(['message' => 'Review prompt dismissed']);
    }
}