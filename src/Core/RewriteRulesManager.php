<?php
namespace WalletUpPro\Core;

use WalletUpPro\Core\Error\ErrorHandler;

if (!defined('ABSPATH')) {
    exit;
}

class RewriteRulesManager
{
    private static $instance = null;
    private $errorHandler;
    private static $rulesRegistered = false;
    private const FLUSH_OPTION = 'wallet_up_flush_rules';

    private const RULES_VERSION = '3';
    
    public static function getInstance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct()
    {
        $this->errorHandler = ErrorHandler::getInstance();
        
        add_action('init', [$this, 'registerAllRewriteRules'], 1);
        add_filter('query_vars', [$this, 'addAllQueryVars'], 10);
        
        register_activation_hook(WALLET_UP_FILE, [$this, 'scheduleRewriteFlush']);
        
        add_action('admin_notices', [$this, 'maybeShowPermalinkNotice']);
    }
    
    public function registerAllRewriteRules(): void
    {
        if (self::$rulesRegistered) {
            return;
        }
        
        try {
            $permalink_structure = get_option('permalink_structure');
            
            if (empty($permalink_structure)) {
                $this->registerFallbackHandlers();
                $this->errorHandler->log('INFO: Using fallback URL handlers for plain permalink structure');
                self::$rulesRegistered = true;
                return;
            }
            
            add_rewrite_rule(
                'go/([a-zA-Z0-9]+)/?$',
                'index.php?walletup_short_code=$matches[1]',
                'top'
            );
            
            add_rewrite_rule(
                '^pay/([a-zA-Z0-9_-]+)/?$',
                'index.php?wallet_up_payer=$matches[1]',
                'top'
            );
            
            add_rewrite_rule(
                '^pay/multi/([^/]+)/?$',
                'index.php?wallet_up_multi_pay=$matches[1]',
                'top'
            );
            
            add_rewrite_rule(
                '^donate/([a-zA-Z0-9_-]+)/?$',
                'index.php?wallet_up_donation=$matches[1]',
                'top'
            );
            
            add_rewrite_rule(
                '^donate/multi/([^/]+)/?$',
                'index.php?wallet_up_multi_donate=$matches[1]',
                'top'
            );

            add_rewrite_rule(
                '^donate/([a-zA-Z0-9_-]+)/embed/?$',
                'index.php?wallet_up_donation=$matches[1]&walup_embed=1',
                'top'
            );

            add_rewrite_rule(
                '^paywith/([a-z0-9_]+)/?$',
                'index.php?walletup_paywith=$matches[1]',
                'top'
            );

            add_rewrite_rule(
                '^walup-donation-form/?$',
                'index.php?walup_form_embed=1',
                'top'
            );

            self::$rulesRegistered = true;

            if (get_option('walletup_rules_version') !== self::RULES_VERSION) {
                update_option(self::FLUSH_OPTION, 'yes');
                update_option('walletup_rules_version', self::RULES_VERSION, false);
            }

            $this->maybeFlushRules();
            
            
        } catch (\Exception $e) {
            $this->errorHandler->log('Failed to register rewrite rules: ' . $e->getMessage());
        }
    }
    
    private function registerFallbackHandlers(): void
    {
        add_action('init', function() {
            // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Public URL routing; no form data, just query parameters for redirection.
            if (isset($_GET['wallet_up_action'])) {
                $action = sanitize_text_field(wp_unslash($_GET['wallet_up_action']));

                switch ($action) {
                    case 'go':
                        if (isset($_GET['code'])) {
                            set_query_var('walletup_short_code', sanitize_text_field(wp_unslash($_GET['code'])));
                            add_action('template_redirect', [$this, 'handleShortenerRedirect'], 1);
                        }
                        break;

                    case 'pay':
                        if (isset($_GET['code'])) {
                            set_query_var('wallet_up_payer', sanitize_text_field(wp_unslash($_GET['code'])));
                        }
                        break;

                    case 'donate':
                        if (isset($_GET['code'])) {
                            set_query_var('wallet_up_donation', sanitize_text_field(wp_unslash($_GET['code'])));
                        }
                        break;
                }
            }
            // phpcs:enable WordPress.Security.NonceVerification.Recommended
        }, 5);
    }
    
    public function handleShortenerRedirect(): void
    {
        $shortCode = get_query_var('walletup_short_code');
        if ($shortCode) {
            $urlHandler = \WalletUpPro\Core\URL\URLHandler::getInstance();
            $urlHandler->handleRedirect();
        }
    }
    
    public function addAllQueryVars($vars): array
    {
        if (!is_array($vars)) {
            $vars = [];
        }
        
        return array_merge($vars, self::queryVars());
    }

    public static function queryVars(): array
    {
        return [
            'walletup_short_code',
            'wallet_up_payer',
            'wallet_up_multi_pay',
            'wallet_up_donation',
            'wallet_up_multi_donate',
            'walup_form_embed',
            'walup_embed',
            'walletup_paywith'
        ];
    }
    
