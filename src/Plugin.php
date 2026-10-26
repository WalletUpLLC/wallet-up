<?php
namespace WalletUpPro;

use WalletUpPro\Core\BaseSingleton;
use WalletUpPro\Core\ServiceContainer;
use WalletUpPro\Core\PaymentRegistry;
use WalletUpPro\Core\Admin\AdminManager;
use WalletUpPro\Core\Admin\TransactionAdmin;
use WalletUpPro\Core\Installation\InstallationHandler;
use WalletUpPro\Core\Database\DatabaseManager;
use WalletUpPro\Core\Error\ErrorHandler;
use WalletUpPro\Core\Migration\MigrationManager;
use WalletUpPro\Core\Assets\AssetManager;
use WalletUpPro\Core\Display\DisplayHandler;
use WalletUpPro\Services\QRCodeService;
use WalletUpPro\WooCommerce\WooCommerceIntegration;
use WalletUpPro\WpForms\Forms\WPFormsService;
use WalletUpPro\Core\Donations\DonationHandler;
use WalletUpPro\Core\Donations\DonationPageHandler;
use WalletUpPro\Core\Payers\PayerHandler;
use WalletUpPro\Core\Payers\PayerPageHandler;
use WalletUpPro\Services\URLShortenerService;
use WalletUpPro\Core\Utilities\TimestampUtility;
use WalletUpPro\ZenithQR\ZenithQRIntegration;
use WalletUpPro\Core\Translation\EnterpriseTranslationManager;
use WalletUpPro\Core\Admin\TranslationAdmin;
use WalletUpPro\Core\Admin\TranslationHelp;
use WalletUpPro\Core\Cron\GeoIPUpdater;

