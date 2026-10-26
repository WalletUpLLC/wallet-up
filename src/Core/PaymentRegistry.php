<?php
namespace WalletUpPro\Core;

use WalletUpPro\Core\Database\DatabaseManager;
use WalletUpPro\Core\Error\ErrorHandler;
use WalletUpPro\Core\Config\PaymentMethodsConfig;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
class PaymentRegistry
{
    private static $instance = null;
    private $methods = [];
    private $db;
    private $errorHandler;
    private $enabledMethods;

    private bool $initialized = false;

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
        try {
            $this->errorHandler = ErrorHandler::getInstance();

            global $wpdb;
            $this->db = new DatabaseManager($wpdb->prefix . 'wallet_up_analytics');

            $this->loadEnabledMethods();

            $this->registerPaymentMethods();

            $this->registerShortcodes();

            $this->initialized = $this->checkInitializationStatus();

        } catch (\Exception $e) {
            if (isset($this->errorHandler)) {
                $this->errorHandler->log('PaymentRegistry initialization error: ' . $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * Checks if the registry is fully initialized by verifying all necessary components are set up.
     * 
     * @return bool True if all initialization steps have been completed successfully, false otherwise.
     */
    private function checkInitializationStatus(): bool
    {
        return !empty($this->methods) && $this->db instanceof DatabaseManager && $this->errorHandler instanceof ErrorHandler;
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    private function loadEnabledMethods(): void
    {
        $availableMethodIds = PaymentMethodsConfig::getAvailableMethodIds();

        if (!get_option('wallet_up_enabled_backfilled')) {
            $this->backfillEnabledFromHolders($availableMethodIds);
            update_option('wallet_up_enabled_backfilled', 1, false);
        }

        $savedMethods = get_option('wallet_up_enabled_methods', null);

        if (is_array($savedMethods) && !empty($savedMethods)) {
            if (array_keys($savedMethods) === range(0, count($savedMethods) - 1)) {
                $this->enabledMethods = [];
                foreach ($availableMethodIds as $methodId) {
                    $this->enabledMethods[$methodId] = in_array($methodId, $savedMethods, true);
                }
            } else {
                $this->enabledMethods = array_intersect_key($savedMethods, array_flip($availableMethodIds));
            }

            foreach ($availableMethodIds as $methodId) {
                if (!isset($this->enabledMethods[$methodId])) {
                    $this->enabledMethods[$methodId] = false;
                }
            }
        } else {
            $this->enabledMethods = array_fill_keys($availableMethodIds, false);
        }

    }

    private function registerPaymentMethods(): void
    {
        try {
            foreach (PaymentMethodsConfig::getAvailableMethodIds() as $id) {
                $class = PaymentMethodsConfig::getMethodClass($id);
                if (!$class) {
                    continue;
                }
                if (!class_exists($class)) {
                    continue;
                }
                if ($this->isMethodEnabled($id)) {
                    try {
                        $this->methods[$id] = new $class($this->db);
                    } catch (\Exception $e) {
                        $this->errorHandler->log("Failed to initialize payment method {$id}: " . $e->getMessage());
                        continue;
                    }
                }
            }

            if (empty($this->methods)) {
                return;
            }

        } catch (\Exception $e) {
            $this->errorHandler->log('Failed to register payment methods: ' . $e->getMessage());
            throw $e;
        }
    }

    private function registerShortcodes(): void
    {
        add_shortcode('wallet_up', [$this, 'handleGenericShortcode']);

        $isProVersion = class_exists('WalletUpPro\\Payments\\Bitcoin') || class_exists('WalletUpPro\\Payments\\Zelle');
        if (!$isProVersion) {
            $premiumMethods = array_diff(
                PaymentMethodsConfig::getAllMethodIds(),
                PaymentMethodsConfig::FREE_METHOD_IDS
            );
            foreach ($premiumMethods as $methodId) {
                add_shortcode($methodId, static function () use ($methodId) {
                    return self::upgradeNotice($methodId);
                });
            }
        }

        $supported = $isProVersion
            ? PaymentMethodsConfig::getAllMethodIds()
            : PaymentMethodsConfig::FREE_METHOD_IDS;
        foreach ($supported as $methodId) {
            if (shortcode_exists($methodId)) {
                continue;
            }
            add_shortcode($methodId, static function () use ($methodId) {
                return self::setupNotice($methodId);
            });
        }
    }

    public static function upgradeNotice(string $methodId): string
    {
        $name = PaymentMethodsConfig::getMethodName($methodId) ?: $methodId;
        $url  = \WalletUpPro\wallet_up_upgrade_url();
        $link = '<a href="' . esc_url($url) . '" style="color:#674FBF;font-weight:600;">';

        return sprintf(
            '<div class="walletup-upgrade-notice" style="padding:16px;border:1px solid #e2e8f0;border-radius:8px;text-align:center;color:#4a5568;">%s</div>',
            sprintf(
                /* translators: 1: payment method name, 2: link opening tag, 3: link closing tag */
                esc_html__('%1$s is available in %2$sWalletUp Pro%3$s.', 'walletup'),
                esc_html($name),
                $link,
                '</a>'
            )
        );
    }

    public static function setupNotice(string $methodId): string
    {
        if (!current_user_can('manage_options')) {
            return '';
        }
        $methodName = PaymentMethodsConfig::getMethodName($methodId) ?: $methodId;
        return sprintf(
            '<div class="walletup-shortcode-notice" style="padding:14px 16px;border:1px solid #ddd6fe;background:#f5f3ff;border-radius:10px;color:#4c1d95;font-size:14px;line-height:1.5;">' .
            '<strong>%s</strong> %s <a href="%s" style="color:#674FBF;font-weight:600;text-decoration:underline;">%s</a>.' .
            '</div>',
            esc_html__('Wallet Up:', 'walletup'),
            esc_html(sprintf(
                /* translators: %s = payment method name */
                __('%s is turned off. Turn it on and add your details under', 'walletup'),
                $methodName
            )),
            esc_url(admin_url('admin.php?page=wallet-up-payments')),
            esc_html__('Wallet Up → Payments', 'walletup')
        );
    }

    public function handleGenericShortcode($atts): string
    {
        try {
            $atts = shortcode_atts([
                'method' => '',
                'amount' => '',
                'note' => '',
                'scan' => '',
                'size' => ''
            ], $atts);

            if (empty($atts['method'])) {
                throw new \Exception(esc_html('Payment method must be specified for generic shortcode'));
            }

            $method = $this->getMethod($atts['method']);
            if (!$method) {
                $requested = $atts['method'];
                if (in_array($requested, PaymentMethodsConfig::FREE_METHOD_IDS, true)) {
                    return self::setupNotice($requested);
                }
                if (in_array($requested, PaymentMethodsConfig::getAllMethodIds(), true)) {
                    return self::upgradeNotice($requested);
                }
                throw new \Exception(esc_html(sprintf('Payment method %s not found', $requested)));
            }

            unset($atts['method']);

            return $method->processShortcode($atts);

        } catch (\Exception $e) {
            $this->errorHandler->log('Generic shortcode error: ' . $e->getMessage());
            return sprintf(
                '<div class="walletup-error"><p>%s</p></div>',
                esc_html__('Error processing payment shortcode.', 'walletup')
            );
        }
    }

    public static function hasNativeLinkFor(string $methodId): bool
    {
        try {
            $m = self::getInstance()->getMethod($methodId);
            if ($m && method_exists($m, 'hasNativeLink')) {
                return (bool) $m->hasNativeLink();
            }
        } catch (\Throwable $e) {
        }
        return true;
    }

    public static function qrPayloadFor(string $methodId, string $url, string $holder, string $amount = '', string $reference = ''): string
    {
        try {
            $m = self::getInstance()->getMethod($methodId);
            if ($m && method_exists($m, 'qrPayload')) {
                $own = trim((string) $m->qrPayload($amount, $reference));
                if ($own !== '') {
                    return $own;
                }
            }
        } catch (\Throwable $e) {
        }
        return PaymentMethodsConfig::qrPayload($methodId, $url, $holder);
    }

    public function getMethod(string $methodId)
    {
        if (!$this->isMethodEnabled($methodId)) {
            return null;
        }
        return $this->methods[$methodId] ?? null;
    }

    public function getAllMethods(): array
    {
        return array_filter($this->methods, function ($method, $methodId) {
            return $this->isMethodEnabled($methodId);
        }, ARRAY_FILTER_USE_BOTH);
    }
    private function getEnabledMethods(): array
    {
        $methods = [];
        foreach ($this->methods as $methodId => $method) { // Changed from $this->registry to $this->methods
            if ($this->isMethodEnabled($methodId)) {
                $methods[$methodId] = [
                    'name' => ucfirst($methodId),
                    'description' => $method->getDescription(),
                    'icon' => $this->getMethodIcon($methodId)
                ];
            }
        }
        return $methods;
    }
    private function getMethodIcon($methodId): string
    {
        return \WalletUpPro\Core\Display\DisplayHandler::methodLogoUrl($methodId);
    }
    public function isMethodEnabled(string $methodId): bool
    {
        return !empty($this->enabledMethods[$methodId]);
    }

    private function backfillEnabledFromHolders(array $availableMethodIds): void
    {
        $enabled  = (array) get_option('wallet_up_enabled_methods', []);
        $transfer = (array) get_option('wallet_up_transfer_call', []);
        $changed  = false;

        foreach ($availableMethodIds as $methodId) {
            if (PaymentMethodsConfig::isMobileMoney($methodId)) {
                continue; // mobile money carries its own enable flag
            }
            $hasHolder = trim((string) ($transfer[$methodId . '_holder'] ?? '')) !== '';
            $isOn = !empty($enabled[$methodId]) && $enabled[$methodId] !== 'false' && $enabled[$methodId] !== '0';
            $switchedOff = get_option("wallet_up_method_{$methodId}_enabled") === 'no';
            if ($hasHolder && !$isOn && !$switchedOff) {
                $enabled[$methodId] = true;
                $changed = true;
            }
        }

        if ($changed) {
            update_option('wallet_up_enabled_methods', $enabled);
        }
    }

    public function enableMethod(string $methodId): bool
    {
        if (isset($this->enabledMethods[$methodId])) {
            $this->enabledMethods[$methodId] = true;
            return update_option('wallet_up_enabled_methods', $this->enabledMethods);
        }
        return false;
    }

    public function disableMethod(string $methodId): bool
    {
        if (isset($this->enabledMethods[$methodId])) {
            $this->enabledMethods[$methodId] = false;
            return update_option('wallet_up_enabled_methods', $this->enabledMethods);
        }
        return false;
    }

    public function getMethodSettings(string $methodId): array
    {
        $method = $this->getMethod($methodId);
        if (!$method) {
            return [];
        }

        return [
            'id' => $methodId,
            'name' => $this->getMethodName($methodId),
            'enabled' => $this->isMethodEnabled($methodId),
            'holder' => $method->getHolder(),
            'display' => [
                'defaultMode' => 'qr',
                'qrSize' => 'medium',
                'qrColor' => '#674fbf',
                'qrBackground' => '#FFFFFF',
                'qrBorder' => false,
                'qrStyle' => 'square',
                'showDefaultMessage' => true,
                'customMessage' => '',
                'hideMessage' => false
            ]
        ];
    }

    private function getMethodName(string $methodId): string
    {
        return PaymentMethodsConfig::getMethodName($methodId);
    }

    public function getAvailableShortcodes(): array
    {
        $shortcodes = [];

        foreach ($this->getAllMethods() as $methodId => $method) {
            $shortcodes[$methodId] = [
                'tag' => $methodId,
                'name' => $this->getMethodName($methodId),
                'examples' => [
                    sprintf('[%s]', $methodId),
                    sprintf('[%s amount="50"]', $methodId),
                    sprintf('[%s amount="50" scan="yes"]', $methodId),
                    sprintf('[%s amount="50" scan="no"]', $methodId),
                    sprintf('[%s amount="50" scan="link"]', $methodId)
                ]
            ];
        }

        $shortcodes['wallet_up'] = [
            'tag' => 'wallet_up',
            'name' => 'Generic Payment',
            'examples' => [
                '[wallet_up method="paypal" amount="50"]',
                '[wallet_up method="venmo" amount="50" scan="yes"]',
                '[wallet_up method="cashapp" amount="50" scan="no"]'
            ]
        ];

        return $shortcodes;
    }
}