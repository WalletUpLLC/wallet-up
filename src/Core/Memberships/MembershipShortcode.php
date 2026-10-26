<?php
namespace WalletUpPro\Core\Memberships;

if (!defined('ABSPATH')) {
    exit;
}

class MembershipShortcode
{
    public static function register(): void
    {
        add_shortcode('walletup_membership_form', [self::class, 'render']);
    }

    public static function render($atts = []): string
    {
        $atts = shortcode_atts([
            'tier_id' => '0',
            'color'   => '#7c3aed',
        ], (array) $atts, 'walletup_membership_form');

        self::enqueue();

        return sprintf(
            '<div class="walup-membership-form-root" data-tier-id="%s" data-color="%s"></div>',
            esc_attr($atts['tier_id']),
            esc_attr($atts['color'])
        );
    }

    private static function enqueue(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $manifestPath = WALLET_UP_PATH . 'walup-react/dist/.vite/manifest.json';
        if (!file_exists($manifestPath)) {
            return;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local read
        $manifest = json_decode(file_get_contents($manifestPath), true);
        $entryKey = 'src/membershipForm.jsx';
        if (empty($manifest[$entryKey])) {
            return;
        }
        $baseUrl = WALLET_UP_URL . 'walup-react/dist/';

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
        $collect($entryKey);
        $i = 0;
        foreach (array_keys($cssFiles) as $cssFile) {
            wp_enqueue_style('walup-membership-form-css-' . ($i++), $baseUrl . $cssFile, [], WALLET_UP_PRO_VERSION);
        }

        wp_enqueue_script('walup-membership-form', $baseUrl . $manifest[$entryKey]['file'], [], WALLET_UP_PRO_VERSION, true);
        add_filter('script_loader_tag', function ($tag, $handle) {
            if ($handle === 'walup-membership-form') {
                $tag = str_replace(' src=', ' type="module" src=', $tag);
            }
            return $tag;
        }, 10, 2);

        $automatedApis = class_exists('\\WalletUpPro\\Core\\Config\\AutomatedApiConfig')
            ? \WalletUpPro\Core\Config\AutomatedApiConfig::getPublicConfig()
            : [];

        wp_localize_script('walup-membership-form', 'walletUpData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'siteUrl' => site_url(),
            'nonce'   => wp_create_nonce('wallet_up_admin_nonce'),
            'nonces'  => [
                'walup_submit_membership' => wp_create_nonce('walup_submit_membership'),
                'wallet_up_admin'         => wp_create_nonce('wallet_up_admin_nonce'),
            ],
            'settings' => [
                'stripe_publishable_key'   => $automatedApis['stripe']['publishable_key'] ?? '',
                'stripe_connected_account' => $automatedApis['stripe']['connected_account'] ?? '',
                'automatedApis'            => $automatedApis,
            ],
        ]);
    }
}