use Automattic\WooCommerce\Utilities\CacheHelper as WC_Cache_Helper;
use Exception;
use WalletUpPro\Core\RewriteRulesManager;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class Plugin extends BaseSingleton
{
    private const DONATION_SCHEMA_VERSION = '3';

    /**
     * @var ServiceContainer Service container instance
     */
    private $container;

    /**
     * @var DatabaseManager Database manager instance
     */
    private $db;

    /**
     * @var PaymentRegistry Payment registry instance
     */
    private $registry;

    /**
     * @var AdminManager Admin manager instance
     */
    private $admin;

    /**
     * @var ErrorHandler Error handler instance
     */
    private $errorHandler;

    /**
     * @var bool Guards the once-per-request cache purge after a settings save.
     */
    private static $cachePurgeScheduled = false;

    /**
     * @var MigrationManager Migration manager instance
     */
    private $migrationManager;

    /**
     * @var ZenithQRIntegration
     */
    private $zenithQRIntegration;

    //  * @var PayPalAdminServiceProvider PayPal admin service provider

    /**
     * @var AssetManager Asset manager instance
     */
    private $assetManager;

    /**
     * @var DisplayHandler Display handler instance
     */
    private $displayHandler;

    /**
     * @var QRCodeService QR code service instance
     */
    private $qrService;

    /**
     * @var bool Installation status
     */
    private $installed = false;

    /**
     * @var string Table name
     */
    private $table_name;

    /**
     * @var URLShortenerService URL shortener service
     */
    private $urlShortener;

    /**
     * @var mixed Cache instance
     */
    private $cache;

    /**
     * @var string Clicks table name
     */
    private $clicks_table;

    protected function __construct()
    {
        try {
            parent::__construct();

            register_deactivation_hook(WALLET_UP_FILE, [$this, 'deactivate']);

            add_action('before_woocommerce_init', [$this, 'declareWooCommerceCompatibility']);

            add_action('plugins_loaded', [$this, 'initializeComponents'], 20);

        } catch (Exception $e) {
            if (isset($this->errorHandler)) {
                $this->errorHandler->log('Constructor initialization failed: ' . $e->getMessage());
            }
            add_action('admin_notices', function () use ($e) {
                printf(
                    '<div class="notice notice-error"><p>%s</p></div>',
                    esc_html('WalletUp Pro: Initialization error — ' . $e->getMessage())
                );
            });
        }
    }

    public function activate(): void
    {
        try {
            if (!$this->isInitialized()) {
                $this->initialize();
            }

            $this->checkRequirements();

            $installer = new InstallationHandler($this->db, $this->errorHandler);
            $installer->install();

            $this->createDefaultOptions();

            update_option('wallet_up_flush_rules', 'yes');

            if (!$this->installed) {
                $tableName = $this->getTableName();
                $this->migrationManager = new MigrationManager($this->db, $this->errorHandler, $tableName);
                $this->migrationManager->runMigrations();
            }

            flush_rewrite_rules();
            $this->installed = true;
            delete_option('wallet_up_quick_access_count');

            update_option('wallet_up_activated', 'yes');
            update_option('wallet_up_version', WALLET_UP_VERSION);

        } catch (Exception $e) {
            $this->errorHandler->log('Activation failed: ' . $e->getMessage());
            $this->deactivate();
            throw $e;
        }
    }

    protected function initialize(): void
    {
        try {
            $this->errorHandler = ErrorHandler::getInstance();
            $this->container = ServiceContainer::getInstance();
            $this->db = new DatabaseManager($this->getTableName());
            $this->assetManager = AssetManager::getInstance();
            $this->displayHandler = DisplayHandler::getInstance();

            if (wallet_up_fs()->can_use_premium_code__premium_only()) {
                $analyticsHandler = \WalletUpPro\Core\Analytics\AnalyticsHandler::getInstance();
            }

            // resolve on every site/subsite regardless of license. Scan analytics
            \WalletUpPro\Services\QRCodeService::registerCacheBypass();
            add_action('init', ['\\WalletUpPro\\Services\\QRCodeService', 'registerScanRewriteRule'], 0);
            add_action('init', ['\\WalletUpPro\\Services\\QRCodeService', 'handleScanRedirect'], 1);
            add_action('parse_request', ['\\WalletUpPro\\Services\\QRCodeService', 'handleScanRedirect'], 0);

            if (get_option('wallet_up_scan_rewrite_v1') !== 'yes') {
                add_action('wp_loaded', static function () {
                    flush_rewrite_rules(false);
                    update_option('wallet_up_scan_rewrite_v1', 'yes', false);
                });
            }

            if (class_exists(\WalletUpPro\Core\Onboarding\PaymentPageGenerator::class)) {
                \WalletUpPro\Core\Onboarding\PaymentPageGenerator::registerLiveSection();
            }

            if (wallet_up_fs()->can_use_premium_code__premium_only()) {
                \WalletUpPro\Core\Tenancy\Tenant::register();
                \WalletUpPro\Core\Payments\Availability\MethodAvailability::register();
                if (is_admin()) {
                    \WalletUpPro\Core\Payments\Availability\AvailabilityAdmin::register();
                }
            }
            \WalletUpPro\Core\Retention\DeactivationOffer::register();
            if (class_exists(\WalletUpPro\Core\Support\SetupHelp::class)) {
                \WalletUpPro\Core\Support\SetupHelp::register();
            }
            $this->registry = PaymentRegistry::getInstance();

            add_action('updated_option', function ($option) {
                $option = (string) $option;
                if (strpos($option, 'wallet_up') !== 0
                    && strpos($option, 'walletup') !== 0
                    && $option !== 'woocommerce_wallet_up_settings') {
                    return;
                }
                if (self::$cachePurgeScheduled) {
                    return;
                }
                self::$cachePurgeScheduled = true;
                add_action('shutdown', [self::class, 'purgePaymentCaches']);
            }, 10, 1);

            if (wallet_up_fs()->can_use_premium_code__premium_only()) {
                $translationManager = EnterpriseTranslationManager::getInstance();
                $this->container->set('translation_manager', $translationManager);

                if (is_admin()) {
                    new TranslationAdmin();
                    new TranslationHelp();
                }
            }

            new \WalletUpPro\API\QRCodeEndpoint();

            if (wallet_up_fs()->can_use_premium_code__premium_only()) {
                new \WalletUpPro\Core\Shortcodes\QRCodeShortcode();
            }

            if (wallet_up_fs()->can_use_premium_code__premium_only()) {
                \WalletUpPro\Core\Crypto\CryptoConverter::getInstance();
                new \WalletUpPro\Core\Crypto\CryptoRateAjaxHandler();
            }

            $rewriteManager = RewriteRulesManager::getInstance();
            $this->container->set('rewrite_manager', $rewriteManager);

            $premiumManager = \WalletUpPro\Core\Manager\PremiumFeaturesManager::getInstance();
            $this->container->set('premium_manager', $premiumManager);

            if (wallet_up_fs()->can_use_premium_code__premium_only()) { // Fully premium

                $this->initializeDonationSystem();
                $this->initializeDisplaySettings();
                $this->initializePayerSystem();
                $this->zenithQRMenu();

                $currentVersion = get_option('wallet_up_version', '0');
                $pluginVersion = WALLET_UP_PRO_VERSION;
                
                if (!$this->db->tableExists() || version_compare($currentVersion, $pluginVersion, '<')) {
                    $installer = new InstallationHandler($this->db, $this->errorHandler);
                    $installer->install();
                    update_option('wallet_up_version', $pluginVersion);
                    $this->installed = true;
                } else {
                    $this->installed = true;
                }
            }

            $this->initializeServices();
            $this->runMigrations();

            if (class_exists(\WalletUpPro\Core\Payments\MerchantProvisioning::class)) {
                \WalletUpPro\Core\Payments\MerchantProvisioning::getInstance()->init();
            }

           
        } catch (Exception $e) {
            $this->errorHandler->log('Plugin initialization failed: ' . $e->getMessage());
            throw $e;
        }
    }

    public function initializeComponents(): void
    {
        try {
            if (!$this->isInitialized()) {
                $this->initialize();
            }

            if (wallet_up_fs()->can_use_premium_code__premium_only()) {
                try {
                    $this->urlShortener = new URLShortenerService();
                } catch (Exception $e) {
                    $this->errorHandler->log('URLShortenerService init failed: ' . $e->getMessage());
                }
                try {
                    $this->zenithQRIntegration = new ZenithQRIntegration();
                } catch (Exception $e) {
                    $this->errorHandler->log('ZenithQRIntegration init failed: ' . $e->getMessage());
                }
            }

            if (is_admin()) {
                $this->admin = new AdminManager(
                    $this->registry,
                    $this->assetManager,
                    $this->db,
                    $this->getQRService()
                );

                if (wallet_up_fs()->can_use_premium_code__premium_only()) {
                    if (get_option('wallet_up_admin_v2', 'yes') === 'yes') {
                        add_action('admin_menu', [\WalletUpPro\Core\Admin\AdminV2MenuRegistrar::class, 'register'], 11);
                    }
                    if (class_exists(\WalletUpPro\Core\Admin\MenuVisibility::class)) {
                        \WalletUpPro\Core\Admin\MenuVisibility::init();
                    }

                    TransactionAdmin::getInstance();
                }

                $redirect = get_transient('wallet_up_activation_redirect');
                if ($redirect && is_admin() && !wp_doing_ajax()) {
                }
            }

            if (wallet_up_fs()->can_use_premium_code__premium_only()) {
                GeoIPUpdater::getInstance();
            }
            
            $this->registerAjaxHandlers();

        } catch (Exception $e) {
            if (isset($this->errorHandler)) {
                $this->errorHandler->log('Component initialization failed: ' . $e->getMessage());
            }
            add_action('admin_notices', function () use ($e) {
                printf(
                    '<div class="notice notice-error"><p>%s</p></div>',
                    esc_html('WalletUp Pro: Component initialization failed — ' . $e->getMessage())
                );
            });
        }
    }

    private function initializeServices(): void
    {
        $this->container->set('database', $this->db);
        $this->container->set('registry', $this->registry);
        $this->container->set('asset_manager', $this->assetManager);
        $this->container->set('display_handler', $this->displayHandler);

        $theme_manager = \WalletUpPro\Core\Admin\Themes\AdminThemeManager::getInstance();
        $this->container->set('theme_manager', $theme_manager);

        if (wallet_up_fs()->can_use_premium_code__premium_only()) {
            $urlHandler = \WalletUpPro\Core\URL\URLHandler::getInstance();
            $this->container->set('url_shortener', $urlHandler->getShortener());
        }

        if (wallet_up_fs()->can_use_premium_code__premium_only()) {
            $wpformsService = WPFormsService::getInstance($this->errorHandler);
            if ($wpformsService->isActive()) {
                $this->container->set('wpforms', $wpformsService);
                $wpformsService->init();
            }
        }

        if (wallet_up_fs()->can_use_premium_code__premium_only()) {
            if (class_exists('WooCommerce')) {
                $this->container->set('wc_integration', WooCommerceIntegration::getInstance());
                \WalletUpPro\WooCommerce\OrderStatusBridge::init();
                if (class_exists(\WalletUpPro\Integrations\ZI2NexusBridge::class)) {
                    \WalletUpPro\Integrations\ZI2NexusBridge::init();
                }
                if (class_exists(\WalletUpPro\Core\Admin\Integrations\OnboardingPromoter::class)) {
                    \WalletUpPro\Core\Admin\Integrations\OnboardingPromoter::init();
                }
            }
        } else {
            \WalletUpPro\WooCommerce\FreeCardBootstrap::init();
        }

        if (!wp_next_scheduled('walletup_clean_expired_payments')) {
            wp_schedule_event(time(), 'twicedaily', 'walletup_clean_expired_payments');
        }
        add_action('walletup_clean_expired_payments', function () {
            try {
                \WalletUpPro\Services\PaymentProcessor::getInstance()->cleanExpiredPayments();
            } catch (\Exception $e) {
            }
        });

        if (is_admin() && class_exists(\WalletUpPro\Core\Admin\CheckoutHandoff::class)) {
            \WalletUpPro\Core\Admin\CheckoutHandoff::init();
        }

        if (is_admin() && class_exists(\WalletUpPro\Core\Admin\OnboardingHandler::class)) {
            \WalletUpPro\Core\Admin\OnboardingHandler::init();
            $quiet = class_exists(\WalletUpPro\Core\Retention\DeactivationOffer::class)
                && \WalletUpPro\Core\Retention\DeactivationOffer::isPaused();
            if (!$quiet && class_exists(\WalletUpPro\Core\Admin\Notices\OnboardingBanner::class)) {
                \WalletUpPro\Core\Admin\Notices\OnboardingBanner::init();
            }
            if (class_exists(\WalletUpPro\Core\Onboarding\PaymentPageGenerator::class)) {
                \WalletUpPro\Core\Onboarding\PaymentPageGenerator::init();
            }
            if (!$quiet && class_exists(\WalletUpPro\Core\Admin\Notices\IntegrationDetector::class)) {
                \WalletUpPro\Core\Admin\Notices\IntegrationDetector::init();
            }
            if (!$quiet && class_exists(\WalletUpPro\Core\Admin\NonprofitOffer::class)) {
                \WalletUpPro\Core\Admin\NonprofitOffer::init();
                add_filter('wallet_up_admin_react_data', [\WalletUpPro\Core\Admin\NonprofitOffer::class, 'addScriptData']);
            }
            if (class_exists(\WalletUpPro\Core\Admin\Integrations\OneClickAttach::class)) {
                \WalletUpPro\Core\Admin\Integrations\OneClickAttach::init();
            }
        }

        if (class_exists(\WalletUpPro\Core\Blocks\BlockRegistrar::class)) {
            \WalletUpPro\Core\Blocks\BlockRegistrar::init();
        }

        if (wallet_up_fs()->can_use_premium_code__premium_only()) {
            \WalletUpPro\Core\Emails\EmailTemplateHandler::init();

            \WalletUpPro\Core\Donations\DonationReminders::init();

            \WalletUpPro\Core\Security\AuditLog::init();

            \WalletUpPro\Core\Donors\DonorHandler::init();

            \WalletUpPro\Core\Reviews\ReviewHandler::init();

            \WalletUpPro\Core\Donations\DonationSystemHandler::init();

            require_once __DIR__ . "/Core/Reports/ReportsHandler.php";
            \WalletUpPro\Core\Reports\ReportsHandler::init();
            add_action("wallet_up_send_scheduled_report", ["\WalletUpPro\Core\Reports\ReportsHandler", "processScheduledReports"]);

            \WalletUpPro\Core\Payments\PaymentGatewayHandler::init();

            add_action('rest_api_init', function () {
                register_rest_route('walletup/v1', '/webhook/cashapp', [
                    'methods' => 'POST',
                    'callback' => function (\WP_REST_Request $request) {
                        try {
                            $api = \WalletUpPro\Payments\CashAppPayAPI::getInstance();
                            if ($api->processWebhook($request)) {
                                return new \WP_REST_Response(['status' => 'ok'], 200);
                            }
                            return new \WP_REST_Response(['status' => 'error'], 400);
                        } catch (\Exception $e) {
                            return new \WP_REST_Response(['status' => 'error', 'message' => $e->getMessage()], 500);
                        }
                    },
                    'permission_callback' => '__return_true',
                ]);

                register_rest_route('walletup/v1', '/webhook/venmo', [
                    'methods' => 'POST',
                    'callback' => function (\WP_REST_Request $request) {
                        try {
                            $api = \WalletUpPro\Payments\VenmoBraintreeAPI::getInstance();
                            $btSignature = sanitize_text_field($request->get_param('bt_signature') ?? '');
                            $btPayload = $request->get_param('bt_payload') ?? '';

                            if ($api->processWebhook($btSignature, $btPayload)) {
                                return new \WP_REST_Response(['status' => 'ok'], 200);
                            }
                            return new \WP_REST_Response(['status' => 'error'], 400);
                        } catch (\Exception $e) {
                            return new \WP_REST_Response(['status' => 'error', 'message' => $e->getMessage()], 500);
                        }
                    },
                    'permission_callback' => '__return_true',
            ]);
        });
        } // end premium-only service initialization
    }

    /**
     * Gets the database table name for analytics.
     * 
     * @return string The table name prefixed with WordPress database prefix.
     */
    private function getTableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'wallet_up_analytics';
    }

    private function initializeDonationSystem(): void
    {
        if (wallet_up_fs()->can_use_premium_code__premium_only()) {
            try {
                global $wpdb;
                $donationDb = new DatabaseManager($wpdb->prefix . 'wallet_up_donations');

                $donationHandler = DonationHandler::getInstance();
                $donationPageHandler = DonationPageHandler::getInstance();

                $this->container->set('donation_handler', $donationHandler);
                $this->container->set('donation_page_handler', $donationPageHandler);

                add_action('init', function () {
                    if (!$this->areDonationTablesCreated()) {
                        $this->createDonationTables();
                    }
                    if (!\WalletUpPro\Core\Subscriptions\SubscriptionManager::tableExists()) {
                        \WalletUpPro\Core\Subscriptions\SubscriptionManager::createTable();
                    }
                    \WalletUpPro\Core\Subscriptions\SubscriptionManager::getInstance();
                    if (class_exists('\\WalletUpPro\\Core\\Memberships\\MembershipManager')) {
                        if (!\WalletUpPro\Core\Memberships\MembershipManager::schemaCurrent()) {
                            \WalletUpPro\Core\Memberships\MembershipManager::createTable();
                        }
                        \WalletUpPro\Core\Memberships\MembershipManager::getInstance()->ensureDefaultTiers();
                    }
                });

                if (class_exists('\\WalletUpPro\\Core\\Memberships\\MembershipAjax')) {
                    \WalletUpPro\Core\Memberships\MembershipAjax::register();
                }
                if (class_exists('\\WalletUpPro\\Core\\Memberships\\MembershipShortcode')) {
                    \WalletUpPro\Core\Memberships\MembershipShortcode::register();
                }
                if (class_exists('\\WalletUpPro\\Core\\Memberships\\MembershipGate')) {
                    \WalletUpPro\Core\Memberships\MembershipAccess::ensureDefaults();
                    \WalletUpPro\Core\Memberships\MembershipGate::register();
                }
                if (class_exists('\\WalletUpPro\\Core\\Memberships\\MembershipFileGuard')) {
                    \WalletUpPro\Core\Memberships\MembershipFileGuard::register();
                }
                if (class_exists('\\WalletUpPro\\Core\\Payments\\HostedPayPage')) {
                    \WalletUpPro\Core\Payments\HostedPayPage::register();
                }
                if (class_exists('\\WalletUpPro\\Core\\Shortcodes\\ExplainerShortcode')) {
                    \WalletUpPro\Core\Shortcodes\ExplainerShortcode::register();
                }

                add_action('init', [$this, 'registerDonationEndpoints']);
                add_action('wp_enqueue_scripts', [$this, 'enqueueDonationAssets']);

                if (get_option('wallet_up_admin_v2', 'yes') !== 'yes') {
                    add_action('admin_menu', function () {
                        add_submenu_page(
                            'wallet-up',
                            esc_html__('Donations', 'walletup'),
                            esc_html__('Donations', 'walletup'),
                            'manage_options',
                            'wallet-up-donations',
                            [$this, 'renderDonationSettingsPage']
                        );
                    });
                }

            } catch (Exception $e) {
                $this->errorHandler->log('Donation system initialization failed: ' . $e->getMessage());
                throw $e;
            }
        }
    }

    private function createDonationTables(): void
    {
        if (wallet_up_fs()->can_use_premium_code__premium_only()) { // Fully premium

            global $wpdb;
            $charset_collate = $wpdb->get_charset_collate();

            $donationTrackingTable = $wpdb->prefix . 'wallet_up_donations';

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
            $sql = "CREATE TABLE {$donationTrackingTable} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            donation_id varchar(50) NOT NULL,
            payment_method varchar(50) NOT NULL,
            amount decimal(10,2) NOT NULL,
            currency varchar(10) DEFAULT 'USD',
            status varchar(20) NOT NULL,
            donor_ip varchar(45),
            donor_email varchar(100),
            donor_name varchar(100),
            donor_phone varchar(30),
            message text,
            campaign_id varchar(64),
            form_id varchar(64),
            is_recurring tinyint(1) DEFAULT 0,
            is_anonymous tinyint(1) DEFAULT 0,
            metadata text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY donation_id (donation_id),
            KEY payment_method (payment_method),
            KEY status (status),
            KEY campaign_id (campaign_id),
            KEY created_at (created_at)
        ) $charset_collate;";

            dbDelta($sql);

            $receiptsTable = $wpdb->prefix . 'wallet_up_tax_receipts';
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
            $receiptsSql = "CREATE TABLE {$receiptsTable} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            receipt_id varchar(64) NOT NULL,
            receipt_number varchar(64) NOT NULL,
            donation_id varchar(64) DEFAULT NULL,
            donor_name varchar(190) DEFAULT NULL,
            donor_email varchar(190) DEFAULT NULL,
            amount decimal(12,2) NOT NULL DEFAULT 0,
            currency varchar(10) NOT NULL DEFAULT 'USD',
            tax_year smallint(6) DEFAULT NULL,
            receipt_type varchar(20) NOT NULL DEFAULT 'single',
            status varchar(20) NOT NULL DEFAULT 'generated',
            data longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY receipt_id (receipt_id),
            KEY donor_email (donor_email),
            KEY tax_year (tax_year)
        ) $charset_collate;";

            dbDelta($receiptsSql);

            $reviewsTable = $wpdb->prefix . 'wallet_up_reviews';
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
            $reviewsSql = "CREATE TABLE {$reviewsTable} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            review_id varchar(64) NOT NULL,
            donation_id varchar(64) DEFAULT NULL,
            campaign_id varchar(64) DEFAULT NULL,
            form_id varchar(64) DEFAULT NULL,
            donor_name varchar(190) DEFAULT NULL,
            donor_email varchar(190) DEFAULT NULL,
            rating tinyint(1) NOT NULL DEFAULT 5,
            title varchar(190) DEFAULT NULL,
            content text,
            status varchar(20) NOT NULL DEFAULT 'pending',
            is_featured tinyint(1) NOT NULL DEFAULT 0,
            donor_ip varchar(45) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            approved_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY review_id (review_id),
            KEY status (status),
            KEY campaign_id (campaign_id),
            KEY form_id (form_id),
            KEY created_at (created_at)
        ) $charset_collate;";

            dbDelta($reviewsSql);

            update_option('wallet_up_donation_schema_version', self::DONATION_SCHEMA_VERSION, false);
        }
    }
    public function registerDonationEndpoints(): void
    {
        if (wallet_up_fs()->can_use_premium_code__premium_only()) { // Fully premium

        }
    }

    public function enqueueDonationAssets(): void
    {
        if (wallet_up_fs()->can_use_premium_code__premium_only()) { // Fully premium

            if (get_query_var('wallet_up_donation') || is_page('donate')) {

                $css_path = WALLET_UP_URL . 'assets/css/donations/donations-qr.css';
                $version = file_exists($css_path) ? filemtime($css_path) : WALLET_UP_VERSION;
                wp_enqueue_style(
                    'wallet-up-donations',
                    WALLET_UP_URL . 'assets/css/donations/donations-qr.css',
                    [],
                    $version
                );

            }
        }
    }

    public function renderDonationSettingsPage(): void
    {
        if (wallet_up_fs()->can_use_premium_code__premium_only()) { // Fully premium

            if (!current_user_can('manage_options')) {
                return;
            }

            ?>
            <div class="wrap">
                <!-- <h1><?php echo esc_html__('Donations', 'walletup'); ?></h1> -->
                <div id="wallet-up-admin-root" data-initial-tab="donations"></div>
            </div>
            <?php
        }
    }
    private function initializeDisplaySettings(): void
    {
        if (wallet_up_fs()->can_use_premium_code__premium_only()) { // Fully premium

            try {
                if (get_option('wallet_up_admin_v2', 'yes') !== 'yes') {
                    add_action('admin_menu', function () {
                        add_submenu_page(
                            'wallet-up',
                            esc_html__('QR Settings', 'walletup'),
                            esc_html__('QR Settings', 'walletup'),
                            'manage_options',
                            'wallet-up-qr-display',
                            [$this, 'renderQRSettingsPage']
                        );
                    });

                    add_action('admin_menu', function () {
                        add_submenu_page(
                            'wallet-up',
                            esc_html__('QR Analytics', 'walletup'),
                            esc_html__('QR Analytics', 'walletup'),
                            'manage_options',
                            'wallet-up-qr-analytics',
                            [$this, 'renderQRAnalyticsPage']
                        );
                    });
                }
            } catch (Exception $e) {
                $this->errorHandler->log('Menu system initialization failed: ' . $e->getMessage());
                throw $e;
            }
        }
    }
    public function renderQRSettingsPage(): void
    {
        if (wallet_up_fs()->can_use_premium_code__premium_only()) { // Fully premium

            if (!current_user_can('manage_options')) {
                wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'walletup'));
                return;
            }

            ?>
            <div class="wrap">
                <!-- <h1><?php echo esc_html__('QR Settings', 'walletup'); ?></h1> -->
                <div id="wallet-up-admin-root" data-initial-tab="display"></div>
            </div>
            <?php
        }
    }

    public function renderQRAnalyticsPage(): void
    {
        if (wallet_up_fs()->can_use_premium_code__premium_only()) { // Fully premium

            if (!current_user_can('manage_options')) {
                wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'walletup'));
                return;
            }

            ?>
            <div class="wrap">
                <!-- <h1><?php echo esc_html__('QR Analytics', 'walletup'); ?></h1> -->
                <div id="wallet-up-admin-root" data-initial-tab="analytics"></div>
            </div>
            <?php
        }
    }

    private function zenithQRMenu(): void {
        if (wallet_up_fs()->can_use_premium_code__premium_only()) {
            if (file_exists(WALLET_UP_PRO_BASE_DIR . '/zenithQR/zenith-qr-loader.php')) {
                require_once WALLET_UP_PRO_BASE_DIR . '/zenithQR/zenith-qr-loader.php';
            }
        }
    }

    private function initializePayerSystem(): void
    {
        if (wallet_up_fs()->can_use_premium_code__premium_only()) { // Fully premium

            try {
                global $wpdb;
                $payerDb = new DatabaseManager($wpdb->prefix . 'wallet_up_payers');

                $payerHandler = PayerHandler::getInstance();
                $payerPageHandler = PayerPageHandler::getInstance();

                $this->container->set('payer_handler', $payerHandler);
                $this->container->set('payer_page_handler', $payerPageHandler);

                add_action('init', function () {
                    if (!$this->arePayerTablesCreated()) {
                        $this->createPayerTables();
                    }
                });

                add_action('init', [$this, 'registerPayerEndpoints']);
                add_action('wp_enqueue_scripts', [$this, 'enqueuePayerAssets']);

                if (get_option('wallet_up_admin_v2', 'yes') !== 'yes') {
                    add_action('admin_menu', function () {
                        add_submenu_page(
                            'wallet-up',
                            esc_html__('Business Card', 'walletup'),
                            esc_html__('Business Card', 'walletup'),
                            'manage_options',
                            'wallet-up-business-card',
                            [$this, 'renderBusinessCardPage']
                        );
                    });

                    add_action('admin_menu', function () {
                        add_submenu_page(
                            'wallet-up',
                            esc_html__('Shortcodes', 'walletup'),
                            esc_html__('Shortcodes', 'walletup'),
                            'manage_options',
                            'wallet-up-shortcodes',
                            [$this, 'renderShortcodesPage']
                        );
                    });

                    add_action('admin_menu', function () {
                        add_submenu_page(
                            'wallet-up',
                            esc_html__('URL Shortener', 'walletup'),
                            esc_html__('URL Shortener', 'walletup'),
                            'manage_options',
                            'wallet-up-qr-shortener',
                            [$this, 'renderQRShortenerPage']
                        );
                    });
                }
            } catch (Exception $e) {
                $this->errorHandler->log('Payer system initialization failed: ' . $e->getMessage());
                throw $e;
            }
        }
    }

    private function createPayerTables(): void
    {
        if (wallet_up_fs()->can_use_premium_code__premium_only()) { // Fully premium

            global $wpdb;
            $charset_collate = $wpdb->get_charset_collate();

            $payerTrackingTable = $wpdb->prefix . 'wallet_up_payers';

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
            $sql = "CREATE TABLE {$payerTrackingTable} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            payer_id varchar(50) NOT NULL,
            payment_method varchar(50) NOT NULL,
            amount decimal(10,2) NOT NULL,
            status varchar(20) NOT NULL,
            payer_ip varchar(45),
            payer_email varchar(100),
            payer_name varchar(100),
            metadata text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY payer_id (payer_id),
            KEY payment_method (payment_method),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";

            dbDelta($sql);
        }
    }

    public function registerPayerEndpoints(): void
    {
        if (wallet_up_fs()->can_use_premium_code__premium_only()) { // Fully premium

        }
    }
    public function enqueuePayerAssets(): void
    {
        if (wallet_up_fs()->can_use_premium_code__premium_only()) { // Fully premium

            if (get_query_var('wallet_up_payer') || is_page('pay')) {

                $css_path = WALLET_UP_URL . 'assets/css/payers/payers-qr.css';
                $version = file_exists($css_path) ? filemtime($css_path) : WALLET_UP_VERSION;
                wp_enqueue_style(
                    'wallet-up-payers',
                    WALLET_UP_URL . 'assets/css/payers/payers-qr.css',
                    [],
                    $version
                );

            }
        }
    }

    public function renderPayerSettingsPage(): void
    {
        if (wallet_up_fs()->can_use_premium_code__premium_only()) { // Fully premium

            if (!current_user_can('manage_options')) {
                return;
            }

            ?>
            <div class="wrap">
                <!-- <h1> <?php echo esc_html__('URL Shortener', 'walletup'); ?></h1> -->
                <div id="wallet-up-admin-root" data-initial-tab="shorts"></div>
            </div>
            <?php
        }
    }

    public function renderBusinessCardPage(): void
    {
        if (wallet_up_fs()->can_use_premium_code__premium_only()) {
            if (!current_user_can('manage_options')) {
                return;
            }
            ?>
            <div class="wrap">
                <div id="wallet-up-admin-root" data-initial-tab="shorts" data-initial-section="business-card"></div>
            </div>
            <?php
        }
    }

    public function renderShortcodesPage(): void
    {
        if (wallet_up_fs()->can_use_premium_code__premium_only()) {
            if (!current_user_can('manage_options')) {
                return;
            }
            ?>
            <div class="wrap">
                <div id="wallet-up-admin-root" data-initial-tab="shortcodes"></div>
            </div>
            <?php
        }
    }

    public function renderQRShortenerPage(): void
    {
        if (wallet_up_fs()->can_use_premium_code__premium_only()) {
            if (!current_user_can('manage_options')) {
                return;
            }
            ?>
            <div class="wrap">
                <div id="wallet-up-admin-root" data-initial-tab="shorts" data-initial-section="qr-shortener"></div>
            </div>
            <?php
        }
    }

    public function deactivate(): void
    {
        try {
            if (class_exists('WooCommerce')) {
                if (class_exists('WC_Cache_Helper')) {
                    \WC_Cache_Helper::get_transient_version('payment_gateways', true);
                    \WC_Cache_Helper::get_transient_version('shipping', true);
                    delete_transient('wc_blocks_payment_method_type_registry');
                }

                if ($this->container->has('woocommerce')) {
                    $wc = $this->container->get('woocommerce');
                    if (method_exists($wc, 'reset')) {
                        $wc::reset();
                    }
                }
                $this->cleanupDonationData();

                $this->cleanupPayerData();

                if ($this->container->has('theme_manager')) {
                    $this->container->get('theme_manager')->cleanup();
                }

                if (class_exists('Automattic\WooCommerce\Blocks\Integrations\IntegrationRegistry')) {
                    global $woocommerce_blocks_integration_registry;
                    if (
                        isset($woocommerce_blocks_integration_registry) &&
                        method_exists($woocommerce_blocks_integration_registry, 'is_registered')
                    ) {
                        if ($woocommerce_blocks_integration_registry->is_registered('wallet_up')) {
                            $woocommerce_blocks_integration_registry->unregister('wallet_up');
                        }
                    }
                }
            }

            wp_clear_scheduled_hook('wallet_up_cleanup_analytics');
            wp_clear_scheduled_hook('walletup_process_email_queue');
            $this->clearTransients();

            wp_cache_delete('woocommerce_payment_gateways', 'options');
            delete_transient('wc_gateway_wallet_up_initialized');

            flush_rewrite_rules();
            $this->resetSingletons();

        } catch (Exception $e) {
            $this->errorHandler->log('Deactivation failed: ' . $e->getMessage());
        }
    }

    private function cleanupDonationData(): void
    {
        if (wallet_up_fs()->can_use_premium_code__premium_only()) { // Fully premium

            if (defined('WALLET_UP_REMOVE_DATA_ON_DEACTIVATE') && WALLET_UP_REMOVE_DATA_ON_DEACTIVATE) {
                global $wpdb;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Cleanup on deactivation when explicitly configured.
                $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wallet_up_donations");
                delete_option('wallet_up_donation_settings');
                delete_option('wallet_up_flush_rules');
            }
        }
    }

    private function cleanupPayerData(): void
    {
        if (wallet_up_fs()->can_use_premium_code__premium_only()) { // Fully premium

            if (defined('WALLET_UP_REMOVE_DATA_ON_DEACTIVATE') && WALLET_UP_REMOVE_DATA_ON_DEACTIVATE) {
                global $wpdb;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Cleanup on deactivation when explicitly configured.
                $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wallet_up_payers");
                delete_option('wallet_up_payer_settings');
                delete_option('wallet_up_flush_rules');
            }
        }
    }
    /**
     * Logs an error with optional method context.
     * 
     * @param string $message The error message to log.
     * @param string $method The method where the error occurred, if applicable.
     */
    private function logError(string $message, string $method = ''): void
    {
        $this->errorHandler->log(sprintf('[%s] %s', $method, $message));
    }

    private function resetSingletons(): void
    {
        $classes = [
            QRCodeService::class,
            PaymentRegistry::class,
            AssetManager::class,
            DisplayHandler::class,
            ErrorHandler::class,
            WooCommerceIntegration::class,
            WPFormsService::class
        ];

        foreach ($classes as $class) {
            if (method_exists($class, 'reset')) {
                $class::reset();
            }
        }
    }

    private function runMigrations(): void
    {
        $currentVersion = get_option('wallet_up_version', '0');
        $pluginVersion = WALLET_UP_PRO_VERSION;
        
        if (!$this->installed && version_compare($currentVersion, $pluginVersion, '<')) {
            $tableName = $this->getTableName();
            if (empty($tableName)) {
                throw new Exception(esc_html('Table name cannot be empty'));
            }
            $this->migrationManager = new MigrationManager(
                $this->db,
                $this->errorHandler,
                $tableName
            );
            $this->migrationManager->runMigrations();
            
            update_option('wallet_up_version', $pluginVersion);
            $this->installed = true;
        }
    }

    private function checkRequirements(): void
    {
        $this->verifyQRCapabilities();
    }

    private function verifyQRCapabilities(): void
    {
        if (!extension_loaded('gd') && !extension_loaded('imagick')) {
            $this->errorHandler->log('WARNING: Neither GD nor ImageMagick extension is available');
            define('WALLET_UP_QR_CODE_API', true);
            define('WALLET_UP_QR_CODE_EXTERNAL_API_FALLBACK', 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&ecc=L&margin=0&data=');
        } else {
            define('WALLET_UP_QR_CODE_API', false);
        }
    }

    /**
 * Sanitizes content based on its type while preserving special characters safely
 * 
 * @param string $content The content to sanitize
 * @param string $type The content type ('url', 'text', or 'json')
 * @param bool $decode Whether to URL-decode before sanitizing (for handling double-encoded content)
 * @return string|array Sanitized content
 */
private function sanitizeContent(string $content, string $type = 'url', bool $decode = false): mixed
{
    if (empty($content)) {
        return '';
    }

    if ($decode && $type === 'url' && strpos($content, '%') !== false) {
        $content = urldecode($content);
    }
    
    switch ($type) {
        case 'url':
            $sanitized = esc_url_raw($content);
            
            if (!empty($sanitized) && !preg_match('~^(?:f|ht)tps?://~i', $sanitized)) {
                $sanitized = 'https://' . $sanitized;
            }
            
            if (empty($sanitized) || $sanitized === 'https://') {
                $this->errorHandler->log('URL completely removed during sanitization: ' . substr($content, 0, 100));
                return '';
            }
            
            return $sanitized;
            
        case 'text':
            $allowed_html = array(
                'br' => array(),
                'em' => array(),
                'strong' => array(),
                'b' => array(),
                'i' => array(),
                'u' => array(),
                'p' => array(),
            );
            return wp_kses($content, $allowed_html);
            
        case 'json':
            $decoded = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->errorHandler->log('JSON decode error: ' . json_last_error_msg());
                return [];
            }

            if (!is_array($decoded)) {
                return [];
            }

            $sanitized = $this->sanitizeArrayRecursive($decoded);
            return $sanitized;

        default:
            return sanitize_text_field($content);
    }
}

