<?php
namespace WalletUpPro\Core\Onboarding;

use WalletUpPro\Core\PaymentRegistry;
use WalletUpPro\Core\Config\PaymentMethodsConfig;
use WalletUpPro\Services\QRCodeService;

if (!defined('ABSPATH')) {
    exit;
}

class PaymentPageGenerator
{
    public const LIVE_SHORTCODE = 'walletup_payment_section';
    public const GENERATED_META = '_wallet_up_generated';
    public const SURFACE = 'page_generator';
    private const CARD_FORM_SHORTCODE = 'walletup_donation_form';
    private const CARD_METHOD = 'stripe';
    private const GENERATED_BLOCKS = ['walletup/payment-qr', 'walletup/payment-button', 'walletup/pay-link', 'walletup/tip-jar'];

    private static bool $renderingLive = false;

    public static function registerLiveSection(): void
    {
        add_shortcode(self::LIVE_SHORTCODE, [__CLASS__, 'renderLiveSection']);
    }

    public static function renderLiveSection(): string
    {
        if (self::$renderingLive) {
            return '';
        }
        self::$renderingLive = true;
        try {
            $blocks = self::sectionBlocks(self::buildArtifact(false), '', false);
            return $blocks ? do_shortcode(do_blocks(implode("\n\n", $blocks))) : '';
        } finally {
            self::$renderingLive = false;
        }
    }

    public static function liveSectionMarkup(): string
    {
        return '<!-- wp:shortcode -->' . "\n" . '[' . self::LIVE_SHORTCODE . ']' . "\n" . '<!-- /wp:shortcode -->';
    }

    public static function init(): void
    {
        add_action('wp_ajax_wallet_up_get_artifact', [__CLASS__, 'handleGetArtifact']);
        add_action('wp_ajax_wallet_up_generate_payment_page', [__CLASS__, 'handleGeneratePage']);
        add_action('wp_ajax_wallet_up_generate_shortcode_page', [__CLASS__, 'handleGenerateShortcodePage']);
        add_action('wp_ajax_wallet_up_generate_donation_form_page', [__CLASS__, 'handleGenerateDonationFormPage']);
    }

