<?php
namespace WalletUpPro\Services;

use WalletUpPro\Core\Error\ErrorHandler;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
class ChartService
{
    private static $instance = null;
    private $errorHandler;
    private static $optionName = 'wallet_up_qr_status';

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
        $this->initialize();
    }
    public static function check_qr_service(): array
    {
        $has_gd = extension_loaded('gd');
        $has_imagick = extension_loaded('imagick');

        return [
            'qr_available' => $has_gd || $has_imagick,
            'gd_available' => $has_gd,  // New structure
            'imagick_available' => $has_imagick, // New structure
            'gd' => $has_gd,  // Legacy structure
            'imagick' => $has_imagick // Legacy structure
        ];
    }

    public function initialize(): void
    {
        $this->registerHooks();
        $this->verifyQRCapabilities();
    }

    private function registerHooks(): void
    {
        add_action('init', [$this, 'verifyQRCapabilities']);
    }

    public function verifyQRCapabilities(): void
    {
        $status = self::check_qr_service();
        if (!$status['qr_available']) {
            $this->errorHandler->log('WARNING: Neither GD nor ImageMagick extension is available');
        }

        $status = get_option(self::$optionName, []);
        if (empty($status) || (time() - ($status['last_check'] ?? 0)) > 3600) {
            $gd_available = $this->checkGDSupport();
            $imagick_available = $this->checkImagickSupport();

            $newStatus = [
                'qr_available' => $gd_available || $imagick_available,
                'gd_available' => $gd_available,
                'imagick_available' => $imagick_available,
                'last_check' => time()
            ];

            if (empty($status) || $status['qr_available'] !== $newStatus['qr_available']) {
                update_option(self::$optionName, $newStatus);
            }

            if (!$newStatus['qr_available']) {
                if (!defined('WALLET_UP_QR_CODE_API')) {
                    define('WALLET_UP_QR_CODE_API', true);
                    define('WALLET_UP_QR_CODE_EXTERNAL_API_FALLBACK', 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&ecc=L&margin=0&data=');
                }
            } else {
                if (!defined('WALLET_UP_QR_CODE_API')) {
                    define('WALLET_UP_QR_CODE_API', false);
                }
            }
        }
    }

    private function checkGDSupport(): bool
    {
        try {
            if (!extension_loaded('gd')) {
                $this->errorHandler->log('GD extension not loaded');
                return false;
            }

            $gd_info = gd_info();
            if (empty($gd_info)) {
                $this->errorHandler->log('GD info not available');
                return false;
            }

            $required_functions = ['imagecreate', 'imagepng', 'imagedestroy'];
            foreach ($required_functions as $function) {
                if (!function_exists($function)) {
                    $this->errorHandler->log("Required GD function missing: {$function}");
                    return false;
                }
            }

            return true;
        } catch (\Exception $e) {
            $this->errorHandler->log('GD check failed: ' . $e->getMessage());
            return false;
        }
    }

    private function checkImagickSupport(): bool
    {
        try {
            if (!extension_loaded('imagick') || !class_exists('Imagick')) {
                return false;
            }
            return true;
        } catch (\Exception $e) {
            $this->errorHandler->log('ImageMagick check failed: ' . $e->getMessage());
            return false;
        }
    }

    public function getQRStatus(): array
    {
        return get_option(self::$optionName, [
            'qr_available' => false,
            'gd_available' => false,
            'imagick_available' => false
        ]);
    }

    public function handleQRStatusCheck(): void
    {
        try {
            if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
                throw new \Exception(esc_html('Invalid security token'));
            }

            if (!current_user_can('manage_options')) {
                throw new \Exception(esc_html('Permission denied'));
            }

            $status = $this->getQRStatus();

            if (!$status || (time() - ($status['last_check'] ?? 0)) > 3600) {
                $this->verifyQRCapabilities();
                $status = $this->getQRStatus();
            }

            wp_send_json_success($status);
        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    public function displayAdminNotices(): void
    {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, ['plugins', 'toplevel_page_wallet-up'])) {
            return;
        }

        $status = $this->getQRStatus();
        if (!$status['qr_available']) {
            printf(
                '<div class="notice notice-warning"><p>%s</p><p>%s</p></div>',
                esc_html__('Warning: Wallet Up requires either the GD or ImageMagick PHP extension for QR code generation.', 'walletup'),
                esc_html__('Please contact your hosting provider to enable one of these extensions.', 'walletup')
            );
        }
    }
}