/**
 * Recursively sanitizes all elements in an array
 * 
 * @param array $array The array to sanitize
 * @return array Sanitized array
 */
private function sanitizeArrayRecursive(array $array): array
{
    foreach ($array as $key => $value) {
        $clean_key = sanitize_text_field($key);

        if (is_array($value)) {
            $array[$clean_key] = $this->sanitizeArrayRecursive($value);
            if ($clean_key !== $key) {
                unset($array[$key]);
            }
        } else {
            if (filter_var($value, FILTER_VALIDATE_URL)) {
                $array[$clean_key] = $this->sanitizeContent($value, 'url');
            } else {
                $array[$clean_key] = sanitize_text_field($value);
            }
            
            if ($clean_key !== $key) {
                unset($array[$key]);
            }
        }
    }
    
    return $array;
}

/**
 * Enhanced URL validation that preserves special characters but ensures security
 * 
 * @param string $url The URL to validate
 * @return bool Whether the URL is valid
 */
private function isValidUrl(string $url): bool
{
    if (empty($url)) {
        return false;
    }

    $parsed = wp_parse_url($url);

    if (!$parsed || !isset($parsed['scheme']) || !isset($parsed['host'])) {
        return false;
    }

    if (!in_array(strtolower($parsed['scheme']), ['http', 'https'])) {
        return false;
    }

    if (strpos($parsed['host'], '.') === false) {
        return false;
    }

    $suspicious_patterns = [
        '/<script/i',
        '/javascript:/i',
        '/data:text/i',
        '/union\s+select/i',
        '/;\s*rm\s/i',
        '/;\s*wget\s/i',
        '/onload=/i',
        '/onerror=/i'
    ];
    
    foreach ($suspicious_patterns as $pattern) {
        if (preg_match($pattern, $url)) {
            $this->errorHandler->log('Suspicious pattern detected in URL: ' . substr($url, 0, 100));
            return false;
        }
    }
    
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}
    /**
     * Validates nonce for AJAX requests
     * 
     * @param string $action The nonce action
     * @return bool Whether the nonce is valid
     */
    private function validateNonce(string $action): bool
    {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), $action)) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return false;
        }
        return true;
    }

    /**
     * Parses settings from JSON string
     * 
     * @param string $settings JSON string of settings
     * @return array|null Parsed settings or null if invalid
     */
    private function parseSettings(string $settings): ?array
    {
        $parsed = json_decode(stripslashes($settings), true);
        if (!is_array($parsed)) {
            wp_send_json_error(['message' => 'Invalid settings data']);
            return null;
        }
        return $parsed;
    }

    private function registerAjaxHandlers(): void
    {
        add_action('wp_ajax_wallet_up_generate_qr', [$this, 'handleQRGeneration']);
        add_action('wp_ajax_nopriv_wallet_up_generate_qr', [$this, 'handleQRGeneration']);
        add_action('wp_ajax_wallet_up_get_quick_access_count', [$this, 'getQuickAccessCount']);
        add_action('wp_ajax_wallet_up_increment_quick_access', [$this, 'incrementQuickAccess']);

        if (wallet_up_fs()->can_use_premium_code__premium_only()) { // Fully premium

            add_action('wp_ajax_wallet_up_standalone_shorten_url', [$this, 'handleStandaloneUrlShortening']);
            add_action('wp_ajax_wallet_up_standalone_url_analytics', [$this, 'handleStandaloneUrlAnalytics']);
            add_action('wp_ajax_wallet_up_get_user_urls', [$this, 'handleGetUserUrls']);

            add_action('wp_ajax_wallet_up_update_long_url', [$this, 'handleUpdateLongUrl']);
            add_action('wp_ajax_wallet_up_upload_qr_logo', [$this, 'handleQRLogoUpload']);
            add_action('wp_ajax_wallet_up_upload_qr_logo_base64', [$this, 'handleQRLogoUploadBase64']);

            add_action('wp_ajax_wallet_up_delete_url', [$this, 'handleDeleteURLAjax']);
            add_action('wp_ajax_wallet_up_restore_url', [$this, 'handleRestoreURLAjax']);

            add_action('wp_ajax_wallet_up_save_timezone', [$this, 'handleSaveUserTimezone']);

            add_action('wp_ajax_wallet_up_fix_permalinks', [$this, 'handleFixPermalinks']);
            add_action('wp_ajax_wallet_up_dismiss_permalink_notice', [$this, 'handleDismissPermalinkNotice']);
            add_action('wp_ajax_wallet_up_get_permalink_status', [$this, 'handleGetPermalinkStatus']);
    }
}
    

