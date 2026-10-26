<?php
namespace WalletUpPro\Payments;

use WalletUpPro\Core\Database\DatabaseManager;
use WalletUpPro\Core\Display\DisplayHandler;
use WalletUpPro\Core\Analytics\AnalyticsHandler;
use WalletUpPro\Core\Error\ErrorHandler;
use Exception;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

abstract class PaymentMethod
{
    /** @var string Payment method identifier */
    protected $methodId;

    /** @var array Payment settings */
    protected $settings;

    /** @var array Display settings */
    protected $displaySettings;

    /** @var DisplayHandler */
    protected $displayHandler;

    /** @var DatabaseManager */
    protected $db;

    /** @var AnalyticsHandler */
    protected $analytics;

    /** @var ErrorHandler */
    protected $errorHandler;

    /** @var array Cache for logo data */
    protected static $logoCache = [];

    /** @var array Error messages */
    protected const ERROR_MESSAGES = [
        'holder_missing' => 'Error: %s holder not configured.',
        'tracking_error' => 'Error tracking usage: %s',
        'invalid_token' => 'Invalid security token',
        'display_error' => 'Error generating display: %s',
        'logo_error' => 'Error processing logo: %s'
    ];

    /**
     * Constructor
     * 
     * @param string $methodId Payment method identifier
     * @param DatabaseManager|null $db Database manager instance (for injection)
     * @throws Exception If initialization fails
     */
    public function __construct(string $methodId, ?DatabaseManager $db = null)
    {
        $this->methodId = $methodId;
        $this->db = $db ?? DatabaseManager::getInstance();

        $this->initializeServices();
        $this->loadSettings();
        $this->registerHooks();
    }

    protected function initializeServices(): void
    {
        try {
            $this->errorHandler = ErrorHandler::getInstance();
            $this->displayHandler = DisplayHandler::getInstance();
            if (class_exists(AnalyticsHandler::class)) {
                $this->analytics = AnalyticsHandler::getInstance();
            }

        } catch (Exception $e) {
            if (isset($this->errorHandler)) {
                $this->errorHandler->log('Service initialization failed: ' . $e->getMessage());
            }
            throw $e;
        }
    }

    protected function loadSettings(): void
    {
        try {
            $this->settings = get_option('wallet_up_transfer_call', []);
            $this->displaySettings = get_option('wallet_up_display_settings', [
                'defaultMode' => 'qr',
                'qrSize' => 'medium',
                'qrColor' => '#674FBF',
                'qrBackground' => '#FFFFFF',
                'qrBorder' => false,
                'qrStyle' => 'square',
                'showDefaultMessage' => true,
                'customMessage' => '',
                'hideMessage' => false,
                'showLogo' => true,
                'showMethodLogo' => true,
                'showAccentBar' => true,
                'showPoweredBy' => true
            ]);

        } catch (Exception $e) {
            $this->errorHandler->log('Failed to load settings: ' . $e->getMessage());
            throw $e;
        }
    }
    public function getPublicMethodLogo(): ?string
    {
        return $this->getMethodLogo();
    }
    protected function getMethodLogo(): ?string
    {
        if (isset(self::$logoCache[$this->methodId])) {
            return self::$logoCache[$this->methodId];
        }

        foreach (['png' => 'image/png', 'svg' => 'image/svg+xml'] as $ext => $mime) {
            $logoPath = WALLET_UP_PATH . 'assets/images/' . $this->methodId . '-walup.' . $ext;
            if (!file_exists($logoPath)) {
                continue;
            }
            try {
                $logoData = file_get_contents($logoPath);
                if ($logoData !== false) {
                    self::$logoCache[$this->methodId] = 'data:' . $mime . ';base64,' . base64_encode($logoData);
                    return self::$logoCache[$this->methodId];
                }
            } catch (Exception $e) {
                $this->errorHandler->log('Logo loading error: ' . $e->getMessage());
            }
        }

        return null;
    }

