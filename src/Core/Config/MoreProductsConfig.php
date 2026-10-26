<?php
namespace WalletUpPro\Core\Config;

if (!defined('ABSPATH')) {
    exit;
}

class MoreProductsConfig
{
    const PRODUCTS = [
        'widewalk' => [
            'name'        => 'Widewalk',
            'category'    => 'plugin',
            'accent'      => 'blue',
            'url'         => 'https://wordpress.org/plugins/widewalk/',
            'tagline'     => 'A private accessibility toolbar for any WordPress site',
            'description' => 'Widewalk adds a lightweight accessibility panel so visitors can adjust text size, contrast, spacing and reading preferences, with no tracking and no third-party services.',
            'highlights'  => [
                'Free on WordPress.org',
                'No tracking, no third-party calls',
                'Works with any theme, opens from any link',
            ],
        ],
        'beacon' => [
            'name'        => 'Beacon',
            'category'    => 'theme',
            'accent'      => 'purple',
            'url'         => 'https://walletup.app/beacon/',
            'tagline'     => 'A block theme with a Studio panel and a Collections store',
            'description' => 'Beacon is a full-site-editing theme with donation and campaign tools, a Studio control panel for styling, and a WooCommerce-backed Collections store for selling your work.',
            'highlights'  => [
                'Donation and campaign tools',
                'A Studio control panel',
                'A sellable Collections store',
            ],
        ],
        'beacon-toolkit' => [
            'name'        => 'Beacon Toolkit',
            'category'    => 'plugin',
            'accent'      => 'green',
            'url'         => 'https://walletup.app/beacon/',
            'tagline'     => 'The companion plugin that powers the Beacon theme',
            'description' => 'The site kit for the Beacon theme: a mega-menu header, electronic forms with signatures, donation widgets, content blocks and a site control panel.',
            'highlights'  => [
                'Mega-menu header and identity footer',
                'Forms with signatures and donation widgets',
                'Content blocks and a site control panel',
            ],
        ],
        'walup-showroom' => [
            'name'        => 'Wallet Up Showroom',
            'category'    => 'plugin',
            'accent'      => 'amber',
            'url'         => 'https://walletup.app/beacon/',
            'tagline'     => 'A branded showroom for your products',
            'description' => 'Wallet Up Showroom presents your products and merchandise as polished, branded showcases, each with its own identity, pages and payment wiring.',
            'highlights'  => [
                'Polished product showcases',
                'A distinct identity per showcase',
                'Payment wiring built in',
            ],
        ],
    ];

    public static function getAll(): array
    {
        $out = [];
        foreach (self::PRODUCTS as $id => $product) {
            $out[] = array_merge(['id' => $id], $product);
        }
        return $out;
    }

    public static function count(): int
    {
        return count(self::PRODUCTS);
    }
}