public function handleQRLogoUpload(): void
{
    try {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
            throw new Exception(esc_html('Invalid security token'));
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- $_FILES validated via isset() and is_uploaded_file(); MIME checks below.
        if (!isset($_FILES['logo']['tmp_name']) || !is_uploaded_file($_FILES['logo']['tmp_name'])) {
            throw new Exception(esc_html('No logo file uploaded'));
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_FILES is validated via is_uploaded_file, wp_check_filetype, and MIME checks below.
        $file = $_FILES['logo'];

        $max_size = 2 * 1024 * 1024; // 2MB
        if ($file['size'] > $max_size) {
            throw new Exception(esc_html('Logo file exceeds maximum size of 2MB'));
        }

        $allowed_types = ['image/png', 'image/jpeg', 'image/svg+xml'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detected_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!in_array($detected_type, $allowed_types)) {
            throw new Exception(esc_html('Invalid file type. Only PNG, JPEG, and SVG are allowed'));
        }

        if ($detected_type === 'image/svg+xml') {
            $svg_content = file_get_contents($file['tmp_name']);

            if (preg_match('/<script|javascript:|on[a-z]+\s*=|<foreignObject|xlink:href|data:\s*text\/html|<iframe|<embed|<object|expression\s*\(|url\s*\(\s*["\']?\s*javascript/i', $svg_content)) {
                throw new Exception(esc_html('SVG contains potentially malicious content'));
            }
        }
        
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/wallet-up-temp';

        if (!file_exists($temp_dir)) {
            wp_mkdir_p($temp_dir);

            file_put_contents($temp_dir . '/.htaccess', 'Deny from all');
        }

        $filename = md5(uniqid((string) wp_rand(), true)) . '.' . pathinfo($file['name'], PATHINFO_EXTENSION);
        $filepath = $temp_dir . '/' . $filename;

        if (!wp_is_writable($temp_dir) || !move_uploaded_file($file['tmp_name'], $filepath)) { // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- WordPress wp_handle_upload() not applicable for non-standard upload directories.
            throw new Exception(esc_html('Failed to upload logo file'));
        }

        wp_send_json_success([
            'message' => 'Logo uploaded successfully',
            'file' => [
                'name' => $filename,
                'url' => $upload_dir['baseurl'] . '/wallet-up-temp/' . $filename,
                'path' => $filepath
            ]
        ]);
        
    } catch (Exception $e) {
        $this->errorHandler->log('Logo upload error: ' . $e->getMessage());
        wp_send_json_error(['message' => $e->getMessage()]);
    }
}

public function handleQRLogoUploadBase64(): void
{
    try {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
            throw new Exception(esc_html('Invalid security token'));
        }

        if (!isset($_POST['logo_data']) || empty($_POST['logo_data'])) {
            throw new Exception(esc_html('No logo data provided'));
        }

        $logo_data = sanitize_text_field(wp_unslash($_POST['logo_data'] ?? ''));

        if (!preg_match('/^data:image\/(\w+);base64,/', $logo_data, $matches)) {
            throw new Exception(esc_html('Invalid base64 format'));
        }

        $file_extension = $matches[1];
        $file_type = 'image/' . $file_extension;

        $allowed_types = ['image/png', 'image/jpeg', 'image/svg+xml'];
        $allowed_extensions = ['png', 'jpg', 'jpeg', 'svg'];

        if (!in_array($file_type, $allowed_types) || !in_array($file_extension, $allowed_extensions)) {
            throw new Exception(esc_html('Invalid file type. Only PNG, JPEG, and SVG are allowed'));
        }

        $base64_data = substr($logo_data, strpos($logo_data, ',') + 1);
        $decoded_data = base64_decode($base64_data);

        if (!$decoded_data) {
            throw new Exception(esc_html('Invalid base64 data'));
        }

        if (strlen($decoded_data) > 2 * 1024 * 1024) {
            throw new Exception(esc_html('Logo file exceeds maximum size of 2MB'));
        }

        if ($file_type === 'image/svg+xml') {
            if (preg_match('/<script|javascript:|on[a-z]+=/i', $decoded_data)) {
                throw new Exception(esc_html('SVG contains potentially malicious content'));
            }
        }

        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/wallet-up-temp';

        if (!file_exists($temp_dir)) {
            wp_mkdir_p($temp_dir);
            file_put_contents($temp_dir . '/.htaccess', 'Deny from all');
        }

        $filename = md5(uniqid((string) wp_rand(), true)) . '.' . $file_extension;
        $filepath = $temp_dir . '/' . $filename;

        if (!file_put_contents($filepath, $decoded_data)) {
            throw new Exception(esc_html('Failed to save logo file'));
        }

        $public_url = $upload_dir['baseurl'] . '/wallet-up-temp/' . $filename;
        
        wp_send_json_success([
            'filename' => $filename,
            'url' => $public_url,
            'path' => $filepath
        ]);
        
    } catch (Exception $e) {
        $this->errorHandler->log('Base64 logo upload error: ' . $e->getMessage());
        wp_send_json_error(['message' => $e->getMessage()]);
    }
}

