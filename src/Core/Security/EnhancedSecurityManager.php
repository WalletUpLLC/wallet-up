<?php
namespace WalletUpPro\Core\Security;

use WalletUpPro\Core\Error\ErrorHandler;
use WalletUpPro\Core\BaseSingleton;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class EnhancedSecurityManager extends BaseSingleton
{
    /** @var ErrorHandler */
    private $errorHandler;
    
    /** @var array Security configuration */
    private $config;
    
    /** @var array Suspicious activity patterns */
    private const SUSPICIOUS_PATTERNS = [
        '/(<script[^>]*>.*?<\/script>)/i',
        '/(javascript:)/i',
        '/(data:text\/html)/i',
        '/(vbscript:)/i',
        '/(on\w+\s*=)/i',
        '/(<iframe[^>]*>.*?<\/iframe>)/i',
        '/(<object[^>]*>.*?<\/object>)/i',
        '/(<embed[^>]*>)/i',
        '/(expression\s*\()/i',
        '/(@import)/i',
        '/(url\s*\()/i'
    ];
    
    /** @var array Rate limiting storage */
    private $rateLimitCache = [];
    
    protected function init(): void
    {
        $this->errorHandler = ErrorHandler::getInstance();
        $this->loadSecurityConfig();
        $this->setupSecurityHooks();
    }
    
    private function loadSecurityConfig(): void
    {
        $this->config = apply_filters('wallet_up_security_config', [
            'enable_ip_whitelist' => get_option('wallet_up_security_ip_whitelist_enabled', false),
            'allowed_ips' => get_option('wallet_up_security_allowed_ips', []),
            'enable_user_agent_validation' => get_option('wallet_up_security_user_agent_validation', true),
            'enable_referrer_validation' => get_option('wallet_up_security_referrer_validation', true),
            'max_login_attempts' => get_option('wallet_up_security_max_login_attempts', 5),
            'lockout_duration' => get_option('wallet_up_security_lockout_duration', 300), // 5 minutes
            'enable_security_headers' => get_option('wallet_up_security_headers_enabled', true),
            'log_security_events' => get_option('wallet_up_security_event_logging', true),
            'suspicious_activity_threshold' => get_option('wallet_up_security_suspicious_threshold', 10)
        ]);
    }
    
    private function setupSecurityHooks(): void
    {
        if ($this->config['enable_security_headers']) {
            add_action('send_headers', [$this, 'addSecurityHeaders']);
        }
        
        add_action('wp_login_failed', [$this, 'handleLoginFailure']);
        add_action('wp_login', [$this, 'handleSuccessfulLogin'], 10, 2);
    }
    
    public function validateIPAccess(): bool
    {
        if (!$this->config['enable_ip_whitelist'] || empty($this->config['allowed_ips'])) {
            return true; // No IP restrictions
        }
        
        $client_ip = $this->getClientIP();
        
        foreach ($this->config['allowed_ips'] as $allowed_ip) {
            if ($this->ipMatches($client_ip, $allowed_ip)) {
                return true;
            }
        }
        
        $this->logSecurityEvent('ip_access_denied', [
            'client_ip' => $client_ip,
            'allowed_ips' => $this->config['allowed_ips']
        ]);
        
        return false;
    }
    
    public function getClientIP(): string
    {
        $ip_headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_FORWARDED_FOR',      // Load balancers/proxies
            'HTTP_X_FORWARDED',          // Proxies
            'HTTP_X_CLUSTER_CLIENT_IP',  // Cluster balancers
            'HTTP_FORWARDED_FOR',        // Proxies
            'HTTP_FORWARDED',            // Proxies
            'HTTP_CLIENT_IP',            // Some proxies
            'REMOTE_ADDR'                // Standard
        ];
        
        foreach ($ip_headers as $header) {
            if (!empty(sanitize_text_field(wp_unslash($_SERVER[$header] ?? '')))) {
                $ips = explode(',', sanitize_text_field(wp_unslash($_SERVER[$header] ?? '')));
                $ip = trim($ips[0]);
                
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '')) ?? '0.0.0.0';
    }
    
    private function ipMatches(string $ip, string $allowed): bool
    {
        if (strpos($allowed, '/') === false) {
            return $ip === $allowed;
        }
        
        list($network, $mask) = explode('/', $allowed);
        
        $ip_long = ip2long($ip);
        $network_long = ip2long($network);
        $mask_long = -1 << (32 - $mask);
        
        return ($ip_long & $mask_long) === ($network_long & $mask_long);
    }
    
    public function detectSuspiciousActivity(): bool
    {
        $client_ip = $this->getClientIP();
        $user_agent = sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')) ?? '';
        $request_uri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '')) ?? '';
        
        $suspicious_score = 0;
        $flags = [];
        
        if ($this->containsSuspiciousPatterns($user_agent)) {
            $suspicious_score += 3;
            $flags[] = 'suspicious_user_agent';
        }
        
        if ($this->containsSuspiciousPatterns($request_uri)) {
            $suspicious_score += 2;
            $flags[] = 'suspicious_request_uri';
        }
        
        if ($this->config['enable_referrer_validation'] && $this->hasSuspiciousReferrer()) {
            $suspicious_score += 1;
            $flags[] = 'suspicious_referrer';
        }
        
        if ($this->detectBotBehavior($user_agent)) {
            $suspicious_score += 2;
            $flags[] = 'bot_behavior';
        }
        
        if ($this->detectHighRequestFrequency($client_ip)) {
            $suspicious_score += 2;
            $flags[] = 'high_frequency';
        }
        
        $is_suspicious = $suspicious_score >= $this->config['suspicious_activity_threshold'];
        
        if ($is_suspicious) {
            $this->logSecurityEvent('suspicious_activity_detected', [
                'client_ip' => $client_ip,
                'user_agent' => $user_agent,
                'request_uri' => $request_uri,
                'suspicious_score' => $suspicious_score,
                'flags' => $flags
            ]);
        }
        
        return $is_suspicious;
    }
    
    public function sanitizeEmail(string $email): string
    {
        $email = trim($email);
        $email = sanitize_email($email);
        
        if ($this->containsSuspiciousPatterns($email)) {
            return '';
        }
        
        $dangerous_patterns = ['@[', '%0a', '%0d', '\n', '\r'];
        foreach ($dangerous_patterns as $pattern) {
            if (stripos($email, $pattern) !== false) {
                return '';
            }
        }
        
        return $email;
    }
    
    public function sanitizeAlphanumeric(string $input): string
    {
        $input = trim($input);
        $input = preg_replace('/[^A-Za-z0-9_-]/', '', $input);
        
        if ($this->containsSuspiciousPatterns($input)) {
            return '';
        }
        
        return $input;
    }
    
    public function maskSensitiveData(string $data, int $visible_chars = 4): string
    {
        if (empty($data)) {
            return '';
        }
        
        $length = strlen($data);
        
        if ($length <= $visible_chars * 2) {
            return str_repeat('*', $length);
        }
        
        $prefix = substr($data, 0, $visible_chars);
        $suffix = substr($data, -$visible_chars);
        $masked_length = $length - ($visible_chars * 2);
        
        return $prefix . str_repeat('*', $masked_length) . $suffix;
    }
    
    public function logSecurityEvent(string $event_type, array $context = []): void
    {
        if (!$this->config['log_security_events']) {
            return;
        }
        
        $event_data = array_merge([
            'event_type' => $event_type,
            'timestamp' => current_time('c'),
            'client_ip' => $this->getClientIP(),
            'user_agent' => sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')) ?? '',
            'request_uri' => sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '')) ?? '',
            'user_id' => get_current_user_id(),
            'session_id' => session_id()
        ], $context);
        
        
        $this->storeSecurityEvent($event_data);
        
        do_action('wallet_up_security_event', $event_type, $event_data);
    }
    
    public function auditAction(string $action, array $context = []): void
    {
        $this->logSecurityEvent('action_audit', array_merge([
            'action' => $action
        ], $context));
    }
    
    public function addSecurityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        
        header('X-Frame-Options: SAMEORIGIN');
        
        header('X-XSS-Protection: 1; mode=block');
        
        header('X-Content-Type-Options: nosniff');
        
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }
    
    public function validateSessionIntegrity(): bool
    {
        if (!session_id() && !headers_sent()) {
            session_start();
        }
        
        $current_time = time();
        $session_needs_destruction = false;
        
        if (isset($_SESSION['wallet_up_last_activity'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Integer cast; session data set by our own code.
            $inactive_time = $current_time - intval($_SESSION['wallet_up_last_activity']);
            if ($inactive_time > 3600) { // 1 hour timeout
                $session_needs_destruction = true;
            }
        }
        
        if (!$session_needs_destruction) {
            $current_fingerprint = $this->generateSessionFingerprint();
            if (isset($_SESSION['wallet_up_fingerprint'])) {
                if ($_SESSION['wallet_up_fingerprint'] !== $current_fingerprint) {
                    $session_needs_destruction = true;
                }
            } else {
                $_SESSION['wallet_up_fingerprint'] = $current_fingerprint;
            }
        }
        
        if ($session_needs_destruction) {
            $this->destroySession();
            return false;
        }
        
        $_SESSION['wallet_up_last_activity'] = $current_time;
        
        if (session_id()) {
            session_write_close();
        }
        
        return true;
    }
    
    private function generateSessionFingerprint(): string
    {
        $user_agent = sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')) ?? '';
        $accept_language = sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) ?? '';
        $client_ip = $this->getClientIP();
        
        return hash('sha256', $user_agent . $accept_language . $client_ip);
    }
    
    private function destroySession(): void
    {
        $session_status = session_status();
        $original_session_id = session_id();
        
        try {
            if ($session_status === PHP_SESSION_ACTIVE) {
                session_unset();
                session_destroy();
            } elseif ($session_status === PHP_SESSION_NONE && $original_session_id) {
                if (!headers_sent()) {
                    @session_start();
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        session_unset();
                        session_destroy();
                    }
                }
            }
        } catch (\Exception $e) {
        }
        
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        
        $this->logSecurityEvent('session_destroyed', [
            'reason' => 'integrity_check_failed',
            'original_session_status' => $session_status,
            'original_session_id' => $original_session_id
        ]);
    }
    
    public function handleLoginFailure(string $username): void
    {
        $client_ip = $this->getClientIP();
        $attempts_key = 'login_attempts_' . $client_ip;
        
        $attempts = get_transient($attempts_key) ?: 0;
        $attempts++;
        
        set_transient($attempts_key, $attempts, $this->config['lockout_duration']);
        
        if ($attempts >= $this->config['max_login_attempts']) {
            $this->logSecurityEvent('login_lockout', [
                'username' => $username,
                'attempts' => $attempts,
                'lockout_duration' => $this->config['lockout_duration']
            ]);
        } else {
            $this->logSecurityEvent('login_failure', [
                'username' => $username,
                'attempts' => $attempts
            ]);
        }
    }
    
    public function handleSuccessfulLogin(string $user_login, $user): void
    {
        $client_ip = $this->getClientIP();
        
        delete_transient('login_attempts_' . $client_ip);
        
        $this->logSecurityEvent('login_success', [
            'username' => $user_login,
            'user_id' => $user->ID
        ]);
    }
    
    private function containsSuspiciousPatterns(string $input): bool
    {
        foreach (self::SUSPICIOUS_PATTERNS as $pattern) {
            if (preg_match($pattern, $input)) {
                return true;
            }
        }
        
        return false;
    }
    
    private function hasSuspiciousReferrer(): bool
    {
        $referrer = sanitize_text_field(wp_unslash($_SERVER['HTTP_REFERER'] ?? '')) ?? '';
        
        if (empty($referrer)) {
            return false; // Missing referrer is not necessarily suspicious for AJAX
        }
        
        $site_host = wp_parse_url(get_site_url(), PHP_URL_HOST);
        $referrer_host = wp_parse_url($referrer, PHP_URL_HOST);
        
        if ($referrer_host !== $site_host && is_admin()) {
            return true;
        }
        
        return false;
    }
    
    private function detectBotBehavior(string $user_agent): bool
    {
        $bot_patterns = [
            '/bot/i',
            '/spider/i',
            '/crawler/i',
            '/curl/i',
            '/wget/i',
            '/python/i',
            '/script/i'
        ];
        
        foreach ($bot_patterns as $pattern) {
            if (preg_match($pattern, $user_agent)) {
                return true;
            }
        }
        
        return false;
    }
    
    private function detectHighRequestFrequency(string $client_ip): bool
    {
        $cache_key = 'request_count_' . $client_ip;
        $window = 60; // 1 minute window
        $max_requests = 100; // 100 requests per minute
        
        $current_time = time();
        $request_data = get_transient($cache_key);
        
        if (!$request_data) {
            $request_data = ['count' => 1, 'window_start' => $current_time];
        } else {
            if ($current_time - $request_data['window_start'] > $window) {
                $request_data = ['count' => 1, 'window_start' => $current_time];
            } else {
                $request_data['count']++;
            }
        }
        
        set_transient($cache_key, $request_data, $window);
        
        return $request_data['count'] > $max_requests;
    }
    
    private function storeSecurityEvent(array $event_data): void
    {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wallet_up_security_events';
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            $this->createSecurityEventsTable();
        }
        
        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Write operation, caching not applicable.
            $wpdb->insert(
                $table_name,
                [
                    'event_type' => $event_data['event_type'],
                    'client_ip' => $event_data['client_ip'],
                    'user_id' => $event_data['user_id'] ?: null,
                    'user_agent' => $event_data['user_agent'],
                    'request_uri' => $event_data['request_uri'],
                    'event_data' => wp_json_encode($event_data),
                    'created_at' => current_time('mysql')
                ],
                ['%s', '%s', '%d', '%s', '%s', '%s', '%s']
            );
        } catch (\Exception $e) {
        }
    }
    
    private function createSecurityEventsTable(): void
    {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wallet_up_security_events';
        $charset_collate = $wpdb->get_charset_collate();
        
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
        $sql = "CREATE TABLE IF NOT EXISTS `{$table_name}` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `event_type` varchar(64) NOT NULL,
            `client_ip` varchar(45) NOT NULL,
            `user_id` bigint(20) DEFAULT NULL,
            `user_agent` text,
            `request_uri` text,
            `event_data` longtext,
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_event_type` (`event_type`),
            KEY `idx_client_ip` (`client_ip`),
            KEY `idx_user_id` (`user_id`),
            KEY `idx_created_at` (`created_at`)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    public function cleanupSecurityEvents(): void
    {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wallet_up_security_events';
        $retention_days = apply_filters('wallet_up_security_events_retention_days', 30);
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Direct query required, caching not applicable.
        $wpdb->query($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
            "DELETE FROM {$table_name} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $retention_days
        ));
    }
    
    public function getSecurityStats(int $days = 7): array
    {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wallet_up_security_events';
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return [];
        }
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table from wpdb prefix; days via prepare.
        $stats = $wpdb->get_results($wpdb->prepare(
            "SELECT event_type, COUNT(*) as count
                FROM {$table_name}
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
             GROUP BY event_type
             ORDER BY count DESC",
            $days
        ), ARRAY_A);
        
        return $stats ?: [];
    }
}