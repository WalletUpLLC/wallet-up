<?php
namespace WalletUpPro\Core\Security;

use WalletUpPro\Core\BaseSingleton;
use WalletUpPro\Core\Error\ErrorHandler;
use WalletUpPro\Services\DatabaseManager;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
class SecurityManager extends BaseSingleton
{
    private $errorHandler;
    private $rateLimiter;
    private $validator;
    private $sanitizer;
    private $encryptor;
    private $accessControl;

    private const RATE_LIMITS = [
        'payment_process' => ['requests' => 10, 'period' => 300], // 10 requests per 5 minutes
        'admin_login' => ['requests' => 5, 'period' => 300],      // 5 requests per 5 minutes
        'api_calls' => ['requests' => 60, 'period' => 60],        // 60 requests per minute
    ];

    private const SENSITIVE_PATTERNS = [
        'credit_card' => '/^(?:\d[ -]*?){13,16}$/',
        'ssn' => '/^\d{3}-?\d{2}-?\d{4}$/',
        'bank_account' => '/^\d{10,12}$/'
    ];

    protected function initialize(): void
    {
        try {
            $this->errorHandler = ErrorHandler::getInstance();
            $this->initializeComponents();
            $this->registerHooks();
        } catch (\Exception $e) {
            if (isset($this->errorHandler)) {
                $this->errorHandler->log('Security initialization failed: ' . $e->getMessage());
            }
            throw $e;
        }
    }

    private function initializeComponents(): void
    {
        $this->rateLimiter = new RateLimiter();
        $this->validator = new InputValidator();
        $this->sanitizer = new OutputSanitizer();
        $this->encryptor = new DataEncryption();
        $this->accessControl = new AccessControl();

        foreach (self::RATE_LIMITS as $action => $limits) {
            $this->rateLimiter->addLimit(
                $action,
                $limits['requests'],
                $limits['period']
            );
        }
    }

    private function registerHooks(): void
    {
        add_action('init', [$this, 'setupSecurityHeaders']);
        add_filter('wp_headers', [$this, 'addSecurityHeaders']);

        add_action('wp_ajax_nopriv_wallet_up_process_payment', [$this, 'validateAjaxRequest']);
        add_action('wp_ajax_wallet_up_process_payment', [$this, 'validateAjaxRequest']);

        add_filter('rest_authentication_errors', [$this, 'validateApiRequest']);
    }

    public function setupSecurityHeaders(): void
    {
        if (is_admin()) {
            return;
        }

        if (!headers_sent()) {
            header("X-Content-Type-Options: nosniff");
            header("X-Frame-Options: SAMEORIGIN");
            header("X-XSS-Protection: 1; mode=block");
            header("Referrer-Policy: strict-origin-when-cross-origin");
        }
    }

    public function addSecurityHeaders(array $headers): array
    {
        $headers['X-Content-Type-Options'] = 'nosniff';
        $headers['X-Frame-Options'] = 'SAMEORIGIN';
        $headers['X-XSS-Protection'] = '1; mode=block';
        $headers['Referrer-Policy'] = 'strict-origin-when-cross-origin';

        return $headers;
    }

    public function validateInput(array $data, array $rules): array
    {
        try {
            return $this->validator->validate($data, $rules);
        } catch (\Exception $e) {
            $this->errorHandler->log('Input validation failed: ' . $e->getMessage());
            throw $e;
        }
    }

    public function sanitizeOutput($data, string $context = 'html'): string
    {
        try {
            return $this->sanitizer->sanitize($data, $context);
        } catch (\Exception $e) {
            $this->errorHandler->log('Output sanitization failed: ' . $e->getMessage());
            throw $e;
        }
    }

    public function checkRateLimit(string $action, string $identifier): bool
    {
        try {
            return $this->rateLimiter->check($action, $identifier);
        } catch (\Exception $e) {
            $this->errorHandler->log('Rate limit check failed: ' . $e->getMessage());
            return false;
        }
    }
    public function validateAjaxRequest(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new \Exception(esc_html('Invalid security token'));
            }

            $identifier = $this->getClientIdentifier();
            if (!$this->checkRateLimit('api_calls', $identifier)) {
                throw new \Exception(esc_html('Rate limit exceeded'));
            }

            if (is_user_logged_in() && !current_user_can('manage_options')) {
                if (!current_user_can('edit_posts')) { // Example capability for AJAX actions that don't require full admin access
                    throw new \Exception(esc_html('Insufficient permissions'));
                }
            }

        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    private function getClientIdentifier(): string
    {
        $identifier = '';

        if (is_user_logged_in()) {
            $identifier .= get_current_user_id();
        }

        $ip = $this->getClientIP();
        $identifier .= $ip;

        $userAgent = sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')) ?? '';
        $identifier .= $userAgent;

        return md5($identifier);
    }

    private function getClientIP(): string
    {
        $ipKeys = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($ipKeys as $key) {
            if (isset($_SERVER[$key])) {
                $ip = filter_var(sanitize_text_field(wp_unslash($_SERVER[$key])), FILTER_VALIDATE_IP);
                if ($ip !== false) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    public function encrypt(string $data): string
    {
        try {
            return $this->encryptor->encrypt($data);
        } catch (\Exception $e) {
            $this->errorHandler->log('Encryption failed: ' . $e->getMessage());
            throw $e;
        }
    }

    public function decrypt(string $data): string
    {
        try {
            return $this->encryptor->decrypt($data);
        } catch (\Exception $e) {
            $this->errorHandler->log('Decryption failed: ' . $e->getMessage());
            throw $e;
        }
    }

    public function checkAccess(string $capability, ?int $userId = null): bool
    {
        try {
            return $this->accessControl->check($capability, $userId);
        } catch (\Exception $e) {
            $this->errorHandler->log('Access check failed: ' . $e->getMessage());
            return false;
        }
    }

    public function cleanSensitiveData(string $text): string
    {
        foreach (self::SENSITIVE_PATTERNS as $pattern) {
            $text = preg_replace($pattern, '[REDACTED]', $text);
        }
        return $text;
    }

    public function validateApiRequest($errors)
    {
        $route = '';
        if (isset($GLOBALS['wp']->query_vars['rest_route'])) {
            $route = (string) $GLOBALS['wp']->query_vars['rest_route'];
        }
        if ($route === '' && isset($_SERVER['REQUEST_URI'])) {
            $route = (string) wp_unslash($_SERVER['REQUEST_URI']);
        }
        if (strpos($route, 'wallet-up-pro/') === false
            && strpos($route, 'walletup/') === false
            && strpos($route, 'walup/') === false) {
            return $errors;
        }
        try {
            $identifier = $this->getClientIdentifier();
            if (!$this->checkRateLimit('api_calls', $identifier)) {
                return new \WP_Error('rate_limit_exceeded', 'API rate limit exceeded', ['status' => 429]);
            }
        } catch (\Exception $e) {
            return new \WP_Error('api_security_error', $e->getMessage());
        }
        return $errors;
    }
}