public function handleQRGeneration(): void
{
    try {
        header('Content-Type: application/json; charset=UTF-8');
        
        if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
            throw new Exception(esc_html('Invalid QR Generation security token'));
        }

        $contentType = isset($_POST['content_type']) ? sanitize_text_field(wp_unslash($_POST['content_type'])) : 'url';
        $data = isset($_POST['data']) ? $this->sanitizeContent(sanitize_text_field(wp_unslash($_POST['data'] ?? '')), $contentType) : '';
        $settings = isset($_POST['settings']) ? $this->sanitizeContent(sanitize_text_field(wp_unslash($_POST['settings'] ?? '')), 'json') : [];

        $settings['contentType'] = $contentType;

        if (empty($data)) {
            throw new Exception(esc_html('No data provided for QR code'));
        }

        $qrImage = $this->getQRService()->generateQR($data, $settings);

        wp_send_json_success(['qr' => $qrImage]);
    } catch (Exception $e) {
        $this->errorHandler->log('QR generation error: ' . $e->getMessage());
        wp_send_json_error(['message' => $e->getMessage()]);
    }
}

    public function getQuickAccessCount(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new Exception(esc_html('Invalid security token'));
            }

            $count = get_option('wallet_up_quick_access_count', 0);
            wp_send_json_success(['count' => $count]);

        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function incrementQuickAccess(): void
    {
        try {
            if (!current_user_can('manage_options')) {
                wp_send_json_error('Unauthorized', 403);
            }

            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new Exception(esc_html('Invalid security token'));
            }

            $count = get_option('wallet_up_quick_access_count', 0);
            update_option('wallet_up_quick_access_count', $count + 1);
            wp_send_json_success(['count' => $count + 1]);

        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

public function handleStandaloneUrlShortening(): void
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized', 403);
    }

    if (wallet_up_fs()->can_use_premium_code__premium_only()) { // Fully premium
        header('Content-Type: application/json; charset=UTF-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');

        if (sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'] ?? '')) === 'OPTIONS') {
            status_header(200);
            exit();
        }
        
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new Exception(esc_html('Invalid security token'));
            }

            $long_url = isset($_POST['long_url']) ? $this->sanitizeContent(sanitize_text_field(wp_unslash($_POST['long_url'] ?? '')), 'url') : '';

            if (empty($long_url)) {
                throw new Exception(esc_html('URL is required'));
            }

            if (!$this->isValidUrl($long_url)) {
                throw new Exception(esc_html('Invalid URL format - please check the URL and try again'));
            }

            $shortener = $this->container->get('url_shortener');

            $options = [];

            if (isset($_POST['expires_in']) && !empty($_POST['expires_in']) && sanitize_text_field(wp_unslash($_POST['expires_in'] ?? '')) !== 'never') {
                $options['expires_in'] = intval(wp_unslash($_POST['expires_in']));
            }

            if (isset($_POST['custom_slug']) && !empty($_POST['custom_slug'])) {
                $custom_slug = sanitize_text_field(wp_unslash($_POST['custom_slug']));
                if (!preg_match('/^[a-zA-Z0-9-]{1,12}$/', $custom_slug)) {
                    throw new Exception(esc_html('Custom slug must contain only letters, numbers, and hyphens (max 12 characters)'));
                }
                $options['custom_slug'] = $custom_slug;
            }

            if (isset($_POST['metadata']) && !empty($_POST['metadata'])) {
                try {
                    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via sanitizeContent() JSON sanitizer below.
                    $raw_metadata = wp_unslash($_POST['metadata']);
                    $metadata = $this->sanitizeContent($raw_metadata, 'json');
                    if (empty($metadata)) {
                        throw new Exception(esc_html('Invalid metadata format'));
                    }
                    $options['metadata'] = $metadata;
                } catch (Exception $e) {
                    $this->errorHandler->log('Metadata parsing error: ' . $e->getMessage());
                }
            }

            $options['track_clicks'] = isset($_POST['track_clicks']) && sanitize_text_field(wp_unslash($_POST['track_clicks'] ?? '')) === '1';

            if (is_user_logged_in()) {
                $options['user_id'] = get_current_user_id();
            }

            $short_url = $shortener->shortenURL($long_url, $options);

            wp_send_json_success([
                'message' => 'URL shortened successfully',
                'short_url' => $short_url,
                'long_url' => $long_url
            ]);

        } catch (Exception $e) {
            $this->errorHandler->log('URL shortening error: ' . $e->getMessage());
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }
}

    public function handleUpdateLongUrl(): void
    {
        if (wallet_up_fs()->can_use_premium_code__premium_only()) { // Fully premium
            header('Content-Type: application/json; charset=UTF-8');
            
            try {
                if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                    throw new Exception(esc_html('Invalid security token'));
                }

                $userId = get_current_user_id();
                if (!$userId) {
                    throw new Exception(esc_html('User not logged in'));
                }

                $code = isset($_POST['code']) ? sanitize_text_field(wp_unslash($_POST['code'])) : '';
                $newLongUrl = isset($_POST['new_long_url']) ? $this->sanitizeContent(sanitize_text_field(wp_unslash($_POST['new_long_url'] ?? '')), 'url') : '';

                $modifiedAt = isset($_POST['modified_at']) ? sanitize_text_field(wp_unslash($_POST['modified_at'])) : null;

                if (empty($code)) {
                    throw new Exception(esc_html('Missing URL code'));
                }

                if (empty($newLongUrl)) {
                    throw new Exception(esc_html('Missing new URL destination'));
                }

                if (!$this->isValidUrl($newLongUrl)) {
                    throw new Exception(esc_html('Invalid URL format. Please check the URL and try again.'));
                }
                
                $this->errorHandler->log(sprintf(
                    'URL update request: Code=%s, NewURL=%s, UserID=%d, ModifiedAt=%s',
                    $code,
                    substr($newLongUrl, 0, 100),
                    $userId,
                    $modifiedAt ?? 'auto'
                ));

                $shortener = $this->container->get('url_shortener');
                $success = $shortener->updateLongUrl($code, $newLongUrl, $userId, $modifiedAt);

                if (!$success) {
                    throw new Exception(esc_html('Failed to update URL destination'));
                }

                $formattedTimestamp = \WalletUpPro\Core\Utilities\TimestampUtility::formatTimestamp(
                    \WalletUpPro\Core\Utilities\TimestampUtility::toUTCTimestamp($modifiedAt),
                    'mysql'
                );

                wp_send_json_success([
                    'message' => 'URL updated successfully',
                    'code' => $code,
                    'new_long_url' => $newLongUrl,
                    'modified_at' => $formattedTimestamp
                ]);
            } catch (Exception $e) {
                $this->errorHandler->log('URL update error: ' . $e->getMessage());
                wp_send_json_error(['message' => $e->getMessage()]);
            }
        } else {
            wp_send_json_error(['message' => 'This feature requires a premium license']);
        }
    }

