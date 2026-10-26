<?php

namespace WalletUpPro;

use WalletUpPro\Helpers\ClassManager;
use WalletUpPro\Helpers\ClassLoaderHelper;
use WalletUpPro\API\ClassManagerREST;
use WalletUpPro\Compatibility\walletUpCompatibilityHandler;
use WalletUpPro\Core\PaymentInit;
use WalletUpPro\Core\Activation\ActivationHandler;
if ( !defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\PremiumPluginManager', false ) ) {
class PremiumPluginManager {
    private static $instance = null;

    const FREE_PLUGIN_BASENAME = 'wallet-up/wallet-up.php';

    public static function getInstance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->ensurePluginFunctionsLoaded();
        if ( self::FREE_PLUGIN_BASENAME === plugin_basename( WALLET_UP_PRO_FILE ) ) {
            return;
        }
        $this->deactivateFreeVersion();
        add_filter(
            'plugin_action_links',
            [$this, 'modifyFreePluginActions'],
            10,
            4
        );
        add_action(
            'after_plugin_row_' . self::FREE_PLUGIN_BASENAME,
            [$this, 'addFreePluginNotice'],
            10,
            3
        );
        add_action( 'admin_footer', [$this, 'addDeactivationScripts'] );
    }

    private function ensurePluginFunctionsLoaded() {
        if ( !function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
    }

    public function deactivateFreeVersion() {
        if ( self::FREE_PLUGIN_BASENAME === plugin_basename( WALLET_UP_PRO_FILE ) ) {
            return;
        }
        if ( !file_exists( WP_PLUGIN_DIR . '/' . self::FREE_PLUGIN_BASENAME ) ) {
            return;
        }
        if ( is_plugin_active( self::FREE_PLUGIN_BASENAME ) ) {
            deactivate_plugins( self::FREE_PLUGIN_BASENAME, true );
            set_transient( 'wallet_up_free_deactivated', 1, 60 );
            add_action( 'admin_notices', function () {
                echo '<div class="notice notice-info is-dismissible"><p>';
                echo esc_html__( 'Wallet Up free version has been automatically deactivated because the Pro version is active.', 'walletup' );
                echo '</p></div>';
            } );
        }
    }

    public function modifyFreePluginActions(
        $actions,
        $plugin_file,
        $plugin_data,
        $context
    ) {
        if ( $plugin_file !== self::FREE_PLUGIN_BASENAME ) {
            return $actions;
        }
        if ( isset( $actions['activate'] ) ) {
            unset($actions['activate']);
        }
        if ( isset( $actions['delete'] ) ) {
            $actions['delete'] = sprintf(
                '<a href="%s" class="delete wallet-up-delete-free" aria-label="%s" style="color: #b32d2e; font-weight: bold;">%s</a>',
                esc_url( wp_nonce_url( 'plugins.php?action=delete-selected&amp;checked[]=' . $plugin_file . '&amp;plugin_status=inactive', 'bulk-plugins' ) ),
                esc_attr__( 'Delete Wallet Up Free Version', 'walletup' ),
                esc_html__( 'Delete', 'walletup' )
            );
        }
        $actions['pro-active'] = sprintf( '<span class="wallet-up-pro-active-notice" style="color: #72aee6; cursor: help;" title="%s">%s</span>', esc_attr__( 'You are using Wallet Up Pro. The free version cannot be activated simultaneously.', 'walletup' ), esc_html__( 'Pro Active', 'walletup' ) );
        return $actions;
    }

    public function addFreePluginNotice( $plugin_file, $plugin_data, $status ) {
        echo '<tr class="wallet-up-free-notice-row"><td colspan="4" class="plugin-update wallet-up-free-notice">';
        echo '<div class="notice inline notice-info notice-alt" style="margin: 0; padding: 8px 12px; border-left: 4px solid #72aee6;">';
        echo '<p>' . esc_html__( 'The free version of Wallet Up is currently inactive because you are using the Pro version. You can safely delete the free version.', 'walletup' ) . '</p>';
        echo '</div></td></tr>';
    }

    public function addDeactivationScripts() {
        $screen = get_current_screen();
        if ( !$screen || $screen->base !== 'plugins' ) {
            return;
        }
        ?>

        <script type="text/javascript">
            document.addEventListener('DOMContentLoaded', function () {
                var deleteButtons = document.querySelectorAll('.wallet-up-delete-free');

                deleteButtons.forEach(function (button) {
                    button.addEventListener('click', function (e) {
                        if (!confirm('<?php 
        echo esc_js( __( 'Since you are using Wallet Up Pro, you can safely delete the free version. Would you like to proceed?', 'walletup' ) );
        ?>')) {
                            e.preventDefault();
                        }
                    });
                });
            });
        </script>
    <?php
    }

}
}

if ( get_transient( 'wallet_up_free_deactivated' ) ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-success is-dismissible"><p>';
        echo esc_html__( 'Wallet Up free version has been automatically deactivated because the Pro version is active.', 'walletup' );
        echo '</p></div>';
        delete_transient( 'wallet_up_free_deactivated' );
    } );
}
// which has the Plugin Name header — WooCommerce FeaturesUtil requires this
define( 'WALLET_UP_PRO_VERSION', '6.3.9' );
define( 'WALLET_UP_PRO_PRODUCT_NAME', 'Wallet Up Pro' );
define( 'WALLET_UP_IS_PRO_BUILD', false );
define( 'WALLET_UP_PRO_FILE', dirname( __FILE__ ) . '/wallet-up.php' );
define( 'WALLET_UP_PRO_PATH', plugin_dir_path( __FILE__ ) );
define( 'WALLET_UP_PRO_URL', plugin_dir_url( __FILE__ ) );
define( 'WALLET_UP_PRO_BASE_DIR', plugin_dir_path( __FILE__ ) );
define( 'WALLET_UP_PRO_BASE_URL', plugin_dir_url( __FILE__ ) );
define( 'WALLET_UP_PRO_BASENAME', plugin_basename( dirname( __FILE__ ) . '/wallet-up.php' ) );
define( 'WALLET_UP_PRO_BLOCKS_VERSION', '1.0.2' );
define( 'WALLET_UP_PRO_BLOCKS_MANIFEST', WALLET_UP_PRO_PATH . 'walup-react/dist/.vite/manifest.json' );
define( 'WALLET_UP_PRO_BLOCKS_URL', plugins_url( 'walup-react/dist/', WALLET_UP_PRO_FILE ) );