    public function isEnabled(): bool
    {
        $optionName = "wallet_up_{$this->methodId}_enabled";
        return get_option($optionName, 'yes') === 'yes';
    }

    public function processShortcode($atts): string
    {
        try {
            $this->errorHandler->log('Processing shortcode for ' . $this->methodId);

            $processedAtts = $this->processAttributes($atts);
            $holder = $this->getHolder();

            if (empty($holder)) {
                throw new Exception(esc_html(sprintf(
                    /* translators: %s: Payment method name */
                    __('Payment method %s is not configured. Please set up your account details.', 'walletup'),
                    ucfirst($this->methodId)
                )));
            }

            $paymentUrl = $this->generatePaymentUrl(
                $processedAtts['amount'],
                $processedAtts['note'] ?? ''
            );

            $logo = null;
            if ($this->displaySettings['showLogo'] ?? true) {
                $logo = $this->getMethodLogo();
            }

            $displaySettings = array_merge($this->displaySettings, ['logo' => $logo]);

            $output = $this->displayHandler->generateDisplay(
                $processedAtts['display_mode'],
                $paymentUrl,
                $this->methodId,
                $displaySettings,
                array_merge($processedAtts, [
                    'logo' => $logo,
                    'post_id' => get_the_ID() ?: 0,
                    'post_type' => get_post_type() ?: 'unknown',
                    'post_title' => get_the_title() ?: 'Unknown'
                ])
            );

            return $output;

        } catch (Exception $e) {
            $this->errorHandler->log('Shortcode error: ' . $e->getMessage());
            return $this->formatError('display_error', $e->getMessage());
        }
    }
    protected function processAttributes($atts): array
    {
        if (!is_array($atts)) {
            $atts = [];
        }

        $defaults = [
            'scan' => $this->displaySettings['defaultMode'] === 'qr' ? 'yes' : 'no',
            'amount' => '',
            'note' => '',
            'size' => $this->displaySettings['qrSize'] ?? 'medium',
            'show_logo' => $this->displaySettings['showLogo'] ?? true
        ];

        $atts = shortcode_atts($defaults, $atts);

        $display_mode = $atts['scan'] === 'yes' ? 'qr' :
            ($atts['scan'] === 'link' ? 'link' :
                ($atts['scan'] === 'no' ? 'button' :
                    $this->displaySettings['defaultMode'] ?? 'qr'));

        return [
            'display_mode' => $display_mode,
            'amount' => sanitize_text_field($atts['amount']),
            'note' => isset($atts['note']) ? sanitize_text_field($atts['note']) : '',
            'size' => sanitize_text_field($atts['size']),
            'show_logo' => filter_var($atts['show_logo'], FILTER_VALIDATE_BOOLEAN)
        ];
    }

    protected function registerHooks(): void
    {
        try {
            add_shortcode($this->methodId, [$this, 'processShortcode']);

            $ajaxAction = "wallet_up_track_usage_{$this->methodId}";
            add_action("wp_ajax_{$ajaxAction}", [$this, 'handleTracking']);
            add_action("wp_ajax_nopriv_{$ajaxAction}", [$this, 'handleTracking']);

            add_filter("wallet_up_{$this->methodId}_logo", [$this, 'filterMethodLogo'], 10, 1);

        } catch (Exception $e) {
            $this->errorHandler->log('Failed to register hooks: ' . $e->getMessage());
            throw $e;
        }
    }

    public function filterMethodLogo(?string $logo): ?string
    {
        return apply_filters("wallet_up_{$this->methodId}_custom_logo", $logo);
    }

