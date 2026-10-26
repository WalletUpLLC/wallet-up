<?php

namespace WalletUpPro\Services;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Logo\Logo;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\Result\ResultInterface;
use Exception;
use WalletUpPro\Core\BaseSingleton;
use WalletUpPro\Core\Error\ErrorHandler;

if (!defined('ABSPATH')) {
    exit;
}

class QRCodeService extends BaseSingleton
{
    /**
     * @var PngWriter Writer for QR codes
     */
    private $writer;

    /**
     * @var string Fallback URL for external QR code generation
     */
    private $fallbackUrl;

    /**
     * @var ErrorHandler Error handler instance
     */
    private $errorHandler;

    /**
     * @var string|null Last error message
     */
    private $lastError = null;

    /**
     * @var bool Whether the service has been initialized
     */
    protected $initialized = false;

    /**
     * @var array QR code cache
     */
    private $qrCache = [];

    /**
     * @var bool Whether fileinfo extension is available
     */
    private $hasFileinfo = false;

    /**
     * @var array Mime type mapping for fallback detection
     */
    private $mimeTypeMap = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'pdf' => 'application/pdf',
        'txt' => 'text/plain',
    ];
    /**
     * @var int Cache TTL in seconds (1 hour)
     */
    private const CACHE_TTL = 3600;

    /**
     * @var string Cache transient prefix
     */
    private const CACHE_PREFIX = 'wallet_up_qr_';

    private const QR_DIMENSIONS = [
        'tiny' => 100,
        'small' => 150,
        'medium' => 180,
        'large' => 250,
        'xlarge' => 400,
        'xxlarge' => 500
    ];

    private const QR_STYLES = [
        'square' => 'square',          // Traditional square QR
        'rounded' => 'rounded',        // Rounded corners
        'dot' => 'dot',                // Dot pattern
        'circular' => 'circular',      // Circular patterns
        'diamond' => 'diamond',        // Diamond patterns
        'sharp' => 'sharp'             // Sharp-edged patterns
    ];

    private const LOGO_POSITIONS = [
        'center' => 'center',          // Center position (default)
        'top' => 'top',                // Top center
        'bottom' => 'bottom',          // Bottom center
        'left' => 'left',              // Left center
        'right' => 'right',            // Right center
        'top-left' => 'top-left',      // Top left corner
        'top-right' => 'top-right',    // Top right corner
        'bottom-left' => 'bottom-left',// Bottom left corner
        'bottom-right' => 'bottom-right'// Bottom right corner
    ];

    /**
     * Initialize the QR code service
     * 
     * @throws Exception
     */
    protected function initialize(): void
    {
        try {
            $this->hasFileinfo = extension_loaded('fileinfo');

            $this->loadDependencies();
            $this->initializeComponents();
            $this->initialized = true;

            $this->checkRequiredExtensions();

           /*  if (isset($this->errorHandler)) {
                $this->errorHandler->log('Fileinfo extension available: ' . ($this->hasFileinfo ? 'Yes' : 'No'));
            } */
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            if (isset($this->errorHandler)) {
                $this->errorHandler->log('QRCodeService Initialization Error: ' . $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * Load required dependencies
     * 
     * @throws Exception
     */
    private function loadDependencies(): void
    {
        $this->errorHandler = ErrorHandler::getInstance();

        if (file_exists(WALLET_UP_PATH . 'vendor/autoload.php')) {
            require_once(WALLET_UP_PATH . 'vendor/autoload.php');
        } else {
            $this->errorHandler->log('QRCodeService: vendor/autoload.php missing — using external QR API fallback');
            return;
        }

    }

    private function initializeComponents(): void
    {
        $this->fallbackUrl = defined('WALLET_UP_QR_CODE_EXTERNAL_API_FALLBACK')
            ? WALLET_UP_QR_CODE_EXTERNAL_API_FALLBACK
            : 'https://api.qrserver.com/v1/create-qr-code/?';
    }

    private function writer(): ?PngWriter
    {
        if ($this->writer instanceof PngWriter) {
            return $this->writer;
        }
        if (version_compare(PHP_VERSION, '8.1.0', '<')) {
            $this->errorHandler->log('QRCodeService: PHP < 8.1 — using external QR API fallback');
            return null;
        }
        if (!class_exists('Endroid\\QrCode\\Writer\\PngWriter')) {
            $this->errorHandler->log('QRCodeService: Endroid QR Code library not loaded — using external QR API fallback');
            return null;
        }
        $this->writer = new PngWriter();
        return $this->writer;
    }

    /**
     * Generate QR code with advanced customization
     * 
     * @param string $data Data to encode
     * @param array $settings QR code settings
     * @return string Generated QR code as data URI or URL
     * @throws Exception
     */
    /**
     * Build a scan-tracking redirect URL for payment QR codes.
     * When a phone camera scans the QR, it hits this URL which records
     * the scan event then 302-redirects to the actual payment URL.
     *
     * @param string $payment_url The actual payment URL to redirect to
     * @param string $method      Payment method ID (e.g. 'cashapp', 'venmo')
     * @param int    $post_id     Optional post ID for location tracking
     * @return string The tracking redirect URL
     */
    public static function buildScanTrackingUrl(string $payment_url, string $method, int $post_id = 0): string
    {
        $b64url = rtrim(strtr(base64_encode($payment_url), '+/', '-_'), '=');

        if (get_option('permalink_structure')) {
            $path = 'wallet-up/scan/' . rawurlencode($method) . '/' . $b64url;
            if ($post_id) {
                $path .= '/' . $post_id;
            }
            return home_url('/' . $path . '/');
        }

        $params = [
            'wup_scan' => $method,
            'r' => base64_encode($payment_url),
        ];
        if ($post_id) {
            $params['p'] = $post_id;
        }
        return home_url('/') . '?' . http_build_query($params);
    }

    /**
     * Resolve a scanned payment QR code: decode the wrapped URL, record the
     * scan if premium analytics are available, then 302-redirect to the real
     * payment URL. Registered on `init` unconditionally so the redirect works
     * on every site and subsite regardless of license scope — the QR image
     * itself is wrapped on all builds, so the resolver must exist on all too.
     */
    public static function handleScanRedirect($wp = null): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Public redirect endpoint, no state change beyond an analytics insert.
        $qv = ($wp instanceof \WP && is_array($wp->query_vars)) ? $wp->query_vars : [];

        $method = $qv['wup_scan']
            ?? (isset($_GET['wup_scan']) ? (string) wp_unslash($_GET['wup_scan']) : '');
        $encoded_url = $qv['r']
            ?? (isset($_GET['r']) ? (string) wp_unslash($_GET['r']) : '');
        $post_id = absint($qv['p'] ?? ($_GET['p'] ?? 0));
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if ($method === '' || $encoded_url === '') {
            return;
        }
        $method = preg_replace('/[^a-z0-9_-]/i', '', $method);

        $target_url = base64_decode(strtr($encoded_url, '-_', '+/'), true);
        if (!$target_url) {
            $target_url = base64_decode($encoded_url, true);
        }
        if (!$target_url || !filter_var($target_url, FILTER_VALIDATE_URL)) {
            wp_die(esc_html__('Invalid payment link.', 'walletup'), '', ['response' => 400]);
        }

        if (class_exists('\\WalletUpPro\\Core\\Analytics\\AnalyticsHandler')) {
            \WalletUpPro\Core\Analytics\AnalyticsHandler::recordScanEvent($method ?: 'unknown', $target_url, $post_id);
        }

        nocache_headers();
        if (!headers_sent()) {
            header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true);
            header('Pragma: no-cache', true);
            header('Expires: 0', true);
            header('X-Accel-Expires: 0', true);
            header('CDN-Cache-Control: no-store', true);
        }
        wp_redirect(esc_url_raw($target_url), 302);
        exit;
    }

    public static function registerScanRewriteRule(): void
    {
        add_rewrite_rule(
            '^wallet-up/scan/([^/]+)/([A-Za-z0-9_\-]+)/?$',
            'index.php?wup_scan=$matches[1]&r=$matches[2]',
            'top'
        );
        add_rewrite_rule(
            '^wallet-up/scan/([^/]+)/([A-Za-z0-9_\-]+)/([0-9]+)/?$',
            'index.php?wup_scan=$matches[1]&r=$matches[2]&p=$matches[3]',
            'top'
        );
        add_filter('query_vars', static function ($vars) {
            $vars[] = 'wup_scan';
            $vars[] = 'r';
            $vars[] = 'p';
            return $vars;
        });
    }

    public static function registerCacheBypass(): void
    {
        add_filter('rocket_cache_reject_uri', static function ($urls) {
            $urls[] = '/wallet-up/scan/(.*)';
            $urls[] = '/?wup_scan=(.*)';
            return $urls;
        });
        add_filter('litespeed_excludes_uri', static function ($urls) {
            $urls = (array) $urls;
            $urls[] = '/wallet-up/scan/';
            $urls[] = '?wup_scan=';
            return $urls;
        });
        add_action('init', static function () {
            $on_scan_url = isset($_GET['wup_scan'])
                || (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/wallet-up/scan/') !== false);
            if ($on_scan_url && !defined('DONOTCACHEPAGE')) {
                define('DONOTCACHEPAGE', true);
            }
        }, 0);
    }

    public function generateQR(string $data, array $settings = []): string
    {
        try {
            if (empty($data)) {
                throw new Exception(esc_html('QR data cannot be empty'));
            }

            if (!empty($settings['payment_method']) && filter_var($data, FILTER_VALIDATE_URL)) {
                $preciseZelle = $settings['payment_method'] === 'zelle'
                    && trim((string) get_option('wallet_up_zelle_name', '')) !== '';
                if (!$preciseZelle) {
                    $post_id = isset($settings['post_id']) ? absint($settings['post_id']) : 0;
                    $data = self::buildScanTrackingUrl($data, $settings['payment_method'], $post_id);
                }
            }

            if ($this->writer() === null) {
                $size = $this->getSize($settings);
                $params = $this->getQRParams($data, $size, $settings);
                return $this->fallbackQR($params);
            }

            $this->verifyQRCapabilities();
            $size = $this->getSize($settings);
            $params = $this->getQRParams($data, $size, $settings);

            $cacheKey = $this->generateCacheKey($data, $settings);

            $cachedQR = $this->getCachedQR($cacheKey);
            if ($cachedQR) {
                $this->errorHandler->log('Retrieved QR from cache for: ' . substr($data, 0, 30) . '...');

                $this->trackQRGeneration('cache_hit', $data, $settings);

                return $cachedQR;
            }

            if (defined('WALLET_UP_QR_CODE_API') && WALLET_UP_QR_CODE_API) {
                $result = $this->fallbackQR($params);
            } else {
                $logo = null;
                $logoPath = $settings['logoPath'] ?? null;

                if ($logoPath && file_exists($logoPath)) {
                    try {
                        $this->errorHandler->log("Processing logo at: $logoPath");

                        if ($this->isValidLogoPath($logoPath)) {
                            $logo = $this->prepareLogoForQR($logoPath, $size, $settings);

                            if ($logo === null) {
                                $this->errorHandler->log("Warning: Logo preparation failed, generating QR code without logo");
                            } else {

                                if (!isset($settings['errorCorrectionLevel']) || $settings['errorCorrectionLevel'] !== 'high') {
                                    $settings['errorCorrectionLevel'] = 'high';
                                    $this->errorHandler->log("Using high error correction for logo integration");
                                }
                            }
                        } else {
                            $this->errorHandler->log("Invalid logo, generating QR code without logo");
                        }
                    } catch (Exception $logoException) {
                        $this->errorHandler->log("Logo processing error: " . $logoException->getMessage());
                    }
                }

                $result = $this->generateLocalQR($data, $size, $settings, $logo);
            }

            $this->cacheQR($cacheKey, $result);

            $this->trackQRGeneration('generated', $data, $settings);

            return $result;

        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            $this->errorHandler->log('QR Generation Error: ' . $e->getMessage());
            if ($this->fallbackUrl && isset($params)) {
                $this->errorHandler->log('Falling back to external QR service');
                return $this->fallbackQR($params);
            }
            throw $e;
        }
    }

    private function generateCacheKey(string $data, array $settings): string
    {
        $secretKey = getenv('CACHE_SECRET_KEY') ?: (defined('AUTH_KEY') ? AUTH_KEY : 'wallet-up-' . md5(ABSPATH));

        $settingsHash = hash_hmac('sha256', serialize($settings), $secretKey);
        $dataHash = hash_hmac('sha256', $data, $secretKey);

        return sprintf(
            '%s%s_%s',
            self::CACHE_PREFIX,
            $dataHash,
            $settingsHash
        );
    }
    private function getCachedQR(string $key)
    {
        if (isset($this->qrCache[$key])) {
            return $this->qrCache[$key];
        }

        return get_transient($key);
    }

    private function cacheQR(string $key, string $value): void
    {
        $this->qrCache[$key] = $value;

        set_transient($key, $value, self::CACHE_TTL);
    }

    private function getSize(array $settings): int
    {
        $sizeKey = strtolower($settings['qrSize'] ?? 'medium');
        return self::QR_DIMENSIONS[$sizeKey] ?? self::QR_DIMENSIONS['medium'];
    }

    private function getQRParams(string $data, int $size, array $settings): array
    {
        $errorCorrection = strtolower($settings['errorCorrectionLevel'] ?? 'high');
        $ecc = in_array($errorCorrection, ['low', 'medium', 'quartile', 'high']) ? $errorCorrection : 'high';

        return [
            'size' => "{$size}x{$size}",
            'margin' => ($settings['qrBorder'] ?? true) ? ($settings['marginSize'] ?? 10) : 0,
            'color' => str_replace('#', '', $settings['qrColor'] ?? '674FBF'),
            'bgcolor' => str_replace('#', '', $settings['qrBackground'] ?? 'F4F4FF'),
            'data' => urlencode($data),
            'style' => $settings['qrStyle'] ?? 'rounded',
            'qzone' => isset($settings['logoPath']) || isset($settings['logoData']) ?
                ($settings['logoMargin'] ?? 5) : 1,
            'ecc' => $ecc,
            'format' => 'png'
        ];
    }

    /**
     * Safely normalize opacity values for PHP GD compatibility
     * 
     * @param mixed $opacity Opacity value to normalize
     * @return int Normalized opacity value between 0-127
     */
    private function normalizeOpacity($opacity): int
    {
        if (!isset($opacity) || !is_numeric($opacity)) {
            return 0; // 0 = fully opaque in GD
        }

        if ($opacity >= 0 && $opacity <= 127) {
            return (int) $opacity;
        }

        if ($opacity >= 0 && $opacity <= 100) {
            return (int) (127 - (($opacity / 100) * 127));
        }

        return max(0, min(127, (int) $opacity));
    }
    /*  private function getErrorCorrectionLevel(string $level)
     {
         $className = 'Endroid\\QrCode\\ErrorCorrectionLevel';

         $caseName = ucfirst(strtolower($level));

         // If the class isn't found, return the string value
         if (!class_exists($className)) {
             return strtolower($level);
         }

         // Use reflection to safely access the enum value
         try {
             // For PHP 8.1+ with enum support
             if (PHP_VERSION_ID >= 80100) {
                 $reflection = new \ReflectionClass($className);
                 $cases = $reflection->getConstants();

                 foreach ($cases as $case => $value) {
                     if (strtolower($case) === strtolower($level)) {
                         return constant("$className::$case");
                     }
                 }
             }

             // Final fallback - try direct instantiation with string value
             return strtolower($level);
         } catch (\Throwable $e) {
             // If anything fails, return the string value as fallback
             return strtolower($level);
         }
     } */
    private function getErrorCorrectionLevel(string $level): ErrorCorrectionLevel
    {
        $normalizedLevel = strtolower($level);

        switch ($normalizedLevel) {
            case 'low':
                return ErrorCorrectionLevel::Low;
            case 'medium':
                return ErrorCorrectionLevel::Medium;
            case 'quartile':
                return ErrorCorrectionLevel::Quartile;
            case 'high':
            default:
                return ErrorCorrectionLevel::High;
        }
    }
    /*  private function getRoundBlockSizeMode(string $mode)
     {
         $className = 'Endroid\\QrCode\\RoundBlockSizeMode';

         $caseName = ucfirst(strtolower($mode));

         // If the class isn't found, return the string value
         if (!class_exists($className)) {
             return strtolower($mode);
         }

         // Use reflection to safely access the enum value
         try {
             // For PHP 8.1+ with enum support
             if (PHP_VERSION_ID >= 80100) {
                 $reflection = new \ReflectionClass($className);
                 $cases = $reflection->getConstants();

                 foreach ($cases as $case => $value) {
                     if (strtolower($case) === strtolower($mode)) {
                         return constant("$className::$case");
                     }
                 }
             }

             // Final fallback - try direct instantiation with string value
             return strtolower($mode);
         } catch (\Throwable $e) {
             // If anything fails, return the string value as fallback
             return strtolower($mode);
         }
     } */
    private function getRoundBlockSizeMode(string $mode): RoundBlockSizeMode
    {
        $normalizedMode = strtolower($mode);

        switch ($normalizedMode) {
            case 'margin':
                return RoundBlockSizeMode::Margin;
            case 'enlarge':
                return RoundBlockSizeMode::Enlarge;
            case 'shrink':
                return RoundBlockSizeMode::Shrink;
            case 'none':
            default:
                return RoundBlockSizeMode::None;
        }
    }
    /**
     * Generate QR code locally with advanced styling and shape preservation
     * 
     * @param string $data Data to encode
     * @param int $size QR code size
     * @param array $settings QR code settings
     * @return string Generated QR code as data URI
     * @throws Exception
     */
    private function generateLocalQR(string $data, int $size, array $settings, ?Logo $logo = null): string
    {
        try {
            $errorCorrectionLevel = $this->getErrorCorrectionLevel($settings['errorCorrectionLevel'] ?? 'high');

            if (
                (isset($settings['logoSizePercentage']) && (int) $settings['logoSizePercentage'] > 25) ||
                (isset($settings['logoPath']) || isset($settings['logoData']))
            ) {
                $errorCorrectionLevel = $this->getErrorCorrectionLevel('high');
            }

            $roundBlockSizeMode = $this->getRoundBlockSizeMode($settings['roundBlockSizeMode'] ?? 'shrink');

            $fgOpacity = 0; // 0 = fully opaque in GD
            if (isset($settings['foregroundOpacity'])) {
                if ($settings['foregroundOpacity'] >= 0 && $settings['foregroundOpacity'] <= 127) {
                    $fgOpacity = (int) $settings['foregroundOpacity'];
                }
                else if ($settings['foregroundOpacity'] >= 0 && $settings['foregroundOpacity'] <= 100) {
                    $fgOpacity = (int) (127 - (($settings['foregroundOpacity'] / 100) * 127));
                    $fgOpacity = max(0, min(127, $fgOpacity));
                }
            }

            $qr = QrCode::create($data)
                ->setSize($size)
                ->setMargin($settings['qrBorder'] ?? true ? ($settings['marginSize'] ?? 10) : 0)
                ->setEncoding(new Encoding('UTF-8'))
                ->setErrorCorrectionLevel($errorCorrectionLevel)
                ->setRoundBlockSizeMode($roundBlockSizeMode);

            $bgColorRgb = $this->hexToRgb($settings['qrBackground'] ?? '#F4F4FF');
            $fgColorRgb = $this->hexToRgb($settings['qrColor'] ?? '#674FBF');

            [$fgColorRgb, $fgOpacity] = $this->ensureScannableForeground($fgColorRgb, $bgColorRgb, $fgOpacity);

            $qr->setForegroundColor(new Color($fgColorRgb[0], $fgColorRgb[1], $fgColorRgb[2], $fgOpacity));
            $qr->setBackgroundColor(new Color($bgColorRgb[0], $bgColorRgb[1], $bgColorRgb[2]));

            unset($settings['foregroundOpacity']);

            $qrStyle = $settings['qrStyle'] ?? 'rounded';
            $this->applyQRCodeStyle($qr, $qrStyle, $settings);

                if ($qrStyle === 'dot') {
                    $qr->{'dotStyle'} = true;
                    
                    if (isset($settings['dotSizeRatio'])) {
                        $qr->{'dotSizeRatio'} = $settings['dotSizeRatio'];
                    }

                    if (isset($settings['smoothDots'])) {
                        $qr->{'smoothDots'} = $settings['smoothDots'];
                    }

                    if (isset($settings['dotSpacing'])) {
                        $qr->{'dotSpacing'} = $settings['dotSpacing'];
                    }
                }
            $logo = null;
            if (isset($settings['logoPath']) && $this->isValidLogoPath($settings['logoPath'])) {
                $logoPath = $settings['logoPath'];
                $logoShape = $settings['logoShape'] ?? 'original';

                if ($logoShape !== 'original' && $logoShape !== 'auto') {
                    $shapeOptions = [
                        'borderRadius' => $settings['logoShapeBorderRadius'] ?? 10 // Default border radius for rounded shape
                    ];

                    $logoPath = $this->preserveLogoShape($logoPath, $logoShape, $shapeOptions);

                    $settings['punchoutBackground'] = false;
                }

                if (isset($settings['addWhiteBorder']) && $settings['addWhiteBorder']) {
                    $logoPath = $this->addWhiteBackgroundToLogo(
                        $logoPath,
                        (int) ($settings['whiteBorderSize'] ?? 5)
                    );
                }

                $logo = $this->prepareLogoForQR($logoPath, $size, $settings);
            } elseif (isset($settings['logoData']) && !empty($settings['logoData'])) {
                $logoData = $this->decodeBase64Image($settings['logoData']);
                if ($logoData) {
                    $tempFile = $this->createTempLogoFile($logoData, $settings['logoType'] ?? 'png');
                    if ($tempFile) {
                        $logoShape = $settings['logoShape'] ?? 'original';

                        if ($logoShape !== 'original' && $logoShape !== 'auto') {
                            $shapeOptions = [
                                'borderRadius' => $settings['logoShapeBorderRadius'] ?? 10 // Default border radius for rounded shape
                            ];

                            $tempFile = $this->preserveLogoShape($tempFile, $logoShape, $shapeOptions);

                            $settings['punchoutBackground'] = false;
                        }

                        if (isset($settings['addWhiteBorder']) && $settings['addWhiteBorder']) {
                            $tempFile = $this->addWhiteBackgroundToLogo(
                                $tempFile,
                                (int) ($settings['whiteBorderSize'] ?? 5)
                            );
                        }

                        $logo = $this->prepareLogoForQR($tempFile, $size, $settings);
                        register_shutdown_function(function () use ($tempFile) {
                            if (file_exists($tempFile)) {
                                @wp_delete_file($tempFile);
                            }
                        });
                    }
                }
            }

            $result = $this->writer()->write($qr, $logo);

            if ($qrStyle !== 'square' && $qrStyle !== 'rounded') {
                if ($qrStyle === 'dot') {
                    $dotSettings = array_merge($settings, [
                        'dotSizeRatio' => $qr->{'dotSizeRatio'} ?? 0.7,
                        'smoothDots' => $qr->{'smoothDots'} ?? false,
                        'dotSpacing' => $qr->{'dotSpacing'} ?? 0.2
                    ]);
                    $result = $this->applyDotStyle($result, $dotSettings);
                } else if ($qrStyle === 'circular') {
                    $result = $this->applyCircularStyle($result, $settings);
                } else if ($qrStyle === 'diamond') {
                    $result = $this->applyDiamondStyle($result, $settings);
                } else if ($qrStyle === 'sharp') {
                    $result = $this->applySharpStyle($result, $settings);
                }
            }

            if (isset($settings['roundFinderPatterns']) && $settings['roundFinderPatterns']) {
                $result = $this->applyRoundedFinderPatterns($result);
            }

            return 'data:image/png;base64,' . base64_encode($result->getString());

        } catch (Exception $e) {
            $this->errorHandler->log('Error in generateLocalQR: ' . $e->getMessage() . ' / Stack: ' . $e->getTraceAsString());

            if ($logo !== null) {
                $this->errorHandler->log('Attempting fallback generation without logo');
                try {
                    $fallbackSettings = $settings;
                    unset($fallbackSettings['logoPath']);
                    unset($fallbackSettings['logoData']);
                    unset($fallbackSettings['logoShape']);
                    unset($fallbackSettings['logoSizePercentage']);

                    return $this->generateLocalQR($data, $size, $fallbackSettings);
                } catch (Exception $fallbackError) {
                    $this->errorHandler->log('Fallback generation also failed: ' . $fallbackError->getMessage());
                }
            }

            throw $e;
        }
    }

    private function applyPostProcessing(ResultInterface $result, string $style, array $settings): ResultInterface
    {
        switch ($style) {
            case 'rounded':
                return $result;

            case 'dot':
                return $this->applyDotStyle($result, $settings);

            case 'diamond':
                return $this->applyDiamondStyle($result, $settings);

            case 'circular':
                return $this->applyCircularStyle($result, $settings);

            case 'sharp':
                return $this->applySharpStyle($result, $settings);

            default:
                return $result;
        }
    }
    /*   private function createModifiedResult(string $newPngData, ResultInterface $originalResult): ResultInterface
      {
          return new class ($newPngData, $originalResult) implements ResultInterface {
              private $data;
              private $originalResult;

              public function __construct(string $data, ResultInterface $originalResult)
              {
                  $this->data = $data;
                  $this->originalResult = $originalResult;
              }

              public function getString(): string
              {
                  return $this->data;
              }

              public function getDataUri(): string
              {
                  return 'data:image/png;base64,' . base64_encode($this->data);
              }

              public function saveToFile(string $path): void
              {
                  file_put_contents($path, $this->data);
              }

              public function getMatrix(): \Endroid\QrCode\Matrix\MatrixInterface
              {
                  return $this->originalResult->getMatrix();
              }

              public function getMimeType(): string
              {
                  return 'image/png';
              }
          };
      } */

    /**
     * Helper method to draw circular QR code
     * 
     * @param resource $dest Destination image
     * @param resource $src Source image
     * @param int $width Image width
     * @param int $height Image height
     * @param int $centerX Center X coordinate
     * @param int $centerY Center Y coordinate
     * @param int $radius Radius
     * @param int $moduleSize Module size
     */
    private function drawCircularQR($dest, $src, $width, $height, $centerX, $centerY, $radius, $moduleSize)
    {
        $patterns = $this->detectQRPatterns($src, $width, $height, $moduleSize);

        $rings = (int) ($radius / $moduleSize);

        for ($ring = 0; $ring < $rings; $ring++) {
            $currentRadius = $moduleSize * ($ring + 1);
            $circumference = 2 * M_PI * $currentRadius;

            $modules = max(8, (int) ($circumference / $moduleSize));

            $angleIncrement = 2 * M_PI / $modules;

            for ($i = 0; $i < $modules; $i++) {
                $angle = $i * $angleIncrement;

                $x = (int) ($centerX + $currentRadius * cos($angle));
                $y = (int) ($centerY + $currentRadius * sin($angle));

                if ($x < 0 || $x >= $width || $y < 0 || $y >= $height) {
                    continue;
                }

                $origX = (int) (($x - $centerX + $width / 2) / $width * $width);
                $origY = (int) (($y - $centerY + $height / 2) / $height * $height);

                if ($origX < 0 || $origX >= $width || $origY < 0 || $origY >= $height) {
                    continue;
                }

                $color = imagecolorat($src, $origX, $origY);
                $rgb = imagecolorsforindex($src, $color);

                if ($rgb['alpha'] > 20) {
                    continue;
                }

                $moduleColor = imagecolorallocate($dest, $rgb['red'], $rgb['green'], $rgb['blue']);
                $moduleSize = (int) ($moduleSize * 0.9);
                imagefilledellipse($dest, $x, $y, $moduleSize, $moduleSize, $moduleColor);
            }
        }

        foreach ($patterns as $pattern) {
            for ($y = $pattern['y']; $y < $pattern['y'] + $pattern['size']; $y++) {
                for ($x = $pattern['x']; $x < $pattern['x'] + $pattern['size']; $x++) {
                    if ($x >= $width || $y >= $height)
                        continue;

                    $color = imagecolorat($src, $x, $y);
                    $rgb = imagecolorsforindex($src, $color);

                    if ($rgb['alpha'] > 20)
                        continue;

                    $pixelColor = imagecolorallocate($dest, $rgb['red'], $rgb['green'], $rgb['blue']);
                    imagesetpixel($dest, $x, $y, $pixelColor);
                }
            }
        }
    }

private function applyQRCodeStyle(QrCode &$qr, string $style, array $settings): void
{
    switch ($style) {
        case 'square':
            $qr->setRoundBlockSizeMode(RoundBlockSizeMode::None);
            break;

        case 'rounded':
            $qr->setRoundBlockSizeMode(RoundBlockSizeMode::Shrink);
            $qr->setMargin(max(4, $settings['marginSize'] ?? 10));
            break;

        case 'dot':
            $qr->setRoundBlockSizeMode(RoundBlockSizeMode::None);
            $qr->{'dotStyle'} = true;
            $qr->{'dotSizeRatio'} = $settings['dotSizeRatio'] ?? 0.7;
            $qr->{'smoothDots'} = $settings['smoothDots'] ?? false;
            $qr->{'dotSpacing'} = $settings['dotSpacing'] ?? 0.2;
            $qr->setMargin(max(10, $settings['marginSize'] ?? 10));
            break;

        case 'circular':
            $qr->setRoundBlockSizeMode(RoundBlockSizeMode::Enlarge);
            $qr->{'circularPattern'} = true;
            $qr->setMargin(max(10, $settings['marginSize'] ?? 10));
            break;

        case 'diamond':
            $qr->setRoundBlockSizeMode(RoundBlockSizeMode::None);
            $qr->{'diamondPattern'} = true;
            break;

        case 'sharp':
            $qr->setRoundBlockSizeMode(RoundBlockSizeMode::None);
            $qr->setMargin(max(15, $settings['marginSize'] ?? 15));
            $qr->{'sharpPattern'} = true;
            break;

        default:
            $qr->setRoundBlockSizeMode(RoundBlockSizeMode::None);
            break;
    }
}

private function applyDotStyle(ResultInterface $result, array $settings): ResultInterface
{
    try {
        $matrix = $result->getMatrix();
        $moduleCount = $matrix->getBlockCount();

        $pngData = $result->getString();
        $image = imagecreatefromstring($pngData);

        if (!$image) {
            $this->errorHandler->log('Failed to create image from QR code data for dot style');
            return $result;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        $dotImage = imagecreatetruecolor($width, $height);
        imagealphablending($dotImage, false);
        imagesavealpha($dotImage, true);

        $bgColorRgb = $this->hexToRgb($settings['qrBackground'] ?? '#F4F4FF');
        $bgColor = imagecolorallocate($dotImage, $bgColorRgb[0], $bgColorRgb[1], $bgColorRgb[2]);
        imagefill($dotImage, 0, 0, $bgColor);

        $moduleSize = (int) floor(min($width, $height) / $moduleCount);

        $fgColorRgb = $this->hexToRgb($settings['qrColor'] ?? '#674FBF');
        $fgColor = imagecolorallocate($dotImage, $fgColorRgb[0], $fgColorRgb[1], $fgColorRgb[2]);

        imagealphablending($dotImage, true);

        $dotSizeRatio = 0.7;
        if (isset($settings['dotSizeRatio']) && is_numeric($settings['dotSizeRatio'])) {
            $dotSizeRatio = min(0.9, max(0.5, (float) $settings['dotSizeRatio']));
        }

        $dotSize = (int) max(3, floor($moduleSize * $dotSizeRatio));

        if ($dotSize % 2 == 0) {
            $dotSize--;
        }

        for ($y = 0; $y < $moduleCount; $y++) {
            for ($x = 0; $x < $moduleCount; $x++) {
                try {
                    $isDarkModule = $matrix->getBlockValue($x, $y); // Fixed from ($y, $x)

                    if ($isDarkModule) {
                        $centerX = (int) ($x * $moduleSize + $moduleSize / 2);
                        $centerY = (int) ($y * $moduleSize + $moduleSize / 2);

                        if ($centerX >= 0 && $centerX < $width && $centerY >= 0 && $centerY < $height) {
                            if ($this->isFinderPatternModule($x, $y, $moduleCount)) {
                                $squareSize = (int) ($moduleSize * 0.9);
                                $halfSize = (int) ($squareSize / 2);

                                imagefilledrectangle(
                                    $dotImage,
                                    $centerX - $halfSize,
                                    $centerY - $halfSize,
                                    $centerX + $halfSize,
                                    $centerY + $halfSize,
                                    $fgColor
                                );
                            } else if ($this->isTimingPatternModule($x, $y, $moduleCount)) {
                                $timingDotSize = (int) ($dotSize * 1.1);
                                imagefilledellipse(
                                    $dotImage,
                                    $centerX,
                                    $centerY,
                                    $timingDotSize,
                                    $timingDotSize,
                                    $fgColor
                                );
                            } else {
                                imagefilledellipse(
                                    $dotImage,
                                    $centerX,
                                    $centerY,
                                    $dotSize,
                                    $dotSize,
                                    $fgColor
                                );
                            }
                        }
                    }
                } catch (Exception $e) {
                    continue;
                }
            }
        }

        if (isset($settings['smoothDots']) && $settings['smoothDots']) {
            imagefilter($dotImage, IMG_FILTER_SMOOTH, 10);
        }

        ob_start();
        imagepng($dotImage, null, 9);
        $newPngData = ob_get_clean();

        imagedestroy($image);
        imagedestroy($dotImage);

        return $this->createModifiedResult($newPngData, $result);

    } catch (Exception $e) {
        $this->errorHandler->log('Dot style application error: ' . $e->getMessage());
        return $result;
    }
}

private function isFinderPatternModule(int $x, int $y, int $moduleCount): bool
{
    if ($x < 7 && $y < 7) {
        return true;
    }
    
    if ($x >= $moduleCount - 7 && $y < 7) {
        return true;
    }
    
    if ($x < 7 && $y >= $moduleCount - 7) {
        return true;
    }
    
    if (($x == 7 && $y < 8) || ($x < 8 && $y == 7)) {
        return true;
    }
    
    if (($x == $moduleCount - 8 && $y < 8) || ($x >= $moduleCount - 8 && $y == 7)) {
        return true;
    }
    
    if (($x == 7 && $y >= $moduleCount - 8) || ($x < 8 && $y == $moduleCount - 8)) {
        return true;
    }
    
    return false;
}

private function isTimingPatternModule(int $x, int $y, int $moduleCount): bool
{
    if ($y == 6 && $x >= 8 && $x < $moduleCount - 8) {
        return true;
    }
    
    if ($x == 6 && $y >= 8 && $y < $moduleCount - 8) {
        return true;
    }
    
    return false;
}

    /**
     * Draw finder patterns for QR code
     * 
     * @param resource $image Image resource
     * @param int $moduleCount Number of modules in the QR code
     * @param int $moduleSize Size of each module in pixels
     * @param int $color Color to use for drawing
     */
    private function drawFinderPatterns($image, int $moduleCount, int $moduleSize, $color): void
    {
        $positions = [
            [0, 0], // Top-left
            [$moduleCount - 7, 0], // Top-right
            [0, $moduleCount - 7] // Bottom-left
        ];

        foreach ($positions as [$x, $y]) {
            $pixelX = $x * $moduleSize;
            $pixelY = $y * $moduleSize;
            $patternSize = 7 * $moduleSize;

            imagefilledrectangle(
                $image,
                $pixelX,
                $pixelY,
                $pixelX + $patternSize - 1,
                $pixelY + $patternSize - 1,
                $color
            );

            $innerX = $pixelX + $moduleSize;
            $innerY = $pixelY + $moduleSize;
            $innerSize = 5 * $moduleSize;

            $white = imagecolorallocate($image, 255, 255, 255);
            imagefilledrectangle(
                $image,
                $innerX,
                $innerY,
                $innerX + $innerSize - 1,
                $innerY + $innerSize - 1,
                $white
            );

            $centerX = $pixelX + 2 * $moduleSize;
            $centerY = $pixelY + 2 * $moduleSize;
            $centerSize = 3 * $moduleSize;

            imagefilledrectangle(
                $image,
                $centerX,
                $centerY,
                $centerX + $centerSize - 1,
                $centerY + $centerSize - 1,
                $color
            );
        }
    }

    /**
     * Draw timing patterns for QR code
     * 
     * @param resource $image Image resource
     * @param int $moduleCount Number of modules in the QR code
     * @param int $moduleSize Size of each module in pixels
     * @param int $color Color to use for drawing
     */
    private function drawTimingPatterns($image, int $moduleCount, int $moduleSize, $color): void
    {
        for ($x = 8; $x < $moduleCount - 8; $x++) {
            if ($x % 2 == 0) { // Alternate modules
                $pixelX = $x * $moduleSize;
                $pixelY = 6 * $moduleSize;

                imagefilledrectangle(
                    $image,
                    $pixelX,
                    $pixelY,
                    $pixelX + $moduleSize - 1,
                    $pixelY + $moduleSize - 1,
                    $color
                );
            }
        }

        for ($y = 8; $y < $moduleCount - 8; $y++) {
            if ($y % 2 == 0) { // Alternate modules
                $pixelX = 6 * $moduleSize;
                $pixelY = $y * $moduleSize;

                imagefilledrectangle(
                    $image,
                    $pixelX,
                    $pixelY,
                    $pixelX + $moduleSize - 1,
                    $pixelY + $moduleSize - 1,
                    $color
                );
            }
        }
    }

    private const QR_MIN_CONTRAST = 4.5;

    private function relativeLuminance(array $rgb): float
    {
        $c = [];
        foreach (array_slice($rgb, 0, 3) as $v) {
            $v = max(0.0, min(1.0, ((int) $v) / 255));
            $c[] = $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
        }
        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    private function contrastRatio(array $a, array $b): float
    {
        $la = $this->relativeLuminance($a);
        $lb = $this->relativeLuminance($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * Keep the merchant's hue, move its lightness until the code is scannable.
     *
     * Walks the foreground toward black on a light background, toward white on a dark one,
     * stopping at the first step that clears the floor. Returns the original colour untouched
     * when it already passes, so a well-chosen brand colour renders exactly as chosen.
     *
     * @return array{0:array{0:int,1:int,2:int},1:int} the safe RGB and a forced-opaque alpha
     */
    private function ensureScannableForeground(array $fg, array $bg, int $opacity): array
    {
        $opacity = 0;

        if ($this->contrastRatio($fg, $bg) >= self::QR_MIN_CONTRAST) {
            return [$fg, $opacity];
        }

        $towardWhite = $this->relativeLuminance($bg) < 0.5;
        $target = $towardWhite ? 255 : 0;

        for ($step = 1; $step <= 20; $step++) {
            $t = $step / 20;
            $candidate = [
                (int) round($fg[0] + ($target - $fg[0]) * $t),
                (int) round($fg[1] + ($target - $fg[1]) * $t),
                (int) round($fg[2] + ($target - $fg[2]) * $t),
            ];
            if ($this->contrastRatio($candidate, $bg) >= self::QR_MIN_CONTRAST) {
                return [$candidate, $opacity];
            }
        }

        return [$towardWhite ? [255, 255, 255] : [0, 0, 0], $opacity];
    }

    private function hexToRgb(string $hex): array
    {
        $raw = trim($hex);

        if (preg_match('/^rgba?\\(([^)]+)\\)$/i', $raw, $m)) {
            $parts = array_map('trim', explode(',', $m[1]));
            if (count($parts) >= 3) {
                return [
                    (int) max(0, min(255, (int) $parts[0])),
                    (int) max(0, min(255, (int) $parts[1])),
                    (int) max(0, min(255, (int) $parts[2])),
                ];
            }
        }

        $hex = ltrim($raw, '#');

        if (strlen($hex) === 3 || strlen($hex) === 4) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (!preg_match('/^[0-9a-fA-F]{6}/', $hex)) {
            return [103, 79, 191];
        }

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2))
        ];
    }

    private function createModifiedResult(string $newPngData, ResultInterface $originalResult): ResultInterface
    {
        return new class ($newPngData, $originalResult) implements ResultInterface {
            private $data;
            private $originalResult;

            public function __construct(string $data, ResultInterface $originalResult)
            {
                $this->data = $data;
                $this->originalResult = $originalResult;
            }

            public function getString(): string
            {
                return $this->data;
            }

            public function getDataUri(): string
            {
                return 'data:image/png;base64,' . base64_encode($this->data);
            }

            public function saveToFile(string $path): void
            {
                file_put_contents($path, $this->data);
            }

            public function getMatrix(): \Endroid\QrCode\Matrix\MatrixInterface
            {
                return $this->originalResult->getMatrix();
            }

            public function getMimeType(): string
            {
                return 'image/png';
            }
        };
    }

    /**
     * Estimate the number of modules in the QR code
     * 
     * @param resource $image GD image resource
     * @param int $width Image width
     * @param int $height Image height
     * @return int Estimated number of modules
     */
    private function estimateQRCodeModules($image, int $width, int $height): int
    {
        $possibleSizes = [21, 25, 29, 33, 37, 41, 45, 49, 53, 57, 61, 65, 69];

        $defaultSize = 21;

        try {
            $startX = -1;
            $startY = -1;

            for ($y = 0; $y < $height / 4; $y++) {
                for ($x = 0; $x < $width / 4; $x++) {
                    $color = imagecolorat($image, $x, $y);
                    $rgb = imagecolorsforindex($image, $color);

                    if ($rgb['red'] < 100 && $rgb['green'] < 100 && $rgb['blue'] < 100) {
                        $startY = $y;
                        break 2;
                    }
                }
            }

            if ($startY < 0) {
                return $defaultSize;
            }

            for ($x = 0; $x < $width / 4; $x++) {
                $color = imagecolorat($image, $x, $startY);
                $rgb = imagecolorsforindex($image, $color);

                if ($rgb['red'] < 100 && $rgb['green'] < 100 && $rgb['blue'] < 100) {
                    $startX = $x;
                    break;
                }
            }

            if ($startX < 0) {
                return $defaultSize;
            }

            $endX = $startX;
            for ($x = $startX + 1; $x < $width / 3; $x++) {
                $color = imagecolorat($image, $x, $startY);
                $rgb = imagecolorsforindex($image, $color);

                if ($rgb['red'] < 100 && $rgb['green'] < 100 && $rgb['blue'] < 100) {
                    $endX = $x;
                } else if ($x > $endX + 5) {
                    break;
                }
            }

            $finderSize = $endX - $startX + 1;

            $estimatedModuleSize = $finderSize / 7;

            $estimatedModules = round($width / $estimatedModuleSize);

            $closestSize = $defaultSize;
            $minDiff = PHP_INT_MAX;

            foreach ($possibleSizes as $size) {
                $diff = abs($size - $estimatedModules);
                if ($diff < $minDiff) {
                    $minDiff = $diff;
                    $closestSize = $size;
                }
            }

            return $closestSize;
        } catch (Exception $e) {
            $this->errorHandler->log('QR module estimation error: ' . $e->getMessage());
            return $defaultSize;
        }
    }

    /**
     * Detect QR code finder patterns
     * 
     * @param resource $image GD image resource
     * @param int $width Image width
     * @param int $height Image height
     * @param int $moduleSize Module size in pixels
     * @return array Detected finder patterns
     */
    private function detectQRFinderPatterns($image, int $width, int $height, int $moduleSize): array
    {
        $patterns = [];
        $finderSize = $moduleSize * 7; // Finder patterns are 7x7 modules

        try {
            $samplingRate = max(1, floor($moduleSize / 2));

            $corners = [
                ['x' => 0, 'y' => 0], // Top-left
                ['x' => $width - $finderSize, 'y' => 0], // Top-right
                ['x' => 0, 'y' => $height - $finderSize] // Bottom-left
            ];

            foreach ($corners as $corner) {
                $centerX = $corner['x'] + (int) ($finderSize / 2);
                $centerY = $corner['y'] + (int) ($finderSize / 2);

                if ($centerX >= $width || $centerY >= $height) {
                    continue;
                }

                if ($this->isFinderPattern($image, $centerX, $centerY, $moduleSize)) {
                    list($refinedX, $refinedY) = $this->refinePatternPosition(
                        $image,
                        $centerX,
                        $centerY,
                        $moduleSize,
                        $finderSize
                    );

                    $patterns[] = [
                        'x' => $refinedX - (int) ($finderSize / 2),
                        'y' => $refinedY - (int) ($finderSize / 2),
                        'size' => $finderSize,
                        'center_x' => $refinedX,
                        'center_y' => $refinedY
                    ];
                }
            }

            if (count($patterns) < 3) {
                for ($y = $moduleSize; $y < $height - $finderSize; $y += $moduleSize * 2) {
                    for ($x = $moduleSize; $x < $width - $finderSize; $x += $moduleSize * 2) {
                        $tooClose = false;
                        foreach ($patterns as $pattern) {
                            $distance = sqrt(
                                pow($x + $finderSize / 2 - $pattern['center_x'], 2) +
                                pow($y + $finderSize / 2 - $pattern['center_y'], 2)
                            );

                            if ($distance < $finderSize * 2) {
                                $tooClose = true;
                                break;
                            }
                        }

                        if ($tooClose) {
                            continue;
                        }

                        $centerX = $x + (int) ($finderSize / 2);
                        $centerY = $y + (int) ($finderSize / 2);

                        if ($this->isFinderPattern($image, $centerX, $centerY, $moduleSize)) {
                            list($refinedX, $refinedY) = $this->refinePatternPosition(
                                $image,
                                $centerX,
                                $centerY,
                                $moduleSize,
                                $finderSize
                            );

                            $patterns[] = [
                                'x' => $refinedX - (int) ($finderSize / 2),
                                'y' => $refinedY - (int) ($finderSize / 2),
                                'size' => $finderSize,
                                'center_x' => $refinedX,
                                'center_y' => $refinedY
                            ];

                            if (count($patterns) >= 3) {
                                break 2;
                            }
                        }
                    }
                }
            }

            if (empty($patterns)) {
                $patternSize = max(7 * $moduleSize, (int) ($width / 7));

                $patterns = [
                    [
                        'x' => $moduleSize,
                        'y' => $moduleSize,
                        'size' => $patternSize,
                        'center_x' => $moduleSize + $patternSize / 2,
                        'center_y' => $moduleSize + $patternSize / 2
                    ],
                    [
                        'x' => $width - $patternSize - $moduleSize,
                        'y' => $moduleSize,
                        'size' => $patternSize,
                        'center_x' => $width - $moduleSize - $patternSize / 2,
                        'center_y' => $moduleSize + $patternSize / 2
                    ],
                    [
                        'x' => $moduleSize,
                        'y' => $height - $patternSize - $moduleSize,
                        'size' => $patternSize,
                        'center_x' => $moduleSize + $patternSize / 2,
                        'center_y' => $height - $moduleSize - $patternSize / 2
                    ]
                ];
            }

            return $patterns;
        } catch (Exception $e) {
            $this->errorHandler->log('Finder pattern detection error: ' . $e->getMessage());
            return [];
        }
    }

    private function isFinderPattern($image, int $centerX, int $centerY, int $moduleSize): bool
    {
        try {
            $checkPoints = [
                ['offset_x' => -3, 'offset_y' => -3, 'expected' => 'dark'],
                ['offset_x' => 0, 'offset_y' => -3, 'expected' => 'dark'],
                ['offset_x' => 3, 'offset_y' => -3, 'expected' => 'dark'],
                ['offset_x' => -3, 'offset_y' => 0, 'expected' => 'dark'],
                ['offset_x' => 3, 'offset_y' => 0, 'expected' => 'dark'],
                ['offset_x' => -3, 'offset_y' => 3, 'expected' => 'dark'],
                ['offset_x' => 0, 'offset_y' => 3, 'expected' => 'dark'],
                ['offset_x' => 3, 'offset_y' => 3, 'expected' => 'dark'],

                ['offset_x' => -2, 'offset_y' => -2, 'expected' => 'light'],
                ['offset_x' => 0, 'offset_y' => -2, 'expected' => 'light'],
                ['offset_x' => 2, 'offset_y' => -2, 'expected' => 'light'],
                ['offset_x' => -2, 'offset_y' => 0, 'expected' => 'light'],
                ['offset_x' => 2, 'offset_y' => 0, 'expected' => 'light'],
                ['offset_x' => -2, 'offset_y' => 2, 'expected' => 'light'],
                ['offset_x' => 0, 'offset_y' => 2, 'expected' => 'light'],
                ['offset_x' => 2, 'offset_y' => 2, 'expected' => 'light'],

                ['offset_x' => -1, 'offset_y' => -1, 'expected' => 'dark'],
                ['offset_x' => 0, 'offset_y' => -1, 'expected' => 'dark'],
                ['offset_x' => 1, 'offset_y' => -1, 'expected' => 'dark'],
                ['offset_x' => -1, 'offset_y' => 0, 'expected' => 'dark'],
                ['offset_x' => 0, 'offset_y' => 0, 'expected' => 'dark'],
                ['offset_x' => 1, 'offset_y' => 0, 'expected' => 'dark'],
                ['offset_x' => -1, 'offset_y' => 1, 'expected' => 'dark'],
                ['offset_x' => 0, 'offset_y' => 1, 'expected' => 'dark'],
                ['offset_x' => 1, 'offset_y' => 1, 'expected' => 'dark']
            ];

            $matchCount = 0;
            $totalChecks = count($checkPoints);

            foreach ($checkPoints as $point) {
                $x = $centerX + $point['offset_x'] * $moduleSize;
                $y = $centerY + $point['offset_y'] * $moduleSize;

                if ($x < 0 || $x >= imagesx($image) || $y < 0 || $y >= imagesy($image)) {
                    continue;
                }

                $color = imagecolorat($image, $x, $y);
                $rgb = imagecolorsforindex($image, $color);

                $isDark = ($rgb['red'] < 100 && $rgb['green'] < 100 && $rgb['blue'] < 100);

                if (
                    ($point['expected'] === 'dark' && $isDark) ||
                    ($point['expected'] === 'light' && !$isDark)
                ) {
                    $matchCount++;
                }
            }

            return ($matchCount / $totalChecks) >= 0.75;
        } catch (Exception $e) {
            $this->errorHandler->log('Finder pattern verification error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Refine finder pattern position for better accuracy
     * 
     * @param resource $image GD image resource
     * @param int $centerX Initial center X coordinate
     * @param int $centerY Initial center Y coordinate
     * @param int $moduleSize Module size in pixels
     * @param int $patternSize Pattern size in pixels
     * @return array Refined [x, y] position
     */
    private function refinePatternPosition($image, int $centerX, int $centerY, int $moduleSize, int $patternSize): array
    {
        try {
            $searchRadius = $moduleSize * 2;
            $bestX = $centerX;
            $bestY = $centerY;
            $bestScore = 0;

            for ($yOffset = -$searchRadius; $yOffset <= $searchRadius; $yOffset += $moduleSize) {
                for ($xOffset = -$searchRadius; $xOffset <= $searchRadius; $xOffset += $moduleSize) {
                    $testX = $centerX + $xOffset;
                    $testY = $centerY + $yOffset;

                    if (
                        $testX < 0 || $testX >= imagesx($image) ||
                        $testY < 0 || $testY >= imagesy($image)
                    ) {
                        continue;
                    }

                    $score = $this->scoreFinderPatternPosition($image, $testX, $testY, $moduleSize);

                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestX = $testX;
                        $bestY = $testY;
                    }
                }
            }

            return [$bestX, $bestY];
        } catch (Exception $e) {
            $this->errorHandler->log('Pattern position refinement error: ' . $e->getMessage());
            return [$centerX, $centerY];
        }
    }

    /**
     * Score a potential finder pattern position
     * 
     * @param resource $image GD image resource
     * @param int $centerX Center X coordinate
     * @param int $centerY Center Y coordinate
     * @param int $moduleSize Module size in pixels
     * @return int Score (higher is better)
     */
    private function scoreFinderPatternPosition($image, int $centerX, int $centerY, int $moduleSize): int
    {
        $score = 0;
        $radius = $moduleSize * 3; // Check up to 3 modules out

        try {
            $lastPixelDark = null;
            $transitions = 0;

            for ($x = $centerX - $radius; $x <= $centerX + $radius; $x++) {
                if ($x < 0 || $x >= imagesx($image))
                    continue;

                $color = imagecolorat($image, $x, $centerY);
                $rgb = imagecolorsforindex($image, $color);
                $isDark = ($rgb['red'] < 100 && $rgb['green'] < 100 && $rgb['blue'] < 100);

                if ($lastPixelDark !== null && $lastPixelDark !== $isDark) {
                    $transitions++;
                }

                $lastPixelDark = $isDark;
            }

            $lastPixelDark = null;
            $verticalTransitions = 0;

            for ($y = $centerY - $radius; $y <= $centerY + $radius; $y++) {
                if ($y < 0 || $y >= imagesy($image))
                    continue;

                $color = imagecolorat($image, $centerX, $y);
                $rgb = imagecolorsforindex($image, $color);
                $isDark = ($rgb['red'] < 100 && $rgb['green'] < 100 && $rgb['blue'] < 100);

                if ($lastPixelDark !== null && $lastPixelDark !== $isDark) {
                    $verticalTransitions++;
                }

                $lastPixelDark = $isDark;
            }

            $score = max(0, 7 - abs(6 - $transitions)) + max(0, 7 - abs(6 - $verticalTransitions));

            return $score;
        } catch (Exception $e) {
            $this->errorHandler->log('Pattern scoring error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Detect QR code timing patterns
     * These patterns are critical for properly reading QR codes with special characters
     *
     * @param resource $image The image resource
     * @param int $width The width of the image
     * @param int $height The height of the image
     * @param int $moduleSize The size of each module
     * @param array $finderPatterns The detected finder patterns
     * @return array The detected timing patterns
     */
    private function detectQRTimingPatterns($image, int $width, int $height, int $moduleSize, array $finderPatterns): array
    {
        $timingPatterns = [];

        try {
            if (count($finderPatterns) < 2) {
                return $timingPatterns;
            }

            usort($finderPatterns, function ($a, $b) {
                if (abs($a['y'] - $b['y']) > 5) {
                    return $a['y'] - $b['y'];
                }
                return $a['x'] - $b['x'];
            });

            $topLeft = $finderPatterns[0];

            $topRight = null;
            foreach ($finderPatterns as $pattern) {
                if (
                    abs($pattern['y'] - $topLeft['y']) < $moduleSize * 2 &&
                    $pattern['x'] > $topLeft['x'] + $topLeft['size']
                ) {
                    $topRight = $pattern;
                    break;
                }
            }

            $bottomLeft = null;
            foreach ($finderPatterns as $pattern) {
                if (
                    abs($pattern['x'] - $topLeft['x']) < $moduleSize * 2 &&
                    $pattern['y'] > $topLeft['y'] + $topLeft['size']
                ) {
                    $bottomLeft = $pattern;
                    break;
                }
            }

            if (!$topRight && count($finderPatterns) >= 2) {
                $secondPattern = $finderPatterns[1];

                if (abs($secondPattern['x'] - $topLeft['x']) > abs($secondPattern['y'] - $topLeft['y'])) {
                    $topRight = $secondPattern;
                } else {
                    $bottomLeft = $secondPattern;
                }
            }

            if (!$bottomLeft && count($finderPatterns) >= 3) {
                $bottomLeft = $finderPatterns[2];
            }

            if ($topRight) {
                $hTimingX = $topLeft['x'] + $topLeft['size'];
                $hTimingY = $topLeft['y'] + (int) ($topLeft['size'] / 2);
                $hTimingWidth = $topRight['x'] - $hTimingX;
                $hTimingHeight = $moduleSize;

                $timingPatterns[] = [
                    'x' => $hTimingX,
                    'y' => $hTimingY - (int) ($hTimingHeight / 2),
                    'width' => $hTimingWidth,
                    'height' => $hTimingHeight,
                    'type' => 'horizontal'
                ];
            }

            if ($bottomLeft) {
                $vTimingX = $topLeft['x'] + (int) ($topLeft['size'] / 2);
                $vTimingY = $topLeft['y'] + $topLeft['size'];
                $vTimingWidth = $moduleSize;
                $vTimingHeight = $bottomLeft['y'] - $vTimingY;

                $timingPatterns[] = [
                    'x' => $vTimingX - (int) ($vTimingWidth / 2),
                    'y' => $vTimingY,
                    'width' => $vTimingWidth,
                    'height' => $vTimingHeight,
                    'type' => 'vertical'
                ];
            } else if ($topLeft) {
                $vTimingX = $topLeft['x'] + (int) ($topLeft['size'] / 2);
                $vTimingY = $topLeft['y'] + $topLeft['size'];
                $vTimingWidth = $moduleSize;
                $vTimingHeight = max(
                    $height - $vTimingY - $moduleSize * 2,
                    $topLeft['size'] * 2
                );

                $timingPatterns[] = [
                    'x' => $vTimingX - (int) ($vTimingWidth / 2),
                    'y' => $vTimingY,
                    'width' => $vTimingWidth,
                    'height' => $vTimingHeight,
                    'type' => 'vertical'
                ];
            }

            return $timingPatterns;
        } catch (Exception $e) {
            $this->errorHandler->log('Timing pattern detection error: ' . $e->getMessage());
            return [];
        }
    }

    /*   private function createModifiedResult(string $newPngData, ResultInterface $originalResult): ResultInterface
      {
          return new class ($newPngData, $originalResult) implements ResultInterface {
              private $data;
              private $originalResult;

              public function __construct(string $data, ResultInterface $originalResult)
              {
                  $this->data = $data;
                  $this->originalResult = $originalResult;
              }

              public function getString(): string
              {
                  return $this->data;
              }

              public function getDataUri(): string
              {
                  return 'data:image/png;base64,' . base64_encode($this->data);
              }

              public function saveToFile(string $path): void
              {
                  file_put_contents($path, $this->data);
              }

              public function getMatrix(): \Endroid\QrCode\Matrix\MatrixInterface
              {
                  return $this->originalResult->getMatrix();
              }

              public function getMimeType(): string
              {
                  return 'image/png';
              }
          };
      } */
    private function applyCircularStyle(ResultInterface $result, array $settings): ResultInterface
    {
        try {
            $pngData = $result->getString();
            $image = imagecreatefromstring($pngData);
            if (!$image) {
                $this->errorHandler->log('Failed to create image from QR code for circular style');
                return $result;
            }

            $width = imagesx($image);
            $height = imagesy($image);

            $temp = imagecreatetruecolor($width, $height);
            imagealphablending($temp, false);
            imagesavealpha($temp, true);

            $transparent = imagecolorallocatealpha($temp, 0, 0, 0, 127);
            imagefill($temp, 0, 0, $transparent);

            $bgColorRgb = $this->hexToRgb($settings['qrBackground'] ?? '#F4F4FF');
            $bgColor = imagecolorallocate($temp, $bgColorRgb[0], $bgColorRgb[1], $bgColorRgb[2]);
            imagefilledrectangle($temp, 0, 0, $width, $height, $bgColor);

            $moduleSize = $this->calculateModuleSize($image, $width, $height);

            if ($moduleSize <= 0) {
                $moduleSize = max(1, (int) ($width / 33));
            }

            $centerX = (int) ($width / 2);
            $centerY = (int) ($height / 2);

            $radius = (int) (min($width, $height) * 0.45);

            if ($radius <= 0) {
                $radius = min($width, $height) / 2 - 10;
            }

            imagealphablending($temp, true);

            $patterns = $this->detectQRPatterns($image, $width, $height, $moduleSize);

            $numCircles = max(10, (int) ($radius / $moduleSize));

            for ($circleIndex = 1; $circleIndex <= $numCircles; $circleIndex++) {
                $circleRadius = ($radius * $circleIndex) / $numCircles;

                $circumference = 2 * M_PI * $circleRadius;

                $numDots = max(8, (int) ($circumference / $moduleSize));

                $angleStep = 2 * M_PI / $numDots;

                for ($dotIndex = 0; $dotIndex < $numDots; $dotIndex++) {
                    $angle = $dotIndex * $angleStep;

                    $dotX = (int) ($centerX + $circleRadius * cos($angle));
                    $dotY = (int) ($centerY + $circleRadius * sin($angle));

                    if ($dotX < 0 || $dotX >= $width || $dotY < 0 || $dotY >= $height) {
                        continue;
                    }

                    $qrX = (int) (($dotX - ($width - $height) / 2) / $height * $height);
                    $qrY = (int) ($dotY / $height * $height);

                    $qrX = max(0, min($width - 1, $qrX));
                    $qrY = max(0, min($height - 1, $qrY));

                    $color = imagecolorat($image, $qrX, $qrY);
                    $rgb = imagecolorsforindex($image, $color);

                    if ($rgb['alpha'] <= 20) {
                        $dotColor = imagecolorallocate($temp, $rgb['red'], $rgb['green'], $rgb['blue']);

                        $dotSize = max(3, (int) ($moduleSize * 0.8));
                        imagefilledellipse($temp, $dotX, $dotY, $dotSize, $dotSize, $dotColor);
                    }
                }
            }

            $fgColorRgb = $this->hexToRgb($settings['qrColor'] ?? '#674FBF');
            $fgColor = imagecolorallocate($temp, $fgColorRgb[0], $fgColorRgb[1], $fgColorRgb[2]);

            foreach ($patterns as $pattern) {
                imagefilledrectangle(
                    $temp,
                    $pattern['x'],
                    $pattern['y'],
                    $pattern['x'] + $pattern['size'] - 1,
                    $pattern['y'] + $pattern['size'] - 1,
                    $fgColor
                );
            }

            ob_start();
            imagepng($temp);
            $newPngData = ob_get_clean();

            imagedestroy($image);
            imagedestroy($temp);

            return $this->createModifiedResult($newPngData, $result);
        } catch (Exception $e) {
            $this->errorHandler->log('Error applying circular style: ' . $e->getMessage());
            return $result;
        }
    }

    private function applyDiamondStyle(ResultInterface $result, array $settings): ResultInterface
    {
        try {
            $pngData = $result->getString();
            $image = imagecreatefromstring($pngData);
            if (!$image) {
                $this->errorHandler->log('Failed to create image from QR code for diamond style');
                return $result;
            }

            $width = imagesx($image);
            $height = imagesy($image);

            $temp = imagecreatetruecolor($width, $height);
            imagealphablending($temp, false);
            imagesavealpha($temp, true);

            $transparent = imagecolorallocatealpha($temp, 0, 0, 0, 127);
            imagefill($temp, 0, 0, $transparent);

            $bgColorRgb = $this->hexToRgb($settings['qrBackground'] ?? '#F4F4FF');
            $bgColor = imagecolorallocate($temp, $bgColorRgb[0], $bgColorRgb[1], $bgColorRgb[2]);
            imagefilledrectangle($temp, 0, 0, $width, $height, $bgColor);

            $moduleSize = $this->calculateModuleSize($image, $width, $height);

            $patterns = $this->detectQRPatterns($image, $width, $height, $moduleSize);

            imagealphablending($temp, true);

            for ($y = 0; $y < $height; $y += $moduleSize) {
                for ($x = 0; $x < $width; $x += $moduleSize) {
                    if ($x >= $width || $y >= $height) {
                        continue;
                    }

                    $centerX = min($x + (int) ($moduleSize / 2), $width - 1);
                    $centerY = min($y + (int) ($moduleSize / 2), $height - 1);

                    $color = imagecolorat($image, $centerX, $centerY);
                    $rgb = imagecolorsforindex($image, $color);

                    if ($rgb['alpha'] > 20) {
                        continue;
                    }

                    $isFinderPattern = false;
                    foreach ($patterns as $pattern) {
                        if (
                            $x >= $pattern['x'] && $x < $pattern['x'] + $pattern['size'] &&
                            $y >= $pattern['y'] && $y < $pattern['y'] + $pattern['size']
                        ) {
                            $isFinderPattern = true;
                            break;
                        }
                    }

                    $moduleColor = imagecolorallocate($temp, $rgb['red'], $rgb['green'], $rgb['blue']);

                    if ($isFinderPattern) {
                        imagefilledrectangle(
                            $temp,
                            $x,
                            $y,
                            min($x + $moduleSize - 1, $width - 1),
                            min($y + $moduleSize - 1, $height - 1),
                            $moduleColor
                        );
                    } else {
                        $margin = max(1, (int) ($moduleSize * 0.15));

                        $points = [
                            $centerX,
                            $y + $margin,
                            $x + $moduleSize - $margin,
                            $centerY,
                            $centerX,
                            $y + $moduleSize - $margin,
                            $x + $margin,
                            $centerY
                        ];

                        for ($i = 0; $i < 8; $i += 2) {
                            $points[$i] = max(0, min($width - 1, $points[$i]));
                            $points[$i + 1] = max(0, min($height - 1, $points[$i + 1]));
                        }

                        imagefilledpolygon($temp, $points, 4, $moduleColor);
                    }
                }
            }

            ob_start();
            imagepng($temp);
            $newPngData = ob_get_clean();

            imagedestroy($image);
            imagedestroy($temp);

            return $this->createModifiedResult($newPngData, $result);
        } catch (Exception $e) {
            $this->errorHandler->log('Error applying diamond style: ' . $e->getMessage());
            return $result;
        }
    }

    private function applySharpStyle(ResultInterface $result, array $settings): ResultInterface
    {
        try {
            $pngData = $result->getString();
            $image = imagecreatefromstring($pngData);
            if (!$image) {
                $this->errorHandler->log('Failed to create image from QR code for sharp style');
                return $result;
            }

            $width = imagesx($image);
            $height = imagesy($image);

            $temp = imagecreatetruecolor($width, $height);
            imagealphablending($temp, false);
            imagesavealpha($temp, true);

            $transparent = imagecolorallocatealpha($temp, 0, 0, 0, 127);
            imagefill($temp, 0, 0, $transparent);

            $bgColorRgb = $this->hexToRgb($settings['qrBackground'] ?? '#F4F4FF');
            $bgColor = imagecolorallocate($temp, $bgColorRgb[0], $bgColorRgb[1], $bgColorRgb[2]);
            imagefilledrectangle($temp, 0, 0, $width, $height, $bgColor);

            $fgColorRgb = $this->hexToRgb($settings['qrColor'] ?? '#674FBF');
            $fgColor = imagecolorallocate($temp, $fgColorRgb[0], $fgColorRgb[1], $fgColorRgb[2]);

            $moduleSize = $this->calculateModuleSize($image, $width, $height);

            $grid = [];
            $visited = [];

            for ($y = 0; $y < $height; $y += $moduleSize) {
                $row = [];
                $visitedRow = [];

                for ($x = 0; $x < $width; $x += $moduleSize) {
                    if ($x >= $width || $y >= $height) {
                        $row[] = 0;
                        $visitedRow[] = true;
                        continue;
                    }

                    $centerX = min($x + (int) ($moduleSize / 2), $width - 1);
                    $centerY = min($y + (int) ($moduleSize / 2), $height - 1);

                    $color = imagecolorat($image, $centerX, $centerY);
                    $rgb = imagecolorsforindex($image, $color);

                    $row[] = ($rgb['alpha'] <= 20) ? 1 : 0;
                    $visitedRow[] = false;
                }

                $grid[] = $row;
                $visited[] = $visitedRow;
            }

            imagealphablending($temp, true);

            $gridHeight = count($grid);
            $gridWidth = $gridHeight > 0 ? count($grid[0]) : 0;

            $patterns = $this->detectQRPatterns($image, $width, $height, $moduleSize);

            foreach ($patterns as $pattern) {
                imagefilledrectangle(
                    $temp,
                    $pattern['x'],
                    $pattern['y'],
                    $pattern['x'] + $pattern['size'] - 1,
                    $pattern['y'] + $pattern['size'] - 1,
                    $fgColor
                );
            }

            for ($gridY = 0; $gridY < $gridHeight; $gridY++) {
                $runStart = -1;

                for ($gridX = 0; $gridX < $gridWidth; $gridX++) {
                    $inPattern = false;
                    $realX = $gridX * $moduleSize;
                    $realY = $gridY * $moduleSize;

                    foreach ($patterns as $pattern) {
                        if (
                            $realX >= $pattern['x'] && $realX < $pattern['x'] + $pattern['size'] &&
                            $realY >= $pattern['y'] && $realY < $pattern['y'] + $pattern['size']
                        ) {
                            $inPattern = true;
                            break;
                        }
                    }

                    if ($inPattern) {
                        if ($runStart >= 0) {
                            imagefilledrectangle(
                                $temp,
                                $runStart * $moduleSize,
                                $gridY * $moduleSize,
                                ($gridX - 1) * $moduleSize + $moduleSize - 1,
                                $gridY * $moduleSize + $moduleSize - 1,
                                $fgColor
                            );
                            $runStart = -1;
                        }
                        continue;
                    }

                    if ($grid[$gridY][$gridX] == 1) {
                        if ($runStart < 0) {
                            $runStart = $gridX;
                        }
                    } else if ($runStart >= 0) {
                        imagefilledrectangle(
                            $temp,
                            $runStart * $moduleSize,
                            $gridY * $moduleSize,
                            ($gridX - 1) * $moduleSize + $moduleSize - 1,
                            $gridY * $moduleSize + $moduleSize - 1,
                            $fgColor
                        );
                        $runStart = -1;
                    }
                }

                if ($runStart >= 0) {
                    imagefilledrectangle(
                        $temp,
                        $runStart * $moduleSize,
                        $gridY * $moduleSize,
                        ($gridWidth - 1) * $moduleSize + $moduleSize - 1,
                        $gridY * $moduleSize + $moduleSize - 1,
                        $fgColor
                    );
                }
            }

            for ($gridX = 0; $gridX < $gridWidth; $gridX++) {
                $runStart = -1;

                for ($gridY = 0; $gridY < $gridHeight; $gridY++) {
                    $inPattern = false;
                    $realX = $gridX * $moduleSize;
                    $realY = $gridY * $moduleSize;

                    foreach ($patterns as $pattern) {
                        if (
                            $realX >= $pattern['x'] && $realX < $pattern['x'] + $pattern['size'] &&
                            $realY >= $pattern['y'] && $realY < $pattern['y'] + $pattern['size']
                        ) {
                            $inPattern = true;
                            break;
                        }
                    }

                    if ($inPattern) {
                        if ($runStart >= 0) {
                            imagefilledrectangle(
                                $temp,
                                $gridX * $moduleSize,
                                $runStart * $moduleSize,
                                $gridX * $moduleSize + $moduleSize - 1,
                                ($gridY - 1) * $moduleSize + $moduleSize - 1,
                                $fgColor
                            );
                            $runStart = -1;
                        }
                        continue;
                    }

                    if ($grid[$gridY][$gridX] == 1) {
                        if ($runStart < 0) {
                            $runStart = $gridY;
                        }
                    } else if ($runStart >= 0) {
                        imagefilledrectangle(
                            $temp,
                            $gridX * $moduleSize,
                            $runStart * $moduleSize,
                            $gridX * $moduleSize + $moduleSize - 1,
                            ($gridY - 1) * $moduleSize + $moduleSize - 1,
                            $fgColor
                        );
                        $runStart = -1;
                    }
                }

                if ($runStart >= 0) {
                    imagefilledrectangle(
                        $temp,
                        $gridX * $moduleSize,
                        $runStart * $moduleSize,
                        $gridX * $moduleSize + $moduleSize - 1,
                        ($gridHeight - 1) * $moduleSize + $moduleSize - 1,
                        $fgColor
                    );
                }
            }

            ob_start();
            imagepng($temp);
            $newPngData = ob_get_clean();

            imagedestroy($image);
            imagedestroy($temp);

            return $this->createModifiedResult($newPngData, $result);
        } catch (Exception $e) {
            $this->errorHandler->log('Error applying sharp style: ' . $e->getMessage());
            return $result;
        }
    }
    private function createBinaryMapFromImage($image, int $moduleSize): array
    {
        $width = imagesx($image);
        $height = imagesy($image);

        $moduleCount = (int) ceil($width / $moduleSize);
        $map = array();

        for ($y = 0; $y < $moduleCount; $y++) {
            $row = array();
            for ($x = 0; $x < $moduleCount; $x++) {
                $pixelX = (int) ($x * $moduleSize + $moduleSize / 2);
                $pixelY = (int) ($y * $moduleSize + $moduleSize / 2);

                $pixelX = min($pixelX, $width - 1);
                $pixelY = min($pixelY, $height - 1);

                $color = imagecolorat($image, $pixelX, $pixelY);
                $rgb = imagecolorsforindex($image, $color);

                $isDark = ($rgb['alpha'] <= 20);

                $row[] = $isDark;
            }
            $map[] = $row;
        }

        return $map;
    }

    /**
     * Estimate module size from QR code image dimensions
     * 
     * @param int $width Image width
     * @param int $height Image height
     * @return int Estimated module size in pixels
     */
    private function estimateModuleSize(int $width, int $height): int
    {
        $minDimension = min($width, $height);

        $versions = array(
            1 => 21,   // Version 1: 21x21 modules
            2 => 25,   // Version 2: 25x25 modules
            3 => 29,   // Version 3: 29x29 modules
            4 => 33,   // Version 4: 33x33 modules
            5 => 37,   // Version 5: 37x37 modules
            6 => 41,   // Version 6: 41x41 modules
            7 => 45,   // Version 7: 45x45 modules
            8 => 49,   // Version 8: 49x49 modules
            9 => 53,   // Version 9: 53x53 modules
            10 => 57,  // Version 10: 57x57 modules
            11 => 61,  // Version 11: 61x61 modules
            12 => 65,  // Version 12: 65x65 modules
            13 => 69,  // Version 13: 69x69 modules
            14 => 73,  // Version 14: 73x73 modules
            15 => 77,  // Version 15: 77x77 modules
            16 => 81,  // Version 16: 81x81 modules
            17 => 85,  // Version 17: 85x85 modules
            18 => 89,  // Version 18: 89x89 modules
            19 => 93,  // Version 19: 93x93 modules
            20 => 97,  // Version 20: 97x97 modules
            21 => 101, // Version 21: 101x101 modules
            22 => 105, // Version 22: 105x105 modules
            23 => 109, // Version 23: 109x109 modules
            24 => 113, // Version 24: 113x113 modules
            25 => 117, // Version 25: 117x117 modules
            26 => 121, // Version 26: 121x121 modules
            27 => 125, // Version 27: 125x125 modules
            28 => 129, // Version 28: 129x129 modules
            29 => 133, // Version 29: 133x133 modules
            30 => 137, // Version 30: 137x137 modules
            31 => 141, // Version 31: 141x141 modules
            32 => 145, // Version 32: 145x145 modules
            33 => 149, // Version 33: 149x149 modules
            34 => 153, // Version 34: 153x153 modules
            35 => 157, // Version 35: 157x157 modules
            36 => 161, // Version 36: 161x161 modules
            37 => 165, // Version 37: 165x165 modules
            38 => 169, // Version 38: 169x169 modules
            39 => 173, // Version 39: 173x173 modules
            40 => 177  // Version 40: 177x177 modules
        );

        $bestFit = 21; // Default to version 1
        $minDiff = $minDimension;

        foreach ($versions as $moduleCount) {
            $diff = abs($minDimension / $moduleCount - round($minDimension / $moduleCount));
            if ($diff < $minDiff) {
                $minDiff = $diff;
                $bestFit = $moduleCount;
            }
        }

        return (int) round($minDimension / $bestFit);
    }
    /**
     * Improved detection of QR code patterns (finder and alignment patterns)
     * 
     * @param resource $image QR code image
     * @param int $width Image width
     * @param int $height Image height
     * @param int $moduleSize Module size
     * @return array Array of pattern info (position and size)
     */
    private function detectQRPatterns($image, int $width, int $height, int $moduleSize): array
    {
        $patterns = [];

        $moduleSize = max(1, $moduleSize);

        $patternSize = $moduleSize * 7;

        if ($patternSize <= 0 || $patternSize >= $width || $patternSize >= $height) {
            $patternSize = min($width, $height) / 5;
        }

        $safeWidth = $width - 1;
        $safeHeight = $height - 1;

        $patterns[] = [
            'x' => 0,
            'y' => 0,
            'size' => min($patternSize, $safeWidth, $safeHeight)
        ];

        $topRightX = max(0, $safeWidth - $patternSize);
        $patterns[] = [
            'x' => $topRightX,
            'y' => 0,
            'size' => min($patternSize, $safeWidth - $topRightX, $safeHeight)
        ];

        $bottomLeftY = max(0, $safeHeight - $patternSize);
        $patterns[] = [
            'x' => 0,
            'y' => $bottomLeftY,
            'size' => min($patternSize, $safeWidth, $safeHeight - $bottomLeftY)
        ];

        foreach ($patterns as $i => $pattern) {
            if ($pattern['size'] < 5) {
                unset($patterns[$i]);
                continue;
            }

            $patterns[$i]['x'] = max(0, min($safeWidth - 1, $pattern['x']));
            $patterns[$i]['y'] = max(0, min($safeHeight - 1, $pattern['y']));
            $patterns[$i]['size'] = min($pattern['size'], $safeWidth - $patterns[$i]['x'], $safeHeight - $patterns[$i]['y']);
        }

        return array_values($patterns);
    }

    /**
     * Improved module size calculation based on QR code image
     * 
     * @param resource $image QR code image
     * @param int $width Image width
     * @param int $height Image height
     * @return int Estimated module size
     */
    private function calculateModuleSize($image, int $width, int $height): int
    {
        if ($width <= 0 || $height <= 0) {
            return 5;
        }

        $minDimension = min($width, $height);

        $centerX = (int) ($width / 2);
        $centerY = (int) ($height / 2);

        $horizontalTransitions = [];
        $lastColor = null;
        $transitionStart = 0;

        for ($x = $centerX - (int) ($minDimension / 3); $x < $centerX + (int) ($minDimension / 3); $x++) {
            if ($x < 0 || $x >= $width) {
                continue;
            }

            $color = imagecolorat($image, $x, $centerY);
            $rgb = imagecolorsforindex($image, $color);
            $isForeground = ($rgb['alpha'] <= 20);

            if ($lastColor === null) {
                $lastColor = $isForeground;
                $transitionStart = $x;
            } else if ($lastColor !== $isForeground) {
                $transitionLength = $x - $transitionStart;
                if ($transitionLength > 1) {
                    $horizontalTransitions[] = $transitionLength;
                }
                $lastColor = $isForeground;
                $transitionStart = $x;
            }
        }

        $verticalTransitions = [];
        $lastColor = null;
        $transitionStart = 0;

        for ($y = $centerY - (int) ($minDimension / 3); $y < $centerY + (int) ($minDimension / 3); $y++) {
            if ($y < 0 || $y >= $height) {
                continue;
            }

            $color = imagecolorat($image, $centerX, $y);
            $rgb = imagecolorsforindex($image, $color);
            $isForeground = ($rgb['alpha'] <= 20);

            if ($lastColor === null) {
                $lastColor = $isForeground;
                $transitionStart = $y;
            } else if ($lastColor !== $isForeground) {
                $transitionLength = $y - $transitionStart;
                if ($transitionLength > 1) {
                    $verticalTransitions[] = $transitionLength;
                }
                $lastColor = $isForeground;
                $transitionStart = $y;
            }
        }

        $allTransitions = array_merge($horizontalTransitions, $verticalTransitions);

        if (count($allTransitions) > 0) {
            sort($allTransitions);

            $counts = array_count_values($allTransitions);
            arsort($counts);
            $mostCommon = key($counts);

            return max(1, (int) $mostCommon);
        }

        $estimatedModuleSize = (int) ($minDimension / 33); // QR codes have 21-177 modules (Version 1-40)

        $patternSize = 0;
        $lastWhite = false;

        for ($x = 0; $x < $minDimension / 4; $x++) {
            $color = imagecolorat($image, $x, 0);
            $rgb = imagecolorsforindex($image, $color);
            $isBlack = ($rgb['alpha'] <= 20);

            if ($isBlack && $lastWhite) {
                $patternSize = $x;
                break;
            }

            $lastWhite = !$isBlack;
        }

        if ($patternSize > 0) {
            return max(1, (int) ($patternSize / 7)); // Finder pattern is 7 modules wide
        }

        return max(1, $estimatedModuleSize);
    }

    /**
     * Improved detection of QR code patterns (finder and alignment patterns)
     * 
     * @param resource $image QR code image
     * @param int $width Image width
     * @param int $height Image height
     * @param int $moduleSize Module size
     * @return array Array of pattern info (position and size)
     */
    /*  private function detectQRPatterns($image, int $width, int $height, int $moduleSize): array
     {
         // Initialize patterns array
         $patterns = [];

         // Ensure module size is valid
         $moduleSize = max(1, $moduleSize);

         // QR codes always have finder patterns in the three corners
         // Each finder pattern is 7x7 modules
         $patternSize = $moduleSize * 7;

         // Safety check to avoid invalid patterns
         if ($patternSize <= 0 || $patternSize >= $width || $patternSize >= $height) {
             // Fallback calculation if moduleSize calculation failed
             $patternSize = min($width, $height) / 5; // Approximately 1/5 of the size
         }

         // Safety margin - ensure we don't exceed image bounds
         $safeWidth = $width - 1;
         $safeHeight = $height - 1;

         // Top-left corner
         $patterns[] = [
             'x' => 0,
             'y' => 0,
             'size' => min($patternSize, $safeWidth, $safeHeight)
         ];

         // Top-right corner (ensure it doesn't go beyond right edge)
         $topRightX = max(0, $safeWidth - $patternSize);
         $patterns[] = [
             'x' => $topRightX,
             'y' => 0,
             'size' => min($patternSize, $safeWidth - $topRightX, $safeHeight)
         ];

         // Bottom-left corner (ensure it doesn't go beyond bottom edge)
         $bottomLeftY = max(0, $safeHeight - $patternSize);
         $patterns[] = [
             'x' => 0,
             'y' => $bottomLeftY,
             'size' => min($patternSize, $safeWidth, $safeHeight - $bottomLeftY)
         ];

         // Validate patterns - make sure they don't overlap
         foreach ($patterns as $i => $pattern) {
             // Skip if the pattern is too small to be useful
             if ($pattern['size'] < 5) {
                 unset($patterns[$i]);
                 continue;
             }

             // Validate and sanitize coordinates
             $patterns[$i]['x'] = max(0, min($safeWidth - 1, $pattern['x']));
             $patterns[$i]['y'] = max(0, min($safeHeight - 1, $pattern['y']));
             $patterns[$i]['size'] = min($pattern['size'], $safeWidth - $patterns[$i]['x'], $safeHeight - $patterns[$i]['y']);
         }

         // Reset array keys after potential removals
         return array_values($patterns);
     } */

    /**
     * Improved module size calculation based on QR code image
     * 
     * @param resource $image QR code image
     * @param int $width Image width
     * @param int $height Image height
     * @return int Estimated module size
     */
    /*  private function calculateModuleSize($image, int $width, int $height): int
     {
         // For safety, use a reasonable default if dimensions are invalid
         if ($width <= 0 || $height <= 0) {
             return 5; // Reasonable default
         }

         // Analyze the image to detect the module size
         $minDimension = min($width, $height);

         // QR codes have a standard structure - try to detect module size by sampling
         // the image horizontally and vertically to find pattern transitions

         // Sample from the center of the image
         $centerX = (int) ($width / 2);
         $centerY = (int) ($height / 2);

         // Look for horizontal transitions near the center
         $horizontalTransitions = [];
         $lastColor = null;
         $transitionStart = 0;

         for ($x = $centerX - (int) ($minDimension / 3); $x < $centerX + (int) ($minDimension / 3); $x++) {
             if ($x < 0 || $x >= $width) {
                 continue;
             }

             $color = imagecolorat($image, $x, $centerY);
             $rgb = imagecolorsforindex($image, $color);
             $isForeground = ($rgb['alpha'] <= 20); // Consider it foreground if not transparent

             if ($lastColor === null) {
                 $lastColor = $isForeground;
                 $transitionStart = $x;
             } else if ($lastColor !== $isForeground) {
                 // We found a transition
                 $transitionLength = $x - $transitionStart;
                 if ($transitionLength > 1) { // Ignore noise
                     $horizontalTransitions[] = $transitionLength;
                 }
                 $lastColor = $isForeground;
                 $transitionStart = $x;
             }
         }

         // Look for vertical transitions near the center
         $verticalTransitions = [];
         $lastColor = null;
         $transitionStart = 0;

         for ($y = $centerY - (int) ($minDimension / 3); $y < $centerY + (int) ($minDimension / 3); $y++) {
             if ($y < 0 || $y >= $height) {
                 continue;
             }

             $color = imagecolorat($image, $centerX, $y);
             $rgb = imagecolorsforindex($image, $color);
             $isForeground = ($rgb['alpha'] <= 20); // Consider it foreground if not transparent

             if ($lastColor === null) {
                 $lastColor = $isForeground;
                 $transitionStart = $y;
             } else if ($lastColor !== $isForeground) {
                 // We found a transition
                 $transitionLength = $y - $transitionStart;
                 if ($transitionLength > 1) { // Ignore noise
                     $verticalTransitions[] = $transitionLength;
                 }
                 $lastColor = $isForeground;
                 $transitionStart = $y;
             }
         }

         // Analyze the transitions to estimate module size
         $allTransitions = array_merge($horizontalTransitions, $verticalTransitions);

         if (count($allTransitions) > 0) {
             // Sort transitions and find the most common sizes
             sort($allTransitions);

             $counts = array_count_values($allTransitions);
             arsort($counts);
             $mostCommon = key($counts);

             return max(1, (int) $mostCommon);
         }

         // Fallback methods if transitions analysis fails

         // Method 1: Standard approximation based on QR code version
         $estimatedModuleSize = (int) ($minDimension / 33); // QR codes have 21-177 modules (Version 1-40)

         // Method 2: Size of the QR code finder pattern (always 7 modules)
         // Look for the top-left finder pattern (typically starts at 0,0)
         $patternSize = 0;
         $lastWhite = false;

         for ($x = 0; $x < $minDimension / 4; $x++) {
             $color = imagecolorat($image, $x, 0);
             $rgb = imagecolorsforindex($image, $color);
             $isBlack = ($rgb['alpha'] <= 20);

             if ($isBlack && $lastWhite) {
                 // We've found the first black after white, marks the end of the finder pattern
                 $patternSize = $x;
                 break;
             }

             $lastWhite = !$isBlack;
         }

         if ($patternSize > 0) {
             return max(1, (int) ($patternSize / 7)); // Finder pattern is 7 modules wide
         }

         // Final fallback
         return max(1, $estimatedModuleSize);
     } */

    private function findConnectedModules($image, int $width, int $height, int $moduleSize): array
    {
        $grid = [];
        for ($y = 0; $y < $height; $y += $moduleSize) {
            $row = [];
            for ($x = 0; $x < $width; $x += $moduleSize) {
                $centerX = min($x + (int) ($moduleSize / 2), $width - 1);
                $centerY = min($y + (int) ($moduleSize / 2), $height - 1);
                $color = imagecolorat($image, $centerX, $centerY);

                $rgb = imagecolorsforindex($image, $color);
                $row[] = ($rgb['alpha'] <= 20) ? 1 : 0;
            }
            $grid[] = $row;
        }

        $regions = [];
        $visited = [];

        for ($y = 0; $y < count($grid); $y++) {
            for ($x = 0; $x < count($grid[$y]); $x++) {
                if (isset($visited[$y][$x]) || $grid[$y][$x] === 0) {
                    continue;
                }

                $region = [];
                $this->traceContour($grid, $visited, $x, $y, $region, $moduleSize);

                if (!empty($region)) {
                    $regions[] = $region;
                }
            }
        }

        return $regions;
    }

    /**
     * Trace contour of a connected region
     * 
     * @param array $grid Binary grid
     * @param array &$visited Visited cells
     * @param int $startX Starting X
     * @param int $startY Starting Y
     * @param array &$region Region points
     * @param int $moduleSize Module size
     */
    private function traceContour(array $grid, array &$visited, int $startX, int $startY, array &$region, int $moduleSize): void
    {
        $dx = [1, 1, 0, -1, -1, -1, 0, 1];
        $dy = [0, 1, 1, 1, 0, -1, -1, -1];

        $x = $startX;
        $y = $startY;
        $visited[$y][$x] = true;

        $region[] = [
            'x' => $x * $moduleSize,
            'y' => $y * $moduleSize
        ];

        $dir = 0;

        do {
            $found = false;

            for ($i = 0; $i < 8; $i++) {
                $nextDir = ($dir + $i) % 8;
                $nx = $x + $dx[$nextDir];
                $ny = $y + $dy[$nextDir];

                if ($ny < 0 || $ny >= count($grid) || $nx < 0 || $nx >= count($grid[$ny])) {
                    continue;
                }

                if ($grid[$ny][$nx] === 1) {
                    $dir = $nextDir;
                    $x = $nx;
                    $y = $ny;
                    $found = true;

                    if (!isset($visited[$y][$x])) {
                        $visited[$y][$x] = true;

                        $region[] = [
                            'x' => $x * $moduleSize,
                            'y' => $y * $moduleSize
                        ];
                    }

                    break;
                }
            }

            if (!$found) {
                break;
            }
        } while ($x !== $startX || $y !== $startY);

        if (!empty($region)) {
            $region[] = $region[0];
        }
    }
    /**
     * Prepare logo for QR code with optimized size and positioning for readability
     * The max size is now 35% of the QR code size
     * 
     * @param string $logoPath
     * @param int $qrSize
     * @param array $settings
     * @return Logo|null
     */
    private function prepareLogoForQR(string $logoPath, int $qrSize, array $settings): ?Logo
    {

        try {
            $dimensions = @getimagesize($logoPath);
            if ($dimensions === false) {
                $this->errorHandler->log("Failed to get logo dimensions for $logoPath");
                if (strtolower(pathinfo($logoPath, PATHINFO_EXTENSION)) === 'svg') {
                    $svgContent = file_get_contents($logoPath);
                    if ($svgContent !== false) {
                        preg_match('/width="([^"]*)"/', $svgContent, $widthMatches);
                        preg_match('/height="([^"]*)"/', $svgContent, $heightMatches);

                        $logoWidth = !empty($widthMatches[1]) ? (int) $widthMatches[1] : 100;
                        $logoHeight = !empty($heightMatches[1]) ? (int) $heightMatches[1] : 100;
                    } else {
                        $logoWidth = $logoHeight = 100;
                    }
                } else {
                    $logoWidth = $logoHeight = 100;
                }
            } else {
                $logoWidth = $dimensions[0];
                $logoHeight = $dimensions[1];
            }

            if ($logoWidth <= 0 || $logoHeight <= 0) {
                throw new Exception(esc_html('Invalid logo dimensions'));
            }

            $logoSizePercentage = isset($settings['logoSizePercentage'])
                ? min(max((int) $settings['logoSizePercentage'], 5), 35)
                : 25;

            $maxLogoWidth = (int) ($qrSize * $logoSizePercentage / 100);

            $this->errorHandler->log("Logo size calculation: QR size=$qrSize, percentage=$logoSizePercentage%, max width=$maxLogoWidth");

            $resizeToWidth = min($logoWidth, $maxLogoWidth);
            $resizeToHeight = null;

            if (!isset($settings['ignoreAspectRatio']) || !$settings['ignoreAspectRatio']) {
                $aspectRatio = $logoWidth / $logoHeight;
                $resizeToHeight = (int) ($resizeToWidth / $aspectRatio);
            } elseif (isset($settings['logoSizePercentage'])) {
                $resizeToHeight = (int) ($qrSize * $logoSizePercentage / 100);
            }

            try {
                $logo = Logo::create($logoPath)
                    ->setResizeToWidth($resizeToWidth);

                if ($resizeToHeight !== null) {
                    $logo->setResizeToHeight($resizeToHeight);
                } elseif (isset($settings['logoResizeToHeight']) && is_int($settings['logoResizeToHeight'])) {
                    $logo->setResizeToHeight($settings['logoResizeToHeight']);
                }

                if (isset($settings['punchoutBackground']) && $settings['punchoutBackground']) {
                    $logo->setPunchoutBackground(true);
                }

                return $logo;
            } catch (Exception $e) {
                $this->errorHandler->log('Logo creation error: ' . $e->getMessage());
                return null;
            }
        } catch (Exception $e) {
            $this->errorHandler->log('Logo processing error: ' . $e->getMessage());
            return null;
        }
    }
    /**
     * Add white background to logo for better visibility
     * 
     * @param string $logoPath Path to original logo file
     * @param int $padding Padding size in pixels
     * @return string Path to processed logo with white background
     * @throws Exception If processing fails
     */
    private function addWhiteBackgroundToLogo(string $logoPath, int $padding = 5): string
    {
        if (!extension_loaded('gd')) {
            throw new Exception(esc_html('GD extension is required for logo processing'));
        }

        try {
            list($width, $height, $type) = getimagesize($logoPath);

            switch ($type) {
                case IMAGETYPE_JPEG:
                    $source = imagecreatefromjpeg($logoPath);
                    break;
                case IMAGETYPE_PNG:
                    $source = imagecreatefrompng($logoPath);
                    break;
                default:
                    throw new Exception(esc_html('Unsupported image type for background processing'));
            }

            if (!$source) {
                throw new Exception(esc_html('Failed to create image resource for background processing'));
            }

            if ($type === IMAGETYPE_PNG) {
                imagealphablending($source, true);
                imagesavealpha($source, true);
            }

            $newWidth = $width + (2 * $padding);
            $newHeight = $height + (2 * $padding);
            $dest = imagecreatetruecolor($newWidth, $newHeight);

            if ($type === IMAGETYPE_PNG) {
                imagealphablending($dest, false);
                imagesavealpha($dest, true);
            }

            $white = imagecolorallocate($dest, 255, 255, 255);
            imagefilledrectangle($dest, 0, 0, $newWidth - 1, $newHeight - 1, $white);

            if ($type === IMAGETYPE_PNG) {
                imagealphablending($dest, true);
            }

            imagecopy($dest, $source, $padding, $padding, 0, 0, $width, $height);

            $tempFile = wp_tempnam('walletup-logo-bg-' . uniqid() . '.png');

            imagepng($dest, $tempFile, 9);

            imagedestroy($source);
            imagedestroy($dest);

            $this->errorHandler->log("Added white background to logo: $logoPath → $tempFile");
            return $tempFile;
        } catch (Exception $e) {
            $this->errorHandler->log('Logo background processing error: ' . $e->getMessage());
            throw $e;
        }
    }
    /**
     * Add white border around logo for better visibility
     * 
     * @param string $logoPath Path to original logo file
     * @param int $borderSize Width of border in pixels
     * @return string Path to processed logo with border
     * @throws Exception If processing fails
     */
    private function addWhiteBorderToLogo(string $logoPath, int $borderSize = 2): string
    {
        if (!extension_loaded('gd')) {
            throw new Exception(esc_html('GD extension is required for logo border processing'));
        }

        try {
            list($width, $height, $type) = getimagesize($logoPath);

            switch ($type) {
                case IMAGETYPE_JPEG:
                    $source = imagecreatefromjpeg($logoPath);
                    break;
                case IMAGETYPE_PNG:
                    $source = imagecreatefrompng($logoPath);
                    break;
                default:
                    throw new Exception(esc_html('Unsupported image type for border processing'));
            }

            if (!$source) {
                throw new Exception(esc_html('Failed to create image resource for border processing'));
            }

            if ($type === IMAGETYPE_PNG) {
                imagealphablending($source, true);
                imagesavealpha($source, true);
            }

            $newWidth = $width + (2 * $borderSize);
            $newHeight = $height + (2 * $borderSize);
            $dest = imagecreatetruecolor($newWidth, $newHeight);

            if ($type === IMAGETYPE_PNG) {
                imagealphablending($dest, false);
                imagesavealpha($dest, true);
                $transparent = imagecolorallocatealpha($dest, 0, 0, 0, 127);
                imagefilledrectangle($dest, 0, 0, $newWidth, $newHeight, $transparent);
            }

            $white = imagecolorallocate($dest, 255, 255, 255);
            imagefilledrectangle($dest, 0, 0, $newWidth - 1, $newHeight - 1, $white);

            imagecopy($dest, $source, $borderSize, $borderSize, 0, 0, $width, $height);

            $tempFile = wp_tempnam('walletup-logo-border-' . uniqid() . '.png');

            if ($type === IMAGETYPE_JPEG) {
                imagejpeg($dest, $tempFile, 95);
            } else {
                imagepng($dest, $tempFile, 9);
            }

            imagedestroy($source);
            imagedestroy($dest);

            return $tempFile;
        } catch (Exception $e) {
            $this->errorHandler->log('Logo border processing error: ' . $e->getMessage());
            throw $e;
        }
    }

    private function applyCustomStyling(QrCode &$qr, array $settings): void
    {
        $style = $settings['qrStyle'] ?? 'rounded';

        switch ($style) {
            case 'circular':
                $qr->setRoundBlockSizeMode(RoundBlockSizeMode::Margin);
                break;

            case 'diamond':
                break;

            case 'sharp':
                $qr->setRoundBlockSizeMode(RoundBlockSizeMode::None);
                $qr->setMargin(($settings['qrBorder'] ?? true) ? 15 : 0);
                break;

            case 'dot':
                $qr->setRoundBlockSizeMode(RoundBlockSizeMode::Margin);
                break;

            case 'rounded':
                $qr->setRoundBlockSizeMode(RoundBlockSizeMode::Margin);
                break;

            default:
                $qr->setRoundBlockSizeMode(RoundBlockSizeMode::None);
                break;
        }

        if (isset($settings['foregroundOpacity']) && is_numeric($settings['foregroundOpacity'])) {
            $normalizedOpacity = $this->normalizeOpacity($settings['foregroundOpacity']);

            $fgColor = $qr->getForegroundColor();
            $newColor = new Color(
                $fgColor->getRed(),
                $fgColor->getGreen(),
                $fgColor->getBlue(),
                $normalizedOpacity
            );
            $qr->setForegroundColor($newColor);
        }

        if (isset($settings['roundFinderPatterns']) && $settings['roundFinderPatterns']) {
            $qr->{'roundFinderPatterns'} = true;
        }
    }

    private function applyRoundedFinderPatterns(ResultInterface $result): ResultInterface
    {
        try {
            $pngData = $result->getString();

            $image = imagecreatefromstring($pngData);
            if (!$image) {
                $this->errorHandler->log('Failed to create image from QR code for finder pattern rounding');
                return $result;
            }

            $width = imagesx($image);
            $height = imagesy($image);

            $finderSize = (int) ($width * 0.07) * 7;  // Usually 7 modules
            $borderSize = (int) ($finderSize * 0.14);  // Usually 1 module out of 7

            $positions = [
                ['x' => $borderSize, 'y' => $borderSize],
                ['x' => $width - $finderSize - $borderSize, 'y' => $borderSize],
                ['x' => $borderSize, 'y' => $height - $finderSize - $borderSize]
            ];

            foreach ($positions as $pos) {
                $this->roundCorners($image, $pos['x'], $pos['y'], $finderSize, $finderSize);
            }

            ob_start();
            imagepng($image);
            $newPngData = ob_get_clean();
            imagedestroy($image);

            $modifiedResult = new class ($newPngData, $result) implements ResultInterface {
                private $data;
                private $originalResult;

                public function __construct(string $data, ResultInterface $originalResult)
                {
                    $this->data = $data;
                    $this->originalResult = $originalResult;
                }

                public function getString(): string
                {
                    return $this->data;
                }

                public function getDataUri(): string
                {
                    return 'data:image/png;base64,' . base64_encode($this->data);
                }

                public function saveToFile(string $path): void
                {
                    file_put_contents($path, $this->data);
                }

                public function getMatrix(): \Endroid\QrCode\Matrix\MatrixInterface
                {
                    return $this->originalResult->getMatrix();
                }

                public function getMimeType(): string
                {
                    return 'image/png';
                }
            };
            return $modifiedResult;
        } catch (Exception $e) {
            $this->errorHandler->log('Error applying rounded finder patterns: ' . $e->getMessage());
            return $result;
        }
    }

    /**
     * Round the corners of a rectangle in an image
     * 
     * @param resource $image GD image resource
     * @param int $x X position of rectangle
     * @param int $y Y position of rectangle
     * @param int $width Rectangle width
     * @param int $height Rectangle height
     * @param int $radius Corner radius (default: 10)
     */
    private function roundCorners($image, int $x, int $y, int $width, int $height, int $radius = 10): void
    {
        $mask = imagecreatetruecolor($width, $height);
        $transparent = imagecolorallocatealpha($mask, 0, 0, 0, 127);
        imagefill($mask, 0, 0, $transparent);

        $bgColor = imagecolorat($image, $width - 1, $height - 1);

        $black = imagecolorallocate($mask, 0, 0, 0);

        imagefilledrectangle($mask, 0, 0, $width, $height, $transparent);

        imagefilledrectangle($mask, $radius, 0, $width - $radius, $height, $black);
        imagefilledrectangle($mask, 0, $radius, $width, $height - $radius, $black);

        $this->drawRoundedCorner($mask, $radius, $radius, $radius, $black, 180, 270);
        $this->drawRoundedCorner($mask, $width - $radius, $radius, $radius, $black, 270, 360);
        $this->drawRoundedCorner($mask, $radius, $height - $radius, $radius, $black, 90, 180);
        $this->drawRoundedCorner($mask, $width - $radius, $height - $radius, $radius, $black, 0, 90);

        imagecopymerge($image, $mask, $x, $y, 0, 0, $width, $height, 100);

        imagedestroy($mask);
    }

    /**
     * Draw a rounded corner
     * 
     * @param resource $image GD image resource
     * @param int $cx Center X
     * @param int $cy Center Y
     * @param int $radius Radius
     * @param int $color Color
     * @param int $startAngle Start angle
     * @param int $endAngle End angle
     */
    private function drawRoundedCorner($image, int $cx, int $cy, int $radius, int $color, int $startAngle, int $endAngle): void
    {
        for ($i = $startAngle; $i <= $endAngle; $i++) {
            $angle = $i * M_PI / 180;
            $x = $cx + round($radius * cos($angle));
            $y = $cy + round($radius * sin($angle));
            imagesetpixel($image, $x, $y, $color);
        }
        imagefilledarc($image, $cx, $cy, $radius * 2, $radius * 2, $startAngle, $endAngle, $color, IMG_ARC_PIE);
    }

    private function getMimeType(string $filePath): string
    {
        if ($this->hasFileinfo) {
            if (function_exists('mime_content_type')) {
                return mime_content_type($filePath);
            }

            if (class_exists('finfo')) {
                try {
                    $finfo = new \finfo(FILEINFO_MIME_TYPE);
                    return $finfo->file($filePath);
                } catch (\Exception $e) {
                    $this->errorHandler->log('Finfo error: ' . $e->getMessage());

                    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
                    $mimeTypes = [
                        'jpg' => 'image/jpeg',
                        'jpeg' => 'image/jpeg',
                        'png' => 'image/png',
                        'gif' => 'image/gif',
                        'svg' => 'image/svg+xml',
                        'webp' => 'image/webp',
                        'pdf' => 'application/pdf',
                        'txt' => 'text/plain',
                    ];

                    return $mimeTypes[$ext] ?? 'application/octet-stream';
                }
            }

        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (isset($this->mimeTypeMap[$ext])) {
            return $this->mimeTypeMap[$ext];
        }

        $mimeType = $this->detectMimeFromSignature($filePath);
        if ($mimeType) {
            return $mimeType;
        }

        $upload_dir = wp_upload_dir();
        $upload_base = str_replace('\\', '/', $upload_dir['basedir']);
        $file_path = str_replace('\\', '/', $filePath);

        if (strpos($file_path, $upload_base) === 0) {
            $attachment_id = attachment_url_to_postid(str_replace($upload_base, $upload_dir['baseurl'], $file_path));
            if ($attachment_id) {
                $mime = get_post_mime_type($attachment_id);
                if ($mime) {
                    return $mime;
                }
            }
        }

        return 'application/octet-stream';
    }
    /**
     * Detect MIME type from file signature (first bytes of the file)
     * 
     * @param string $filePath Path to file
     * @return string|null Detected MIME type or null if detection failed
     */
    private function detectMimeFromSignature(string $filePath): ?string
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return null;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Binary file reading for MIME type detection.
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            return null;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
        $signature = fread($handle, 12); // Read first 12 bytes for signature detection
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($handle);

        $hex = bin2hex($signature);

        if (substr($hex, 0, 6) === 'ffd8ff') {
            return 'image/jpeg';
        }

        if (substr($hex, 0, 16) === '89504e470d0a1a0a') {
            return 'image/png';
        }

        if (substr($hex, 0, 8) === '47494638') {
            return 'image/gif';
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Binary file reading for SVG detection.
        $handle = fopen($filePath, 'rb');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
        $content = fread($handle, 1024); // Read more bytes
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($handle);

        if (stripos($content, '<svg') !== false || stripos($content, '<?xml') !== false) {
            return 'image/svg+xml';
        }

        return null;
    }

    /**
     * Create a safe temporary file from a base64 image
     * 
     * @param string $base64Data Base64 encoded image data
     * @param string $prefix File prefix for the temporary file
     * @return string Path to temporary file
     * @throws Exception If file creation fails
     */
    private function createTempFileFromBase64(string $base64Data, string $prefix = 'walletup-temp'): string
    {
        if (strpos($base64Data, 'data:') === 0) {
            $parts = explode(',', $base64Data, 2);
            $base64Data = isset($parts[1]) ? $parts[1] : $base64Data;

            $mime = '';
            if (preg_match('/data:(.*?);base64/', $parts[0], $matches)) {
                $mime = $matches[1];
            }
        }

        $decodedData = base64_decode($base64Data);
        if ($decodedData === false) {
            throw new Exception(esc_html('Invalid base64 data'));
        }

        $tempFile = wp_tempnam($prefix);
        if (!$tempFile) {
            throw new Exception(esc_html('Failed to create temporary file'));
        }

        if (file_put_contents($tempFile, $decodedData) === false) {
            throw new Exception(esc_html('Failed to write to temporary file'));
        }

        $extension = '.png';
        $mimeType = $this->detectMimeFromSignature($tempFile);

        if ($mimeType === 'image/jpeg') {
            $extension = '.jpg';
        } elseif ($mimeType === 'image/png') {
            $extension = '.png';
        } elseif ($mimeType === 'image/gif') {
            $extension = '.gif';
        } elseif ($mimeType === 'image/svg+xml') {
            $extension = '.svg';
        }

        $newFile = $tempFile . $extension;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic rename for temp file processing.
        rename($tempFile, $newFile);

        register_shutdown_function(function () use ($newFile) {
            if (file_exists($newFile)) {
                @wp_delete_file($newFile);
            }
        });

        return $newFile;
    }

    /**
     * Improved isValidLogoPath method with fileinfo fallback
     * 
     * @param string $path Logo file path
     * @return bool Whether path is valid
     */
    private function isValidLogoPath(string $logoPath): bool
    {
        if (!file_exists($logoPath) || !is_readable($logoPath)) {
            $this->errorHandler->log("Logo file not found or not readable: $logoPath");
            return false;
        }

        $fileSize = @filesize($logoPath);
        $maxSize = 10 * 1024 * 1024; // 10MB max

        if ($fileSize === false || $fileSize > $maxSize) {
            $this->errorHandler->log("Logo file is too large: " . ($fileSize === false ? 'Unknown size' : format_size($fileSize)));
            return false;
        }

        $imageSizeInfo = @getimagesize($logoPath);
        if ($imageSizeInfo !== false) {
            if (isset($imageSizeInfo[2]) && in_array($imageSizeInfo[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF])) {
                return true;
            }
        }

        $mimeType = $this->getMimeType($logoPath);
        $validTypes = ['image/png', 'image/jpeg', 'image/jpg', 'image/gif', 'image/svg+xml'];

        if (!in_array($mimeType, $validTypes)) {
            $this->errorHandler->log("Invalid logo file type: $mimeType");
            return false;
        }

        return true;
    }

    private function decodeBase64Image(string $base64Data)
    {
        if (strpos($base64Data, 'data:image/') === 0) {
            $base64Data = preg_replace('/^data:image\/\w+;base64,/', '', $base64Data);
        }

        $data = base64_decode($base64Data);
        if ($data === false) {
            $this->errorHandler->log('Invalid base64 image data');
            return false;
        }

        return $data;
    }

    private function createTempLogoFile(string $imageData, string $type)
    {
        $extension = strtolower($type) === 'jpeg' ? 'jpg' : strtolower($type);
        $tempFile = wp_tempnam('walletup-logo-' . uniqid() . '.' . $extension);

        if (!$tempFile || file_put_contents($tempFile, $imageData) === false) {
            $this->errorHandler->log('Failed to create temporary logo file');
            return false;
        }

        return $tempFile;
    }

    private function fallbackQR(array $params): string
    {
        return $this->fallbackUrl . http_build_query($params);
    }

    /**
     * Verify QR code capabilities
     * 
     * @throws Exception
     */
    private function verifyQRCapabilities(): void
    {
        if (!class_exists('Endroid\QrCode\QrCode')) {
            throw new Exception(esc_html('QR Code library not found. Please run composer install.'));
        }

        if (!extension_loaded('gd') && !extension_loaded('imagick')) {
            throw new Exception(esc_html('Either GD or ImageMagick extension is required for QR code generation.'));
        }
    }

    /**
     * Convert hex color to RGB array
     * 
     * @param string $hex
     * @return array
     * @throws Exception
     */
    /*     private function hexToRgb(string $hex): array
        {
            $hex = ltrim($hex, '#');
            if (strlen($hex) === 3) {
                $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
            }
            if (strlen($hex) !== 6) {
                throw new Exception(esc_html('Invalid hex color provided: ' . $hex));
            }
            return [
                hexdec(substr($hex, 0, 2)),
                hexdec(substr($hex, 2, 2)),
                hexdec(substr($hex, 4, 2))
            ];
        } */

    /**
     * Process and generate QR code from uploaded file with fileinfo fallback
     * 
     * @param array $file Uploaded file information ($_FILES array item)
     * @param string $data Data to encode in QR
     * @param array $settings QR code settings
     * @return string Base64 encoded QR code with logo
     * @throws Exception If processing fails
     */
    public function processUploadedLogo(array $file, string $data, array $settings = []): string
    {
        if (!isset($file['tmp_name']) || !file_exists($file['tmp_name'])) {
            throw new Exception(esc_html('Invalid logo file upload'));
        }

        $mimeTypes = [];

        if (isset($file['type']) && !empty($file['type'])) {
            $mimeTypes[] = $file['type'];
        }

        $detectedType = $this->getMimeType($file['tmp_name']);
        if ($detectedType) {
            $mimeTypes[] = $detectedType;
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (isset($this->mimeTypeMap[$ext])) {
            $mimeTypes[] = $this->mimeTypeMap[$ext];
        }

        $validMimeType = false;
        $allowedTypes = ['image/png', 'image/jpeg', 'image/jpg', 'image/svg+xml'];

        foreach ($mimeTypes as $type) {
            if (in_array($type, $allowedTypes)) {
                $validMimeType = true;
                break;
            }
        }

        if (!$validMimeType && !empty($mimeTypes)) {
            $this->errorHandler->log('Invalid logo mime types detected: ' . implode(', ', $mimeTypes));
            throw new Exception(esc_html('Logo must be PNG, JPEG, or SVG image. Detected: ' . implode(', ', $mimeTypes)));
        }

        if (empty($mimeTypes)) {
            $this->errorHandler->log('Warning: Could not detect MIME type for uploaded file. Proceeding anyway.');
        }

        $logoPath = $file['tmp_name'];
        $logoShape = $settings['logoShape'] ?? 'original';

        $this->errorHandler->log("Processing logo with shape: {$logoShape}");

        if ($logoShape !== 'original' && $logoShape !== 'auto') {
            $shapeOptions = [
                'borderRadius' => $settings['logoShapeBorderRadius'] ?? 10
            ];

            try {
                $shapedLogoPath = $this->preserveLogoShape($logoPath, $logoShape, $shapeOptions);
                if ($shapedLogoPath && file_exists($shapedLogoPath)) {
                    $logoPath = $shapedLogoPath;
                    $settings['punchoutBackground'] = false;
                } else {
                    $this->errorHandler->log("Warning: Shape preservation failed, using original logo");
                }
            } catch (Exception $e) {
                $this->errorHandler->log("Shape preservation error: " . $e->getMessage());
            }
        }

        if (isset($settings['addWhiteBorder']) && $settings['addWhiteBorder']) {
            try {
                $bgLogoPath = $this->addWhiteBackgroundToLogo(
                    $logoPath,
                    (int) ($settings['whiteBorderSize'] ?? 5)
                );
                if ($bgLogoPath && file_exists($bgLogoPath)) {
                    $logoPath = $bgLogoPath;
                }
            } catch (Exception $e) {
                $this->errorHandler->log("White background error: " . $e->getMessage());
            }
        }

        $settings['logoPath'] = $logoPath;

        if (isset($settings['foregroundOpacity'])) {
            $settings['foregroundOpacity'] = $this->normalizeOpacity($settings['foregroundOpacity']);
        }

        return $this->generateQR($data, $settings);
    }
    /**
     * Function to preserve logo shape by creating a properly shaped background
     * 
     * @param string $logoPath Path to the logo file
     * @param string $logoShape Shape to apply (circle, square, rounded, original)
     * @param array $options Additional options for shape processing
     * @return string Path to processed logo with preserved shape
     */
    private function preserveLogoShape(string $logoPath, string $logoShape, array $options = []): string
    {
        if (!extension_loaded('gd')) {
            $this->errorHandler->log('GD extension required for shape preservation');
            return $logoPath;
        }

        try {
            list($width, $height, $type) = getimagesize($logoPath);

            switch ($type) {
                case IMAGETYPE_JPEG:
                    $source = imagecreatefromjpeg($logoPath);
                    break;
                case IMAGETYPE_PNG:
                    $source = imagecreatefrompng($logoPath);
                    break;
                default:
                    $this->errorHandler->log('Unsupported image type for shape preservation');
                    return $logoPath;
            }

            if (!$source) {
                throw new Exception(esc_html('Failed to create image resource for shape preservation'));
            }

            imagealphablending($source, true);
            imagesavealpha($source, true);

            $shaped = imagecreatetruecolor($width, $height);
            imagealphablending($shaped, false);
            imagesavealpha($shaped, true);

            $transparent = imagecolorallocatealpha($shaped, 0, 0, 0, 127);
            imagefilledrectangle($shaped, 0, 0, $width - 1, $height - 1, $transparent);

            $borderRadius = isset($options['borderRadius']) ? (int) $options['borderRadius'] : 10;

            imagealphablending($shaped, true);

            switch ($logoShape) {
                case 'circle':
                    $diameter = min($width, $height);
                    $centerX = $width / 2;
                    $centerY = $height / 2;

                    $white = imagecolorallocate($shaped, 255, 255, 255);
                    imagefilledellipse($shaped, $centerX, $centerY, $diameter, $diameter, $white);

                    $mask = imagecreatetruecolor($width, $height);
                    imagealphablending($mask, false);
                    $transparentMask = imagecolorallocatealpha($mask, 0, 0, 0, 127);
                    imagefilledrectangle($mask, 0, 0, $width - 1, $height - 1, $transparentMask);
                    $blackMask = imagecolorallocate($mask, 0, 0, 0);
                    imagefilledellipse($mask, $centerX, $centerY, $diameter, $diameter, $blackMask);

                    for ($x = 0; $x < $width; $x++) {
                        for ($y = 0; $y < $height; $y++) {
                            $maskColor = imagecolorat($mask, $x, $y);
                            if ($maskColor === $blackMask) {
                                $color = imagecolorat($source, $x, $y);
                                imagesetpixel($shaped, $x, $y, $color);
                            }
                        }
                    }

                    imagedestroy($mask);
                    break;

                case 'square':
                    $size = min($width, $height);
                    $offsetX = ($width - $size) / 2;
                    $offsetY = ($height - $size) / 2;

                    $white = imagecolorallocate($shaped, 255, 255, 255);
                    imagefilledrectangle($shaped, $offsetX, $offsetY, $offsetX + $size - 1, $offsetY + $size - 1, $white);

                    imagecopy($shaped, $source, $offsetX, $offsetY, $offsetX, $offsetY, $size, $size);
                    break;

                case 'rounded':
                    $white = imagecolorallocate($shaped, 255, 255, 255);

                    $this->imagefilledroundedrectangle(
                        $shaped,
                        0,
                        0,
                        $width - 1,
                        $height - 1,
                        $borderRadius,
                        $white
                    );

                    for ($x = 0; $x < $width; $x++) {
                        for ($y = 0; $y < $height; $y++) {
                            $shapeColor = imagecolorat($shaped, $x, $y);
                            $shapeAlpha = ($shapeColor >> 24) & 0x7F; // Extract alpha from RGBA

                            if ($shapeAlpha < 127) { // Not fully transparent
                                $color = imagecolorat($source, $x, $y);
                                imagesetpixel($shaped, $x, $y, $color);
                            }
                        }
                    }
                    break;

                case 'original':
                default:
                    imagedestroy($shaped);
                    imagedestroy($source);
                    return $logoPath;
            }

            $tempFile = wp_tempnam('walletup-shaped-logo-' . uniqid() . '.png');

            imagepng($shaped, $tempFile, 9);

            imagedestroy($source);
            imagedestroy($shaped);

            $this->errorHandler->log("Created {$logoShape} shaped logo: $logoPath → $tempFile");

            register_shutdown_function(function () use ($tempFile) {
                if (file_exists($tempFile)) {
                    @wp_delete_file($tempFile);
                }
            });

            return $tempFile;
        } catch (Exception $e) {
            $this->errorHandler->log('Logo shape processing error: ' . $e->getMessage());
            return $logoPath;
        }
    }

    /**
     * Helper function to draw a filled rounded rectangle
     * 
     * @param resource $image GD image resource
     * @param int $x1 Top left X coordinate
     * @param int $y1 Top left Y coordinate
     * @param int $x2 Bottom right X coordinate
     * @param int $y2 Bottom right Y coordinate
     * @param int $radius Corner radius
     * @param int $color Fill color
     */
    private function imagefilledroundedrectangle($image, $x1, $y1, $x2, $y2, $radius, $color)
    {
        imagefilledrectangle($image, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
        imagefilledrectangle($image, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);

        imagefilledarc($image, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, 180, 270, $color, IMG_ARC_PIE);
        imagefilledarc($image, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, 270, 360, $color, IMG_ARC_PIE);
        imagefilledarc($image, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, 90, 180, $color, IMG_ARC_PIE);
        imagefilledarc($image, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, 0, 90, $color, IMG_ARC_PIE);
    }
    /**
     * Process base64 encoded logo data
     * 
     * @param string $logoData Base64 encoded logo image
     * @param string $data Data to encode in QR
     * @param array $settings QR code settings
     * @return string Base64 encoded QR code with logo
     * @throws Exception If processing fails
     */
    public function processBase64Logo(string $logoData, string $data, array $settings = []): string
    {
        if (empty($logoData)) {
            throw new Exception(esc_html('Logo data is empty'));
        }

        try {
            if (preg_match('/^data:image\/(\w+);base64,/', $logoData, $matches)) {
                $logoType = $matches[1];
                $logoData = preg_replace('/^data:image\/\w+;base64,/', '', $logoData);
            } else {
                $logoType = 'png';
            }

            $decodedData = base64_decode($logoData);
            if ($decodedData === false) {
                throw new Exception(esc_html('Invalid base64 logo data'));
            }

            $extension = strtolower($logoType) === 'jpeg' ? 'jpg' : strtolower($logoType);
            if (function_exists('wp_tempnam')) {
                $tempFile = wp_tempnam('walletup-base64-logo-' . uniqid() . '.' . $extension);
                if (!$tempFile || file_put_contents($tempFile, $decodedData) === false) {
                    throw new Exception(esc_html('Failed to create temporary logo file'));
                }
            } else {
                $tempFile = $this->createTempFileFromBase64($logoData);
            }

            $logoShape = $settings['logoShape'] ?? 'original';
            $this->errorHandler->log("Processing base64 logo with shape: {$logoShape}");

            if ($logoShape !== 'original' && $logoShape !== 'auto') {
                $shapeOptions = [
                    'borderRadius' => $settings['logoShapeBorderRadius'] ?? 10
                ];

                $tempFile = $this->preserveLogoShape($tempFile, $logoShape, $shapeOptions);
                $settings['punchoutBackground'] = false;
            }

            if (isset($settings['addWhiteBorder']) && $settings['addWhiteBorder']) {
                $tempFile = $this->addWhiteBackgroundToLogo(
                    $tempFile,
                    (int) ($settings['whiteBorderSize'] ?? 5)
                );
            }

            register_shutdown_function(function () use ($tempFile) {
                if (file_exists($tempFile)) {
                    @wp_delete_file($tempFile);
                }
            });

            $settings['logoPath'] = $tempFile;
            $settings['logoType'] = $logoType;

            if (isset($settings['foregroundOpacity'])) {
                $settings['foregroundOpacity'] = $this->normalizeOpacity($settings['foregroundOpacity']);
            }

            return $this->generateQR($data, $settings);

        } catch (Exception $e) {
            $this->errorHandler->log('Base64 logo processing error: ' . $e->getMessage());
            throw $e;
        }
    }
    /**
     * Process logo manipulation (crop, resize, remove background)
     * 
     * @param string $logoPath Path to logo file
     * @param array $manipulationSettings Settings for manipulation
     * @return string Path to processed logo
     * @throws Exception If processing fails
     */
    public function processLogoManipulation(string $logoPath, array $manipulationSettings = []): string
    {
        if (!file_exists($logoPath)) {
            throw new Exception(esc_html('Logo file not found'));
        }

        if (!extension_loaded('gd')) {
            throw new Exception(esc_html('GD extension is required for logo manipulation'));
        }

        try {
            list($width, $height, $type) = getimagesize($logoPath);

            switch ($type) {
                case IMAGETYPE_JPEG:
                    $source = imagecreatefromjpeg($logoPath);
                    break;
                case IMAGETYPE_PNG:
                    $source = imagecreatefrompng($logoPath);
                    break;
                default:
                    throw new Exception(esc_html('Unsupported image type'));
            }

            if (!$source) {
                throw new Exception(esc_html('Failed to create image resource'));
            }

            $processed = $this->manipulateLogo($source, $width, $height, $manipulationSettings);

            $tempFile = wp_tempnam('walletup-processed-logo-' . uniqid() . '.png');

            imagesavealpha($processed, true);
            imagepng($processed, $tempFile);

            imagedestroy($source);
            imagedestroy($processed);

            register_shutdown_function(function () use ($tempFile) {
                if (file_exists($tempFile)) {
                    @wp_delete_file($tempFile);
                }
            });

            return $tempFile;
        } catch (Exception $e) {
            $this->errorHandler->log('Logo manipulation error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Manipulate logo image with GD
     * 
     * @param resource $source Source image resource
     * @param int $width Original width
     * @param int $height Original height
     * @param array $settings Manipulation settings
     * @return resource Processed image resource
     */
    private function manipulateLogo($source, int $width, int $height, array $settings = [])
    {
        $processed = imagecreatetruecolor($width, $height);
        imagealphablending($processed, false);
        imagesavealpha($processed, true);

        $transparent = imagecolorallocatealpha($processed, 0, 0, 0, 127);
        imagefilledrectangle($processed, 0, 0, $width, $height, $transparent);

        if (isset($settings['removeBackground']) && $settings['removeBackground']) {
            $bgColor = imagecolorat($source, 0, 0);
            $tolerance = isset($settings['colorTolerance']) ? (int) $settings['colorTolerance'] : 30;

            for ($x = 0; $x < $width; $x++) {
                for ($y = 0; $y < $height; $y++) {
                    $pixelColor = imagecolorat($source, $x, $y);

                    if (!$this->colorMatchesWithTolerance($pixelColor, $bgColor, $tolerance)) {
                        $color = imagecolorsforindex($source, $pixelColor);
                        $newColor = imagecolorallocatealpha(
                            $processed,
                            $color['red'],
                            $color['green'],
                            $color['blue'],
                            $color['alpha']
                        );
                        imagesetpixel($processed, $x, $y, $newColor);
                    }
                }
            }
        } else {
            imagecopy($processed, $source, 0, 0, 0, 0, $width, $height);
        }

        if (isset($settings['resize']) && $settings['resize']) {
            $newWidth = isset($settings['width']) ? (int) $settings['width'] : $width;
            $newHeight = isset($settings['height']) ? (int) $settings['height'] : $height;

            if (isset($settings['maintainAspectRatio']) && $settings['maintainAspectRatio']) {
                $ratio = $width / $height;
                if ($newWidth / $newHeight > $ratio) {
                    $newWidth = round($newHeight * $ratio);
                } else {
                    $newHeight = round($newWidth / $ratio);
                }
            }

            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);

            imagefilledrectangle($resized, 0, 0, $newWidth, $newHeight, $transparent);

            imagecopyresampled($resized, $processed, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

            imagedestroy($processed);
            $processed = $resized;
        }

        return $processed;
    }

    private function colorMatchesWithTolerance(int $color1, int $color2, int $tolerance): bool
    {
        $rgb1 = $this->gdColorToRGB($color1);
        $rgb2 = $this->gdColorToRGB($color2);

        return (
            abs($rgb1['red'] - $rgb2['red']) <= $tolerance &&
            abs($rgb1['green'] - $rgb2['green']) <= $tolerance &&
            abs($rgb1['blue'] - $rgb2['blue']) <= $tolerance
        );
    }

    private function gdColorToRGB(int $color): array
    {
        return [
            'red' => ($color >> 16) & 0xFF,
            'green' => ($color >> 8) & 0xFF,
            'blue' => $color & 0xFF
        ];
    }
    public function checkRequiredExtensions(): void
    {
        if (!is_admin()) {
            return;
        }

        if (!extension_loaded('fileinfo')) {
            add_action('admin_notices', function () {
                if (!current_user_can('manage_options')) {
                    return;
                }

                ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    <strong>WalletUpPro Notice:</strong> The PHP fileinfo extension is recommended but not installed on your server.
                    While the plugin has fallback methods for QR code generation, enabling this extension will improve performance.
                    Please contact your hosting provider to enable the PHP fileinfo extension.
                </p>
            </div>
            <?php
            });
        }
    }
    public function getStatus(): array
    {
        return [
            'initialized' => $this->initialized,
            'library_loaded' => class_exists('Endroid\QrCode\QrCode'),
            'gd_available' => extension_loaded('gd'),
            'imagick_available' => extension_loaded('imagick'),
            'writer_initialized' => $this->writer instanceof PngWriter,
            'writer_available'   => class_exists('Endroid\\QrCode\\Writer\\PngWriter', false),
            'last_error' => $this->lastError,
            'supported_sizes' => self::QR_DIMENSIONS,
            'supported_styles' => self::QR_STYLES,
            'supported_logo_positions' => self::LOGO_POSITIONS,
            'supported_error_correction_levels' => ['low', 'medium', 'quartile', 'high'],
            'supported_round_block_size_modes' => ['enlarge', 'margin', 'none', 'shrink'],
            'logo_support' => true,
            'background_removal_support' => extension_loaded('gd'),
            'php_version' => PHP_VERSION
        ];
    }
    
    /**
     * Track QR code generation centrally
     * This is the single source of truth for all QR code tracking
     * 
     * @param string $event_type Type of event (generated, cache_hit, click, error)
     * @param string $data The QR code data
     * @param array $settings The QR settings used
     * @return void
     */
    private function trackQRGeneration(string $event_type, string $data, array $settings = []): void
    {
        try {
            if (is_admin() && !wp_doing_ajax()) {
                return;
            }
            $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
            if (wp_doing_cron()
                || (defined('REST_REQUEST') && REST_REQUEST)
                || ($ua !== '' && preg_match('/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|headless|monitor|pingdom|uptime/i', $ua))) {
                return;
            }
            if (wp_doing_ajax() && !empty($_SERVER['HTTP_REFERER'])
                && strpos((string) $_SERVER['HTTP_REFERER'], admin_url()) === 0) {
                return;
            }

            $payment_method = $settings['payment_method'] ?? $this->detectPaymentMethod($data);

            $analytics_event_type = 'qr_generated';
            switch($event_type) {
                case 'generated':
                    $analytics_event_type = 'qr_generated';
                    break;
                case 'cache_hit':
                    $analytics_event_type = 'qr_cache_hit';
                    break;
                case 'click':
                    $analytics_event_type = 'click';
                    break;
                case 'scan':
                    $analytics_event_type = 'scan';
                    break;
            }

            $tracking_data = [
                'post_id' => get_the_ID() ?: 0,
                'post_type' => get_post_type() ?: 'unknown',
                'post_title' => get_the_title() ?: 'Unknown',
                'qr_data_type' => $this->getDataType($data),
                'qr_style' => $settings['qrStyle'] ?? 'default',
                'qr_size' => $settings['qrSize'] ?? 'medium',
                'has_logo' => !empty($settings['logoPath']),
                'cache_hit' => $event_type === 'cache_hit',
                'source' => 'qr_service',
                'timestamp' => time()
            ];
            
            global $wpdb;
            $table = $wpdb->prefix . 'wallet_up_analytics';
            
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fresh data required for accurate results.
            if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
                $insert_data = [
                    'event_type' => $analytics_event_type,
                    'payment_method' => $payment_method,
                    'post_id' => $tracking_data['post_id'],
                    'post_type' => $tracking_data['post_type'],
                    'post_title' => $tracking_data['post_title'],
                    'user_id' => get_current_user_id() ?: null,
                    'device_type' => $this->detectDeviceType(),
                    'browser' => $this->detectBrowser(),
                    'operating_system' => $this->detectOS(),
                    'user_ip' => $this->getClientIP(),
                    'created_at' => current_time('mysql'),
                    'metadata' => wp_json_encode($tracking_data)
                ];
                
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Write operation, caching not applicable.
                $wpdb->insert($table, $insert_data);

                $this->errorHandler->log("QR tracked: $event_type ($analytics_event_type) for $payment_method");
            }

        } catch (\Exception $e) {
            $this->errorHandler->log('QR tracking error (non-fatal): ' . $e->getMessage());
        }
    }
    
    private function detectDeviceType(): string
    {
        $userAgent = sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')) ?? '';
        if (preg_match('/(tablet|ipad|playbook)|(android(?!.*(mobi|opera mini)))/i', $userAgent)) {
            return 'tablet';
        }
        if (preg_match('/(up.browser|up.link|mmp|symbian|smartphone|midp|wap|phone|android|iemobile)/i', $userAgent)) {
            return 'mobile';
        }
        return 'desktop';
    }
    
    private function getClientIP(): string
    {
        $ipKeys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'REMOTE_ADDR'];
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
    
    private function detectBrowser(): string
    {
        $userAgent = sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')) ?? '';
        if (strpos($userAgent, 'Chrome')) return 'Chrome';
        if (strpos($userAgent, 'Safari')) return 'Safari';
        if (strpos($userAgent, 'Firefox')) return 'Firefox';
        if (strpos($userAgent, 'Edge')) return 'Edge';
        return 'Unknown';
    }
    
    private function detectOS(): string
    {
        $userAgent = sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')) ?? '';
        if (strpos($userAgent, 'Windows')) return 'Windows';
        if (strpos($userAgent, 'Mac')) return 'Mac OS';
        if (strpos($userAgent, 'Linux')) return 'Linux';
        if (strpos($userAgent, 'iPhone') || strpos($userAgent, 'iPad')) return 'iOS';
        if (strpos($userAgent, 'Android')) return 'Android';
        return 'Unknown';
    }
    
    private function getDataType(string $data): string
    {
        if (preg_match('/^https?:\/\//i', $data)) {
            return 'url';
        }
        
        if (filter_var($data, FILTER_VALIDATE_EMAIL)) {
            return 'email';
        }
        
        if (preg_match('/^tel:/i', $data) || preg_match('/^\+?[\d\s\-\(\)]+$/', $data)) {
            return 'phone';
        }
        
        if (preg_match('/^(bitcoin|ethereum|litecoin|dogecoin|bnb|usdt|usdc):/i', $data)) {
            return 'crypto_uri';
        }
        
        if (preg_match('/^(venmo|cashapp|paypal|zelle):/i', $data)) {
            return 'payment_uri';
        }
        
        if (preg_match('/^(bc1|[13])[a-zA-HJ-NP-Z0-9]{25,62}$/', $data) || // Bitcoin
            preg_match('/^0x[a-fA-F0-9]{40}$/', $data) || // Ethereum
            preg_match('/^[LM][a-km-zA-HJ-NP-Z1-9]{26,33}$/', $data) || // Litecoin
            preg_match('/^D[a-km-zA-HJ-NP-Z1-9]{33}$/', $data)) { // Dogecoin
            return 'crypto_address';
        }
        
        if (json_decode($data) !== null && json_last_error() === JSON_ERROR_NONE) {
            return 'json';
        }
        
        if (stripos($data, 'BEGIN:VCARD') !== false) {
            return 'vcard';
        }
        
        if (stripos($data, 'WIFI:') === 0) {
            return 'wifi';
        }

        return 'text';
    }
    
    /**
     * Detect payment method from QR data using centralized config
     * 
     * @param string $data The QR code data
     * @return string The detected payment method
     */
    private function detectPaymentMethod(string $data): string
    {
        $paymentMethods = \WalletUpPro\Core\Config\PaymentMethodsConfig::PAYMENT_METHODS;

        foreach ($paymentMethods as $methodId => $config) {
            if (isset($config['validation_pattern'])) {
                if ($config['type'] === 'crypto') {
                    if (stripos($data, $methodId . ':') === 0) {
                        return $methodId;
                    }
                    if (preg_match($config['validation_pattern'], $data)) {
                        return $methodId;
                    }
                }
            }

            if ($methodId === 'venmo' && (stripos($data, 'venmo.com') !== false || stripos($data, 'venmo://') === 0)) {
                return 'venmo';
            }
            if ($methodId === 'cashapp' && (stripos($data, 'cash.app') !== false || stripos($data, 'cashapp://') === 0)) {
                return 'cashapp';
            }
            if ($methodId === 'paypal' && (stripos($data, 'paypal.com') !== false || stripos($data, 'paypal://') === 0)) {
                return 'paypal';
            }
            if ($methodId === 'zelle' && stripos($data, 'zelle') !== false) {
                return 'zelle';
            }
            if ($methodId === 'fbpay' && (stripos($data, 'facebook.com/pay') !== false || stripos($data, 'meta.com/pay') !== false)) {
                return 'fbpay';
            }
            if ($methodId === 'waluppay' && stripos($data, 'waluppay') !== false) {
                return 'waluppay';
            }
        }
        
        if (preg_match('/^https?:\/\//', $data)) {
            return 'url';
        }
        
        return 'unknown';
    }
    
    /**
     * Public method to track QR click events
     * Called when a QR code is clicked/scanned
     * 
     * @param string $data The QR code data
     * @param array $context Additional context (post_id, etc.)
     * @return void
     */
    public function trackQRClick(string $data, array $context = []): void
    {
        $this->trackQRGeneration('click', $data, $context);
    }
    
    /**
     * Public method to track QR scan events
     * Called when a QR code is actually scanned (e.g., via mobile device)
     * 
     * @param string $data The QR code data
     * @param array $context Additional context (post_id, etc.)
     * @return void
     */
    public function trackQRScan(string $data, array $context = []): void
    {
        $this->trackQRGeneration('scan', $data, $context);
    }
}