<?php
namespace WalletUpPro\Core\Error;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
class ErrorHandler
{
    /** @var ErrorHandler|null Singleton instance */
    private static $instance = null;

    /** @var array Error storage */
    private $errors = [];

    /** @var bool Debug mode flag */
    private static $debug_mode;

    /** @var string Log file path */
    private $log_file;
    
    /** @var array Logging configuration */
    private $log_config = [
        'enable_info_logs' => false,  // Disable info logs by default
        'enable_debug_logs' => false, // Disable debug logs by default
        'enable_warning_logs' => true,
        'enable_error_logs' => true,
        'suppress_ob_end_flush' => true, // Suppress ob_end_flush warnings
        'max_log_size' => 5 * 1024 * 1024, // 5MB
        'max_backups' => 2,
        'rate_limit' => 100, // Max errors per minute
        'rate_limit_window' => 60, // seconds
        'wallet_up_only' => true // Only log WalletUpPro Pro errors by default
    ];
    
    /** @var array Rate limiting data */
    private $rate_limit_data = [];
    
    /** @var array Suppressed error patterns */
    private $suppressed_patterns = [
        '/ob_end_flush\(\): Failed to send buffer of zlib output compression/',
        '/ob_end_flush\(\): Failed to delete buffer/',
        '/Cannot modify header information - headers already sent/',
        '/unlink\(.*wflogs.*\): No such file or directory/' // Wordfence temp files
    ];

    /** @var array Error types mapping */
    private const ERROR_TYPES = [
        E_ERROR => 'Fatal Error',
        E_WARNING => 'Warning',
        E_PARSE => 'Parse Error',
        E_NOTICE => 'Notice',
        E_CORE_ERROR => 'Core Error',
        E_CORE_WARNING => 'Core Warning',
        E_COMPILE_ERROR => 'Compile Error',
        E_COMPILE_WARNING => 'Compile Warning',
        E_USER_ERROR => 'User Error',
        E_USER_WARNING => 'User Warning',
        E_USER_NOTICE => 'User Notice',
        2048 => 'Strict Notice',
        E_RECOVERABLE_ERROR => 'Recoverable Error',
        E_DEPRECATED => 'Deprecated',
        E_USER_DEPRECATED => 'User Deprecated'
    ];
    
    /** @var array Log level priorities */
    private const LOG_LEVELS = [
        'Debug' => 0,
        'Info' => 1,
        'Notice' => 2,
        'Warning' => 3,
        'Error' => 4,
        'Fatal' => 5
    ];

    public static function getInstance(): self
    {
        if (null === self::$instance) {
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
        self::$debug_mode = defined('WP_DEBUG') && WP_DEBUG;
        
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/wallet-up-logs';
        
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
            
            $htaccess_content = "Order deny,allow\nDeny from all\n";
            file_put_contents($log_dir . '/.htaccess', $htaccess_content);
            
            file_put_contents($log_dir . '/index.php', '<?php // Silence is golden');
        }
        
        $this->log_file = $log_dir . '/debug-' . wp_hash(NONCE_SALT . 'wallet-up-log') . '.log';
        
        $this->loadConfiguration();
        
        $this->registerHandlers();

        $this->rotateLogFile();
    }
    
    private function loadConfiguration(): void
    {
        $saved_config = get_option('wallet_up_log_config', []);
        $this->log_config = array_merge($this->log_config, $saved_config);
        
        if (defined('WALLET_UP_ENABLE_INFO_LOGS')) {
            $this->log_config['enable_info_logs'] = WALLET_UP_ENABLE_INFO_LOGS;
        }
        if (defined('WALLET_UP_ENABLE_DEBUG_LOGS')) {
            $this->log_config['enable_debug_logs'] = WALLET_UP_ENABLE_DEBUG_LOGS;
        }
        if (defined('WALLET_UP_SUPPRESS_OB_FLUSH')) {
            $this->log_config['suppress_ob_end_flush'] = WALLET_UP_SUPPRESS_OB_FLUSH;
        }
        if (defined('WALLET_UP_LOG_SYSTEM_WIDE')) {
            $this->log_config['wallet_up_only'] = !WALLET_UP_LOG_SYSTEM_WIDE;
        }
    }
    
    public function updateLogConfig(array $config): void
    {
        $this->log_config = array_merge($this->log_config, $config);
        update_option('wallet_up_log_config', $this->log_config);
    }
    
    public function getLogConfig(): array
    {
        return $this->log_config;
    }
    
    public function isWalletUpProOnly(): bool
    {
        return $this->log_config['wallet_up_only'];
    }
    
    public function setErrorScope(bool $wallet_up_only): void
    {
        $this->log_config['wallet_up_only'] = $wallet_up_only;
        update_option('wallet_up_log_config', $this->log_config);
    }

