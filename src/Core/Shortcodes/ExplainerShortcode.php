<?php

namespace WalletUpPro\Core\Shortcodes;

if (!defined('ABSPATH')) {
    exit;
}

class ExplainerShortcode
{
    private const ENTRY = 'src/explainer.jsx';

    public static function register(): void
    {
        add_shortcode('walletup_explainer', [self::class, 'render']);
    }

    public static function render($atts = []): string
    {
        $atts = shortcode_atts([
            'locale' => '',
        ], is_array($atts) ? $atts : [], 'walletup_explainer');

        if (!self::enqueue()) {
            return '';
        }

        $locale = $atts['locale'] !== ''
            ? sanitize_key($atts['locale'])
            : substr(determine_locale(), 0, 2);

        return sprintf(
            '<div class="walletup-explainer-embed" data-walletup-explainer data-locale="%s"></div>',
            esc_attr($locale)
        );
    }

    private static function enqueue(): bool
    {
        $manifestPath = WALLET_UP_PATH . 'walup-react/dist/.vite/manifest.json';
        if (!file_exists($manifestPath)) {
            return false;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local read
        $manifest = json_decode(file_get_contents($manifestPath), true);
        if (empty($manifest[self::ENTRY]['file'])) {
            return false;
        }

        $baseUrl = WALLET_UP_URL . 'walup-react/dist/';
        $version = defined('WALLET_UP_PRO_VERSION') ? WALLET_UP_PRO_VERSION : '1.0.0';

        $cssFiles = [];
        $seen = [];
        $collect = function ($key) use (&$collect, $manifest, &$cssFiles, &$seen) {
            if (empty($manifest[$key]) || !empty($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            foreach (($manifest[$key]['css'] ?? []) as $c) {
                $cssFiles[$c] = true;
            }
            foreach (($manifest[$key]['imports'] ?? []) as $imp) {
                $collect($imp);
            }
        };
        $collect(self::ENTRY);

        $i = 0;
        foreach (array_keys($cssFiles) as $css) {
            wp_enqueue_style('walletup-explainer-' . $i, $baseUrl . $css, [], $version);
            $i++;
        }

        wp_enqueue_script(
            'walletup-explainer',
            $baseUrl . $manifest[self::ENTRY]['file'],
            [],
            $version,
            true
        );
        add_filter('script_loader_tag', [self::class, 'asModule'], 10, 3);

        return true;
    }

    public static function asModule(string $tag, string $handle, string $src): string
    {
        if ($handle !== 'walletup-explainer') {
            return $tag;
        }
        return sprintf('<script type="module" src="%s" id="%s-js"></script>', esc_url($src), esc_attr($handle));
    }
}