    public function handleTracking(): void
    {
        try {
            if (!check_ajax_referer("wallet_up_track_usage_{$this->methodId}", 'nonce', false)) {
                throw new Exception(esc_html(__('Invalid security token', 'walletup')));
            }

            $trackingData = [
                'method' => $this->methodId,
                'type' => sanitize_text_field(wp_unslash($_POST['type'] ?? '')),
                'post_id' => get_the_ID(),
                'user_id' => get_current_user_id(),
            ];

            if ($this->analytics) {
                $this->analytics->trackUsage($trackingData);
            }
            wp_send_json_success(['message' => __('Tracking recorded', 'walletup')]);

        } catch (Exception $e) {
            wp_send_json_error([
                /* translators: %s: Error message */
                'message' => sprintf(__('Error tracking usage: %s', 'walletup'), $e->getMessage())
            ]);
        }
    }

    public function getHolder(): string
    {
        $key = "{$this->methodId}_holder";
        return $this->settings[$key] ?? '';
    }

    protected function formatError(string $key, string ...$args): string
    {
        $message = self::ERROR_MESSAGES[$key] ?? __('Unknown error occurred', 'walletup');

        $contact_html = '';
        $profile = get_option('wallet_up_transfer_call', []);
        $merchant_name = $profile['holder_owner'] ?? '';
        $merchant_email = $profile['holder_email'] ?? '';
        $merchant_phone = $profile['holder_no'] ?? '';

        if (!empty($merchant_email) || !empty($merchant_phone)) {
            $contact_parts = [];
            if (!empty($merchant_name)) {
                $contact_parts[] = sprintf(
                    '<strong>%s</strong>',
                    esc_html($merchant_name)
                );
            }
            if (!empty($merchant_email)) {
                $contact_parts[] = sprintf(
                    '<a href="mailto:%s">%s</a>',
                    esc_attr($merchant_email),
                    esc_html($merchant_email)
                );
            }
            if (!empty($merchant_phone)) {
                $contact_parts[] = sprintf(
                    '<a href="tel:%s">%s</a>',
                    esc_attr($merchant_phone),
                    esc_html($merchant_phone)
                );
            }
            $contact_html = sprintf(
                '<p style="margin-top:8px;font-size:13px;color:#6b7280;">%s %s</p>',
                esc_html__('Need help? Contact:', 'walletup'),
                implode(' &middot; ', $contact_parts)
            );
        }

        return sprintf(
            '<div class="wallet-up-error">
                <p>%s</p>
                %s
            </div>',
            esc_html(vsprintf($message, $args)),
            $contact_html
        );
    }

    /**
     * Public method to safely get the payment URL.
     *
     * @param string $amount Payment amount.
     * @param string|null $note Optional payment note.
     * @return string Payment URL.
     * @throws Exception If URL generation fails.
     */
    public function getPaymentUrl(string $amount, string $note = ''): string
    {
        try {
            return $this->generatePaymentUrl($amount, $note);
        } catch (Exception $e) {
            $this->errorHandler->log("Failed to generate payment URL for {$this->methodId}: " . $e->getMessage());
            throw $e;
        }
    }

    abstract protected function generatePaymentUrl(string $amount, string $note = ''): string;

    public function hasNativeLink(): bool
    {
        return true;
    }

    abstract public function getTitle(): string;

    public function isCryptocurrency(): bool
    {
        if (class_exists(\WalletUpPro\Core\Config\PaymentMethodsConfig::class)) {
            return \WalletUpPro\Core\Config\PaymentMethodsConfig::isCryptocurrency($this->methodId);
        }
        return isset(\WalletUpPro\Core\Crypto\CryptoConverter::supportedCryptos()[$this->methodId]);
    }

    public function getCurrencySymbol(): string
    {
        if (class_exists(\WalletUpPro\Core\Crypto\CryptoConverter::class)) {
            return \WalletUpPro\Core\Crypto\CryptoConverter::supportedCryptos()[$this->methodId] ?? '$';
        }
        return '$';
    }
    
    public function formatAmountForDisplay(string $amount): string
    {
        if ($this->isCryptocurrency()) {
            return sprintf('%s %s', $amount, $this->getCurrencySymbol());
        }
        return sprintf('$%s', $amount);
    }

    abstract public function getDescription(): string;
}