    public function scheduleRewriteFlush(): void
    {
        update_option(self::FLUSH_OPTION, 'yes');
        
        update_option('walletup_flush_needed', 'yes');
    }
    
    private function maybeFlushRules(): void
    {
        $flush_needed = get_option(self::FLUSH_OPTION, 'no') === 'yes' || 
                       get_option('walletup_flush_needed', 'no') === 'yes';
        
        if ($flush_needed) {
            flush_rewrite_rules(false);
            
            update_option(self::FLUSH_OPTION, 'no');
            update_option('walletup_flush_needed', 'no');
            
            delete_transient('walletup_flush_needed_check');
            
        }
    }
    
    public function forceFlushRules(): void
    {
        self::$rulesRegistered = false;
        
        update_option(self::FLUSH_OPTION, 'no');
        update_option('walletup_flush_needed', 'no');
        delete_transient('walletup_flush_needed_check');
        
        $this->registerAllRewriteRules();
        
        flush_rewrite_rules(true);
        
        $this->errorHandler->log('Rewrite rules force flushed after permalink change');
    }
    
    public function testRewriteRules(): array
    {
        global $wp_rewrite;
        
        $results = [];
        $test_rules = [
            'go/test123' => 'walletup_short_code',
            'pay/test123' => 'wallet_up_payer',
            'donate/test123' => 'wallet_up_donation'
        ];
        
        foreach ($test_rules as $path => $expected_var) {
            $url = home_url($path);
            $parsed = wp_parse_url($url);
            $query = $wp_rewrite->parse_request($parsed['path']);
            
            $results[$path] = [
                'url' => $url,
                'expected_var' => $expected_var,
                'working' => isset($query[$expected_var]),
                'query' => $query
            ];
        }
        
        return $results;
    }
    
    public function getUrl(string $type, string $code, array $extra = []): string
    {
        $permalink_structure = get_option('permalink_structure');
        
        if (empty($permalink_structure)) {
            $params = [
                'wallet_up_action' => $type,
                'code' => $code
            ];
            
            $params = array_merge($params, $extra);
            
            return add_query_arg($params, home_url());
        }
        
        switch ($type) {
            case 'go':
                return home_url('go/' . $code);
                
            case 'pay':
                if (isset($extra['multi']) && $extra['multi']) {
                    return home_url('pay/multi/' . $code);
                }
                return home_url('pay/' . $code);
                
            case 'donate':
                if (isset($extra['multi']) && $extra['multi']) {
                    return home_url('donate/multi/' . $code);
                }
                return home_url('donate/' . $code);
                
            default:
                return home_url();
        }
    }
    
    public function hasPrettyPermalinks(): bool
    {
        return !empty(get_option('permalink_structure'));
    }
    
    public function maybeShowPermalinkNotice(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin page check only; no data processing.
        if (isset($_GET['page']) && strpos(sanitize_text_field(wp_unslash($_GET['page'])), 'walletup') !== false) {
            return;
        }
        
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }
        
        if (get_user_meta(get_current_user_id(), 'wallet_up_permalink_notice_dismissed', true)) {
            return;
        }
        
        if (!$this->hasPrettyPermalinks()) {
            ?>
            <div class="notice notice-error is-dismissible">
                <p><strong>WalletUp Pro: Permalinks Required</strong></p>
                <p>Pretty permalinks must be enabled for WalletUp Pro features to work properly.</p>
                <p>
                    <a href="<?php echo esc_url(admin_url('options-permalink.php')); ?>" class="button button-primary">
                        Go to Permalink Settings
                    </a>
                </p>
            </div>
            <?php
        }
    }
    
    public function getPermalinkStatus(): array
    {
        $hasPrettyPermalinks = $this->hasPrettyPermalinks();
        $currentStructure = get_option('permalink_structure', '');
        
        return [
            'has_pretty_permalinks' => $hasPrettyPermalinks,
            'current_structure' => $currentStructure,
            'is_plain' => empty($currentStructure),
            'notice_dismissed' => (bool) get_user_meta(get_current_user_id(), 'wallet_up_permalink_notice_dismissed', true),
            'fix_nonce' => wp_create_nonce('wallet_up_fix_permalinks'),
            'dismiss_nonce' => wp_create_nonce('wallet_up_dismiss_notice'),
            'settings_url' => admin_url('options-permalink.php'),
            'example_urls' => [
                'with_pretty' => [
                    'shortener' => home_url('/go/abc123'),
                    'payer' => home_url('/pay/invoice123'),
                    'donation' => home_url('/donate/campaign456')
                ],
                'without_pretty' => [
                    'shortener' => home_url('?wallet_up_action=go&code=abc123'),
                    'payer' => home_url('?wallet_up_action=pay&code=invoice123'),
                    'donation' => home_url('?wallet_up_action=donate&code=campaign456')
                ]
            ]
        ];
    }
}