if ( ! function_exists( 'WalletUpPro\\wallet_up_upgrade_url' ) ) {
    function wallet_up_upgrade_url() {
        if ( function_exists( 'WalletUpPro\\wallet_up_fs' ) ) {
            $fs = wallet_up_fs();
            if ( is_object( $fs ) ) {
                if ( wallet_up_pricing_reachable( $fs ) && method_exists( $fs, 'get_upgrade_url' ) ) {
                    $url = $fs->get_upgrade_url();
                    if ( ! empty( $url ) ) {
                        return $url;
                    }
                }
                if ( method_exists( $fs, 'get_activation_url' ) ) {
                    $url = $fs->get_activation_url();
                    if ( ! empty( $url ) ) {
                        return $url;
                    }
                }
            }
        }
        return 'https://walletup.app/pricing/'; // zi2-dry: ignore
    }
}

if ( !function_exists( __NAMESPACE__ . '\\wallet_up_in_activation_mode' ) ) {
    function wallet_up_in_activation_mode( $fs = null ) {
        if ( null === $fs && function_exists( 'WalletUpPro\\wallet_up_fs' ) ) {
            $fs = wallet_up_fs();
        }
        return is_object( $fs ) && method_exists( $fs, 'is_activation_mode' ) && $fs->is_activation_mode();
    }
}

if ( !function_exists( __NAMESPACE__ . '\\wallet_up_pricing_reachable' ) ) {
    function wallet_up_pricing_reachable( $fs ) {
        if ( ! is_object( $fs ) ) {
            return false;
        }
        $pricing = method_exists( $fs, 'is_pricing_page_visible' ) ? $fs->is_pricing_page_visible() : true;
        return ! wallet_up_in_activation_mode( $fs ) && $pricing;
    }
}

if ( !function_exists( __NAMESPACE__ . '\\wallet_up_premium_active' ) ) {
    function wallet_up_premium_active() {
        $fs = function_exists( __NAMESPACE__ . '\\wallet_up_fs' ) ? wallet_up_fs() : null;
        return defined( 'WALLET_UP_IS_PRO_BUILD' ) && WALLET_UP_IS_PRO_BUILD
            && is_object( $fs ) && method_exists( $fs, 'can_use_premium_code' ) && $fs->can_use_premium_code();
    }
}

if ( !function_exists( __NAMESPACE__ . '\\wallet_up_page_slug' ) ) {
    function wallet_up_page_slug( $suffix = '' ) {
        $fs   = function_exists( 'WalletUpPro\\wallet_up_fs' ) ? wallet_up_fs() : null;
        $base = is_object( $fs ) && method_exists( $fs, 'get_menu_slug' ) ? (string) $fs->get_menu_slug() : '';
        return '' === $suffix || '' === $base ? $base : $base . '-' . $suffix;
    }
}

if ( !function_exists( __NAMESPACE__ . '\\wallet_up_admin_page_url' ) ) {
    function wallet_up_admin_page_url( $page ) {
        $slug = wallet_up_in_activation_mode() ? wallet_up_page_slug() : (string) $page;
        return admin_url( 'admin.php?page=' . $slug );
    }
}