    public static function handleGenerateDonationFormPage(): void
    {
        try {
            self::assertAllowed();

            if (!class_exists('\\WalletUpPro\\Core\\Donations\\DonationSystemHandler')) {
                throw new \Exception(esc_html__('Donations are not available on this site.', 'walletup'));
            }
            if (!\WalletUpPro\wallet_up_fs()->can_use_premium_code()) {
                throw new \Exception(esc_html__('Donations are a premium feature. Activate your Wallet Up license to create a donation form.', 'walletup'));
            }
            $formId = \WalletUpPro\Core\Donations\DonationSystemHandler::ensureCardPaymentForm();
            if ($formId === '') {
                throw new \Exception(esc_html__('Could not prepare the donation form.', 'walletup'));
            }

            $title     = esc_html__('Donations', 'walletup');
            $shortcode = '[walletup_donation_form form_id="' . $formId . '"]';
            $heading   = '<!-- wp:heading -->' . "\n"
                . '<h2 class="wp-block-heading">' . esc_html($title) . '</h2>' . "\n"
                . '<!-- /wp:heading -->';
            $block     = '<!-- wp:shortcode -->' . "\n" . $shortcode . "\n" . '<!-- /wp:shortcode -->';
            $content   = $heading . "\n\n" . $block;

            $pageId = self::createDraftPage($title, $content);
            if (!$pageId) {
                throw new \Exception(esc_html__('Could not create page', 'walletup'));
            }

            wp_send_json_success([
                'pageId'  => $pageId,
                'editUrl' => esc_url_raw(get_edit_post_link($pageId, 'raw')),
                'title'   => $title,
            ]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public static function handleGetArtifact(): void
    {
        try {
            self::assertAllowed();
            wp_send_json_success(self::buildArtifact());
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public static function handleGeneratePage(): void
    {
        try {
            self::assertAllowed();
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in assertAllowed()
            $onlyMethodId = isset($_POST['methodId']) ? sanitize_key(wp_unslash($_POST['methodId'])) : '';

            $artifact = self::buildArtifact();
            $title    = self::pageTitleFor($artifact, $onlyMethodId);
            $content  = ($onlyMethodId === '' && self::availabilityOn())
                ? self::headingBlock($title) . "\n\n" . self::liveSectionMarkup()
                : self::buildPageContent($artifact, $title, $onlyMethodId);
            $pageId   = self::createDraftPage($title, $content);

            if (!$pageId) {
                throw new \Exception(esc_html__('Could not create page', 'walletup'));
            }
            wp_send_json_success([
                'pageId'     => $pageId,
                'editUrl'    => esc_url_raw(get_edit_post_link($pageId, 'raw')),
                'previewUrl' => esc_url_raw(add_query_arg('preview', 'true', get_permalink($pageId))),
                'title'      => $title,
                'scope'      => $onlyMethodId !== '' ? 'single' : 'all',
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    private static function headingBlock(string $title): string
    {
        return '<!-- wp:heading -->' . "\n"
            . '<h2 class="wp-block-heading">' . esc_html($title) . '</h2>' . "\n"
            . '<!-- /wp:heading -->';
    }

    private static function buildPageContent(array $artifact, string $title, string $onlyMethodId = ''): string
    {
        $heading = self::headingBlock($title);
        $blocks = self::sectionBlocks($artifact, $onlyMethodId);
        return $blocks ? ($heading . "\n\n" . implode("\n\n", $blocks)) : $heading;
    }

    private static function sectionBlocks(array $artifact, string $onlyMethodId = '', bool $createCardForm = true): array
    {
        if (empty($artifact['methods'])) {
            return ($onlyMethodId === '') ? self::cardSectionBlocks($createCardForm) : [];
        }

        if ($onlyMethodId !== '') {
            $picked = self::findMethod($artifact, $onlyMethodId);
            return $picked === null ? [] : [$picked['blockMarkup']];
        }

        if ($artifact['goal'] === 'donations') {
            $methods = $artifact['methods'];
            $qrBlock = sprintf('<!-- wp:walletup/payment-qr %s /-->', wp_json_encode(['method' => $methods[0]['id']]));
            if (count($methods) === 1) {
                return [$qrBlock, $methods[0]['blockMarkup']];
            }
            $blocks = [$qrBlock];
            foreach ($methods as $m) {
                $blocks[] = '<!-- wp:heading {"level":3} -->' . "\n"
                    . '<h3 class="wp-block-heading">' . esc_html((string) ($m['label'] ?? '')) . '</h3>' . "\n"
                    . '<!-- /wp:heading -->';
                $blocks[] = $m['blockMarkup'];
            }
            return array_merge($blocks, self::cardSectionBlocks($createCardForm));
        }

        $blocks = [];
        foreach ($artifact['methods'] as $m) {
            $blocks[] = $m['blockMarkup'];
        }
        return array_merge($blocks, self::cardSectionBlocks($createCardForm));
    }

    private static function availabilityOn(): bool
    {
        return class_exists('\\WalletUpPro\\Core\\Settings\\SettingsRegistry')
            && \WalletUpPro\Core\Settings\SettingsRegistry::isAvailabilityV2();
    }

    private static function shownHere(string $methodId): bool
    {
        return !class_exists('\WalletUpPro\Core\Payments\Availability\MethodAvailability') || \WalletUpPro\Core\Payments\Availability\MethodAvailability::shows($methodId, self::SURFACE);
    }

    public static function fixedPageIds(int $limit): array
    {
        $ids = get_posts([
            'post_type'      => 'page',
            'post_status'    => ['publish', 'draft', 'pending', 'private', 'future'],
            'meta_key'       => self::GENERATED_META,
            'posts_per_page' => $limit,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);
        return array_values(array_filter(array_map('intval', (array) $ids), static function (int $id): bool {
            $content = (string) get_post_field('post_content', $id);
            return !self::containsShortcode($content, self::LIVE_SHORTCODE) && self::isFixedList($content);
        }));
    }

    public static function pagesNeedingUpdate(int $limit): array
    {
        $after = self::liveMethodIds();
        $out = [];
        foreach (self::fixedPageIds($limit) as $id) {
            $now = array_values(array_filter(
                self::methodsIn((string) get_post_field('post_content', $id)),
                [self::class, 'showsToCustomersNow']
            ));
            $gained = array_values(array_diff($after, $now));
            $lost = array_values(array_diff($now, $after));
            if ($gained === [] && $lost === []) {
                continue;
            }
            $out[] = [
                'id'      => $id,
                'title'   => get_the_title($id),
                'status'  => (string) get_post_status($id),
                'editUrl' => esc_url_raw((string) get_edit_post_link($id, 'raw')),
                'viewUrl' => esc_url_raw((string) get_permalink($id)),
                'now'     => $now,
                'after'   => $after,
                'gained'  => $gained,
                'lost'    => $lost,
            ];
        }
        return $out;
    }

    public static function showsToCustomersNow(string $methodId): bool
    {
        if ($methodId === self::CARD_METHOD) {
            return self::cardSectionBlocks(false) !== [];
        }
        return in_array($methodId, PaymentMethodsConfig::getAvailableMethodIds(), true)
            && \WalletUpPro\Core\Payments\MethodStatusResolver::isReady($methodId);
    }

    public static function liveMethodIds(): array
    {
        $ids = array_column(self::buildArtifact(false)['methods'], 'id');
        if (self::cardSectionBlocks(false) !== []) {
            $ids[] = self::CARD_METHOD;
        }
        return array_values(array_unique($ids));
    }

    public static function methodsIn(string $content): array
    {
        $found = [];
        $walk = static function (array $blocks) use (&$walk, &$found): void {
            foreach ($blocks as $block) {
                if (self::isMethodBlock($block)) {
                    $found[] = (string) $block['attrs']['method'];
                } elseif (self::isCardFormBlock($block)) {
                    $found[] = self::CARD_METHOD;
                }
                if (!empty($block['innerBlocks'])) {
                    $walk($block['innerBlocks']);
                }
            }
        };
        $walk(parse_blocks($content));
        return array_values(array_unique($found));
    }

    public static function isFixedList(string $content): bool
    {
        $blocks = parse_blocks($content);
        $top = [];
        foreach ($blocks as $block) {
            if (self::isMethodBlock($block)) {
                $top[] = (string) $block['attrs']['method'];
            } elseif (self::isCardFormBlock($block)) {
                $top[] = self::CARD_METHOD;
            }
        }
        $top = array_values(array_unique($top));
        if (count($top) !== count(self::methodsIn($content))) {
            return false;
        }
        $wallets = count(array_diff($top, [self::CARD_METHOD]));
        return $wallets >= 2 || ($wallets >= 1 && in_array(self::CARD_METHOD, $top, true));
    }

    public static function swapToLive(int $pageId): bool
    {
        $post = get_post($pageId);
        if (!$post || $post->post_type !== 'page' || !get_post_meta($pageId, self::GENERATED_META, true)
            || self::containsShortcode((string) $post->post_content, self::LIVE_SHORTCODE)
            || !self::isFixedList((string) $post->post_content)) {
            return false;
        }
        $blocks = parse_blocks((string) $post->post_content);
        $kept = [];
        $insertAt = null;
        $count = count($blocks);
        for ($i = 0; $i < $count; $i++) {
            $block = $blocks[$i];
            $generated = self::isMethodBlock($block) || self::isCardFormBlock($block)
                || (self::isSubHeading($block) && isset($blocks[$i + 1]) && (self::isMethodBlock($blocks[$i + 1]) || self::isCardFormBlock($blocks[$i + 1])));
            if ($generated) {
                if ($insertAt === null) {
                    $insertAt = count($kept);
                }
                continue;
            }
            $kept[] = $block;
        }
        $live = parse_blocks(self::liveSectionMarkup());
        array_splice($kept, $insertAt ?? count($kept), 0, $live);
        $result = wp_update_post(wp_slash(['ID' => $pageId, 'post_content' => serialize_blocks($kept)]), true);
        return !is_wp_error($result) && (int) $result > 0;
    }

    private static function containsShortcode(string $content, string $tag): bool
    {
        return strpos($content, '[' . $tag) !== false && (bool) preg_match('/' . get_shortcode_regex([$tag]) . '/', $content);
    }

    private static function isMethodBlock(array $block): bool
    {
        return in_array((string) ($block['blockName'] ?? ''), self::GENERATED_BLOCKS, true) && !empty($block['attrs']['method']);
    }

    private static function isCardFormBlock(array $block): bool
    {
        if (($block['blockName'] ?? '') !== 'core/shortcode' || !class_exists('\\WalletUpPro\\Core\\Donations\\DonationSystemHandler')) {
            return false;
        }
        $formId = \WalletUpPro\Core\Donations\DonationSystemHandler::existingCardPaymentForm();
        $html = (string) ($block['innerHTML'] ?? '');
        return $formId !== ''
            && self::containsShortcode($html, self::CARD_FORM_SHORTCODE)
            && strpos($html, 'form_id="' . $formId . '"') !== false;
    }

    private static function isSubHeading(array $block): bool
    {
        return ($block['blockName'] ?? '') === 'core/heading' && (int) ($block['attrs']['level'] ?? 2) === 3;
    }

    private static function findMethod(array $artifact, string $methodId): ?array
    {
        foreach ($artifact['methods'] as $m) {
            if (($m['id'] ?? '') === $methodId) {
                return $m;
            }
        }
        return null;
    }

    private static function cardSectionBlocks(bool $createForm = true): array
    {
        if (!class_exists('\\WalletUpPro\\Core\\Config\\AutomatedApiConfig')
            || !class_exists('\\WalletUpPro\\Core\\Donations\\DonationSystemHandler')
            || !\WalletUpPro\wallet_up_fs()->can_use_premium_code()) {
            return [];
        }
        $apis = \WalletUpPro\Core\Config\AutomatedApiConfig::getEnabledApis();
        if (empty($apis) || !self::shownHere(self::CARD_METHOD)) {
            return [];
        }
        $formId = $createForm
            ? \WalletUpPro\Core\Donations\DonationSystemHandler::ensureCardPaymentForm()
            : \WalletUpPro\Core\Donations\DonationSystemHandler::existingCardPaymentForm();
        if ($formId === '') {
            return [];
        }
        return [
            '<!-- wp:heading {"level":3} -->' . "\n"
                . '<h3 class="wp-block-heading">' . esc_html__('Pay by card', 'walletup') . '</h3>' . "\n"
                . '<!-- /wp:heading -->',
            '<!-- wp:shortcode -->[' . self::CARD_FORM_SHORTCODE . ' form_id="' . esc_attr($formId) . '"]<!-- /wp:shortcode -->',
        ];
    }

    public static function handleGenerateShortcodePage(): void
    {
        try {
            self::assertAllowed();

            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in assertAllowed()
            $raw = isset($_POST['shortcode']) ? (string) wp_unslash($_POST['shortcode']) : '';
            $shortcode = trim($raw);

            if ($shortcode === ''
                || !preg_match('/^\[\s*[a-z_][a-z0-9_]*/i', $shortcode)
                || substr($shortcode, -1) !== ']'
            ) {
                throw new \Exception(esc_html__('Invalid shortcode', 'walletup'));
            }

            $shortcode = wp_kses($shortcode, []);

            $title = self::shortcodeToTitle($shortcode);

            $heading = '<!-- wp:heading -->' . "\n"
                . '<h2 class="wp-block-heading">' . esc_html($title) . '</h2>' . "\n"
                . '<!-- /wp:heading -->';

            $shortcodeBlock = '<!-- wp:shortcode -->' . "\n" . $shortcode . "\n" . '<!-- /wp:shortcode -->';
            $content = $heading . "\n\n" . $shortcodeBlock;

            $pageId = self::createDraftPage($title, $content);
            if (!$pageId) {
                throw new \Exception(esc_html__('Could not create page', 'walletup'));
            }

            wp_send_json_success([
                'pageId'  => $pageId,
                'editUrl' => esc_url_raw(get_edit_post_link($pageId, 'raw')),
                'title'   => $title,
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    private static function shortcodeToTitle(string $shortcode): string
    {
        if (!preg_match('/^\[\s*([a-z_][a-z0-9_]*)/i', $shortcode, $matches)) {
            return __('Wallet Up payment', 'walletup');
        }
        $tag = strtolower($matches[1]);

        $methodTags = \WalletUpPro\Core\Config\PaymentMethodsConfig::getAllMethodIds();
        if (in_array($tag, $methodTags, true)) {
            return sprintf(
                /* translators: %s: payment method name (e.g. Cash App) */
                __('Pay with %s', 'walletup'),
                self::methodLabel($tag)
            );
        }

        if ($tag === 'walletup_qrcode' || $tag === 'walletup_qr') {
            return __('Scan to pay', 'walletup');
        }
        if (strpos($tag, 'donation') !== false) {
            return __('Support us', 'walletup');
        }
        if (strpos($tag, 'url_shortener') !== false
            || strpos($tag, 'integrated_shortener') !== false
            || strpos($tag, 'click_tracker') !== false
        ) {
            return __('Short link', 'walletup');
        }

        return __('Wallet Up payment', 'walletup');
    }

    private static function assertAllowed(): void
    {
        if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
            throw new \Exception(esc_html__('Invalid security token', 'walletup'));
        }
        if (!current_user_can('manage_options')) {
            throw new \Exception(esc_html__('Permission denied', 'walletup'));
        }
    }

    public static function buildArtifact(bool $withPreview = true): array
    {
        $accounts        = (array) get_option('wallet_up_transfer_call', []);
        $enabled         = (array) get_option('wallet_up_enabled_methods', []);
        $amount          = (string) get_option('wallet_up_default_amount', '');
        $goal            = (string) get_option('wallet_up_onboarding_goal', 'services');
        $eventName       = (string) get_option('wallet_up_event_name', '');
        $presets         = (array)  get_option('wallet_up_tipjar_presets', [3, 5, 10]);
        $displaySettings = (array) get_option('wallet_up_display_settings', []);
        $defaultMode     = (string) ($displaySettings['defaultMode'] ?? 'qr');
        if (!in_array($defaultMode, ['qr', 'button', 'link'], true)) {
            $defaultMode = 'qr';
        }

        $available = PaymentMethodsConfig::getAvailableMethodIds();
        $methods = [];
        foreach (self::orderedEnabledIds($enabled) as $methodId) {
            $holder = trim((string) ($accounts[$methodId . '_holder'] ?? ''));
            if ($holder === '') continue;
            if (!in_array($methodId, $available, true)) continue;
            if (!self::shownHere($methodId)) continue;

            $url = self::buildPaymentUrl($methodId, $holder, $amount);
            $methods[] = [
                'id'          => $methodId,
                'label'       => self::methodLabel($methodId),
                'holder'      => $holder,
                'url'         => $url,
                'qrDataUri'   => $withPreview ? self::qrFor($methodId, $url, $holder, (string) $amount, '') : '',
                'shortcode'   => self::buildShortcode($methodId, $amount, $goal, $presets),
                'blockMarkup' => self::buildBlockMarkup($methodId, $amount, $goal, $presets, $defaultMode),
                'displayName' => self::userLabel($methodId, $amount, $goal, $eventName),
            ];
        }

        $primary = !empty($methods) ? $methods[0] : null;

        return [
            'methods'      => $methods,
            'methodCount'  => count($methods),
            'configured'   => !empty($methods),

            'amount'       => $amount,
            'goal'         => $goal,
            'eventName'    => $eventName,
            'presets'      => array_values(array_filter(array_map('floatval', $presets), function ($v) { return $v > 0; })),
            'defaultMode'  => $defaultMode,

            'method'       => $primary['id'] ?? '',
            'methodLabel'  => $primary['label'] ?? '',
            'holder'       => $primary['holder'] ?? '',
            'url'          => $primary['url'] ?? '',
            'qrDataUri'    => $primary['qrDataUri'] ?? '',
            'shortcode'    => $primary['shortcode'] ?? '',
            'blockMarkup'  => $primary['blockMarkup'] ?? '',
            'label'        => $primary['displayName'] ?? '',
            'linkSmsBody'  => $primary ? self::buildSmsBody($primary['displayName'], $primary['url']) : '',
            'canDonationForm' => ($goal === 'donations'),
        ];
    }

    private static function orderedEnabledIds(array $enabled): array
    {
        $primary = (string) get_option('wallet_up_primary_method', '');
        $freeFirst = (array) PaymentMethodsConfig::FREE_METHOD_IDS;

        $allEnabled = array_keys(array_filter($enabled, [\WalletUpPro\Core\Payments\MethodStatusResolver::class, 'flag']));

        $ordered = [];
        if ($primary !== '' && in_array($primary, $allEnabled, true)) {
            $ordered[] = $primary;
        }
        foreach ($freeFirst as $id) {
            if (in_array($id, $allEnabled, true) && !in_array($id, $ordered, true)) {
                $ordered[] = $id;
            }
        }
        foreach ($allEnabled as $id) {
            if (!in_array($id, $ordered, true)) {
                $ordered[] = $id;
            }
        }
        return $ordered;
    }

    public static function buildPaymentUrl(string $method, string $holder, string $amount = ''): string
    {
        if ($holder === '') return '';

        try {
            if (class_exists(PaymentRegistry::class)) {
                $registry = PaymentRegistry::getInstance();
                if (method_exists($registry, 'getMethod')) {
                    $instance = $registry->getMethod($method);
                    if ($instance && method_exists($instance, 'getPaymentUrl')) {
                        return $instance->getPaymentUrl($amount, '');
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        $amount = $amount !== '' && is_numeric($amount) ? (string) (float) $amount : '';
        switch ($method) {
            case 'cashapp':
                $tag = ltrim(trim($holder), '$');
                $url = 'https://cash.app/$' . rawurlencode($tag);
                if ($amount !== '') $url .= '/' . $amount;
                return $url;
            case 'paypal':
                $user = trim($holder);
                $url = 'https://paypal.me/' . rawurlencode($user);
                if ($amount !== '') $url .= '/' . $amount;
                return $url;
            case 'venmo':
                $user = ltrim(trim($holder), '@');
                $url = 'https://venmo.com/' . rawurlencode($user);
                if ($amount !== '') $url .= '?txn=pay&amount=' . $amount;
                return $url;
            default:
                return '';
        }
    }

    public static function qrFor(string $methodId, string $url, string $holder, string $amount = '', string $reference = ''): string
    {
        $payload = \WalletUpPro\Core\PaymentRegistry::qrPayloadFor($methodId, $url, $holder, $amount, $reference);
        return $payload !== '' ? self::generateQrDataUri($payload) : '';
    }

    public static function generateQrDataUri(string $data): string
    {
        if ($data === '') {
            return '';
        }
        try {
            if (class_exists(QRCodeService::class)) {
                $uri = QRCodeService::getInstance()->generateQR($data);
                if (is_string($uri) && $uri !== '') {
                    return $uri;
                }
            }
        } catch (\Throwable $e) {
        }
        return '';
    }

    private static function buildShortcode(string $method, string $amount, string $goal, array $presets): string
    {
        if ($goal === 'donations') {
            return '';
        }
        $scan = $goal === 'qr' ? 'yes' : 'no';
        $amt = $amount !== '' ? sprintf(' amount="%s"', esc_attr($amount)) : '';
        return sprintf('[%s scan="%s"%s]', $method, $scan, $amt);
    }

    private static function buildBlockMarkup(string $method, string $amount, string $goal, array $presets, string $defaultMode = 'qr'): string
    {
        if ($goal === 'donations') {
            $clean = array_values(array_filter(array_map('floatval', $presets), function ($v) { return $v > 0; }));
            if (empty($clean)) $clean = [3.0, 5.0, 10.0];
            $attrs = wp_json_encode([
                'method'      => $method,
                'presets'     => $clean,
                'allowCustom' => true,
            ]);
            return sprintf('<!-- wp:walletup/tip-jar %s /-->', $attrs);
        }

        $blockMap = [
            'qr'     => 'walletup/payment-qr',
            'button' => 'walletup/payment-button',
            'link'   => 'walletup/pay-link',
        ];
        $blockName = $blockMap[$defaultMode] ?? $blockMap['qr'];

        if ($goal === 'qr') {
            $blockName = 'walletup/payment-qr';
        }

        $attrs = ['method' => $method];
        if ($amount !== '') {
            $attrs['amount'] = $amount;
        }
        return sprintf('<!-- wp:%s %s /-->', $blockName, wp_json_encode($attrs));
    }

    public static function methodLabel(string $methodId): string
    {
        if (class_exists(PaymentMethodsConfig::class) && defined(PaymentMethodsConfig::class . '::PAYMENT_METHODS')) {
            $cfg = PaymentMethodsConfig::PAYMENT_METHODS[$methodId] ?? null;
            if (is_array($cfg) && !empty($cfg['name'])) {
                return (string) $cfg['name'];
            }
        }
        return ucfirst($methodId);
    }

    private static function userLabel(string $method, string $amount, string $goal, string $eventName): string
    {
        $methodName = self::methodLabel($method);
        if ($goal === 'event' && $eventName !== '') {
            return sprintf(
                /* translators: 1: event name, 2: price, 3: payment method */
                __('%1$s — $%2$s via %3$s', 'walletup'),
                $eventName,
                $amount !== '' ? $amount : '?',
                $methodName
            );
        }
        if ($goal === 'donations') {
            return sprintf(__('Tip Jar — %s', 'walletup'), $methodName);
        }
        if ($amount !== '') {
            return sprintf(__('Pay $%1$s with %2$s', 'walletup'), $amount, $methodName);
        }
        return sprintf(__('Pay with %s', 'walletup'), $methodName);
    }

    private static function pageTitleFor(array $artifact, string $onlyMethodId = ''): string
    {
        if ($onlyMethodId !== '') {
            $picked = self::findMethod($artifact, $onlyMethodId);
            if ($picked !== null) {
                $methodName = $picked['label'];
                switch ($artifact['goal']) {
                    case 'event':
                        return $artifact['eventName'] !== ''
                            ? sprintf(
                                /* translators: 1: event name, 2: payment method name */
                                __('%1$s — %2$s', 'walletup'),
                                $artifact['eventName'],
                                $methodName
                            )
                            : sprintf(
                                /* translators: %s: payment method name */
                                __('Pay with %s', 'walletup'),
                                $methodName
                            );
                    case 'donations':
                        return sprintf(
                            /* translators: %s: payment method name */
                            __('Tip jar — %s', 'walletup'),
                            $methodName
                        );
                    case 'qr':
                        return sprintf(
                            /* translators: %s: payment method name */
                            __('Scan to pay with %s', 'walletup'),
                            $methodName
                        );
                    default:
                        return sprintf(
                            /* translators: %s: payment method name */
                            __('Pay with %s', 'walletup'),
                            $methodName
                        );
                }
            }
        }

        switch ($artifact['goal']) {
            case 'event':
                return $artifact['eventName'] !== ''
                    ? $artifact['eventName']
                    : __('Tickets', 'walletup');
            case 'donations':
                return __('Support us', 'walletup');
            case 'qr':
                return __('Scan to pay', 'walletup');
            case 'services':
                return __('Pay me', 'walletup');
            default:
                return __('Wallet Up payment', 'walletup');
        }
    }

    private static function buildSmsBody(string $label, string $url): string
    {
        if ($url === '') return '';
        return sprintf("%s\n%s", $label, $url);
    }

    private static function createDraftPage(string $title, string $content): int
    {
        $pageId = wp_insert_post([
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => 'draft',
            'post_type'    => 'page',
            'post_author'  => get_current_user_id(),
            'meta_input'   => [
                self::GENERATED_META => time(),
            ],
        ], true);

        return is_wp_error($pageId) ? 0 : (int) $pageId;
    }
}
