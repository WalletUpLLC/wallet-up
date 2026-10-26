<?php
namespace WalletUpPro\Core\Display;

use WalletUpPro\Services\QRCodeService;
use WalletUpPro\Core\Error\ErrorHandler;
use Exception;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class DisplayHandler
{
    /** @var DisplayHandler|null */
    private static $instance = null;

    /** @var QRCodeService */
    private $qrService;

    /** @var ErrorHandler */
    private $errorHandler;

    /** @var array QR size dimensions */
    private const QR_DIMENSIONS = [
        'small' => 150,
        'medium' => 180,
        'large' => 250
    ];

    /** @var array Error messages */
    private const ERROR_MESSAGES = [
        'qr_generation' => 'Error: QR Code generation failed. %s',
        'display_error' => 'Error generating display: %s'
    ];

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
    }

    private function qr(): QRCodeService
    {
        if (!$this->qrService instanceof QRCodeService) {
            try {
                $this->qrService = QRCodeService::getInstance();
                $status = $this->qrService->getStatus();
                if (empty($status['library_loaded'])) {
                    throw new Exception(esc_html('QR Code library not loaded'));
                }
            } catch (Exception $e) {
                $this->errorHandler->log('Display Handler QR service error: ' . $e->getMessage());
                throw $e;
            }
        }
        return $this->qrService;
    }

    public function generateDisplay(
        string $mode,
        string $url,
        string $method,
        array $settings,
        array $data = []
    ): string {
        try {

            $post_id = $data['post_id'] ?? get_the_ID() ?: 0;
            $tracking_attr = sprintf('data-wallet-up-method="%s" data-wallet-up-post="%d"', esc_attr($method), intval($post_id));

            $blocks = ['payment' => '', 'reference' => '', 'zelle' => '', 'form' => ''];

            $customQrImage = $this->getCustomQRImage($method);
            if ($customQrImage !== '') {
                $blocks['payment'] = $this->generateQRCode('', $data['amount'] ?? '', $settings, $method, $tracking_attr, null, $customQrImage);
            } elseif (trim($url) === '' || !\WalletUpPro\Core\PaymentRegistry::hasNativeLinkFor($method)) {
                $payload = apply_filters('walletup_suppress_qr', false, $method)
                    ? ''
                    : \WalletUpPro\Core\PaymentRegistry::qrPayloadFor(
                        $method,
                        '',
                        $this->methodHolder($method),
                        (string) ($data['amount'] ?? ''),
                        (string) ($data['note'] ?? ($data['order_id'] ?? ''))
                    );
                $blocks['payment'] = $payload === ''
                    ? ''
                    : $this->generateQRCode($payload, $data['amount'] ?? '', $settings, $method, $tracking_attr, $url);
                $blocks['payment'] .= $this->generatePayToInstructions($method, $settings, $data);
            } else {
                switch ($mode) {
                    case 'qr':
                        $blocks['payment'] = $this->generateQRCode($url, $data['amount'] ?? '', $settings, $method, $tracking_attr);
                        break;
                    case 'button':
                        $blocks['payment'] = $this->generateButton($url, $data['amount'] ?? '', $method, $settings, $tracking_attr);
                        break;
                    default:
                        $blocks['payment'] = $this->generateLink($url, $data['amount'] ?? '', $method, $settings, $tracking_attr);
                        break;
                }

                $blocks['payment'] .= $this->generateCryptoAddress($method);
            }

            $blocks['reference'] = $this->generateOrderReferenceNote($method, $data);

            $blocks['zelle'] = $this->generateZelleHelper($method);

            $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
            $isBot = $ua !== '' && preg_match('/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|headless|monitor|pingdom|uptime/i', $ua);
            $nonVisitor = $isBot || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST);
            if (self::$previewOverride === null && empty($data['skip_impression']) && !$nonVisitor && class_exists(\WalletUpPro\Core\Analytics\AnalyticsHandler::class)) {
                try {
                    $analytics = \WalletUpPro\Core\Analytics\AnalyticsHandler::getInstance();
                    $analytics->recordImpression($method, intval($post_id), $mode, $data['post_title'] ?? '');
                } catch (\Exception $e) {
                }
            }

            if ($this->confirmFormApplies($data)) {
                $wcOrderId = intval($data['order_id'] ?? 0);
                $formHtml = $this->generateConfirmationForm($method, $data['amount'] ?? '', intval($post_id), $wcOrderId);
                $blocks['form'] = $formHtml['html'];
                if (!empty($formHtml['js'])) {
                    $js = $formHtml['js'];
                    add_action('wp_footer', function () use ($js) {
                        echo '<script>' . $js . '</script>';
                    }, 99);
                }
            }

            return $this->composeConfirmationBlocks($blocks);
        } catch (Exception $e) {
            $this->errorHandler->log('Display generation error: ' . $e->getMessage());
            return $this->formatError('display_error', $e->getMessage());
        }
    }

    public const CONFIRMATION_BLOCKS = ['payment', 'reference', 'zelle', 'form'];

    public static function methodDisplayName(string $methodId): string
    {
        if (class_exists(\WalletUpPro\Core\Config\PaymentMethodsConfig::class)) {
            $name = trim(\WalletUpPro\Core\Config\PaymentMethodsConfig::getMethodName($methodId));
            if ($name !== '') {
                return $name;
            }
        }
        return ucfirst(str_replace('_', ' ', $methodId));
    }

    public static function methodBrandColor(string $methodId): string
    {
        if (class_exists(\WalletUpPro\Core\Config\PaymentMethodsConfig::class)) {
            return \WalletUpPro\Core\Config\PaymentMethodsConfig::getBrandColor($methodId);
        }
        return '#674FBF';
    }

    public static function methodLogoUrl(string $methodId): string
    {
        $base = defined('WALLET_UP_PRO_URL') ? WALLET_UP_PRO_URL : plugin_dir_url(dirname(__DIR__, 2) . '/wallet-up.php');
        $candidates = [$methodId];
        if (substr($methodId, -4) === '_api') {
            $candidates[] = substr($methodId, 0, -4);
        }
        if (strpos($methodId, '_') !== false) {
            $candidates[] = strstr($methodId, '_', true);
        }
        foreach (array_unique($candidates) as $id) {
            foreach (['png', 'svg'] as $ext) {
                if (file_exists(WALLET_UP_PATH . 'assets/images/' . $id . '-walup.' . $ext)) {
                    return $base . 'assets/images/' . $id . '-walup.' . $ext;
                }
            }
        }
        return '';
    }

    /**
     * Set to a truthy array while the admin preview generates a demo display, so
     * impression tracking is skipped. Null in normal flow.
     * @var array|null
     */
    public static $previewOverride = null;

    private function composeConfirmationBlocks(array $blocks): string
    {
        $out = '';
        foreach (self::CONFIRMATION_BLOCKS as $key) {
            $out .= $blocks[$key] ?? '';
        }
        return $out;
    }

    private function generateQRCode($url, $amount, $settings, $method, $tracking_attr, $linkUrl = null, $customImageUrl = '')
    {
        try {
            $border_style = $settings['qrBorder'] ?? $settings['border'] ?? false;
            $bg_color = $this->resolveQrBackground($settings);
            $qr_style_type = $settings['qrStyle'] ?? $settings['style'] ?? 'square';
            $qr_size = $settings['qrSize'] ?? $settings['size'] ?? 'medium';
            $qr_size_px = self::QR_DIMENSIONS[$qr_size] ?? 180;

            $post_id = 0;
            if (preg_match('/data-wallet-up-post="(\d+)"/', $tracking_attr, $m)) {
                $post_id = (int)$m[1];
            }

            $qr_source_args = [
                'qrSize' => $qr_size,
                'size' => $qr_size,
                'qrColor' => $settings['qrColor'] ?? $settings['color'] ?? '#674FBF',
                'color' => $settings['qrColor'] ?? $settings['color'] ?? '#674FBF',
                'qrBackground' => $bg_color,
                'background' => $bg_color,
                'qrBorder' => $border_style,
                'border' => $border_style,
                'qrStyle' => $qr_style_type,
                'style' => $qr_style_type,
                'payment_method' => $method,
                'post_id' => $post_id,
            ];

            $vsrc = $customImageUrl !== '' ? $customImageUrl : $this->generateQRSource($url, $qr_source_args);

            if ($customImageUrl === '' && empty($vsrc)) {
                throw new Exception(esc_html('Failed to generate QR code source'));
            }

            $container_style = "padding: 10px; width: -webkit-fit-content; width: fit-content; max-width: 100%; margin-left: auto; margin-right: auto; text-align: center;";

            $container_radius = $image_radius = $border_css = '';
            switch ($qr_style_type) {
                case 'rounded':
                    $container_radius = '26px';
                    $image_radius = '12px';
                    $border_css = $border_style ? 'border: 1px solid #ddd;' : '';
                    break;
                case 'dot':
                    $container_radius = '16px';
                    $image_radius = '8px';
                    $border_css = $border_style ? 'border: 2px dotted #ddd; border-spacing: 2px;' : '';
                    break;
                default:
                    $container_radius = '4px';
                    $image_radius = '2px';
                    $border_css = $border_style ? 'border: 1px solid #ddd;' : '';
                    break;
            }

            $qr_container_style = sprintf(
                "background: %s; border-radius: %s; padding: 16px; %s box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); display: inline-block; overflow: hidden; %s",
                $settings['showBackground'] ?? true ? esc_attr($bg_color) : 'transparent',
                $container_radius,
                $qr_style_type === 'dot' ? 'position: relative;' : '',
                $border_css
            );

            $dot_style = '';
            if ($qr_style_type === 'dot' && $border_style) {
                $dot_style = sprintf(
                    '<style>
                        .walletup-content::before {
                            content: "";
                            position: absolute;
                            top: 8px;
                            left: 8px;
                            right: 8px;
                            bottom: 8px;
                            border: 2px dotted #ddd;
                            border-radius: 8px;
                            pointer-events: none;
                        }
                    </style>'
                );
            }

            $qr_style = sprintf(
                "display: block !important; margin: 10px auto !important; height: %spx !important; width: %spx !important; min-width: %spx !important; min-height: %spx !important; max-width: none !important; border-radius: %s; %s %s",
                $qr_size_px,
                $qr_size_px,
                $qr_size_px,
                $qr_size_px,
                $image_radius,
                $qr_style_type === 'dot' ? 'position: relative; z-index: 1;' : '',
                $customImageUrl !== '' ? 'object-fit: contain !important;' : ''
            );

            $message = $this->generateMessage($amount, $method, [
                'hideMessage' => $settings['hideMessage'] ?? $settings['hide_message'] ?? false,
                'showDefaultMessage' => $settings['showDefaultMessage'] ?? $settings['show_default_message'] ?? true,
                'customMessage' => $settings['customMessage'] ?? $settings['custom_message'] ?? '',
                'qrSize' => $qr_size,
                'size' => $qr_size,
                'holder' => $settings['holder'] ?? ''
            ], ($linkUrl === null ? $url : $linkUrl) === '' ? 'scan' : 'qr');

            $show_method_logo = $settings['showMethodLogo'] ?? $settings['show_method_logo'] ?? true;
            $show_accent_bar = $settings['showAccentBar'] ?? $settings['show_accent_bar'] ?? true;
            $show_powered_by = $settings['showPoweredBy'] ?? $settings['show_powered_by'] ?? true;

            $accent_color = self::methodBrandColor($method);
            $logo_file    = self::methodLogoUrl($method);
            $method_name  = self::methodDisplayName($method);

            $logo_html = '';
            if ($show_method_logo && $logo_file !== '') {
                $logo_html = sprintf(
                    '<div style="text-align:center;margin-bottom:8px;"><img src="%s" alt="%s" style="height:28px;width:auto;display:inline-block;border-radius:6px;" onerror="this.style.display=\'none\'"></div>',
                    esc_url($logo_file),
                    esc_attr($method_name)
                );
            }

            $accent_bar = '';
            if ($show_accent_bar) {
                $accent_bar = sprintf(
                    '<div style="height:4px;background:%s;margin:-16px -16px 12px -16px;"></div>',
                    esc_attr($accent_color)
                );
            }

            $powered_by = '';
            if ($show_powered_by) {
                $powered_by = self::poweredByMarkup();
            }

            $href = $linkUrl === null ? $url : $linkUrl;
            $openTag = $href === ''
                ? sprintf('<div class="walletup-qr-link" data-walletup-link="%s" %s>', esc_attr($method), $tracking_attr)
                : sprintf('<a href="%s" target="_blank" rel="noopener" class="walletup-qr-link" data-walletup-link="%s" %s style="text-decoration:none !important;">', $href, $method, $tracking_attr);
            $closeTag = $href === '' ? '</div>' : '</a>';

            return sprintf(
                '%s
                %s
                    <div class="walletup-container" style="%s">
                        <div class="walletup-content" style="%s">
                            %s
                            %s
                            %s
                            <img style="%s"
                                alt="%s QR Code" src="%s" data-walletup-qr="%s">
                            %s
                        </div>
                    </div>
                %s',
                $dot_style,
                $openTag,
                $container_style,
                $qr_container_style,
                $accent_bar,
                $logo_html,
                $message,
                $qr_style,
                esc_attr($method_name),
                $vsrc,
                $method,
                $powered_by,
                $closeTag
            );

        } catch (Exception $e) {
            $this->errorHandler->log('QR Generation Error: ' . $e->getMessage());
            return $this->formatError('qr_generation', $e->getMessage());
        }
    }

    private function generateQRSource($payment_url, $display_settings)
    {
        try {
            $qr_size = self::QR_DIMENSIONS[$display_settings['qrSize'] ?? $display_settings['size'] ?? 'medium'] ?? 180;

            $qr_settings = [
                'qrSize' => $qr_size,
                'qrColor' => $display_settings['qrColor'] ?? $display_settings['color'] ?? '#674FBF',
                'qrBackground' => $display_settings['qrBackground'] ?? $display_settings['background'] ?? '#FFFFFF',
            ];

            if (!empty($display_settings['payment_method'])) {
                $qr_settings['payment_method'] = $display_settings['payment_method'];
            }
            if (!empty($display_settings['post_id'])) {
                $qr_settings['post_id'] = $display_settings['post_id'];
            }

            $this->errorHandler->log("QR Code generation starting for URL: " . $payment_url);

            $qrDataUri = $this->qr()->generateQR($payment_url, $qr_settings);
            
            if (empty($qrDataUri)) {
                $this->errorHandler->log("ERROR: QR service returned empty data URI");
            } else {
            }
            
            return $qrDataUri;
        } catch (Exception $e) {
            $this->errorHandler->log('QR Source Generation Error: ' . $e->getMessage());
            throw $e;
        }
    }

    private function generateButton($url, $amount, $method, $settings, $tracking_attr)
    {
        try {
            $imgsrc = esc_url(WALLET_UP_URL . 'assets/images/' . $method . '-walup.png');
            $border_style = $settings['qrBorder'] ?? false;
            $bg_color = $settings['qrBackground'] ?? '#FFFFFF';
            $style_type = $settings['qrStyle'] ?? 'square';

            $container_radius = $image_radius = $border_css = '';
            switch ($style_type) {
                case 'rounded':
                    $container_radius = '26px';
                    $image_radius = '12px';
                    $border_css = $border_style ? 'border: 1px solid #ddd;' : '';
                    break;

                case 'dot':
                    $container_radius = '16px';
                    $image_radius = '8px';
                    $border_css = $border_style ? 'border: 2px dotted #ddd; border-spacing: 2px;' : '';
                    break;

                default: // square style
                    $container_radius = '4px';
                    $image_radius = '2px';
                    $border_css = $border_style ? 'border: 1px solid #ddd;' : '';
                    break;
            }

            $container_style = "padding: 10px; max-width: 200px; text-align: center;";

            $button_container_style = sprintf(
                "background: %s; border-radius: %s; padding: 16px; %s box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); display: inline-block; %s",
                esc_attr($bg_color),
                $container_radius,
                $style_type === 'dot' ? 'position: relative;' : '',
                $border_css
            );

            $dot_style = '';
            if ($style_type === 'dot' && $border_style) {
                $dot_style = sprintf(
                    '<style>
                    .walletup-button-content::before {
                        content: "";
                        position: absolute;
                        top: 8px;
                        left: 8px;
                        right: 8px;
                        bottom: 8px;
                        border: 2px dotted #ddd;
                        border-radius: 8px;
                        pointer-events: none;
                    }
                </style>'
                );
            }

            $button_style = sprintf(
                "display: block; margin: 10px auto; max-width: 150px; height: auto; border-radius: %s; %s",
                $image_radius,
                $style_type === 'dot' ? 'position: relative; z-index: 1;' : ''
            );

            return sprintf(
                '%s
                <a href="%s" target="_blank" rel="noopener" class="walletup-button-link" data-walletup-link="%s" %s>
                    <div class="walletup-button-container" style="%s">
                        <div class="walletup-button-content" style="%s">
                            %s
                            <img style="%s" alt="Pay with %s" src="%s">
                        </div>
                    </div>
                </a>',
                $dot_style,
                $url,
                $method,
                $tracking_attr, // For the link element
                $container_style,
                $button_container_style,
                $this->generateMessage($amount, $method, array_merge($settings, ['holder' => $settings['holder'] ?? '']), 'button'),
                $button_style,
                ucfirst($method),
                $imgsrc
            );
        } catch (Exception $e) {
            $this->errorHandler->log('Button Generation Error: ' . $e->getMessage());
            return $this->formatError('display_error', $e->getMessage());
        }
    }

    private function generateLink($url, $amount, $method, $settings, $tracking_attr)
    {
        try {
            $link_color = $settings['qrColor'] ?? '#674FBF';
            $bg_color = $settings['qrBackground'] ?? '#FFFFFF';
            $border_style = $settings['qrBorder'] ?? false;
            $style_type = $settings['qrStyle'] ?? 'square';

            $container_radius = $border_css = '';
            switch ($style_type) {
                case 'rounded':
                    $container_radius = '26px';
                    $border_css = $border_style ? 'border: 1px solid #ddd;' : '';
                    break;

                case 'dot':
                    $container_radius = '16px';
                    $border_css = $border_style ? 'border: 2px dotted #ddd; border-spacing: 2px;' : '';
                    break;

                default: // square style
                    $container_radius = '4px';
                    $border_css = $border_style ? 'border: 1px solid #ddd;' : '';
                    break;
            }

            $container_style = "text-align: center;";

            $link_container_style = sprintf(
                "background: %s; border-radius: %s; padding: 16px; %s box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); display: inline-block; %s",
                esc_attr($bg_color),
                $container_radius,
                $style_type === 'dot' ? 'position: relative;' : '',
                $border_css
            );

            $dot_style = '';
            if ($style_type === 'dot' && $border_style) {
                $dot_style = sprintf(
                    '<style>
                        .walletup-link-content::before {
                            content: "";
                            position: absolute;
                            top: 8px;
                            left: 8px;
                            right: 8px;
                            bottom: 8px;
                            border: 2px dotted #ddd;
                            border-radius: 8px;
                            pointer-events: none;
                        }
                    </style>'
                );
            }

            return sprintf(
                '%s
                <a href="%s" target="_blank" rel="noopener" class="walletup-link-wrapper"
                    data-walletup-link="%s" %s style="color: %s; text-decoration: none;">
                    <div class="walletup-link-container" style="%s">
                        <div class="walletup-link-content" style="%s">
                            %s
                            <div style="margin-top: 8px;">Pay with %s</div>
                        </div>
                    </div>
                </a>',
                $dot_style,
                $url,
                $method,
                $tracking_attr, // For the link element
                $link_color,
                $link_container_style,
                $container_style,
                $this->generateMessage($amount, $method, array_merge($settings, ['holder' => $settings['holder'] ?? '']), 'link'),
                ucfirst($method)
            );
        } catch (Exception $e) {
            $this->errorHandler->log('Link Generation Error: ' . $e->getMessage());
            return $this->formatError('display_error', $e->getMessage());
        }
    }

    /**
     * Generate "I've Sent My Payment" confirmation form.
     * For WC orders: auto-fills from billing, hides name/email, passes order_id.
     * For non-WC: shows input fields for customer info.
     *
     * @return array{html: string, js: string}
     */
    private function generateConfirmationForm(string $method, string $amount, int $postId, int $wcOrderId = 0): array
    {
        $nonce = wp_create_nonce('walletup_confirm_payment');
        $ajaxUrl = admin_url('admin-ajax.php');
        $methodName = ucfirst(str_replace('_', ' ', $method));
        $formId = 'walletup-confirm-' . wp_rand(1000, 9999);

        $isWcOrder = $wcOrderId > 0;
        $nameEmailFields = '';
        if (!$isWcOrder) {
            $nameEmailFields = sprintf(
                '<input type="text" id="%1$s-name" placeholder="%2$s" style="width:100%%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;margin-bottom:8px;box-sizing:border-box" />
                 <input type="email" id="%1$s-email" placeholder="%3$s" style="width:100%%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;margin-bottom:8px;box-sizing:border-box" />',
                esc_attr($formId),
                esc_attr__('Your Name', 'walletup'),
                esc_attr__('Your Email', 'walletup')
            );
        }

        $html = sprintf(
            '<div class="walletup-confirm-wrapper" style="margin-top:16px;text-align:center">
                <button type="button" id="%1$s-toggle"
                    style="background:none;border:1px solid #d1d5db;border-radius:8px;padding:8px 20px;font-size:13px;color:#4b5563;cursor:pointer;font-weight:500">
                    %2$s
                </button>
                <div id="%1$s" style="display:none;margin-top:12px;text-align:left;max-width:360px;margin-left:auto;margin-right:auto;padding:16px;border:1px solid #e5e7eb;border-radius:12px;background:#fafafa">
                    <p style="font-size:13px;color:#374151;margin:0 0 12px;font-weight:600">%3$s</p>
                    %8$s
                    <input type="text" id="%1$s-ref" placeholder="%4$s" style="width:100%%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;margin-bottom:12px;box-sizing:border-box" />
                    <button type="button" id="%1$s-btn" style="width:100%%;padding:10px;background:#6366f1;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer">
                        %5$s
                    </button>
                    <div id="%1$s-msg" style="margin-top:8px;font-size:12px;display:none"></div>
                </div>
            </div>',
            esc_attr($formId),
            esc_html__("I've Sent My Payment", 'walletup'),
            esc_html(sprintf(__('Confirm your %s payment', 'walletup'), $methodName)),
            esc_attr(sprintf(__('Transaction ID / Reference (%s)', 'walletup'), $methodName)),
            esc_html__('Confirm Payment Sent', 'walletup'),
            '', '', '',
            $nameEmailFields
        );

        $confirmLabel = esc_js(__('Confirm Payment Sent', 'walletup'));
        $js = sprintf(
            '(function(){var f="%1$s";var isWc=%8$s;var tgl=document.getElementById(f+"-toggle");var frm=document.getElementById(f);if(tgl&&frm){tgl.addEventListener("click",function(){frm.style.display=frm.style.display==="none"?"block":"none"})}var btn=document.getElementById(f+"-btn");if(!btn)return;btn.addEventListener("click",function(){var b=this,m=document.getElementById(f+"-msg");var nEl=document.getElementById(f+"-name");var eEl=document.getElementById(f+"-email");var n=nEl?nEl.value:"";var e=eEl?eEl.value:"";var r=document.getElementById(f+"-ref")?document.getElementById(f+"-ref").value:"";if(!isWc&&nEl&&eEl&&!n&&!e){m.style.display="block";m.style.color="#dc2626";m.textContent="Please enter your name or email.";return}b.disabled=true;b.textContent="Sending...";var fd=new FormData();fd.append("action","walletup_confirm_payment_sent");fd.append("nonce","%2$s");fd.append("payment_method","%3$s");fd.append("amount","%4$s");fd.append("customer_name",n);fd.append("customer_email",e);fd.append("transaction_reference",r);fd.append("post_id","%5$s");fd.append("wc_order_id","%9$s");fetch("%6$s",{method:"POST",body:fd}).then(function(r){return r.json()}).then(function(d){m.style.display="block";if(d.success){m.style.color="#059669";m.textContent=d.data.message||"Confirmation received!";b.textContent="Sent!";tgl.style.display="none"}else{m.style.color="#dc2626";m.textContent=d.data&&d.data.message?d.data.message:"Failed.";b.disabled=false;b.textContent="%7$s"}}).catch(function(){m.style.display="block";m.style.color="#dc2626";m.textContent="Network error.";b.disabled=false;b.textContent="%7$s"})})})();',
            esc_js($formId),
            esc_js($nonce),
            esc_js($method),
            esc_js($amount),
            esc_js($postId),
            esc_url($ajaxUrl),
            $confirmLabel,
            $isWcOrder ? 'true' : 'false',
            esc_js($wcOrderId)
        );

        return ['html' => $html, 'js' => $js];
    }

    private function isCryptocurrency(string $method): bool
    {
        return \WalletUpPro\Core\Config\PaymentMethodsConfig::isCryptocurrency($method);
    }

    private function getCustomQRImage(string $method): string
    {
        if (!\WalletUpPro\Core\Config\PaymentMethodsConfig::allowsCustomQr($method)) {
            return '';
        }
        $images = get_option('wallet_up_custom_qr_images', []);
        if (!is_array($images)) {
            return '';
        }
        $url = trim((string) ($images[$method] ?? ''));
        return $url;
    }

    private function resolveQrBackground(array $settings): string
    {
        return $settings['qrBackground'] ?? $settings['background'] ?? '#FFFFFF';
    }

    private function generateOrderReferenceNote(string $method, array $data): string
    {
        if (empty($data['order_id']) || intval($data['order_id']) <= 0) {
            return '';
        }

        $wc = get_option('wallet_up_wc_settings', []);
        $enabled = !is_array($wc)
            || !array_key_exists('show_order_id_in_instructions', $wc)
            || !empty($wc['show_order_id_in_instructions']);
        if (!$enabled) {
            return '';
        }

        if ($this->isCryptocurrency($method)) {
            return '';
        }

        $order_ref = sprintf(
            /* translators: %s: order number, e.g. "Order #123" */
            esc_html__('Please include "%s" in your payment note', 'walletup'),
            esc_html(sprintf(
                /* translators: %s: numeric order id */
                __('Order #%s', 'walletup'),
                $data['order_id']
            ))
        );

        return sprintf(
            '<div class="walletup-order-note" style="text-align: center; margin-top: 8px; padding: 6px 12px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; font-size: 13px; color: #166534;">%s</div>',
            $order_ref
        );
    }

    private function methodHolder(string $method): string
    {
        $accounts = get_option('wallet_up_transfer_call', []);
        return is_array($accounts) ? trim((string) ($accounts[$method . '_holder'] ?? '')) : '';
    }

    private function confirmFormApplies(array $data): bool
    {
        if (empty($data['amount'])) {
            return false;
        }
        $isCheckout = ($data['skip_confirmation'] ?? false)
            || (function_exists('is_checkout') && is_checkout() && !is_wc_endpoint_url('order-received'))
            || (wp_doing_ajax() && isset($_POST['action']) && in_array(sanitize_text_field(wp_unslash($_POST['action'])), ['wallet_up_wc_payment_display', 'wallet_up_checkout_display'], true));
        if ($isCheckout) {
            return false;
        }
        return !empty($data['order_id']) && intval($data['order_id']) > 0;
    }

    public static function poweredByMarkup(string $url = 'https://walletup.app'): string
    {
        return sprintf(
            '<div style="text-align:center;margin-top:10px;padding-top:8px;border-top:1px solid rgba(0,0,0,0.06);">'
            . '<a href="%s" target="_blank" rel="noopener noreferrer" style="font-size:10px;color:#9CA3AF;'
            . 'text-decoration:none;font-family:-apple-system,BlinkMacSystemFont,sans-serif;letter-spacing:0.02em;">'
            . '%s <span style="color:#674FBF;font-weight:600;">WalletUp</span></a></div>',
            esc_url($url),
            esc_html__('Powered by', 'walletup')
        );
    }

    private function generatePayToInstructions(string $method, array $settings, array $data = []): string
    {
        $holder = $this->methodHolder($method);
        if ($holder === '') {
            return '';
        }

        $payeeLabel = \WalletUpPro\Core\Config\PaymentMethodsConfig::PAYMENT_METHODS[$method]['payee_label'] ?? '';
        $label = $payeeLabel !== ''
            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- values come from the method registry
            ? __($payeeLabel, 'walletup')
            /* translators: %s: payment method name, e.g. PayID */
            : sprintf(__('Pay to this %s', 'walletup'), self::methodDisplayName($method));

        $rows = $this->payToRow($label, $holder, true);

        $reference = trim((string) ($data['note'] ?? ($data['order_id'] ?? '')));
        if ($method === 'bpay') {
            // A Biller Code alone cannot be paid: BPAY requires a Customer Reference Number to
            $rows .= $reference !== ''
                ? $this->payToRow(__('Customer Reference Number', 'walletup'), $reference, true)
                : $this->payToNotice(__('This payment also needs a Customer Reference Number. Ask the merchant for yours before paying.', 'walletup'));
        } elseif ($reference !== '') {
            $rows .= $this->payToRow(__('Reference', 'walletup'), $reference, true);
        }

        $amount = trim((string) ($data['amount'] ?? ''));
        if ($amount !== '' && (float) $amount > 0) {
            $rows .= $this->payToRow(__('Amount', 'walletup'), $this->formatAmountForDisplay($amount, $method), false);
        }

        $closing = $this->confirmFormApplies($data)
            ? __('Open your banking app to send this payment, then confirm below.', 'walletup')
            : __('Open your banking app and send this payment using the details above.', 'walletup');

        return sprintf(
            '<div class="walletup-payto-instructions" style="max-width:280px;margin:0 auto;padding:14px;border:1px solid #e5e7eb;border-radius:12px;background:#fff;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;text-align:left;">'
                . '%1$s'
                . '<div style="font-size:12px;line-height:1.5;color:#374151;margin-top:10px;">%2$s</div>'
            . '</div>',
            $rows, // built from payToRow(), which escapes every part
            esc_html($closing)
        );
    }

    private function payToRow(string $label, string $value, bool $copyable): string
    {
        $copy = $copyable ? sprintf(
            '<button type="button" class="walletup-copy-address" data-walletup-copy="%1$s" data-copied-label="%2$s" aria-label="%3$s" style="display:block;width:100%%;margin-top:6px;cursor:pointer;border:1px solid #d1d5db;background:#f9fafb;color:#374151;font-size:13px;font-weight:600;border-radius:8px;padding:8px 10px;font-family:inherit;">%4$s</button>',
            esc_attr($value),
            esc_attr__('Copied', 'walletup'),
            /* translators: %s: the detail being copied, e.g. Biller Code */
            esc_attr(sprintf(__('Copy %s', 'walletup'), $label)),
            esc_html__('Copy', 'walletup')
        ) : '';

        return sprintf(
            '<div style="margin-bottom:10px;">'
                . '<div style="font-size:11px;font-weight:600;letter-spacing:0.04em;text-transform:uppercase;color:#6B7280;margin-bottom:6px;">%1$s</div>'
                . '<div style="font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:14px;line-height:1.5;color:#111827;word-break:break-all;background:#f9fafb;border:1px solid #f0f1f3;border-radius:8px;padding:10px;">%2$s</div>'
                . '%3$s'
            . '</div>',
            esc_html($label),
            esc_html($value),
            $copy
        );
    }

    private function payToNotice(string $text): string
    {
        return sprintf(
            '<div style="margin-bottom:10px;padding:10px;border-radius:8px;background:#fffbeb;border:1px solid #fde68a;color:#92400e;font-size:12px;line-height:1.5;">%s</div>',
            esc_html($text)
        );
    }

    private function generateZelleHelper(string $method): string
    {
        if ($method !== 'zelle') {
            return '';
        }
        $accounts = get_option('wallet_up_transfer_call', []);
        $handle = is_array($accounts) ? trim((string) ($accounts['zelle_holder'] ?? '')) : '';
        if ($handle === '') {
            return '';
        }

        if ((bool) get_option('wallet_up_zelle_hide_contact', false)) {
            return '<div class="walletup-zelle-help" style="max-width:280px;margin:10px auto 0;padding:10px 12px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;text-align:left;">'
                . '<div style="font-size:12px;line-height:1.5;color:#374151;">'
                . esc_html__('Open your bank app, choose Zelle, then Scan and point at the code above.', 'walletup')
                . '</div></div>';
        }

        return sprintf(
            '<div class="walletup-zelle-help" style="max-width:280px;margin:10px auto 0;padding:10px 12px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;text-align:left;">'
                . '<div style="font-size:12px;line-height:1.5;color:#374151;margin-bottom:8px;">%1$s</div>'
                . '<div style="font-size:11px;font-weight:600;letter-spacing:0.04em;text-transform:uppercase;color:#6B7280;margin-bottom:6px;">%2$s</div>'
                . '<div style="font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13px;line-height:1.5;color:#111827;word-break:break-all;background:#f9fafb;border:1px solid #f0f1f3;border-radius:8px;padding:8px 10px;">%3$s</div>'
                . '<button type="button" class="walletup-copy-address" data-walletup-copy="%4$s" data-copied-label="%5$s" aria-label="%6$s" style="display:block;width:100%%;margin-top:8px;cursor:pointer;border:1px solid #d1d5db;background:#f9fafb;color:#374151;font-size:13px;font-weight:600;border-radius:8px;padding:8px 10px;font-family:inherit;">%7$s</button>'
            . '</div>',
            esc_html__('Open your bank app, choose Zelle, then Scan and point at the code above.', 'walletup'),
            esc_html__('Bank can\'t scan? Send in Zelle to', 'walletup'),
            esc_html($handle),
            esc_attr($handle),
            esc_attr__('Copied', 'walletup'),
            esc_attr__('Copy Zelle handle', 'walletup'),
            esc_html__('Copy', 'walletup')
        );
    }

    private function generateCryptoAddress(string $method): string
    {
        if (!$this->isCryptocurrency($method)) {
            return '';
        }

        $accounts = get_option('wallet_up_transfer_call', []);
        $address = is_array($accounts) ? trim((string) ($accounts[$method . '_holder'] ?? '')) : '';
        if ($address === '') {
            return '';
        }

        return sprintf(
            '<div class="walletup-crypto-address" style="max-width:280px;margin:10px auto 0;padding:10px 12px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;text-align:left;">'
                . '<div style="font-size:11px;font-weight:600;letter-spacing:0.04em;text-transform:uppercase;color:#6B7280;margin-bottom:6px;">%1$s</div>'
                . '<div style="font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;line-height:1.5;color:#111827;word-break:break-all;background:#f9fafb;border:1px solid #f0f1f3;border-radius:8px;padding:8px 10px;">%2$s</div>'
                . '<button type="button" class="walletup-copy-address" data-walletup-copy="%3$s" data-copied-label="%4$s" aria-label="%5$s" style="display:block;width:100%%;margin-top:8px;cursor:pointer;border:1px solid #d1d5db;background:#f9fafb;color:#374151;font-size:13px;font-weight:600;border-radius:8px;padding:8px 10px;font-family:inherit;">%6$s</button>'
            . '</div>',
            esc_html__('Wallet address', 'walletup'),
            esc_html($address),
            esc_attr($address),
            esc_attr__('Copied', 'walletup'),
            esc_attr__('Copy wallet address', 'walletup'),
            esc_html__('Copy', 'walletup')
        );
    }

    private function formatAmountForDisplay(string $amount, string $method): string
    {
        if ($this->isCryptocurrency($method)) {
            $symbols = class_exists(\WalletUpPro\Core\Crypto\CryptoConverter::class)
                ? \WalletUpPro\Core\Crypto\CryptoConverter::supportedCryptos()
                : [];

            if (empty($amount)) {
                return sprintf('%s', $symbols[$method] ?? 'crypto');
            }

            try {
                if (!class_exists(\WalletUpPro\Core\Crypto\CryptoConverter::class)) {
                    throw new \Exception('CryptoConverter not available');
                }
                $converter = \WalletUpPro\Core\Crypto\CryptoConverter::getInstance();
                $conversion = $converter->convertUsdToCrypto(floatval($amount), $method);
                if ($conversion['success'] && !empty($conversion['amount'])) {
                    $cryptoAmount = $conversion['amount'];
                    $symbol = $symbols[$method] ?? strtoupper($method);
                    return sprintf('%s %s ($%s)', esc_html($cryptoAmount), $symbol, esc_html(number_format(floatval($amount), 2)));
                }
            } catch (\Exception $e) {
            }

            return sprintf('$%s in %s', esc_html(number_format(floatval($amount), 2)), $symbols[$method] ?? strtoupper($method));
        }
        
        if (empty($amount)) {
            return '';  // Return empty for fiat when no amount
        }
        return sprintf('$%s', esc_html(number_format(floatval($amount), 2)));
    }

    private function generateMessage($amount, $method, $settings, $display_mode = 'qr')
    {
        $hide_message = $settings['hideMessage'] ?? $settings['hide_message'] ?? false;
        if ($hide_message) {
            return '';
        }

        $show_default = $settings['showDefaultMessage'] ?? $settings['show_default_message'] ?? true;
        $custom_message = $settings['customMessage'] ?? $settings['custom_message'] ?? '';

        $merchant_name = $settings['merchant_name'] ?? '';
        if (empty($merchant_name)) {
            $profile = get_option('wallet_up_transfer_call', []);
            $merchant_name = $profile['holder_owner'] ?? '';
        }

        $qr_size = self::QR_DIMENSIONS[$settings['qrSize'] ?? $settings['size'] ?? 'medium'] ?? 180;

        if (empty($amount) && !$show_default && empty($custom_message)) {
            return '';
        }

        $action_text = '';
        switch ($display_mode) {
            case 'qr':
                $action_text = 'Scan or Click';
                break;
            case 'scan':
                $action_text = 'Scan';
                break;
            case 'button':
                $action_text = 'Click';
                break;
            case 'link':
                $action_text = 'Click here';
                break;
            default:
                $action_text = 'Click';
                break;
        }

        if ($method === 'zelle' && ($show_default || empty($custom_message))) {
            $holder_id = $settings['holder'] ?? $settings['zelle_holder'] ?? '';

            if ((bool) get_option('wallet_up_zelle_hide_contact', false)) {
                $holder_id = '';
            }

            if (!empty($holder_id)) {
                if (empty($amount)) {
                    return sprintf(
                        '<div class="walletup-message walletup-zelle-message" style="font-size: 14px; text-align: center; width: %spx; margin: 0 auto 10px;">Please <strong>%s</strong> to Send Payment to<br><strong>%s</strong> via Zelle</div>',
                        $qr_size,
                        $action_text,
                        esc_html($holder_id)
                    );
                } else {
                    $formatted_amount = $this->formatAmountForDisplay($amount, $method);
                    return sprintf(
                        '<div class="walletup-message walletup-zelle-message" style="font-size: 14px; text-align: center; width: %spx; margin: 0 auto 10px;">Please <strong>%s</strong> to Send <strong>%s</strong> to<br><strong>%s</strong> via Zelle</div>',
                        $qr_size,
                        $action_text,
                        $formatted_amount,
                        esc_html($holder_id)
                    );
                }
            }
        }

        if ($show_default || empty($custom_message)) {
            $formatted_amount = $this->formatAmountForDisplay($amount, $method);
            $recipient_suffix = !empty($merchant_name)
                ? sprintf(' to <strong>%s</strong>', esc_html($merchant_name))
                : '';

            $method_label = esc_html(self::methodDisplayName($method));
            $msg_style = sprintf(
                'font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,sans-serif;text-align:center;width:%spx;margin:0 auto 8px;line-height:1.4;',
                $qr_size
            );

            $recipient_line = !empty($merchant_name)
                ? sprintf('<div style="font-size:12px;color:#6B7280;margin-top:2px;">to <strong style="color:#374151;">%s</strong></div>', esc_html($merchant_name))
                : '';

            if (empty($amount)) {
                if ($this->isCryptocurrency($method)) {
                    return sprintf(
                        '<div class="walletup-message" style="%s"><div style="font-size:13px;color:#6B7280;">%s to send</div><div style="font-size:16px;font-weight:700;color:#111827;margin:2px 0;">%s</div>%s</div>',
                        $msg_style, $action_text, $formatted_amount, $recipient_line
                    );
                } else {
                    return sprintf(
                        '<div class="walletup-message" style="%s"><div style="font-size:13px;color:#6B7280;">%s to pay with</div><div style="font-size:16px;font-weight:700;color:#111827;margin:2px 0;">%s</div>%s</div>',
                        $msg_style, $action_text, $method_label, $recipient_line
                    );
                }
            } else {
                return sprintf(
                    '<div class="walletup-message" style="%s"><div style="font-size:13px;color:#6B7280;">%s to pay</div><div style="font-size:22px;font-weight:700;color:#111827;margin:4px 0;">%s</div><div style="font-size:12px;color:#9CA3AF;">via %s</div>%s</div>',
                    $msg_style, $action_text, $formatted_amount, $method_label, $recipient_line
                );
            }
        } else {
            $formatted_amount = $this->formatAmountForDisplay($amount, $method);
            $message = str_replace(
                ['$amount', '$method'],
                [strpos($custom_message, '$amount') !== false ? $formatted_amount : esc_html($amount), ucfirst($method)],
                $custom_message
            );
            return sprintf(
                '<div class="walletup-message" style="font-size: 14px; text-align: center; width: %spx; margin: 0 auto 10px;">%s</div>',
                $qr_size,
                wp_kses($message, array('strong' => array()))
            );
        }
    }

    private function formatSettingsForQR($settings)
    {
        return [
            'qrSize' => $settings['qrSize'] ?? $settings['size'] ?? 'medium',
            'qrColor' => $settings['qrColor'] ?? $settings['color'] ?? '#674FBF',
            'qrBackground' => $settings['qrBackground'] ?? $settings['background'] ?? '#FFFFFF',
            'qrBorder' => $settings['qrBorder'] ?? $settings['border'] ?? false,
            'qrStyle' => $settings['qrStyle'] ?? $settings['style'] ?? 'square',
            'showDefaultMessage' => $settings['showDefaultMessage'] ?? $settings['show_default_message'] ?? true,
            'customMessage' => $settings['customMessage'] ?? $settings['custom_message'] ?? '',
            'hideMessage' => $settings['hideMessage'] ?? $settings['hide_message'] ?? false,
        ];
    }

    private function formatError(string $key, string ...$args): string
    {
        $message = self::ERROR_MESSAGES[$key] ?? 'Unknown error occurred';

        $contact_html = '';
        $profile = get_option('wallet_up_transfer_call', []);
        $email = $profile['holder_email'] ?? '';
        $phone = $profile['holder_no'] ?? '';
        if (!empty($email) || !empty($phone)) {
            $parts = [];
            if (!empty($email)) {
                $parts[] = sprintf('<a href="mailto:%s">%s</a>', esc_attr($email), esc_html($email));
            }
            if (!empty($phone)) {
                $parts[] = sprintf('<a href="tel:%s">%s</a>', esc_attr($phone), esc_html($phone));
            }
            $contact_html = sprintf(
                '<p style="margin-top:8px;font-size:13px;color:#6b7280;">%s %s</p>',
                esc_html__('Need help? Contact:', 'walletup'),
                implode(' &middot; ', $parts)
            );
        }

        return sprintf(
            '<div class="walletup-error"><p style="color: red;">%s</p>%s</div>',
            esc_html(vsprintf($message, $args)),
            $contact_html
        );
    }

    public function generateTrackingScript(array $data): string
    {
        if (!isset($data['method'])) {
            return '';
        }

        $sanitized_amount = isset($data['amount']) ? esc_js($data['amount']) : '';
        $sanitized_method = esc_js($data['method']);
        $tracking_id = uniqid('walletup_', true);
        $session_id = hash('sha256', wp_get_session_token() . $sanitized_method . time());

        $trackingData = [
            'tracking_id' => $tracking_id,
            'session_id' => $session_id,
            'post_id' => intval($data['post_id'] ?? get_the_ID() ?: 0),
            'post_type' => sanitize_text_field($data['post_type'] ?? get_post_type() ?: 'unknown'),
            'post_title' => sanitize_text_field($data['post_title'] ?? get_the_title() ?: 'Unknown Title'),
            'display_mode' => sanitize_text_field($data['display_mode'] ?? 'unknown'),
            'amount' => $sanitized_amount,
            'method' => $sanitized_method,
            'timestamp' => time(),
            'user_agent_hash' => hash('sha256', sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')) ?? ''),
            'ip_hash' => hash('sha256', wp_privacy_anonymize_ip(sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '')) ?? ''))
        ];

        $nonce = wp_create_nonce('wallet_up_admin_nonce');

        ob_start();
        ?>
        <script>
            (() => {
                'use strict';

                const WALLETUP_TRACKER = {
                    method: '<?php echo esc_js($sanitized_method); ?>',
                    nonce: '<?php echo esc_js($nonce); ?>',
                    endpoint: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
                    trackingData: <?php echo wp_json_encode($trackingData, JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK); ?>,
                    impressionSent: false,
                    requestQueue: [],
                    isProcessing: false,

                    init() {
                        this.trackImpression();
                        this.setupClickHandler();
                    },

                    trackImpression() {
                        const key = 'walletup_imp_' + this.method + '_' + this.trackingData.post_id;
                        if (this.impressionSent || (window._walletupImpressions && window._walletupImpressions[key])) {
                            return;
                        }
                        this.impressionSent = true;
                        if (!window._walletupImpressions) window._walletupImpressions = {};
                        window._walletupImpressions[key] = true;

                        this.sendEvent('impression');
                    },

                    setupClickHandler() {
                        document.addEventListener('click', (e) => {
                            const container = e.target.closest(
                                '[data-wallet-up-method="' + this.method + '"],' +
                                '.walletup-display-container,' +
                                '.walletup-qr-container,' +
                                '.walletup-button-container'
                            );
                            if (!container) return;

                            const containerMethod = container.dataset?.walletUpMethod;
                            if (containerMethod && containerMethod !== this.method) return;

                            const now = Date.now();
                            if (this._lastClick && (now - this._lastClick) < 500) return;
                            this._lastClick = now;

                            const isQR = e.target.closest('img[data-walletup-qr], .walletup-qr-code, img[src*="qr"]');
                            if (isQR) {
                                this.sendEvent('scan');
                            }
                            this.sendEvent('click');
                        }, { passive: true });
                    },

                    sendEvent(type) {
                        const eventData = {
                            ...this.trackingData,
                            event_type: type,
                            event_timestamp: Date.now(),
                            screen_resolution: window.screen ? (window.screen.width + 'x' + window.screen.height) : ''
                        };
                        this.queueRequest(eventData);
                    },

                    queueRequest(eventData) {
                        this.requestQueue.push(eventData);
                        if (!this.isProcessing) {
                            this.processQueue();
                        }
                    },

                    async processQueue() {
                        if (this.isProcessing || this.requestQueue.length === 0) return;
                        this.isProcessing = true;

                        while (this.requestQueue.length > 0) {
                            const eventData = this.requestQueue.shift();
                            try {
                                await this.sendRequest(eventData);
                            } catch (err) {
                            }
                        }
                        this.isProcessing = false;
                    },

                    async sendRequest(eventData) {
                        const body = new URLSearchParams({
                            action: 'wallet_up_track_usage',
                            type: eventData.event_type,
                            method: this.method,
                            tracking_data: JSON.stringify(eventData),
                            nonce: this.nonce
                        });

                        if (navigator.sendBeacon) {
                            const blob = new Blob([body.toString()], { type: 'application/x-www-form-urlencoded' });
                            navigator.sendBeacon(this.endpoint, blob);
                            return;
                        }

                        const controller = new AbortController();
                        const timeoutId = setTimeout(() => controller.abort(), 5000);
                        try {
                            const response = await fetch(this.endpoint, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded',
                                    'X-Requested-With': 'XMLHttpRequest'
                                },
                                body: body,
                                signal: controller.signal,
                                credentials: 'same-origin'
                            });
                            clearTimeout(timeoutId);
                            return response.ok ? await response.json() : null;
                        } catch {
                            clearTimeout(timeoutId);
                            return null;
                        }
                    }
                };

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', () => WALLETUP_TRACKER.init());
                } else {
                    WALLETUP_TRACKER.init();
                }

                window.WALLETUP_TRACKER = WALLETUP_TRACKER;
            })();
        </script>
        <?php
        $script = ob_get_clean();
        
        $script = str_replace('&amp;&amp;', '&&', $script);
        $script = str_replace('&amp;', '&', $script);
        $script = str_replace('&#038;&#038;', '&&', $script);
        $script = str_replace('&#038;', '&', $script);
        
        return $script;
    }
}