if ( !function_exists( __NAMESPACE__ . '\\wallet_up_is_preview_sandbox' ) ) {
    function wallet_up_is_preview_sandbox() {
        $host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : '';
        if ( $host !== '' && strpos( $host, 'playground.wordpress.net' ) !== false ) {
            return true;
        }
        $software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( (string) $_SERVER['SERVER_SOFTWARE'] ) : '';
        if ( $software !== '' && strpos( $software, 'wasm' ) !== false ) {
            return true;
        }
        $sapi = function_exists( 'php_sapi_name' ) ? strtolower( (string) php_sapi_name() ) : '';
        if ( $sapi !== '' && strpos( $sapi, 'wasm' ) !== false ) {
            return true;
        }
        return false;
    }
}
if ( !defined( 'WALLET_UP_VERSION' ) ) {
    define( 'WALLET_UP_VERSION', WALLET_UP_PRO_VERSION );
}
if ( !defined( 'WALLET_UP_FILE' ) ) {
    define( 'WALLET_UP_FILE', WALLET_UP_PRO_FILE );
}
if ( !defined( 'WALLET_UP_PATH' ) ) {
    define( 'WALLET_UP_PATH', WALLET_UP_PRO_PATH );
}
if ( !defined( 'WALLET_UP_URL' ) ) {
    define( 'WALLET_UP_URL', WALLET_UP_PRO_URL );
}
if ( !defined( 'WALLET_UP_BASE_DIR' ) ) {
    define( 'WALLET_UP_BASE_DIR', WALLET_UP_PRO_BASE_DIR );
}
if ( !defined( 'WALLET_UP_BASE_URL' ) ) {
    define( 'WALLET_UP_BASE_URL', WALLET_UP_PRO_BASE_URL );
}
if ( !defined( 'WALLET_UP_BASENAME' ) ) {
    define( 'WALLET_UP_BASENAME', WALLET_UP_PRO_BASENAME );
}
if ( !defined( 'WALLET_UP_BLOCKS_VERSION' ) ) {
    define( 'WALLET_UP_BLOCKS_VERSION', WALLET_UP_PRO_BLOCKS_VERSION );
}
if ( !defined( 'WALLET_UP_BLOCKS_MANIFEST' ) ) {
    define( 'WALLET_UP_BLOCKS_MANIFEST', WALLET_UP_PRO_BLOCKS_MANIFEST );
}
if ( !defined( 'WALLET_UP_BLOCKS_URL' ) ) {
    define( 'WALLET_UP_BLOCKS_URL', WALLET_UP_PRO_BLOCKS_URL );
}
if ( !defined( 'WALLET_UP_USE_DATABASE' ) ) {
    define( 'WALLET_UP_USE_DATABASE', true );
}
if ( !defined( 'WALLET_UP_TEMPLATES_PATH' ) ) {
    define( 'WALLET_UP_TEMPLATES_PATH', WALLET_UP_PRO_PATH . 'templates/' );
}
$walletup_autoloader_path = WALLET_UP_PRO_PATH . 'vendor-pro/autoload.php'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
if ( file_exists( $walletup_autoloader_path ) ) {
    require_once $walletup_autoloader_path;
} else {
    $walletup_standard_autoloader = WALLET_UP_PRO_PATH . 'vendor/autoload.php'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    if ( file_exists( $walletup_standard_autoloader ) ) {
        require_once $walletup_standard_autoloader;
    } else {
        add_action( 'admin_notices', function () {
            printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html__( 'WalletUp: Composer autoloader is missing. Some features may not work correctly. Please run composer update.', 'walletup' ) );
        } );
    }
}

if ( ! class_exists( __NAMESPACE__ . '\\PHPVersionChecker', false ) ) {
class PHPVersionChecker {
    const MINIMUM_PHP_VERSION = '8.1.0';

    const LEGACY_DOWNLOAD_URL = 'https://walletup.app/legacy';

    public static function is_compatible() : bool {
        return version_compare( PHP_VERSION, self::MINIMUM_PHP_VERSION, '>=' );
    }

    public static function deactivate_plugin() : void {
        if ( function_exists( 'deactivate_plugins' ) ) {
            deactivate_plugins( plugin_basename( WALLET_UP_PRO_FILE ) );
        }
    }

    public static function show_incompatibility_notice() : void {
        $plugin_name = 'Wallet Up Pro';
        $current_php_version = PHP_VERSION;
        $required_php_version = self::MINIMUM_PHP_VERSION;
        $legacy_url = self::LEGACY_DOWNLOAD_URL;
        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Multi-line translatable string for PHP version notice.
        $message = sprintf(
            __( '<strong>%1$s Plugin Activation Prevented</strong><br/>Your current PHP version (%2$s) is too low. This plugin requires PHP %3$s or higher.<br/>Options:<br/>- Upgrade your PHP version in your hosting control panel<br/>- Use the <a href="%4$s" target="_blank">Legacy Version</a> compatible with your current setup', 'walletup' ),
            esc_html( $plugin_name ),
            esc_html( $current_php_version ),
            esc_html( $required_php_version ),
            esc_url( $legacy_url )
        );
        printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', wp_kses_post( $message ) );
    }

    public static function init() : void {
        if ( !self::is_compatible() ) {
            self::deactivate_plugin();
            add_action( 'admin_notices', [self::class, 'show_incompatibility_notice'] );
        }
    }

}
}

