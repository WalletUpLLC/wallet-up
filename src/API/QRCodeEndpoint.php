<?php
namespace WalletUpPro\API;

use WalletUpPro\Services\QRCodeService;
use WalletUpPro\Core\Error\ErrorHandler;
use WalletUpPro\Core\Analytics\QRTracker;
use WalletUpPro\Core\Security\RateLimiter;
use Exception;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class QRCodeEndpoint
{
    /**
     * @var QRCodeService
     */
    private $qrService;

    private function qr(): QRCodeService
    {
        if (!$this->qrService instanceof QRCodeService) {
            $this->qrService = QRCodeService::getInstance();
        }
        return $this->qrService;
    }

    /**
     * @var ErrorHandler
     */
    private $errorHandler;

    /**
     * @var string
     */
    private $namespace = 'wallet-up/v1';

    /**
     * @var bool
     */
    private $initialized = false;

    public function __construct()
    {
        try {
            $this->errorHandler = ErrorHandler::getInstance();

            add_action('rest_api_init', [$this, 'registerRoutes']);

            add_action('wp_ajax_wallet_up_generate_qr_with_logo', [$this, 'handleAjaxRequest']);
            add_action('wp_ajax_nopriv_wallet_up_generate_qr_with_logo', [$this, 'handleAjaxRequest']);

            add_action('wp_ajax_wallet_up_process_logo', [$this, 'handleLogoProcessing']);
            add_action('wp_ajax_nopriv_wallet_up_process_logo', [$this, 'handleLogoProcessing']);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                add_action('wp_ajax_wallet_up_test_qr', [$this, 'handleTestRequest']);
            }

            $this->initialized = true;

        } catch (Exception $e) {
            if (isset($this->errorHandler)) {
                $this->errorHandler->log('QR Code Endpoint Error: ' . $e->getMessage());
            }
        }
    }

    public function registerRoutes(): void
    {
        register_rest_route($this->namespace, '/qrcode', [
            'methods' => 'POST',
            'callback' => [$this, 'generateQRCode'],
            'permission_callback' => function () {
                return true; // Public endpoint
            },
        ]);

        register_rest_route($this->namespace, '/qrcode/styles', [
            'methods' => 'GET',
            'callback' => [$this, 'getQRStyles'],
            'permission_callback' => function () {
                return true; // Public endpoint
            },
        ]);

        register_rest_route($this->namespace, '/qrcode/process-logo', [
            'methods' => 'POST',
            'callback' => [$this, 'processLogo'],
            'permission_callback' => function () {
                return true; // Public endpoint
            },
        ]);

        register_rest_route($this->namespace, '/qrcode/status', [
            'methods' => 'GET',
            'callback' => [$this, 'getServiceStatus'],
            'permission_callback' => function () {
                return current_user_can('manage_options'); // Admin only
            },
        ]);
    }

    public function generateQRCode(\WP_REST_Request $request): \WP_REST_Response
    {
        $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));
        $rate_key = 'walletup_qr_rest_' . md5($ip);
        $count = (int) get_transient($rate_key);
        if ($count >= 60) {
            return new \WP_REST_Response(['success' => false, 'error' => 'Rate limited'], 429);
        }
        set_transient($rate_key, $count + 1, 60); // 60 per minute

        try {
            $data = $request->get_param('data');
            if (empty($data)) {
                throw new Exception(esc_html('Data to encode is required'));
            }

            $settings = $this->parseSettings($request);

            $logo = $request->get_file_params()['logo'] ?? null;

            $result = null;
            if ($logo && is_array($logo) && !empty($logo['tmp_name'])) {
                $result = $this->qr()->processUploadedLogo($logo, $data, $settings);
            } elseif ($logoData = $request->get_param('logoData')) {
                $result = $this->qr()->processBase64Logo($logoData, $data, $settings);
            } else {
                $result = $this->qr()->generateQR($data, $settings);
            }

            return new \WP_REST_Response([
                'success' => true,
                'qrImage' => $result
            ]);

        } catch (Exception $e) {
            $this->errorHandler->log('QR Code API Error: ' . $e->getMessage());
            return new \WP_REST_Response([
                'success' => false,
                'error' => 'An error occurred while generating the QR code'
            ], 400);
        }
    }

    public function getQRStyles(\WP_REST_Request $request): \WP_REST_Response
    {
        $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));
        $rate_key = 'walletup_qr_rest_' . md5($ip);
        $count = (int) get_transient($rate_key);
        if ($count >= 60) {
            return new \WP_REST_Response(['success' => false, 'error' => 'Rate limited'], 429);
        }
        set_transient($rate_key, $count + 1, 60); // 60 per minute

        try {
            $status = $this->qr()->getStatus();

            return new \WP_REST_Response([
                'success' => true,
                'styles' => $status['supported_styles'] ?? [],
                'sizes' => $status['supported_sizes'] ?? [],
                'errorLevels' => $status['supported_error_correction_levels'] ?? []
            ]);

        } catch (Exception $e) {
            $this->errorHandler->log('QR Styles API Error: ' . $e->getMessage());
            return new \WP_REST_Response([
                'success' => false,
                'error' => 'An error occurred while retrieving QR styles'
            ], 400);
        }
    }

    public function processLogo(\WP_REST_Request $request): \WP_REST_Response
    {
        $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));
        $rate_key = 'walletup_qr_rest_' . md5($ip);
        $count = (int) get_transient($rate_key);
        if ($count >= 60) {
            return new \WP_REST_Response(['success' => false, 'error' => 'Rate limited'], 429);
        }
        set_transient($rate_key, $count + 1, 60); // 60 per minute

        try {
            $allowedExtensions = ['png', 'jpg', 'jpeg'];

            $logo = $request->get_file_params()['logo'] ?? null;
            if (!$logo || !is_array($logo) || empty($logo['tmp_name'])) {
                throw new Exception(esc_html('Logo file is required'));
            }

            $uploadDir = wp_upload_dir()['basedir'];
            $tempFilePath = realpath($logo['tmp_name']);

            $originalFilename = basename($logo['name']);
            $sanitizedFilename = preg_replace('/[^a-zA-Z0-9_-]/', '', $originalFilename);
            $extension = strtolower(pathinfo($sanitizedFilename, PATHINFO_EXTENSION));

            if (!in_array($extension, $allowedExtensions)) {
                throw new Exception(esc_html('Invalid file extension'));
            }

            if (
                $tempFilePath === false ||
                strpos($tempFilePath, realpath($uploadDir)) !== 0 ||
                !file_exists($tempFilePath) ||
                !is_readable($tempFilePath)
            ) {
                throw new Exception(esc_html('Invalid file path'));
            }

            $manipulationSettings = [];
            if ($settingsData = $request->get_param('settings')) {
                $manipulationSettings = is_string($settingsData) ?
                    json_decode($this->sanitizeSettings($settingsData), true) :
                    $this->sanitizeArray($settingsData);
            }

            $processedLogoPath = $this->qr()->processLogoManipulation(
                $tempFilePath,
                $manipulationSettings
            );

            $processedRealPath = realpath($processedLogoPath);
            if (
                $processedRealPath === false ||
                strpos($processedRealPath, realpath($uploadDir)) !== 0 ||
                !file_exists($processedRealPath) ||
                !is_readable($processedRealPath)
            ) {
                throw new Exception(esc_html('Invalid processed file path'));
            }

            $processedExtension = strtolower(pathinfo($processedRealPath, PATHINFO_EXTENSION));
            if (!in_array($processedExtension, $allowedExtensions)) {
                throw new Exception(esc_html('Invalid processed file extension'));
            }

            $processedLogo = base64_encode(file_get_contents($processedRealPath));

            return new \WP_REST_Response([
                'success' => true,
                'processedLogo' => 'data:image/png;base64,' . $processedLogo
            ]);

        } catch (Exception $e) {
            $this->errorHandler->log('Logo Processing API Error: ' . $e->getMessage());
            return new \WP_REST_Response([
                'success' => false,
                'error' => 'An error occurred while processing the logo'
            ], 400);
        }
    }

    private function sanitizeSettings(string $settings): string
    {
        return preg_replace('/[^a-zA-Z0-9\s\{\}\[\]\:\"\',\._-]/', '', $settings);
    }

    private function sanitizeArray(array $data): array
    {
        return array_map(function ($value) {
            if (is_array($value)) {
                return $this->sanitizeArray($value);
            }
            return is_string($value) ?
                preg_replace('/[^a-zA-Z0-9_-]/', '', $value) :
                $value;
        }, $data);
    }

    public function getServiceStatus(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $status = $this->qr()->getStatus();

            return new \WP_REST_Response([
                'success' => true,
                'status' => $status
            ]);

        } catch (Exception $e) {
            $this->errorHandler->log('Service Status API Error: ' . $e->getMessage());
            return new \WP_REST_Response([
                'success' => false,
                'error' => 'An error occurred while checking service status'
            ], 400);
        }
    }

    public function handleAjaxRequest(): void
    {
        try {
            $rateLimiter = RateLimiter::getInstance();
            $clientIP = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            $rateKey = "qr_generate_{$clientIP}";
            
            if (!$rateLimiter->checkLimit($rateKey, 60, 60)) {
                throw new Exception(esc_html('Rate limit exceeded. Please try again later.'));
            }
            
            $this->checkRequiredExtensions();

            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new Exception(esc_html('Invalid QR Code security token'));
            }

            $data = isset($_POST['data']) ? sanitize_text_field(wp_unslash($_POST['data'])) : '';
            if (empty($data)) {
                throw new Exception(esc_html('Data to encode is required'));
            }
            
            if (!is_string($data) || strlen($data) > 4096) {
                throw new Exception(esc_html('Invalid data format or length'));
            }

            $settings = [];
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON string; decoded and sanitized via array_walk_recursive below.
            if (isset($_POST['settings']) && is_string(wp_unslash($_POST['settings']))) {
                $rawSettings = wp_unslash($_POST['settings']);
                $settings = json_decode($rawSettings, true) ?: [];
                
                array_walk_recursive($settings, function(&$value) {
                    if (is_string($value)) {
                        $value = sanitize_text_field($value);
                    }
                });

                if (isset($settings['foregroundOpacity'])) {
                    $this->errorHandler->log('Received foregroundOpacity: ' . $settings['foregroundOpacity']);
                }

                if (isset($settings['qrStyle'])) {
                    $this->errorHandler->log('QR code style: ' . $settings['qrStyle']);
                }

                if (isset($settings['logoSizePercentage'])) {
                    $largeLogoFlag = ($settings['logoSizePercentage'] > 25) ? ' (LARGER SIZE)' : '';
                    $this->errorHandler->log('Logo size percentage: ' . $settings['logoSizePercentage'] . $largeLogoFlag);

                    if ($settings['logoSizePercentage'] > 25) {
                        $settings['errorCorrectionLevel'] = 'high';
                        $this->errorHandler->log('Forcing high error correction for large logo');
                    }
                }

                if (isset($settings['logoShape'])) {
                    $this->errorHandler->log('Logo shape setting: ' . $settings['logoShape']);

                    if ($settings['logoShape'] === 'rounded' && isset($settings['logoShapeBorderRadius'])) {
                        $this->errorHandler->log('Logo border radius: ' . $settings['logoShapeBorderRadius'] . 'px');
                    }
                }

                if (isset($settings['addWhiteBorder'])) {
                    $this->errorHandler->log('Add white background around logo: ' . ($settings['addWhiteBorder'] ? 'yes' : 'no'));
                    if (isset($settings['whiteBorderSize'])) {
                        $this->errorHandler->log('White border size: ' . $settings['whiteBorderSize'] . 'px');
                    }
                }

                if (isset($settings['qrStyle']) && in_array($settings['qrStyle'], ['circular', 'diamond'])) {
                    $settings['roundFinderPatterns'] = true;
                    $this->errorHandler->log('Enabling rounded finder patterns for style: ' . $settings['qrStyle']);
                }
            }

            $result = null;

            if (isset($_FILES['logo']) && !empty($_FILES['logo']['tmp_name'])) {
                if (
                    isset($settings['logoShape']) && $settings['logoShape'] === 'rounded' &&
                    isset($_POST['customBorderRadius'])
                ) {
                    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Integer cast is the sanitization.
                    $settings['logoShapeBorderRadius'] = (int) wp_unslash($_POST['customBorderRadius']);
                }

                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- File validated via is_uploaded_file() and MIME checks in processUploadedLogo().
                $result = $this->qr()->processUploadedLogo($_FILES['logo'], $data, $settings);
            } elseif (isset($_POST['logoData']) && !empty($_POST['logoData'])) {
                if (!preg_match('', sanitize_text_field(wp_unslash($_POST['logoData'] ?? '')))) {
                    throw new Exception(esc_html('Invalid logo data'));
                }

                if (
                    isset($settings['logoShape']) && $settings['logoShape'] === 'rounded' &&
                    isset($_POST['customBorderRadius'])
                ) {
                    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Integer cast is the sanitization.
                    $settings['logoShapeBorderRadius'] = (int) wp_unslash($_POST['customBorderRadius']);
                }

                $result = $this->qr()->processBase64Logo(sanitize_text_field(wp_unslash($_POST['logoData'] ?? '')), $data, $settings);
            } else {
                $result = $this->qr()->generateQR($data, $settings);
            }

            wp_send_json_success(['qrImage' => $result]);

        } catch (Exception $e) {
            $this->errorHandler->log('QR Code AJAX Error: ' . $e->getMessage());

            $errorMessage = $e->getMessage();
            $errorData = [
                'error' => $errorMessage
            ];

            if (strpos($errorMessage, 'finfo') !== false || strpos($errorMessage, 'fileinfo') !== false) {
                $errorData['error'] = 'The PHP fileinfo extension is required but not installed on your server. Please contact your hosting provider to enable it.';
                $errorData['solution'] = 'Ask your hosting provider to enable the PHP fileinfo extension.';
            } elseif (strpos($errorMessage, 'gd') !== false) {
                $errorData['error'] = 'The PHP GD extension is required but not installed on your server. Please contact your hosting provider to enable it.';
                $errorData['solution'] = 'Ask your hosting provider to enable the PHP GD extension.';
            }

            wp_send_json_error($errorData);
        }
    }
    /**
     * Check for required PHP extensions and throw informative exceptions
     * 
     * @throws Exception When required extensions are missing
     */
    private function checkRequiredExtensions(): void
    {
        $missingExtensions = [];

        if (!extension_loaded('gd')) {
            $missingExtensions[] = 'GD';
        }

        if (!extension_loaded('fileinfo')) {
            $this->errorHandler->log('Warning: fileinfo extension is not available, using fallback methods');
        }

        if (!empty($missingExtensions)) {
            throw new Exception(esc_html('Required PHP extensions are missing: ' . implode(', ', $missingExtensions)));
        }
    }
    public function handleLogoProcessing(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new Exception(esc_html('Invalid security token'));
            }

            if (!isset($_FILES['logo']) || empty($_FILES['logo']['tmp_name'])) {
                throw new Exception(esc_html('Logo file is required'));
            }

            $settings = [];
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON string; decoded and used as config array.
            if (isset($_POST['settings']) && is_string(wp_unslash($_POST['settings']))) {
                $settings = json_decode(wp_unslash($_POST['settings']), true) ?: [];
            }

            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- File path validated via is_uploaded_file() check above.
            $processedLogoPath = $this->qr()->processLogoManipulation(
                $_FILES['logo']['tmp_name'],
                $settings
            );

            $processedLogo = base64_encode(file_get_contents($processedLogoPath));

            wp_send_json_success([
                'processedLogo' => 'data:image/png;base64,' . $processedLogo
            ]);

        } catch (Exception $e) {
            wp_send_json_error(['error' => 'An error occurred while processing the logo']);
        }
    }

    public function handleTestRequest(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new Exception(esc_html('Invalid security token'));
            }

            $test_type = isset($_POST['test_type']) ? sanitize_text_field(wp_unslash($_POST['test_type'])) : 'status';

            switch ($test_type) {
                case 'status':
                    wp_send_json_success($this->qr()->getStatus());
                    break;

                case 'sample_qr':
                    $data = 'https://example.com/?test=' . time();
                    $settings = [
                        'qrSize' => 'medium',
                        'qrColor' => '#674FBF',
                        'qrBackground' => '#F4F4FF',
                        'qrStyle' => 'rounded'
                    ];
                    $qrCode = $this->qr()->generateQR($data, $settings);
                    wp_send_json_success(['qrImage' => $qrCode]);
                    break;

                default:
                    throw new Exception(esc_html('Unknown test type'));
            }

        } catch (Exception $e) {
            wp_send_json_error(['error' => 'An error occurred while processing the test request']);
        }
    }

    private function parseSettings(\WP_REST_Request $request): array
    {
        $settingsData = $request->get_param('settings');

        if (is_string($settingsData)) {
            $settings = json_decode($settingsData, true) ?: [];
        } elseif (is_array($settingsData)) {
            $settings = $settingsData;
        } else {
            $settings = [];
        }

        $params = [
            'qrSize',
            'qrColor',
            'qrBackground',
            'qrBorder',
            'qrStyle',
            'errorCorrectionLevel',
            'roundBlockSizeMode',
            'marginSize',
            'foregroundOpacity',
            'punchoutBackground',
            'logoSizePercentage',
            'logoResizeToHeight',
            'ignoreAspectRatio',
            'logoPosition'
        ];

        foreach ($params as $param) {
            $value = $request->get_param($param);
            if ($value !== null) {
                $settings[$param] = $value;
            }
        }

        return $settings;
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }
}