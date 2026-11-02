<?php
declare(strict_types=1);

namespace WalletUpPro\Services;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The scan-redirect routes (/wallet-up/scan/… and ?wup_scan=…), split out of QRCodeService in
 * 6.7.0 so a plain page view registers them without loading the 150 KB QR engine. The engine is
 * loaded only when a scan request actually arrives.
 */
final class ScanRoutes
{
    public static function register(): void
    {
        self::registerCacheBypass();
        add_action('init', [self::class, 'registerScanRewriteRule'], 0);
        add_action('init', [self::class, 'handleScanRedirect'], 1);
        add_action('parse_request', [self::class, 'handleScanRedirect'], 0);
    }

    public static function isScanRequest($wp = null): bool
    {
        if ($wp instanceof \WP && is_array($wp->query_vars) && !empty($wp->query_vars['wup_scan'])) {
            return true;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence check only
        if (!empty($_GET['wup_scan'])) {
            return true;
        }
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared as a plain string only
        return $uri !== '' && strpos($uri, '/wallet-up/scan/') !== false;
    }

    /** Delegates to the QR engine only when this is a scan request. */
    public static function handleScanRedirect($wp = null): void
    {
        if (!self::isScanRequest($wp)) {
            return;
        }
        QRCodeService::handleScanRedirect($wp);
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
            if (self::isScanRequest() && !defined('DONOTCACHEPAGE')) {
                define('DONOTCACHEPAGE', true);
            }
        }, 0);
    }
}
