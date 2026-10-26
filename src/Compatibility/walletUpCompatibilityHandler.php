<?php
namespace WalletUpPro\Compatibility;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
class walletUpCompatibilityHandler
{
    private static $instance = null;
    private $minimumRequirements = [
        'php' => '8.1.0',
        'wordpress' => '5.9.0'
    ];
    private $pluginDetails = [
        'name' => 'Wallet Up',
        'slug' => 'wallet-up',
        'min_php_version' => '8.1.0'
    ];

    /**
     * Singleton instance
     * @return walletUpCompatibilityHandler
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('admin_init', [$this, 'checkPluginCompatibility']);
        add_action('admin_notices', [$this, 'displayCompatibilityNotice']);
        add_action('admin_head', [$this, 'injectCustomStyles']);
    }

    public function injectCustomStyles()
    {
        ?>
        <style>
            .wallet-up-compatibility-notice {
                display: flex;
                align-items: center;
                padding: 15px;
                background: #f8f9fa;
                border-left: 4px solid #dc3232;
            }

            .wallet-up-compatibility-content {
                display: flex;
                align-items: center;
            }

            .wallet-up-compatibility-icon {
                margin-right: 20px;
            }

            .wallet-up-compatibility-details {
                flex-grow: 1;
            }

            .wallet-up-compatibility-details h2 {
                margin: 0 0 10px 0;
                color: #dc3232;
            }

            .compatibility-actions {
                margin-top: 15px;
            }

            .compatibility-actions .button {
                margin-right: 10px;
            }

            .compatibility-issue {
                margin-bottom: 10px;
            }
        </style>
        <?php
    }

    public function checkPluginCompatibility()
    {
        $incompatibilities = $this->getIncompatibilities();

        if (!empty($incompatibilities)) {
            if (defined('WALLET_UP_PRO_FILE')) {
                deactivate_plugins(plugin_basename(WALLET_UP_PRO_FILE));
            } else {
                $plugin_file = dirname(dirname(__DIR__)) . '/wallet-up.php';
                if (file_exists($plugin_file)) {
                    deactivate_plugins(plugin_basename($plugin_file));
                }
            }

            set_transient('wallet_up_compatibility_issues', $incompatibilities, 300);
            
            return;
        }
    }

    /**
     * Get list of incompatibilities
     * @return array
     */
    private function getIncompatibilities()
    {
        $incompatibilities = [];

        if (version_compare(PHP_VERSION, $this->minimumRequirements['php'], '<')) {
            $incompatibilities['php'] = [
                'current' => PHP_VERSION,
                'required' => $this->minimumRequirements['php'],
                'message' => sprintf(
                    /* translators: 1: Current PHP version, 2: Plugin name, 3: Required PHP version */
                    __('Your server is running PHP %1$s, but %2$s requires PHP %3$s or higher.', 'walletup'),
                    PHP_VERSION,
                    $this->pluginDetails['name'],
                    $this->minimumRequirements['php']
                )
            ];
        }

        global $wp_version;
        if (version_compare($wp_version, $this->minimumRequirements['wordpress'], '<')) {
            $incompatibilities['wordpress'] = [
                'current' => $wp_version,
                'required' => $this->minimumRequirements['wordpress'],
                'message' => sprintf(
                    /* translators: 1: Current WordPress version, 2: Plugin name, 3: Required WordPress version */
                    __('Your WordPress version %1$s is not compatible. %2$s requires WordPress %3$s or higher.', 'walletup'),
                    $wp_version,
                    $this->pluginDetails['name'],
                    $this->minimumRequirements['wordpress']
                )
            ];
        }

        return $incompatibilities;
    }

    public function displayCompatibilityNotice()
    {
        $incompatibilities = get_transient('wallet_up_compatibility_issues');

        if ($incompatibilities === false) {
            return;
        }

        delete_transient('wallet_up_compatibility_issues');

        ?>
        <div class="notice notice-error is-dismissible wallet-up-compatibility-notice">
            <div class="wallet-up-compatibility-content">
                <div class="wallet-up-compatibility-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="50" height="50" fill="#dc3232">
                        <path
                            d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z" />
                    </svg>
                </div>
                <div class="wallet-up-compatibility-details">
                    <h2><?php
                        /* translators: %s: Plugin name */
                        echo esc_html(sprintf(__('%s Plugin Activation Failed', 'walletup'), $this->pluginDetails['name']));
                    ?></h2>

                    <?php foreach ($incompatibilities as $type => $details): ?>
                        <div class="compatibility-issue <?php echo esc_attr($type); ?>-issue">
                            <p><?php echo esc_html($details['message']); ?></p>
                            <?php if ($type === 'php'): ?>
                                <div class="compatibility-actions">
                                    <a href="https://wordpress.org/support/update-php/" class="button button-primary" target="_blank">
                                        <?php esc_html_e('Learn How to Update PHP', 'walletup'); ?>
                                    </a>
                                    <a href="https://wordpress.org/plugins/health-check/" class="button button-secondary"
                                        target="_blank">
                                        <?php esc_html_e('WordPress Health Check', 'walletup'); ?>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <div class="compatibility-warning">
                        <p><?php esc_html_e('Please resolve these compatibility issues before activating the plugin.', 'walletup'); ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function pluginActivation()
    {
        $incompatibilities = $this->getIncompatibilities();
        if (!empty($incompatibilities)) {
            wp_die(
                esc_html(sprintf(
                    /* translators: %s: Plugin name */
                    __('%s cannot be activated due to compatibility issues. Please check the requirements.', 'walletup'),
                    $this->pluginDetails['name']
                )),
                esc_html__('Activation Error', 'walletup'),
                ['response' => 403, 'back_link' => true]
            );
        }
    }
}