PHPVersionChecker::init();
spl_autoload_register( function ( $class ) {
    if ( $class !== 'Freemius' && $class !== 'Freemius_Abstract' && strpos( $class, 'FS_' ) !== 0 ) {
        return;
    }
    $start = WALLET_UP_PRO_PATH . 'vendor/freemius/start.php';
    if ( ! file_exists( $start ) ) {
        $start = WALLET_UP_PRO_PATH . 'vendor-pro/freemius/start.php';
    }
    if ( file_exists( $start ) && ! function_exists( 'fs_dynamic_init' ) ) {
        require_once $start;
    }
} );

add_action( 'plugins_loaded', function () {
    $u = get_option( 'uninstall_plugins', [] );
    if ( ! is_array( $u ) || empty( $u ) ) {
        return;
    }
    $changed = false;
    foreach ( $u as $file => $callable ) {
        $class = null;
        if ( is_array( $callable ) && isset( $callable[0] ) && is_string( $callable[0] ) ) {
            $class = $callable[0];
        } elseif ( is_string( $callable ) && strpos( $callable, '::' ) !== false ) {
            $class = strtok( $callable, '::' );
        }
        if ( $class === null ) {
            continue;
        }
        $plugin_path = WP_PLUGIN_DIR . '/' . $file;
        if ( ! file_exists( $plugin_path ) ) {
            unset( $u[ $file ] );
            $changed = true;
            continue;
        }
        if ( ! class_exists( $class ) ) {
            unset( $u[ $file ] );
            $changed = true;
        }
    }
    if ( $changed ) {
        update_option( 'uninstall_plugins', $u );
    }
}, 0 );

if ( !function_exists( 'wallet_up_fs' ) ) {
    /**
     * Helper function for Freemius SDK access
     * @return object Freemius instance
     */
    function wallet_up_fs() {
        global $wallet_up_fs;
        if ( !isset( $wallet_up_fs ) ) {
            $freemius_path = ( file_exists( WALLET_UP_PRO_PATH . 'vendor-pro/freemius/start.php' ) ? WALLET_UP_PRO_PATH . 'vendor-pro/freemius/start.php' : WALLET_UP_PRO_PATH . 'vendor/freemius/start.php' );
            if ( file_exists( $freemius_path ) ) {
                require_once $freemius_path;
                $wallet_up_fs = fs_dynamic_init( array(
                    'id'             => '12319',
                    'slug'           => 'wallet-up',
                    'premium_slug'   => 'wallet-up-pro',
                    'type'           => 'plugin',
                    'public_key'     => 'pk_d19cef6c52dca0f60e60df34f248e',
                    'has_addons'     => false,
                    'has_paid_plans' => true,
                    'trial'          => array(
                        'days'               => 7,
                        'is_require_payment' => true,
                    ),
                    'menu'           => array(
                        'slug'       => 'wallet-up',
                        'first-path' => 'admin.php?page=wallet-up',
                        'support'    => false,
                    ),
                    'is_live'        => true,
                    'is_premium'     => false,
                    'has_affiliation' => 'selected',
                ) );
            } else {
            }
        }
        return $wallet_up_fs;
    }

}
wallet_up_fs()->set_basename( true, WALLET_UP_PRO_FILE );
do_action( 'wallet_up_fs_loaded' );

wallet_up_fs()->add_filter( 'is_submenu_visible', function ( $is_visible, $menu_id ) {
    if ( $menu_id === 'affiliation' && ! wallet_up_fs()->can_use_premium_code() ) {
        return false;
    }
    return $is_visible;
}, 10, 2 );

