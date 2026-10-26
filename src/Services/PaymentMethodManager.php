<?php
namespace WalletUpPro\Services;

use WalletUpPro\Core\BaseSingleton;
use WalletUpPro\Core\Error\ErrorHandler;

if (!defined('ABSPATH')) {
    exit;
}

class PaymentMethodManager extends BaseSingleton
{
    private $errorHandler;
    private $table;

    protected function initialize(): void
    {
        global $wpdb;
        $this->errorHandler = ErrorHandler::getInstance();
        $this->table = $wpdb->prefix . 'walletup_payment_methods';

        $this->registerHooks();
    }

    private function registerHooks(): void
    {
        add_action('wp_ajax_walletup_get_payment_methods', [$this, 'getAvailableMethodsAjax']);
        add_action('wp_ajax_nopriv_walletup_get_payment_methods', [$this, 'getAvailableMethodsAjax']);
    }

    public function getEnabledMethods(): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fresh data required for accurate results.
        $methods = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
            "SELECT * FROM {$this->table}
             WHERE is_enabled = 1
             ORDER BY display_order ASC"
        );

        return $this->formatMethods($methods);
    }

    public function getMethodsByCountry(string $countryCode): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fresh data required for accurate results.
        $methods = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
            "SELECT * FROM {$this->table}
             WHERE is_enabled = 1"
        );

        $available = [];
        foreach ($methods as $method) {
            if ($this->isMethodAvailableInCountry($method, $countryCode)) {
                $available[] = $method;
            }
        }

        return $this->formatMethods($available);
    }

    public function getMethodsByCurrency(string $currency): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fresh data required for accurate results.
        $methods = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
            "SELECT * FROM {$this->table}
             WHERE is_enabled = 1"
        );

        $available = [];
        foreach ($methods as $method) {
            if ($this->isMethodAvailableForCurrency($method, $currency)) {
                $available[] = $method;
            }
        }

        return $this->formatMethods($available);
    }

    public function getAutomatedMethods(): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fresh data required for accurate results.
        $methods = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
            "SELECT * FROM {$this->table}
             WHERE is_enabled = 1
             AND is_automated = 1
             ORDER BY display_order ASC"
        );

        return $this->formatMethods($methods);
    }

    public function calculateFee(string $methodId, float $amount): array
    {
        $method = $this->getMethod($methodId);

        if (!$method) {
            return [
                'fee_amount' => 0,
                'net_amount' => $amount,
                'total_amount' => $amount
            ];
        }

        $feeAmount = 0;

        switch ($method->fee_type) {
            case 'fixed':
                $feeAmount = floatval($method->fee_fixed);
                break;

            case 'percentage':
                $feeAmount = $amount * (floatval($method->fee_percentage) / 100);
                break;

            case 'hybrid':
                $percentageFee = $amount * (floatval($method->fee_percentage) / 100);
                $feeAmount = floatval($method->fee_fixed) + $percentageFee;
                break;
        }

        if ($method->fee_cap && $feeAmount > floatval($method->fee_cap)) {
            $feeAmount = floatval($method->fee_cap);
        }

        return [
            'fee_amount' => round($feeAmount, 2),
            'net_amount' => round($amount - $feeAmount, 2),
            'total_amount' => round($amount, 2)
        ];
    }

    public function getMethod(string $methodId): ?object
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fresh data required for accurate results.
        return $wpdb->get_row($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely derived from wpdb prefix.
            "SELECT * FROM {$this->table} WHERE method_id = %s",
            $methodId
        ));
    }

    public function updateMethodSettings(string $methodId, array $settings): bool
    {
        global $wpdb;

        $update = [];

        if (isset($settings['is_enabled'])) {
            $update['is_enabled'] = intval($settings['is_enabled']);
        }

        if (isset($settings['api_credentials'])) {
            $update['api_credentials'] = wp_json_encode($settings['api_credentials']);
        }

        if (isset($settings['settings'])) {
            $update['settings'] = wp_json_encode($settings['settings']);
        }

        if (empty($update)) {
            return false;
        }

        $update['updated_at'] = current_time('mysql');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Write operation, caching not applicable.
        $result = $wpdb->update(
            $this->table,
            $update,
            ['method_id' => $methodId]
        );

        return $result !== false;
    }

    public function getAvailableMethodsAjax(): void
    {
        try {
            if (is_admin() && !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'wallet_up_admin_nonce')) {
                wp_send_json_error('Invalid nonce', 403);
            }

            $country = isset($_POST['country']) ? sanitize_text_field(wp_unslash($_POST['country'])) : null;
            $currency = isset($_POST['currency']) ? sanitize_text_field(wp_unslash($_POST['currency'])) : 'USD';
            $amount = isset($_POST['amount']) ? floatval(wp_unslash($_POST['amount'])) : null;

            if ($country) {
                $methods = $this->getMethodsByCountry($country);
            } else {
                $methods = $this->getEnabledMethods();
            }

            if ($amount) {
                foreach ($methods as &$method) {
                    $method['fees'] = $this->calculateFee($method['method_id'], $amount);
                }
            }

            wp_send_json_success([
                'methods' => $methods,
                'count' => count($methods)
            ]);

        } catch (\Exception $e) {
            $this->errorHandler->log('Get payment methods error: ' . $e->getMessage());
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    private function formatMethods(array $methods): array
    {
        $formatted = [];

        foreach ($methods as $method) {
            $formatted[] = [
                'method_id' => $method->method_id,
                'name' => $method->method_name,
                'type' => $method->method_type,
                'icon' => $method->icon_url,
                'description' => $method->description,
                'is_automated' => (bool) $method->is_automated,
                'requires_manual_verification' => (bool) $method->requires_manual_verification,
                'fee_type' => $method->fee_type,
                'fee_fixed' => floatval($method->fee_fixed),
                'fee_percentage' => floatval($method->fee_percentage),
                'min_amount' => $method->min_amount ? floatval($method->min_amount) : null,
                'max_amount' => $method->max_amount ? floatval($method->max_amount) : null,
                'supported_countries' => json_decode($method->supported_countries, true),
                'supported_currencies' => json_decode($method->supported_currencies, true)
            ];
        }

        return $formatted;
    }

    private function isMethodAvailableInCountry(object $method, string $countryCode): bool
    {
        $countries = json_decode($method->supported_countries, true);

        if (empty($countries)) {
            return true;
        }

        if (in_array('*', $countries)) {
            return true;
        }

        return in_array($countryCode, $countries);
    }

    private function isMethodAvailableForCurrency(object $method, string $currency): bool
    {
        $currencies = json_decode($method->supported_currencies, true);

        if (empty($currencies)) {
            return true;
        }

        return in_array($currency, $currencies);
    }
}
