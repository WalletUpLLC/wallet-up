<?php
namespace WalletUpPro\Core\Blocks;

use WalletUpPro\Core\Config\PaymentMethodsConfig;
use WalletUpPro\Core\Payments\PaymentRegistry;

if (!defined('ABSPATH')) {
    exit;
}

class BlockRegistrar
{
    const SCRIPT_HANDLE       = 'walletup-blocks-editor';
    const VIEW_SCRIPT_HANDLE  = 'walletup-blocks-tipjar-view';
    const STYLE_HANDLE        = 'walletup-blocks-style';

    public static function init(): void
    {
        add_action('init', [__CLASS__, 'registerBlocks']);
        add_action('enqueue_block_editor_assets', [__CLASS__, 'enqueueEditorAssets']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueueFrontendAssets']);
    }

    public static function registerBlocks(): void
    {
        $commonAttrs = [
            'method' => ['type' => 'string', 'default' => 'cashapp'],
            'amount' => ['type' => 'string', 'default' => ''],
            'align'  => ['type' => 'string', 'default' => 'center'],
        ];

        register_block_type('walletup/payment-button', [
            'api_version'     => 3,
            'attributes'      => $commonAttrs,
            'supports'        => ['html' => false, 'align' => ['left', 'center', 'right', 'wide']],
            'render_callback' => [__CLASS__, 'renderPaymentButton'],
        ]);

        register_block_type('walletup/payment-qr', [
            'api_version'     => 3,
            'attributes'      => $commonAttrs,
            'supports'        => ['html' => false, 'align' => ['left', 'center', 'right', 'wide']],
            'render_callback' => [__CLASS__, 'renderPaymentQr'],
        ]);

        register_block_type('walletup/pay-link', [
            'api_version'     => 3,
            'attributes'      => array_merge($commonAttrs, [
                'linkText' => ['type' => 'string', 'default' => ''],
            ]),
            'supports'        => ['html' => false],
            'render_callback' => [__CLASS__, 'renderPayLink'],
        ]);

        register_block_type('walletup/tip-jar', [
            'api_version'     => 3,
            'attributes'      => array_merge($commonAttrs, [
                'presets'     => ['type' => 'array',   'default' => [3, 5, 10]],
                'allowCustom' => ['type' => 'boolean', 'default' => true],
                'heading'     => ['type' => 'string',  'default' => ''],
            ]),
            'supports'        => ['html' => false, 'align' => ['left', 'center', 'right', 'wide']],
            'render_callback' => [__CLASS__, 'renderTipJar'],
        ]);

        if (defined('WALLET_UP_IS_PRO_BUILD') && WALLET_UP_IS_PRO_BUILD) {
            register_block_type('walletup/donation-form', [
                'api_version'     => 3,
                'attributes'      => [
                    'formId' => ['type' => 'string', 'default' => ''],
                ],
                'supports'        => ['html' => false, 'align' => ['wide', 'full']],
                'render_callback' => [__CLASS__, 'renderDonationForm'],
            ]);
        }
    }

    public static function enqueueEditorAssets(): void
    {
        $base = self::distBaseUrl();
        $path = self::distBasePath();
        $jsRel  = 'index.js';
        $cssRel = 'style.css';
        $version = self::pluginVersion();

        if (file_exists($path . $jsRel)) {
            wp_enqueue_script(
                self::SCRIPT_HANDLE,
                $base . $jsRel,
                ['wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n'],
                $version,
                true
            );

            wp_localize_script(self::SCRIPT_HANDLE, 'walletUpBlocksData', self::buildEditorData());
        }

        if (file_exists($path . $cssRel)) {
            wp_enqueue_style(
                self::STYLE_HANDLE,
                $base . $cssRel,
                [],
                $version
            );
        }
    }

    public static function enqueueFrontendAssets(): void
    {
        $base = self::distBaseUrl();
        $path = self::distBasePath();
        $cssRel = 'style.css';
        $version = self::pluginVersion();

        if (file_exists($path . $cssRel)) {
            wp_enqueue_style(
                self::STYLE_HANDLE,
                $base . $cssRel,
                [],
                $version
            );
        }

        if (has_block('walletup/tip-jar')) {
            $viewRel = 'tip-jar-view.js';
            if (file_exists($path . $viewRel)) {
                wp_enqueue_script(
                    self::VIEW_SCRIPT_HANDLE,
                    $base . $viewRel,
                    [],
                    $version,
                    true
                );
            }
        }
    }

    public static function renderPaymentButton(array $attributes): string
    {
        return self::renderViaShortcode($attributes, 'no');
    }

    public static function renderPaymentQr(array $attributes): string
    {
        return self::renderViaShortcode($attributes, 'yes');
    }

    public static function renderPayLink(array $attributes): string
    {
        $html = self::renderViaShortcode($attributes, 'link');
        $linkText = isset($attributes['linkText']) ? (string) $attributes['linkText'] : '';
        if ($linkText === '' || $html === '') {
            return $html;
        }
        return preg_replace_callback(
            '/(<a\b[^>]*>)(.*?)(<\/a>)/is',
            function ($m) use ($linkText) {
                return $m[1] . esc_html($linkText) . $m[3];
            },
            $html,
            1
        );
    }

    public static function renderTipJar(array $attributes): string
    {
        $method = isset($attributes['method']) ? sanitize_key((string) $attributes['method']) : 'cashapp';
        $presets = isset($attributes['presets']) && is_array($attributes['presets'])
            ? array_values(array_filter(array_map('floatval', $attributes['presets']), function ($v) { return $v > 0; }))
            : [3.0, 5.0, 10.0];
        $allowCustom = !empty($attributes['allowCustom']);
        $heading = isset($attributes['heading']) ? trim((string) $attributes['heading']) : '';
        $align = isset($attributes['align']) ? sanitize_key((string) $attributes['align']) : 'center';

        if (!shortcode_exists($method)) {
            return self::renderUpgradeNoticeIfAdmin($method);
        }

        if (!\WalletUpPro\Core\Payments\MethodStatusResolver::isEnabled($method)) {
            return \WalletUpPro\Core\PaymentRegistry::setupNotice($method);
        }
        if (!\WalletUpPro\Core\Payments\Availability\MethodAvailability::shows($method, 'tip_jar')) {
            return '';
        }

        $holder = \WalletUpPro\Core\Payments\MethodStatusResolver::holderFor($method);
        if ($holder === '') {
            return self::renderMissingHolderIfAdmin($method);
        }

        $urlTemplate = self::paymentUrlTemplateFor($method, $holder);

        $buttons = '';
        foreach ($presets as $amt) {
            $amtStr = self::formatAmount($amt);
            $href = $urlTemplate !== '' ? str_replace('__WUP_AMT__', rawurlencode($amtStr), $urlTemplate) : '';
            if ($href === '') {
                $buttons .= '<div class="walletup-tipjar-preset-wrap">'
                    . do_shortcode(sprintf('[%s amount="%s" scan="no"]', $method, esc_attr($amtStr)))
                    . '</div>';
                continue;
            }
            $buttons .= sprintf(
                '<a class="walletup-pay-button walletup-tipjar-preset" href="%s" target="_blank" rel="noopener">%s</a>',
                esc_url($href),
                esc_html('$' . $amtStr)
            );
        }

        if ($allowCustom) {
            $buttons .= sprintf(
                '<div class="walletup-tipjar-custom">'
                . '<input type="number" min="1" step="0.01" inputmode="decimal" class="walletup-tipjar-custom-input" placeholder="%s" aria-label="%s">'
                . '<button type="button" class="walletup-pay-button walletup-tipjar-custom-btn" data-method="%s" data-holder="%s" data-url-template="%s">%s</button>'
                . '</div>',
                esc_attr__('Amount', 'walletup'),
                esc_attr__('Custom tip amount', 'walletup'),
                esc_attr($method),
                esc_attr($holder),
                esc_attr($urlTemplate),
                esc_html__('Pay', 'walletup')
            );
        }

        $methodLabel = self::methodLabel($method);

        return sprintf(
            '<div class="walletup-tipjar" data-align="%s">%s<div class="walletup-tipjar-buttons">%s</div><small class="walletup-tipjar-hint">%s %s</small></div>',
            esc_attr($align),
            $heading !== '' ? '<h3 class="walletup-tipjar-heading">' . esc_html($heading) . '</h3>' : '',
            $buttons,
            esc_html__('Pays with', 'walletup'),
            esc_html($methodLabel)
        );
    }

    public static function renderDonationForm(array $attributes): string
    {
        $formId = isset($attributes['formId']) ? sanitize_text_field((string) $attributes['formId']) : '';
        if ($formId === '') {
            return '';
        }
        return do_shortcode('[walletup_donation_form form_id="' . esc_attr($formId) . '"]');
    }

    private static function renderViaShortcode(array $attributes, string $scan): string
    {
        $method = isset($attributes['method']) ? sanitize_key((string) $attributes['method']) : 'cashapp';
        $amount = isset($attributes['amount']) ? sanitize_text_field((string) $attributes['amount']) : '';

        if (!shortcode_exists($method)) {
            return self::renderUpgradeNoticeIfAdmin($method);
        }

        $atts = sprintf(' scan="%s"', esc_attr($scan));
        if ($amount !== '') {
            $atts .= sprintf(' amount="%s"', esc_attr($amount));
        }
        return do_shortcode("[{$method}{$atts}]");
    }

    private static function renderUpgradeNoticeIfAdmin(string $method): string
    {
        if (!current_user_can('manage_options')) {
            return '';
        }
        if (self::isFreeMethod($method)) {
            return sprintf(
                '<div class="walletup-block-admin-notice">%s <strong>%s</strong> %s <a href="%s">%s</a></div>',
                esc_html__('Wallet Up:', 'walletup'),
                esc_html(self::methodLabel($method)),
                esc_html__('is turned off. Turn it on under', 'walletup'),
                esc_url(admin_url('admin.php?page=wallet-up-payments')),
                esc_html__('Wallet Up → Payments', 'walletup')
            );
        }
        if (function_exists('\WalletUpPro\wallet_up_is_preview_sandbox') && \WalletUpPro\wallet_up_is_preview_sandbox()) {
            return sprintf(
                '<div class="walletup-block-admin-notice">%s <strong>%s</strong>. %s</div>',
                esc_html__('Wallet Up:', 'walletup'),
                esc_html(self::methodLabel($method)),
                esc_html__('is available in Pro.', 'walletup')
            );
        }
        return sprintf(
            '<div class="walletup-block-admin-notice">%s <strong>%s</strong>. %s <a href="%s" target="_blank" rel="noopener">%s</a></div>',
            esc_html__('Wallet Up:', 'walletup'),
            esc_html(self::methodLabel($method)),
            esc_html__('is available in Pro.', 'walletup'),
            esc_url(\WalletUpPro\wallet_up_upgrade_url()),
            esc_html__('Upgrade to Pro', 'walletup')
        );
    }

    private static function isFreeMethod(string $method): bool
    {
        if (class_exists(PaymentMethodsConfig::class) && defined(PaymentMethodsConfig::class . '::FREE_METHOD_IDS')) {
            return in_array($method, (array) PaymentMethodsConfig::FREE_METHOD_IDS, true);
        }
        return in_array($method, (array) PaymentMethodsConfig::FREE_METHOD_IDS, true);
    }

    private static function renderMissingHolderIfAdmin(string $method): string
    {
        if (!current_user_can('manage_options')) {
            return '';
        }
        return sprintf(
            '<div class="walletup-block-admin-notice">%s <a href="%s">%s</a> %s <strong>%s</strong>.</div>',
            esc_html__('Wallet Up:', 'walletup'),
            esc_url(admin_url('admin.php?page=wallet-up-payments')),
            esc_html__('save your details', 'walletup'),
            esc_html__('for', 'walletup'),
            esc_html(self::methodLabel($method))
        );
    }

    private static function buildEditorData(): array
    {
        $isPremium = defined('WALLET_UP_IS_PRO_BUILD') && WALLET_UP_IS_PRO_BUILD;
        $accounts = (array) get_option('wallet_up_transfer_call', []);

        $allMethods = self::knownMethods();
        $available  = self::availableMethodIds();

        $methods = [];
        foreach ($allMethods as $id => $label) {
            $isPro = !in_array($id, (array) PaymentMethodsConfig::FREE_METHOD_IDS, true);
            $methods[] = [
                'id'        => $id,
                'label'     => $label,
                'isPro'     => $isPro,
                'available' => in_array($id, $available, true),
                'hasHolder' => !empty($accounts[$id . '_holder']),
            ];
        }

        $formList = [];
        foreach ((array) get_option('wallet_up_donation_forms', []) as $f) {
            if (empty($f['id'])) {
                continue;
            }
            $formList[] = [
                'id'    => (string) $f['id'],
                'title' => (string) ($f['name'] ?? __('Untitled form', 'walletup')),
            ];
        }

        return [
            'methods'    => $methods,
            'forms'      => $formList,
            'isPremium'  => $isPremium,
            'configUrl'  => admin_url('admin.php?page=wallet-up-payments'),
            'formsUrl'   => admin_url('admin.php?page=wallet-up-donations'),
            'upgradeUrl' => (function_exists('\WalletUpPro\wallet_up_is_preview_sandbox') && \WalletUpPro\wallet_up_is_preview_sandbox()) ? '' : \WalletUpPro\wallet_up_upgrade_url(),
        ];
    }

    private static function availableMethodIds(): array
    {
        if (class_exists(PaymentMethodsConfig::class) && method_exists(PaymentMethodsConfig::class, 'getAvailableMethodIds')) {
            return (array) PaymentMethodsConfig::getAvailableMethodIds();
        }
        return (array) PaymentMethodsConfig::FREE_METHOD_IDS;
    }

    private static function knownMethods(): array
    {
        if (class_exists(PaymentMethodsConfig::class) && defined(PaymentMethodsConfig::class . '::PAYMENT_METHODS')) {
            $labels = [];
            foreach (PaymentMethodsConfig::PAYMENT_METHODS as $id => $cfg) {
                $labels[$id] = isset($cfg['name']) ? (string) $cfg['name'] : ucfirst($id);
            }
            if (!empty($labels)) {
                return $labels;
            }
        }
        return [
            'cashapp' => 'Cash App',
            'paypal'  => 'PayPal.me',
            'venmo'   => 'Venmo',
        ];
    }

    private static function methodLabel(string $id): string
    {
        $known = self::knownMethods();
        return $known[$id] ?? ucfirst($id);
    }

    private static function paymentUrlTemplateFor(string $method, string $holder): string
    {
        if ($holder === '' || !class_exists(\WalletUpPro\Core\Onboarding\PaymentPageGenerator::class)) {
            return '';
        }
        try {
            $tpl = \WalletUpPro\Core\Onboarding\PaymentPageGenerator::buildPaymentUrl($method, $holder, '__WUP_AMT__');
        } catch (\Throwable $e) {
            return '';
        }
        if ($tpl === '' || strpos($tpl, '__WUP_AMT__') === false) {
            return '';
        }
        return $tpl;
    }

    private static function formatAmount(float $amount): string
    {
        if (fmod($amount, 1) === 0.0) {
            return (string) (int) $amount;
        }
        return rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
    }

    private static function distBaseUrl(): string
    {
        if (defined('WALLET_UP_PRO_URL')) {
            return rtrim(WALLET_UP_PRO_URL, '/') . '/walup-react/dist/blocks/';
        }
        return plugins_url('walup-react/dist/blocks/', dirname(__DIR__, 2) . '/wallet-up.php');
    }

    private static function distBasePath(): string
    {
        if (defined('WALLET_UP_PRO_PATH')) {
            return rtrim(WALLET_UP_PRO_PATH, '/') . '/walup-react/dist/blocks/';
        }
        return dirname(__DIR__, 2) . '/walup-react/dist/blocks/';
    }

    private static function pluginVersion(): string
    {
        if (defined('WALLET_UP_PRO_VERSION')) {
            return WALLET_UP_PRO_VERSION;
        }
        return '5.6.0';
    }
}
