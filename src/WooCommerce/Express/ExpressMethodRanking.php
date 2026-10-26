<?php
namespace WalletUpPro\WooCommerce\Express;

use WalletUpPro\Core\Config\PaymentMethodsConfig;
use WalletUpPro\Core\Payments\MethodStatusResolver;

if (!defined('ABSPATH')) {
    exit;
}

class ExpressMethodRanking
{
    private const TYPE_DEVICE_WEIGHT = [
        'mobile'  => ['traditional' => 6, 'mobile_money' => 6, 'crypto' => 2],
        'tablet'  => ['traditional' => 5, 'mobile_money' => 5, 'crypto' => 2],
        'desktop' => ['traditional' => 4, 'mobile_money' => 3, 'crypto' => 3],
    ];

    private const CURRENCY_TYPE_AFFINITY = [
        'USD' => ['traditional' => 4],
        'CAD' => ['traditional' => 3],
        'KES' => ['mobile_money' => 6],
        'XOF' => ['mobile_money' => 6],
        'ZMW' => ['mobile_money' => 6],
        'GHS' => ['mobile_money' => 5],
        'NGN' => ['mobile_money' => 4],
    ];

    /**
     * Return live method ids ordered best-first for the given checkout context.
     *
     * @param array{device_type?:string, currency?:string, amount?:float, country?:string} $ctx
     * @return string[] Ordered method ids (subset of MethodStatusResolver configured set).
     */
    public static function rank(array $ctx = []): array
    {
        $snapshot   = MethodStatusResolver::snapshot();
        $candidates = array_values(array_filter($snapshot['configured'] ?? [], static function ($id) {
            return MethodStatusResolver::storeShows((string) $id) && \WalletUpPro\Core\Payments\Availability\MethodAvailability::shows((string) $id, 'express');
        }));
        if (empty($candidates)) {
            return [];
        }

        $device   = self::normalizeDevice($ctx['device_type'] ?? '');
        $currency = strtoupper((string) ($ctx['currency'] ?? ''));

        $analytics = self::analyticsScores($candidates, $device);
        $hasData   = array_sum($analytics) > 0;

        $scored = [];
        foreach ($candidates as $id) {
            $type = self::methodType($id);
            $scored[$id] = self::heuristicScore($type, $device, $currency)
                         + ($analytics[$id] ?? 0);
        }

        $order = array_flip($candidates);
        uksort($scored, function ($a, $b) use ($scored, $order) {
            if ($scored[$a] === $scored[$b]) {
                return ($order[$a] ?? 0) <=> ($order[$b] ?? 0);
            }
            return $scored[$b] <=> $scored[$a];
        });

        $ranked = array_keys($scored);
        return (array) apply_filters('wallet_up_express_ranking', $ranked, $scored, $hasData, $ctx);
    }

    public static function topMethods(int $limit = 3, array $ctx = []): array
    {
        $ordered = array_slice(self::rank($ctx), 0, max(1, $limit));
        $out = [];
        foreach ($ordered as $id) {
            $title = class_exists('\WalletUpPro\WooCommerce\WooCommerceMethodSettings')
                ? \WalletUpPro\WooCommerce\WooCommerceMethodSettings::getEffectiveTitle($id)
                : self::configName($id);
            $out[$id] = [
                'id'   => $id,
                'name' => $title,
                'icon' => self::methodIcon($id),
                'type' => self::methodType($id),
            ];
        }
        return $out;
    }

    /**
     * Per-method analytics score over the last 30 days. Completions weigh more
     * than intent signals (scan/click). Filtered to the shopper's device class
     * when rows for it exist, else falls back to all-device counts.
     *
     * @param string[] $methodIds
     * @return array<string,float> id => score (0 when no data)
     */
    private static function analyticsScores(array $methodIds, string $device): array
    {
        global $wpdb;
        $scores = array_fill_keys($methodIds, 0.0);

        $table = $wpdb->prefix . 'wallet_up_analytics';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return $scores;
        }

        $placeholders = implode(',', array_fill(0, count($methodIds), '%s'));

        // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT payment_method,
                    SUM(CASE WHEN event_type = 'payment_complete' THEN 3 ELSE 0 END) AS conv,
                    SUM(CASE WHEN event_type IN ('scan','click') THEN 1 ELSE 0 END) AS intent,
                    SUM(CASE WHEN device_type = %s THEN 1 ELSE 0 END) AS device_rows,
                    COUNT(*) AS total_rows
             FROM {$table}
             WHERE payment_method IN ({$placeholders})
               AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY payment_method",
            array_merge([$device], $methodIds)
        ));

        foreach ((array) $rows as $r) {
            $base = ((float) $r->conv) + ((float) $r->intent);
            $deviceBoost = ((int) $r->total_rows) > 0
                ? 1 + (((float) $r->device_rows) / (float) $r->total_rows)
                : 1;
            if (isset($scores[$r->payment_method])) {
                $scores[$r->payment_method] = $base * $deviceBoost;
            }
        }
        return $scores;
    }

    private static function heuristicScore(string $type, string $device, string $currency): int
    {
        $score = (self::TYPE_DEVICE_WEIGHT[$device][$type] ?? 0);
        if ($currency !== '' && isset(self::CURRENCY_TYPE_AFFINITY[$currency][$type])) {
            $score += self::CURRENCY_TYPE_AFFINITY[$currency][$type];
        }
        return $score;
    }

    private static function methodType(string $id): string
    {
        return PaymentMethodsConfig::PAYMENT_METHODS[$id]['type'] ?? 'traditional';
    }

    private static function configName(string $id): string
    {
        return PaymentMethodsConfig::PAYMENT_METHODS[$id]['name'] ?? ucfirst($id);
    }

    private static function methodIcon(string $id): string
    {
        return \WalletUpPro\Core\Display\DisplayHandler::methodLogoUrl($id);
    }

    private static function normalizeDevice(string $device): string
    {
        $device = strtolower(trim($device));
        return in_array($device, ['mobile', 'tablet', 'desktop'], true) ? $device : 'desktop';
    }
}
