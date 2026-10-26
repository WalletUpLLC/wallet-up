<?php
namespace WalletUpPro\Core\Security;

use WalletUpPro\Core\Error\ErrorHandler;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class WordfenceCompatibility {
    /**
     * @var ErrorHandler Error handler instance
     */
    private $errorHandler;
    
    /**
     * @var bool Whether Wordfence is active
     */
    private $wordfence_active = false;
    
    /**
     * @var array WalletUpPro REST API endpoints to whitelist
     */
    private $endpoints = [
        'wallet-up/v1/roles',
        'wallet-up/v1/roles/debug',
        'wallet-up/v1/roles/(?P<id>[\\w-]+)',
        'wallet-up/v1/users',
        'wallet-up/v1/users/(?P<id>[\\d]+)/role',
        'wallet-up/v1/capabilities',
        'wallet-up/v1/capabilities/check',
        'wallet-up/v1/health',
        'wallet-up/v1/nonce-test'
    ];
    
    public function __construct() {
        $this->errorHandler = ErrorHandler::getInstance();
        
        $this->wordfence_active = $this->isWordfenceActive();
        
        if ($this->wordfence_active) {
            $this->errorHandler->log('Wordfence detected - adding compatibility fixes');
            $this->addCompatibilityHooks();
        }
    }
    
    private function isWordfenceActive(): bool {
        if (class_exists('wordfence')) {
            return true;
        }
        
        if (function_exists('is_plugin_active')) {
            if (is_plugin_active('wordfence/wordfence.php')) {
                return true;
            }
        } else {
            $active_plugins = get_option('active_plugins', []);
            if (in_array('wordfence/wordfence.php', $active_plugins)) {
                return true;
            }
        }
        
        return false;
    }
    
    private function addCompatibilityHooks(): void {
        $this->addFirewallCompatibility();
        
        $this->addRateLimitingCompatibility();
        
        $this->addLoginSecurityCompatibility();
        
        add_action('admin_notices', [$this, 'wordfenceWarningNotice']);
        
        add_filter('wallet_up_error_context', [$this, 'addWordfenceContext']);
    }
    
    private function addFirewallCompatibility(): void {
        add_filter('wordfence_wp_rest_whitelist_routes', [$this, 'whitelistRestRoutes']);
        
        if (defined('WFWAF_LOG_PATH') && file_exists(WFWAF_LOG_PATH)) {
            add_filter('wordfence_ls_require_captcha', [$this, 'disableCaptchaForRestApi'], 10, 2);
        }
        
        add_filter('wordfence_filtering_request_path', function($request_path) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Wordfence filter callback; checking action name only, no data processing.
            if (isset($_POST['action']) && strpos(sanitize_text_field(wp_unslash($_POST['action'])), 'wallet_up_') === 0) {
                return false; // Don't filter our AJAX requests
            }

            return $request_path;
        });
    }

    private function addRateLimitingCompatibility(): void {
        if (class_exists('wfConfig')) {
            add_filter('wordfence_rate_limit_whitelist', function($whitelisted) {
                $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '')) : '';

                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Wordfence filter callback; checking action name only, no data processing.
                if (wp_doing_ajax() && isset($_POST['action']) && strpos(sanitize_text_field(wp_unslash($_POST['action'])), 'wallet_up_') === 0) {
                    return true; // Exclude from rate limiting
                }
                
                if (strpos($request_uri, '/wp-json/wallet-up/') !== false) {
                    return true; // Exclude from rate limiting
                }
                
                return $whitelisted;
            });
        }
    }
    
    private function addLoginSecurityCompatibility(): void {
        if (class_exists('wfRESTUtils')) {
            add_filter('wfrest_verify_nonce', function($verify, $route, $method) {
                if (strpos($route, '/wallet-up/v1/') === 0) {
                    return false;
                }
                
                return $verify;
            }, 10, 3);
        }
        
        if (class_exists('wordfence')) {
            add_filter('wordfence_2fa_required', function($required) {
                if (defined('REST_REQUEST') && REST_REQUEST) {
                    $rest_route = isset($GLOBALS['wp']->query_vars['rest_route']) ? 
                        $GLOBALS['wp']->query_vars['rest_route'] : '';
                    
                    if (strpos($rest_route, '/wallet-up/v1/') === 0) {
                        return false; // Don't require 2FA for our REST API
                    }
                }
                
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Wordfence filter callback; checking action name only, no data processing.
                if (wp_doing_ajax() && isset($_POST['action']) && strpos(sanitize_text_field(wp_unslash($_POST['action'])), 'wallet_up_') === 0) {
                    return false; // Don't require 2FA for our AJAX
                }
                
                return $required;
            });
        }
    }
    
    /**
     * Whitelist our REST API routes in Wordfence
     * 
     * @param array $routes Currently whitelisted routes
     * @return array Updated whitelisted routes
     */
    public function whitelistRestRoutes(array $routes): array {
        return array_merge($routes, $this->endpoints);
    }
    
    public function disableCaptchaForRestApi(bool $require_captcha, string $action = ''): bool {
        if (defined('REST_REQUEST') && REST_REQUEST) {
            $rest_route = isset($GLOBALS['wp']->query_vars['rest_route']) ? 
                $GLOBALS['wp']->query_vars['rest_route'] : '';
            
            if (strpos($rest_route, '/wallet-up/v1/') === 0) {
                return false; // Don't require CAPTCHA for our REST API
            }
        }
        
        return $require_captcha;
    }
    
    public function wordfenceWarningNotice(): void {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'walletup') === false) {
            return;
        }
        
        if (!current_user_can('manage_options')) {
            return;
        }
        
        if (!$this->wordfence_active) {
            return;
        }
        
        $rest_api_blocked = get_transient('wallet_up_wordfence_rest_issues');
        
        if ($rest_api_blocked) {
            ?>
            <div class="notice notice-warning">
                <p>
                    <strong><?php esc_html_e('WalletUpPro - Wordfence Compatibility Issue', 'walletup'); ?></strong>
                </p>
                <p>
                    <?php esc_html_e('Wordfence Security plugin may be blocking WalletUpPro\'s REST API access. This can prevent the role manager and other features from working correctly.', 'walletup'); ?>
                </p>
                <p>
                    <?php esc_html_e('Possible solutions:', 'walletup'); ?>
                </p>
                <ol>
                    <li><?php esc_html_e('In Wordfence → Tools → Diagnostic, click "Fix REST API not functioning" button', 'walletup'); ?></li>
                    <li><?php esc_html_e('Add an exception for "/wp-json/wallet-up/" in Wordfence Firewall rules', 'walletup'); ?></li>
                    <li><?php esc_html_e('Temporarily disable Wordfence Web Application Firewall if issues persist', 'walletup'); ?></li>
                </ol>
                <p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wallet-up-capability-diagnostic')); ?>" class="button">
                        <?php esc_html_e('Run Capability Diagnostic', 'walletup'); ?>
                    </a>
                    <a href="#" class="button" id="wallet-up-test-rest-api">
                        <?php esc_html_e('Test REST API Access', 'walletup'); ?>
                    </a>
                    <a href="#" class="button" id="wallet-up-dismiss-wordfence-notice">
                        <?php esc_html_e('Dismiss Notice', 'walletup'); ?>
                    </a>
                </p>
            </div>
            <script type="text/javascript">
                document.addEventListener('DOMContentLoaded', function() {
                    // Test REST API access
                    document.getElementById('wallet-up-test-rest-api').addEventListener('click', function(e) {
                        e.preventDefault();
                        var testUrl = '<?php echo esc_url(rest_url('wallet-up/v1/health')); ?>';
                        
                        // Show loading status
                        this.textContent = '<?php esc_html_e('Testing...', 'walletup'); ?>';
                        
                        // Make the request
                        fetch(testUrl, {
                            credentials: 'same-origin',
                            headers: {
                                'X-WP-Nonce': '<?php echo esc_attr(wp_create_nonce('wp_rest')); ?>'
                            }
                        })
                        .then(function(response) {
                            if (!response.ok) {
                                throw new Error('HTTP error ' + response.status);
                            }
                            return response.json();
                        })
                        .then(function(data) {
                            alert('REST API is accessible! Test successful.');
                            // Reset button text
                            document.getElementById('wallet-up-test-rest-api').textContent = 
                                '<?php esc_html_e('Test REST API Access', 'walletup'); ?>';
                            
                            var formData = new FormData();
                            formData.append('action', 'wallet_up_clear_wordfence_issue');
                            formData.append('nonce', '<?php echo esc_attr(wp_create_nonce('wallet_up_admin_nonce')); ?>');
                            
                            fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                                method: 'POST',
                                credentials: 'same-origin',
                                body: formData
                            });
                            
                            // Hide the notice
                            document.querySelector('.notice-warning').style.display = 'none';
                        })
                        .catch(function(error) {
                            alert('REST API test failed: ' + error.message + '\n\nThis confirms that Wordfence is blocking REST API access. Please follow the suggested solutions.');
                            // Reset button text
                            document.getElementById('wallet-up-test-rest-api').textContent = 
                                '<?php esc_html_e('Test REST API Access', 'walletup'); ?>';
                        });
                    });
                    
                    // Dismiss notice
                    document.getElementById('wallet-up-dismiss-wordfence-notice').addEventListener('click', function(e) {
                        e.preventDefault();
                        
                        var formData = new FormData();
                        formData.append('action', 'wallet_up_dismiss_wordfence_notice');
                        formData.append('nonce', '<?php echo esc_attr(wp_create_nonce('wallet_up_admin_nonce')); ?>');
                        
                        fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                            method: 'POST',
                            credentials: 'same-origin',
                            body: formData
                        });
                        
                        // Hide the notice
                        document.querySelector('.notice-warning').style.display = 'none';
                    });
                });
            </script>
            <?php
        }
    }
    
    public function addWordfenceContext(array $context): array {
        if (!$this->wordfence_active) {
            return $context;
        }
        
        $wordfence_info = [
            'version' => defined('WORDFENCE_VERSION') ? WORDFENCE_VERSION : 'unknown',
            'waf_enabled' => false,
            'live_traffic_enabled' => false,
            'login_security_enabled' => false
        ];
        
        if (class_exists('wfWAF') && method_exists('wfWAF', 'getInstance')) {
            $wordfence_info['waf_enabled'] = true;
        }
        
        if (class_exists('wfConfig') && method_exists('wfConfig', 'get')) {
            $wordfence_info['live_traffic_enabled'] = \wfConfig::get('liveTrafficEnabled', false);
            $wordfence_info['login_security_enabled'] = \wfConfig::get('loginSecurityEnabled', false);
        }
        
        $context['wordfence'] = $wordfence_info;
        return $context;
    }
}

new WordfenceCompatibility();