public function handleStandaloneUrlAnalytics(): void
{
    if (wallet_up_fs()->can_use_premium_code__premium_only()) { // Fully premium

        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');

        if (sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'] ?? '')) === 'OPTIONS') {
            status_header(200);
            exit();
        }

        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new Exception(esc_html('Invalid security token'));
            }

            $code = isset($_POST['code']) ? sanitize_text_field(wp_unslash($_POST['code'])) : '';
            if (empty($code)) {
                throw new Exception(esc_html('URL code is required'));
            }

            $userId = get_current_user_id();
            if (!$userId) {
                throw new Exception(esc_html('User not authenticated'));
            }

            $period = isset($_POST['period']) ? sanitize_text_field(wp_unslash($_POST['period'])) : 'all';
            $includeDetails = isset($_POST['include_details']) ? filter_var(wp_unslash($_POST['include_details']), FILTER_VALIDATE_BOOLEAN) : true;

            $shortener = $this->container->get('url_shortener');
            $analytics = $shortener->getAnalytics($code, $userId);

            if (!$analytics) {
                throw new Exception(esc_html('No analytics data found for this URL'));
            }

            if (isset($analytics['user_id']) && $analytics['user_id'] != $userId && !current_user_can('manage_options')) {
                throw new Exception(esc_html('You do not have permission to view analytics for this URL'));
            }

            $analytics['_request'] = [
                'period' => $period,
                'include_details' => $includeDetails,
                'requested_at' => TimestampUtility::getCurrentUTCTimestamp(),
                'user_id' => $userId
            ];

            wp_send_json_success($analytics);
        } catch (Exception $e) {
            $this->errorHandler->log('URL analytics error: ' . $e->getMessage());
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    } else {
        wp_send_json_error(['message' => 'This feature requires a premium license']);
    }
}
    public function handleGetUserUrls(): void
    {
        if (wallet_up_fs()->can_use_premium_code__premium_only()) { // Fully premium

            try {
                if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                    throw new Exception(esc_html('Invalid security token'));
                }

                $userId = get_current_user_id();
                if (!$userId) {
                    throw new Exception(esc_html('User not logged in'));
                }

                $shortener = $this->container->get('url_shortener');
                $urls = $shortener->getUserURLs($userId, 50, 0);

                wp_send_json_success([
                    'urls' => $urls
                ]);
            } catch (Exception $e) {
                wp_send_json_error(['message' => $e->getMessage()]);
            }
        }
    }

    public function handleDeleteURLAjax(): void
    {
        if (wallet_up_fs()->can_use_premium_code__premium_only()) { // Fully premium

            try {
                if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'wallet_up_admin_nonce')) {
                    wp_send_json_error([
                        'message' => 'Security check failed'
                    ], 403);
                    return;
                }

                if (!current_user_can('manage_options')) {
                    wp_send_json_error('Unauthorized', 403);
                }

                if (!isset($_POST['code']) || empty($_POST['code'])) {
                    wp_send_json_error([
                        'message' => 'Missing URL code'
                    ], 400);
                    return;
                }

                $code = sanitize_text_field(wp_unslash($_POST['code']));

                $userId = get_current_user_id();

                $hardDelete = isset($_POST['hard_delete']) && sanitize_text_field(wp_unslash($_POST['hard_delete'] ?? '')) === 'true';

                if (!isset($this->urlShortener)) {
                    $this->urlShortener = new URLShortenerService();
                }

                $result = $this->urlShortener->deleteShortUrl($code, $userId, $hardDelete);

                if ($result['success']) {
                    wp_send_json_success([
                        'message' => $result['message'],
                        'code' => $result['code'],
                        'hardDelete' => $hardDelete
                    ]);
                } else {
                    $statusCode = $result['status'] ?? 500;

                    wp_send_json_error([
                        'message' => $result['message'],
                        'code' => $result['code']
                    ], $statusCode);
                }
            } catch (Exception $e) {
                $this->errorHandler->log('URL deletion error: ' . $e->getMessage());
                wp_send_json_error([
                    'message' => $e->getMessage()
                ], 500);
            }
        }
    }

    public function handleRestoreURLAjax(): void
    {
        if (wallet_up_fs()->can_use_premium_code__premium_only()) { // Fully premium
            if (wallet_up_fs()->is_plan_or_trial('pro')) {

                try {
                    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'wallet_up_admin_nonce')) {
                        wp_send_json_error([
                            'message' => 'Security check failed'
                        ], 403);
                        return;
                    }

                    if (!current_user_can('manage_options')) {
                        wp_send_json_error('Unauthorized', 403);
                    }

                    if (!isset($_POST['code']) || empty($_POST['code'])) {
                        wp_send_json_error([
                            'message' => 'Missing URL code'
                        ], 400);
                        return;
                    }

                    $code = sanitize_text_field(wp_unslash($_POST['code']));

                    $userId = get_current_user_id();

                    if (!isset($this->urlShortener)) {
                        $this->urlShortener = new \WalletUpPro\Services\URLShortenerService();
                    }

                    $result = $this->urlShortener->restoreURL($code, $userId);

                    if ($result['success']) {
                        wp_send_json_success([
                            'message' => $result['message'],
                            'code' => $result['code']
                        ]);
                    } else {
                        $statusCode = $result['status'] ?? 500;

                        wp_send_json_error([
                            'message' => $result['message'],
                            'code' => $result['code']
                        ], $statusCode);
                    }
                } catch (Exception $e) {
                    $this->errorHandler->log('URL restoration error: ' . $e->getMessage());
                    wp_send_json_error([
                        'message' => $e->getMessage()
                    ], 500);
                }
            } else {
                wp_send_json_error([
                    'message' => __('Your plan does not include URL restore', 'walletup')
                ], 403);
            }
        }
    }