    private function registerHandlers(): void
    {
        register_shutdown_function([$this, 'handleShutdown']);
    }

    public function handleError(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting -- Checking for silenced errors
        if (error_reporting() === 0) {
            return false;
        }
        
        if ($this->log_config['wallet_up_only'] && !$this->isWalletUpProFile($errfile)) {
            return false; // Let PHP handle non-WalletUpPro errors normally
        }
        
        if ($this->shouldSuppressError($errno, $errstr, $errfile)) {
            return true; // Suppress the error
        }

        $error = $this->createErrorData($errno, $errstr, $errfile, $errline);
        $this->processError($error);

        return false;
    }
    
    private function shouldSuppressError(int $errno, string $errstr, string $errfile): bool
    {
        if ($this->log_config['suppress_ob_end_flush'] && 
            ($errno === E_NOTICE || $errno === E_WARNING) &&
            strpos($errstr, 'ob_end_flush()') !== false) {
            return true;
        }
        
        foreach ($this->suppressed_patterns as $pattern) {
            if (preg_match($pattern, $errstr)) {
                return true;
            }
        }
        
        if (strpos($errfile, 'wp-includes/functions.php') !== false && 
            strpos($errstr, 'ob_end_flush()') !== false) {
            return true;
        }
        
        return false;
    }

    public function handleException(\Throwable $exception): void
    {
        if ($this->log_config['wallet_up_only'] && !$this->isWalletUpProFile($exception->getFile())) {
            return;
        }

        $error = $this->createErrorData(
            null,
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            get_class($exception),
            $exception->getTraceAsString()
        );

        $this->processError($error);
    }