add_action( 'admin_menu', function () {
    if ( wallet_up_fs()->can_use_premium_code() ) {
        return;
    }
    remove_submenu_page( 'wallet-up', 'wallet-up-affiliation' );
    remove_submenu_page( 'wallet-up', 'wallet-up-wp-affiliation' );
    global $submenu;
    if ( isset( $submenu['wallet-up'] ) ) {
        foreach ( $submenu['wallet-up'] as $key => $item ) {
            if ( isset( $item[2] ) && strpos( $item[2], 'affiliation' ) !== false ) {
                unset( $submenu['wallet-up'][ $key ] );
            }
        }
    }
}, 999 );
if ( !function_exists( 'wallet_up_check_premium_status' ) ) {
    function wallet_up_check_premium_status() {
        if ( !check_ajax_referer( 'wallet_up_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( [
                'message' => 'Security check failed',
            ] );
            return;
        }
        if ( !current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [
                'message' => 'Permission denied',
            ] );
            return;
        }
        $is_premium = false;
        $checkout_url = '';
        $upgrade_url = '';
        $is_premium   = wallet_up_premium_active();
        $upgrade_url  = wallet_up_upgrade_url();
        $fs           = wallet_up_fs();
        if ( is_object( $fs ) && wallet_up_pricing_reachable( $fs ) && method_exists( $fs, 'checkout_url' ) ) {
            $checkout_url = $fs->checkout_url();
        }
        wp_send_json_success( [
            'isPremium'   => $is_premium,
            'checkoutUrl' => $checkout_url,
            'upgradeUrl'  => $upgrade_url,
        ] );
    }

}
if ( !has_action( 'wp_ajax_wallet_up_check_premium_status' ) ) {
    add_action( 'wp_ajax_wallet_up_check_premium_status', 'WalletUpPro\\wallet_up_check_premium_status' );
}
if ( PHPVersionChecker::is_compatible() ) {
    add_action( 'plugins_loaded', function () {
        wallet_up_fs();
        PremiumPluginManager::getInstance();
    }, 0 );
}
if ( PHPVersionChecker::is_compatible() ) {
    $walletup_activation_handler = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    if ( class_exists( 'WalletUpPro\\Core\\Activation\\ActivationHandler' ) ) {
        $walletup_activation_handler = ActivationHandler::getInstance();
    }
    add_action( 'plugins_loaded', function () use($walletup_activation_handler) {
        try {
            if ( class_exists( '\\WalletUpPro\\Plugin' ) ) {
                $plugin = \WalletUpPro\Plugin::getInstance();
                $GLOBALS['wallet_up'] = $plugin;
            }
        } catch ( \Exception $e ) {
            add_action( 'admin_notices', function () use($e) {
                printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'WalletUpPro initialization failed: ', 'walletup' ) . esc_html( $e->getMessage() ) );
            } );
        }
    }, 5 );
    add_action( 'plugins_loaded', function () {
        if ( class_exists( 'WalletUpPro\\API\\ClassManagerREST' ) ) {
            ClassManagerREST::init();
        }
    } );

    add_action( 'plugins_loaded', function () {
        if ( class_exists( 'WalletUpPro\\Core\\Admin\\Notices\\ReviewPrompt' ) ) {
            \WalletUpPro\Core\Admin\Notices\ReviewPrompt::init();
        }
        if ( class_exists( 'WalletUpPro\\Core\\Admin\\Notices\\PremiumActivationNotice' ) ) {
            \WalletUpPro\Core\Admin\Notices\PremiumActivationNotice::init();
        }
        if ( class_exists( 'WalletUpPro\\Core\\Admin\\Notices\\ProInstallModal' ) ) {
            \WalletUpPro\Core\Admin\Notices\ProInstallModal::init();
        }
        if ( class_exists( 'WalletUpPro\\Core\\Admin\\Notices\\ProUnlockedNotice' ) ) {
            \WalletUpPro\Core\Admin\Notices\ProUnlockedNotice::init();
        }
        if ( class_exists( 'WalletUpPro\\Core\\Admin\\SupportAccessManager' ) ) {
            \WalletUpPro\Core\Admin\SupportAccessManager::init();
        }
    } );
    function walletup_get_pro_asset_path(  string $entry  ) : string {
        $manifest_path = WALLET_UP_PRO_BASE_DIR . 'walup-react/dist/.vite/manifest.json';
        if ( file_exists( $manifest_path ) ) {
            $manifest = json_decode( file_get_contents( $manifest_path ), true );
            return ( isset( $manifest[$entry] ) ? $manifest[$entry]['file'] : $entry );
        }
        return $entry;
    }

    $walletup_compatibility_handler_path = WALLET_UP_PRO_PATH . 'src/Compatibility/walletUpCompatibilityHandler.php'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    if ( file_exists( $walletup_compatibility_handler_path ) ) {
        require_once $walletup_compatibility_handler_path;
    } else {
    }
    $walletup_url_tracking_handler_path = WALLET_UP_PRO_PATH . 'src/Core/URL/URLTrackingHandler.php'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    if ( file_exists( $walletup_url_tracking_handler_path ) ) {
        require_once $walletup_url_tracking_handler_path;
    } else {
    }
    add_action( 'plugins_loaded', function () {
        if ( class_exists( '\\WalletUpPro\\Compatibility\\walletUpCompatibilityHandler' ) ) {
            walletUpCompatibilityHandler::getInstance();
        }
        if ( class_exists( '\\WalletUpPro\\Core\\URL\\URLTrackingHandler' ) ) {
            \WalletUpPro\Core\URL\URLTrackingHandler::getInstance();
        }
    } );
    add_action( 'plugins_loaded', function () {
        if ( class_exists( 'WalletUpPro\\Core\\PaymentInit' ) ) {
            PaymentInit::getInstance();
        }
    } );
    add_action( 'admin_init', function () {
        if ( class_exists( '\\WalletUpPro\\Core\\Install\\PaymentSystemInstaller' ) ) {
            ( new \WalletUpPro\Core\Install\PaymentSystemInstaller() )->ensureSchema();
        }
    } );
    add_action( 'upgrader_process_complete', function () {
        if ( class_exists( '\\WalletUpPro\\Core\\Install\\PaymentSystemInstaller' ) ) {
            ( new \WalletUpPro\Core\Install\PaymentSystemInstaller() )->ensureSchema();
        }
    }, 10, 0 );
    add_action( 'init', function () {
        if ( class_exists( '\\WalletUpPro\\Core\\URL\\URLHandler' ) ) {
            new \WalletUpPro\Core\URL\URLHandler();
        }
    } );
    add_action( 'init', function () {
        add_rewrite_rule( '^go/([a-zA-Z0-9]+)/?$', 'index.php?walletup_short_code=$matches[1]', 'top' );
    } );
    add_filter( 'query_vars', function ( $vars ) {
        $vars[] = 'walletup_short_code';
        return $vars;
    } );
    register_activation_hook( WALLET_UP_PRO_FILE, function () {
        $missing = [];
        if ( ! extension_loaded( 'openssl' ) ) {
            $missing[] = 'openssl';
        }
        if ( ! extension_loaded( 'curl' ) ) {
            $missing[] = 'curl';
        }
        if ( ! extension_loaded( 'json' ) ) {
            $missing[] = 'json';
        }
        if ( ! empty( $missing ) ) {
            wp_die(
                sprintf(
                    /* translators: %s: comma-separated list of missing PHP extensions */
                    esc_html__( 'WalletUp requires the following PHP extensions: %s. Please install them and try again.', 'walletup' ),
                    implode( ', ', $missing )
                ),
                'WalletUp — Missing Requirements',
                [ 'back_link' => true ]
            );
        }

        try {
            if ( class_exists( '\\WalletUpPro\\Core\\Install\\Installer' ) ) {
                $installer = new \WalletUpPro\Core\Install\Installer();
                $installer->install();
            }
            if ( class_exists( '\\WalletUpPro\\Core\\Install\\PaymentSystemInstaller' ) ) {
                $paymentInstaller = new \WalletUpPro\Core\Install\PaymentSystemInstaller();
                $paymentInstaller->install();
            }
            if ( class_exists( '\\WalletUpPro\\Core\\Cron\\URLCleanup' ) ) {
                new \WalletUpPro\Core\Cron\URLCleanup();
            }
            update_option( 'walletup_flush_needed', 'yes' );

            if ( ! get_option( 'wallet_up_activated_at' ) ) {
                update_option( 'wallet_up_activated_at', time() );
            }

            if ( class_exists( '\\WalletUpPro\\Core\\Admin\\OnboardingHandler' ) ) {
                \WalletUpPro\Core\Admin\OnboardingHandler::scheduleRedirectOnActivation();
            }
        } catch ( \Exception $e ) {
            wp_die( 'Failed to install WalletUpPro properly. Please check error logs.' );
        }
    } );
    add_action( 'init', function () {
        if ( class_exists( '\\WalletUpPro\\Core\\URL\\URLHandler' ) ) {
            \WalletUpPro\Core\URL\URLHandler::getInstance();
        }
    }, 1 );
    add_action( 'wp_enqueue_scripts', function () {
        if ( !function_exists( 'wpforms_get' ) ) {
            return;
        }
        wp_enqueue_script( 'react' );
        wp_enqueue_script( 'react-dom' );
        wp_enqueue_script(
            'wallet-up-payment-methods',
            plugins_url( 'assets/js/payment-methods.js', WALLET_UP_PRO_FILE ),
            ['react', 'react-dom'],
            WALLET_UP_PRO_VERSION,
            true
        );
        add_filter(
            'script_loader_tag',
            function ( $tag, $handle ) {
                if ( 'wallet-up-payment-methods' === $handle ) {
                    return str_replace( '<script ', '<script type="module" ', $tag );
                }
                return $tag;
            },
            10,
            2
        );
        wp_localize_script( 'wallet-up-payment-methods', 'walletUpData', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wallet_up_admin_nonce' ),
            'nonces'  => [
                'wcPaymentDisplay'          => wp_create_nonce( 'wallet_up_wc_payment_display' ),
                'wallet_up_payment_methods' => wp_create_nonce( 'wallet_up_payment_methods' ),
            ],
        ] );
    } );
    add_action( 'wp_ajax_wallet_up_get_enabled_methods', 'WalletUpPro\\handle_get_pro_enabled_methods' );
    add_action( 'wp_ajax_nopriv_wallet_up_get_enabled_methods', 'WalletUpPro\\handle_get_pro_enabled_methods' );
    function handle_get_pro_enabled_methods() {
        try {
            if ( !check_ajax_referer( 'wallet_up_admin_nonce', 'nonce', false ) ) {
                throw new \Exception('Invalid security token');
            }
            if ( class_exists( '\\WalletUpPro\\Core\\PaymentRegistry' ) ) {
                $registry = \WalletUpPro\Core\PaymentRegistry::getInstance();
                $methods = [];
                foreach ( $registry->getAllMethods() as $methodId => $method ) {
                    if ( $registry->isMethodEnabled( $methodId ) ) {
                        $methods[] = [
                            'id'          => $methodId,
                            'name'        => $method->getTitle(),
                            'description' => $method->getDescription(),
                            'icon'        => plugins_url( "assets/images/{$methodId}-walup.png", WALLET_UP_PRO_FILE ),
                        ];
                    }
                }
                wp_send_json_success( [
                    'methods' => $methods,
                ] );
            } else {
                throw new \Exception('Payment registry not found');
            }
        } catch ( \Exception $e ) {
            wp_send_json_error( [
                'message' => $e->getMessage(),
            ] );
        }
    }

    add_action( 'plugins_loaded', function () {
        if ( class_exists( '\\WalletUpPro\\Services\\PaymentProcessor' ) ) {
            \WalletUpPro\Services\PaymentProcessor::getInstance();
        }
        if ( class_exists( '\\WalletUpPro\\Services\\PaymentMethodManager' ) ) {
            \WalletUpPro\Services\PaymentMethodManager::getInstance();
        }
        if ( class_exists( '\\WalletUpPro\\API\\WebhookHandler' ) ) {
            \WalletUpPro\API\WebhookHandler::getInstance();
        }
    }, 15 );

    add_action( 'plugins_loaded', function () {
        try {
            if ( class_exists( '\\WPForms\\WPForms' ) && class_exists( '\\WalletUpPro\\WpForms\\Forms\\WPFormsServiceCheckout' ) ) {
                \WalletUpPro\WpForms\Forms\WPFormsServiceCheckout::getInstance();
            }
            $field_mapper_path = WALLET_UP_PRO_PATH . 'src/Core/Mapping/WPFormsFieldMapper.php';
            if ( file_exists( $field_mapper_path ) && !class_exists( 'WalletUpPro\\Core\\Mapping\\WPFormsFieldMapper' ) ) {
                require_once $field_mapper_path;
                \WalletUpPro\Core\Mapping\WPFormsFieldMapper::getInstance();
            }
            $form_adapter_path = WALLET_UP_PRO_PATH . 'src/Core/Integration/PaymentFormAdapter.php';
            if ( file_exists( $form_adapter_path ) && !class_exists( 'WalletUpPro\\Core\\Integration\\PaymentFormAdapter' ) ) {
                require_once $form_adapter_path;
                \WalletUpPro\Core\Integration\PaymentFormAdapter::getInstance();
            }
        } catch ( \Exception $e ) {
            if ( class_exists( 'WalletUpPro\\Core\\Error\\ErrorHandler' ) ) {
                \WalletUpPro\Core\Error\ErrorHandler::getInstance()->log( 'Failed to initialize WalletUpPro form integration: ' . $e->getMessage() );
            } else {
            }
        }
    }, 20 );
    function wallet_up_get_pro_enhanced_url_data() {
        if ( !isset( $_POST['nonce'] ) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'wallet_up_admin_nonce' ) ) {
            wp_send_json_error( [
                'message' => 'Invalid security token',
            ] );
        }
        try {
            $code = ( isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '' );
            if ( empty( $code ) ) {
                throw new \Exception('URL code is required');
            }
            if ( class_exists( '\\WalletUpPro\\Services\\URLShortenerService' ) ) {
                $shortener = new \WalletUpPro\Services\URLShortenerService();
                $urlData = $shortener->getAnalytics( $code );
                if ( !$urlData ) {
                    throw new \Exception('URL not found');
                }
                wp_send_json_success( $urlData );
            } else {
                throw new \Exception('URL shortener service not available');
            }
        } catch ( \Exception $e ) {
            wp_send_json_error( [
                'message' => $e->getMessage(),
            ] );
        }
    }

    add_action( 'wp_ajax_wallet_up_get_enhanced_url_data', 'WalletUpPro\\wallet_up_get_pro_enhanced_url_data' );
    function wallet_up_update_url_metadata() {
        if ( !current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        if ( !isset( $_POST['nonce'] ) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'wallet_up_admin_nonce' ) ) {
            wp_send_json_error( [
                'message' => 'Invalid security token',
            ] );
        }
        try {
            $code = ( isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '' );
            $metadata = ( isset( $_POST['metadata'] ) ? sanitize_text_field(wp_unslash($_POST['metadata'])) : '' );
            if ( empty( $code ) ) {
                throw new \Exception('URL code is required');
            }
            if ( empty( $metadata ) ) {
                throw new \Exception('Metadata is required');
            }
            $metadataArray = json_decode( stripslashes( $metadata ), true );
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                throw new \Exception('Invalid metadata format');
            }
            if ( !is_array( $metadataArray ) ) {
                throw new \Exception('Invalid metadata structure');
            }
            foreach ( $metadataArray as $key => $value ) {
                if ( is_string( $value ) ) {
                    $metadataArray[$key] = sanitize_text_field( $value );
                } else {
                    if ( is_array( $value ) && $key === 'tags' ) {
                        $metadataArray[$key] = array_map( 'sanitize_text_field', $value );
                    }
                }
            }
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Write operation, caching not applicable.
            $updated = $wpdb->update(
                $wpdb->prefix . 'walletup_short_urls',
                [
                    'metadata' => wp_json_encode( $metadataArray ),
                ],
                [
                    'short_code' => $code,
                ],
                ['%s'],
                ['%s']
            );
            if ( $updated === false ) {
                throw new \Exception('Failed to update metadata');
            }
            wp_send_json_success( [
                'message' => 'Metadata updated successfully',
            ] );
        } catch ( \Exception $e ) {
            wp_send_json_error( [
                'message' => $e->getMessage(),
            ] );
        }
    }

    add_action( 'wp_ajax_wallet_up_update_url_metadata', 'WalletUpPro\\wallet_up_update_url_metadata' );
    function wallet_up_url_shortener_shortcode(  $atts  ) {
        $atts = shortcode_atts( [
            'initial_url' => '',
            'integrated'  => 'false',
        ], $atts, 'wallet_up_url_shortener' );
        $integrated = filter_var( $atts['integrated'], FILTER_VALIDATE_BOOLEAN );
        ob_start();
        ?>
        <div id="wallet-up-url-shortener" data-initial-url="<?php 
        echo esc_attr( $atts['initial_url'] );
        ?>" data-integrated="<?php 
        echo esc_attr( $integrated ? 'true' : 'false' );
        ?>"></div>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                if (typeof WalletUpProReact !== 'undefined' && WalletUpProReact.renderURLShortener) {
                    const container = document.getElementById('wallet-up-url-shortener');
                    if (container) {
                        WalletUpProReact.renderURLShortener(
                            container,
                            container.dataset.initialUrl || '',
                            container.dataset.integrated === 'true'
                        );
                    }
                }
            });
        </script>
        <?php 
        return ob_get_clean();
    }

    add_shortcode( 'wallet_up_url_shortener', 'WalletUpPro\\wallet_up_url_shortener_shortcode' );
    function wallet_up_integrated_shortener_shortcode(  $atts  ) {
        $atts = shortcode_atts( [
            'initial_url' => '',
        ], $atts, 'wallet_up_integrated_shortener' );
        ob_start();
        ?>
        <div id="wallet-up-integrated-shortener" data-initial-url="<?php 
        echo esc_attr( $atts['initial_url'] );
        ?>"></div>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                if (typeof WalletUpProReact !== 'undefined' && WalletUpProReact.renderIntegratedShortener) {
                    const container = document.getElementById('wallet-up-integrated-shortener');
                    if (container) {
                        WalletUpProReact.renderIntegratedShortener(
                            container,
                            container.dataset.initialUrl || ''
                        );
                    }
                }
            });
        </script>
        <?php 
        return ob_get_clean();
    }

    add_shortcode( 'wallet_up_integrated_shortener', 'WalletUpPro\\wallet_up_integrated_shortener_shortcode' );
    function wallet_up_click_tracker_shortcode(  $atts  ) {
        $atts = shortcode_atts( [
            'url_code' => '',
        ], $atts, 'wallet_up_click_tracker' );
        ob_start();
        ?>
        <div id="wallet-up-click-tracker" data-url-code="<?php 
        echo esc_attr( $atts['url_code'] );
        ?>"></div>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                if (typeof WalletUpProReact !== 'undefined' && WalletUpProReact.renderClickTracker) {
                    const container = document.getElementById('wallet-up-click-tracker');
                    if (container) {
                        WalletUpProReact.renderClickTracker(
                            container,
                            container.dataset.urlCode || ''
                        );
                    }
                }
            });
        </script>
        <?php 
        return ob_get_clean();
    }

    add_shortcode( 'wallet_up_click_tracker', 'WalletUpPro\\wallet_up_click_tracker_shortcode' );
}