public function handleSaveUserTimezone(): void
{
    try {
        if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
            throw new Exception(esc_html('Invalid security token'));
        }

        $userId = get_current_user_id();
        if (!$userId) {
            throw new Exception(esc_html('User not logged in'));
        }

        $timezone = isset($_POST['timezone']) ? sanitize_text_field(wp_unslash($_POST['timezone'])) : '';

        if (empty($timezone)) {
            throw new Exception(esc_html('No timezone provided'));
        }

        $validTimezones = timezone_identifiers_list();
        if (!in_array($timezone, $validTimezones) && !preg_match('/^UTC[+-]\d{1,2}(:\d{2})?$/', $timezone)) {
            throw new Exception(esc_html('Invalid timezone format'));
        }

        update_user_meta($userId, 'wallet_up_timezone', $timezone);

        wp_send_json_success([
            'message' => 'Timezone preference saved',
            'timezone' => $timezone
        ]);
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
}

    private function createDefaultOptions(): void
    {
        $defaults = [
            'wallet_up_transfer_call' => [],
            'wallet_up_display_settings' => [
                'defaultMode' => 'qr',
                'qrSize' => 'medium',
                'qrColor' => '#674FBF',
                'qrBackground' => '#FFFFFF',
                'qrBorder' => false,
                'qrStyle' => 'square',
                'showDefaultMessage' => true,
                'customMessage' => '',
                'hideMessage' => false
            ]
        ];

        foreach ($defaults as $option => $value) {
            if (false === get_option($option)) {
                add_option($option, $value);
            }
        }
    }

    public function handleFixPermalinks(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_fix_permalinks', '_wpnonce', false)) {
                throw new Exception(esc_html('Invalid security token'));
            }

            if (!current_user_can('manage_options')) {
                throw new Exception(esc_html('You do not have permission to change permalinks'));
            }

            update_option('permalink_structure', '/%postname%/');

            flush_rewrite_rules();

            if ($this->container->has('rewrite_manager')) {
                $rewriteManager = $this->container->get('rewrite_manager');
                $rewriteManager->forceFlushRules();
            }
            
            wp_send_json_success([
                'message' => 'Permalink structure updated successfully'
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function handleDismissPermalinkNotice(): void
    {
        if (!check_ajax_referer('wallet_up_dismiss_notice', '_wpnonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }

        update_user_meta(get_current_user_id(), 'wallet_up_permalink_notice_dismissed', true);
        
        wp_send_json_success();
    }

    public function handleGetPermalinkStatus(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
            return;
        }
        
        if ($this->container->has('rewrite_manager')) {
            $rewriteManager = $this->container->get('rewrite_manager');
            $status = $rewriteManager->getPermalinkStatus();
            wp_send_json_success($status);
        } else {
            wp_send_json_error(['message' => 'Rewrite manager not available']);
        }
    }

    /**
     * Health check for plugin services and integrations
     * 
     * @return array Status information for plugin components
     */
    public function healthCheck(): array
    {
        $health = [
            'wpforms' => [
                'status' => 'error',
                'message' => 'WPForms service not registered in container.'
            ]
        ];

        if ($this->container->has('wpforms')) {
            $wpformsService = $this->container->get('wpforms');
            if ($wpformsService->isActive()) {
                $health['wpforms'] = [
                    'status' => 'ok',
                    'message' => sprintf(
                        'WPForms v%s is active and integrated. Template is %s.',
                        $wpformsService->getVersion(),
                        $wpformsService->isTemplateEnabled() ? 'enabled' : 'disabled'
                    )
                ];
            } else {
                $health['wpforms'] = [
                    'status' => 'error',
                    'message' => 'WPForms is installed but not active.'
                ];
            }
        }

        return $health;
    }

    private function clearTransients(): void
    {
        if (wp_using_ext_object_cache()) {
            if (function_exists('wp_cache_flush_group')) {
                wp_cache_flush_group('walletup');
            }
            return;
        }

        global $wpdb;
        
        $query = "SELECT option_name FROM {$wpdb->options} 
                  WHERE option_name LIKE %s 
                  OR option_name LIKE %s 
                  OR option_name LIKE %s";

        $transients = $wpdb->get_col($wpdb->prepare(
            $query,
            $wpdb->esc_like('_transient_walletup_') . '%',
            $wpdb->esc_like('_transient_wallet_up_') . '%',
            $wpdb->esc_like('_transient_url_stats_') . '%'
        ));

        if (!empty($transients)) {
            foreach ($transients as $transient_key) {
                $timeout_key = str_replace('_transient_', '_transient_timeout_', $transient_key);
                delete_option($transient_key);
                delete_option($timeout_key);
            }
        }
    }

    /**
     * Gets the database manager instance.
     * 
     * @return DatabaseManager The database manager.
     */
    public function getDatabase(): DatabaseManager
    {
        return $this->db;
    }

    /**
     * Gets the payment registry instance.
     * 
     * @return PaymentRegistry The payment registry.
     */
    public function getRegistry(): PaymentRegistry
    {
        return $this->registry;
    }

    public static function purgePaymentCaches(): void
    {
        $purges = [
            static function () {
                if (function_exists('wp_cache_flush')) {
                    wp_cache_flush();
                }
            },
            static function () {
                if (function_exists('sg_cachepress_purge_cache')) {
                    sg_cachepress_purge_cache();
                } elseif (has_action('siteground_optimizer_flush_cache') || class_exists('\\SiteGround_Optimizer\\Supercacher\\Supercacher')) {
                    do_action('siteground_optimizer_flush_cache');
                }
            },
            static function () {
                if (defined('LSCWP_V') || has_action('litespeed_purge_all')) {
                    do_action('litespeed_purge_all');
                }
            },
            static function () {
                if (function_exists('rocket_clean_domain')) {
                    rocket_clean_domain();
                }
            },
            static function () {
                if (function_exists('w3tc_flush_all')) {
                    w3tc_flush_all();
                }
            },
            static function () {
                if (function_exists('wp_cache_clear_cache')) {
                    wp_cache_clear_cache();
                }
            },
            static function () {
                if (function_exists('wpfc_clear_all_cache')) {
                    wpfc_clear_all_cache(true);
                }
            },
            static function () {
                if (class_exists('\\autoptimizeCache') && method_exists('\\autoptimizeCache', 'clearall')) {
                    \autoptimizeCache::clearall();
                }
            },
        ];

        foreach ($purges as $purge) {
            try {
                $purge();
            } catch (\Throwable $e) {
            }
        }
    }

    /**
     * Gets the QR code service instance.
     * 
     * @return QRCodeService The QR code service.
     */
    public function getQRService(): QRCodeService
    {
        if (!$this->qrService instanceof QRCodeService) {
            $this->qrService = QRCodeService::getInstance();
        }
        return $this->qrService;
    }

    /**
     * Gets the error handler instance.
     * 
     * @return ErrorHandler The error handler.
     */
    public function getErrorHandler(): ErrorHandler
    {
        return $this->errorHandler;
    }

    public function declareWooCommerceCompatibility(): void
    {
        try {
            if (!class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
                return;
            }

            // CRITICAL: WC requires the MAIN plugin file (wallet-up.php with Plugin Name header)
            $main_plugin_file = dirname(WALLET_UP_FILE) . '/wallet-up.php';

            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                $main_plugin_file,
                true
            );

            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'cart_checkout_blocks',
                $main_plugin_file,
                true
            );

        } catch (Exception $e) {
            $this->errorHandler->log('Failed to declare WooCommerce compatibility: ' . $e->getMessage());
        }
    }

    private function areDonationTablesCreated(): bool
    {
        return get_option('wallet_up_donation_schema_version') === self::DONATION_SCHEMA_VERSION;
    }

    private function arePayerTablesCreated(): bool
    {
        $cache_key = 'wallet_up_payer_tables_exist';
        $cached_result = get_transient($cache_key);
        
        if ($cached_result !== false) {
            return $cached_result;
        }
        
        global $wpdb;
        
        $payers_table = $wpdb->prefix . 'wallet_up_payers';
        $transaction_table = $wpdb->prefix . 'wallet_up_payer_transactions';
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fresh data required for accurate results.
        $tables_exist = $wpdb->get_var("SHOW TABLES LIKE '$payers_table'") == $payers_table &&
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fresh data required for accurate results.
                        $wpdb->get_var("SHOW TABLES LIKE '$transaction_table'") == $transaction_table;
        
        set_transient($cache_key, $tables_exist, 3600);
        
        return $tables_exist;
    }

    public function cleanup(): void
    {
        $this->container->cleanup();
    }
}