    public function handleShutdown(): void
    {
        $error = error_get_last();

        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) {
            if ($this->log_config['wallet_up_only'] && !$this->isWalletUpProFile($error['file'])) {
                return; // Don't handle non-WalletUpPro fatal errors
            }
            
            if (!$this->shouldSuppressError($error['type'], $error['message'], $error['file'])) {
                $error['time'] = current_time('mysql');
                $this->processError($error);
            }
        }
    }
    
    private function isWalletUpProFile(string $file): bool
    {
        $file = str_replace('\\', '/', $file);
        $plugin_dir = str_replace('\\', '/', WALLET_UP_BASE_DIR);
        
        if (strpos($file, $plugin_dir) === 0) {
            return true;
        }
        
        if (strpos($file, 'wp-includes') !== false || strpos($file, 'wp-admin') !== false) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Required to trace error origin
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
            foreach ($backtrace as $frame) {
                if (isset($frame['file']) && strpos($frame['file'], $plugin_dir) === 0) {
                    return true;
                }
            }
        }
        
        return false;
    }

    private function createErrorData(?int $errno, string $message, string $file, int $line, ?string $customType = null, ?string $trace = null): array
    {
        return [
            'type' => $customType ?? $this->getErrorType($errno),
            'message' => $message,
            'file' => $file,
            'line' => $line,
            'trace' => $trace,
            'time' => current_time('mysql')
        ];
    }

    private function processError(array $error): void
    {
        if ($this->shouldLogError($error['type'])) {
            $this->logError($error);
        }

        if ($this->isAjaxRequest()) {
            $this->sendJsonError($error);
        } elseif (self::$debug_mode && $this->isSignificantError($error['type'])) {
            $this->addAdminNotice($error);
        }
    }
    
    private function shouldLogError(string $type): bool
    {
        $type_lower = strtolower($type);
        
        if (strpos($type_lower, 'info') !== false && !$this->log_config['enable_info_logs']) {
            return false;
        }
        
        if (strpos($type_lower, 'debug') !== false && !$this->log_config['enable_debug_logs']) {
            return false;
        }
        
        if (strpos($type_lower, 'warning') !== false && !$this->log_config['enable_warning_logs']) {
            return false;
        }
        
        if (strpos($type_lower, 'error') !== false && !$this->log_config['enable_error_logs']) {
            return false;
        }
        
        return true;
    }
    
    private function isSignificantError(string $type): bool
    {
        $significant_types = ['Fatal Error', 'Error', 'Parse Error', 'Compile Error'];
        return in_array($type, $significant_types, true);
    }

    private function getErrorType(?int $errno): string
    {
        return self::ERROR_TYPES[$errno] ?? 'Unknown Error';
    }

    private function logError(array $error): void
    {
        if (!$this->checkRateLimit($error)) {
            return;
        }
        
        $message = $this->formatErrorLog($error);

        $dir = dirname($this->log_file);
        if (!file_exists($dir)) {
            if (!wp_mkdir_p($dir)) {
                return;
            }
        }

        if (false === file_put_contents($this->log_file, $message, FILE_APPEND | LOCK_EX)) {
            return;
        }
    }
    
    private function checkRateLimit(array $error): bool
    {
        $current_time = time();
        $error_hash = md5($error['message'] . $error['file'] . $error['line']);
        
        $this->rate_limit_data = array_filter($this->rate_limit_data, function($timestamp) use ($current_time) {
            return ($current_time - $timestamp) < $this->log_config['rate_limit_window'];
        });
        
        if (count($this->rate_limit_data) >= $this->log_config['rate_limit']) {
            return false;
        }
        
        if (isset($this->rate_limit_data[$error_hash]) && 
            ($current_time - $this->rate_limit_data[$error_hash]) < 5) {
            return false;
        }
        
        $this->rate_limit_data[$error_hash] = $current_time;
        
        return true;
    }

    private function formatErrorLog(array $error): string
    {
        $message = sprintf(
            "[%s] %s: %s in %s:%d\n",
            $error['time'],
            $error['type'],
            $error['message'],
            $error['file'],
            $error['line']
        );
        
        if (self::$debug_mode) {
            $context = $this->getErrorContext();
            if (!empty($context)) {
                $message .= "Context:\n";
                foreach ($context as $key => $value) {
                    $message .= "  {$key}: {$value}\n";
                }
            }
            
            if (isset($error['trace'])) {
                $message .= "Stack trace:\n" . $error['trace'] . "\n";
            }
        }

        return $message . "\n";
    }
    
    private function getErrorContext(): array
    {
        $context = [];
        
        if (isset($_SERVER['REQUEST_URI']) && isset($_SERVER['REQUEST_METHOD'])) {
            $request_method = sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD']));
            $request_uri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']));
            $context['URL'] = $request_method . ' ' . $request_uri;
        }
        
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            $context['User'] = sprintf('ID: %d, Role: %s', $user->ID, implode(', ', $user->roles));
        }
        
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Error context logging, not processing
        if ($this->isAjaxRequest() && isset($_REQUEST['action'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Error context logging, not processing
            $context['AJAX Action'] = sanitize_text_field(wp_unslash($_REQUEST['action']));
        }
        
        $context['Memory'] = sprintf('%.2f MB / %.2f MB', 
            memory_get_usage(true) / 1048576,
            memory_get_peak_usage(true) / 1048576
        );
        
        return $context;
    }

    public function log(string $message, string $type = 'Info'): void
    {
        if (!$this->shouldLogError($type)) {
            return;
        }
        
        $error = $this->createErrorData(null, $message, 'system', 0, $type);
        $this->logError($error);

        if (file_exists($this->log_file) && filesize($this->log_file) > $this->log_config['max_log_size']) {
            $this->rotateLogFile();
        }
    }

    private function rotateLogFile(): void
    {
        if (!file_exists($this->log_file)) {
            return;
        }

        if (filesize($this->log_file) > $this->log_config['max_log_size']) {
            $backup = $this->log_file . '.' . gmdate('Y-m-d-H-i-s');
            
            global $wp_filesystem;
            if (!$wp_filesystem) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                WP_Filesystem();
            }
            
            if (!$wp_filesystem->move($this->log_file, $backup)) {
                return;
            }

            $pattern = $this->log_file . '.*';
            $files = glob($pattern);
            if ($files && count($files) > $this->log_config['max_backups']) {
                usort($files, function ($a, $b) {
                    return filemtime($a) - filemtime($b);
                });
                foreach (array_slice($files, 0, -$this->log_config['max_backups']) as $file) {
                    wp_delete_file($file);
                }
            }
        }
    }

    private function addAdminNotice(array $error): void
    {
        add_action('admin_notices', function () use ($error) {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                sprintf(
                    'WalletUpPro Error: %s in %s on line %d',
                    esc_html($error['message']),
                    esc_html(basename($error['file'])),
                    (int) $error['line']
                )
            );
        });
    }

    private function sendJsonError(array $error): void
    {
        $response = [
            'success' => false,
            'message' => $error['message']
        ];

        wp_send_json_error($response);
    }

    private function isAjaxRequest(): bool
    {
        return defined('DOING_AJAX') && DOING_AJAX;
    }

    public function addError(string $message, string $type = 'Error'): void
    {
        $error = $this->createErrorData(null, $message, 'system', 0, $type);
        $this->errors[] = $error;
        if (count($this->errors) > 50) {
            $this->errors = array_slice($this->errors, -50);
        }

        if ($this->shouldLogError($type)) {
            $this->logError($error);
        }
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function clearErrors(): void
    {
        $this->errors = [];
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }
    
    public function debug(string $message): void
    {
        $this->log($message, 'Debug');
    }
    
    public function info(string $message): void
    {
        $this->log($message, 'Info');
    }
    
    public function warning(string $message): void
    {
        $this->log($message, 'Warning');
    }
    
    public function error(string $message): void
    {
        $this->log($message, 'Error');
    }

    public function cleanup(): void
    {
    }
}