<?php

namespace WalletUpPro\WooCommerce;

if (!defined('ABSPATH')) {
    exit;
}

class OrderConfirmationBlocks
{
    private const TEMPLATE_ID = 'woocommerce/woocommerce//order-confirmation';
    private const TEMPLATE_SLUG = 'order-confirmation';

    private const SECTION_MARKERS = [
        'thank_you'       => 'order-confirmation-status',
        'order_summary'   => 'order-confirmation-summary',
        'order_details'   => 'order-confirmation-totals',
        'billing_address' => 'order-confirmation-billing',
        'payment'         => 'order-confirmation-additional-information',
    ];

    public static function boot(): void
    {
        if (did_action('init')) {
            self::registerBlock();
        } else {
            add_action('init', [self::class, 'registerBlock']);
        }

        add_filter('get_block_templates', [self::class, 'filterTemplates'], 20, 1);
        add_filter('get_block_template', [self::class, 'filterTemplate'], 20, 2);
    }

    public static function registerBlock(): void
    {
        if (function_exists('register_block_type') && !\WP_Block_Type_Registry::get_instance()->is_registered('walletup/order-confirmation')) {
            register_block_type('walletup/order-confirmation', [
                'render_callback' => [self::class, 'renderBlock'],
            ]);
        }
    }

    public static function renderBlock($attributes = [], $content = ''): string
    {
        $order = self::currentOrder();
        if (!$order instanceof \WC_Order) {
            return '';
        }
        StripeCardGateway::verifyReturn($order->get_id());
        return ConfirmationPage::buildFromOrder($order);
    }

    public static function filterTemplates($templates)
    {
        if (!is_array($templates) || !self::shouldApply()) {
            return $templates;
        }
        foreach ($templates as $i => $template) {
            if (is_object($template) && isset($template->slug) && $template->slug === self::TEMPLATE_SLUG && !empty($template->content)) {
                $clone = clone $template;
                self::apply($clone);
                $templates[$i] = $clone;
            }
        }
        return $templates;
    }

    public static function filterTemplate($template, $id)
    {
        if (is_object($template) && $id === self::TEMPLATE_ID && !empty($template->content) && self::shouldApply()) {
            $clone = clone $template;
            self::apply($clone);
            return $clone;
        }
        return $template;
    }

    private static function shouldApply(): bool
    {
        if (get_option('wallet_up_confirmation_display_enabled', 'no') !== 'yes') {
            return false;
        }
        $order = self::currentOrder();
        if (!$order instanceof \WC_Order) {
            return false;
        }
        return ConfirmationPage::rendersOrder($order);
    }

    private static function apply($template): void
    {
        try {
            $rewritten = self::reorder($template->content);
            if ($rewritten !== null) {
                $template->content = $rewritten;
            }
        } catch (\Throwable $e) {
        }
    }

    private static function currentOrder(): ?\WC_Order
    {
        $order_id = absint(get_query_var('order-received'));
        if (!$order_id && isset($GLOBALS['wp']->query_vars['order-received'])) {
            $order_id = absint($GLOBALS['wp']->query_vars['order-received']);
        }
        if (!$order_id) {
            return null;
        }
        $order = wc_get_order($order_id);
        return $order instanceof \WC_Order ? $order : null;
    }

    private static function reorder(string $content): ?string
    {
        $blocks = parse_blocks($content);
        $applied = false;

        $walk = function (array &$nodes) use (&$walk, &$applied) {
            foreach ($nodes as &$node) {
                if (!empty($node['innerBlocks'])) {
                    if (self::isSectionContainer($node['innerBlocks'])) {
                        $node = self::replaceContainer($node);
                        $applied = true;
                    } else {
                        $walk($node['innerBlocks']);
                    }
                }
            }
            unset($node);
        };
        $walk($blocks);

        return $applied ? serialize_blocks($blocks) : null;
    }

    private static function isSectionContainer(array $children): bool
    {
        $hits = 0;
        foreach ($children as $child) {
            if (self::classify($child) !== null) {
                $hits++;
            }
        }
        return $hits >= 2;
    }

    private static function classify(array $block): ?string
    {
        $serialized = serialize_block($block);
        foreach (self::SECTION_MARKERS as $section => $marker) {
            if (strpos($serialized, $marker) !== false) {
                return $section;
            }
        }
        return null;
    }

    private static function replaceContainer(array $group): array
    {
        $ourBlock = [
            'blockName'    => 'walletup/order-confirmation',
            'attrs'        => [],
            'innerBlocks'  => [],
            'innerHTML'    => '',
            'innerContent' => [],
        ];
        $group['innerBlocks'] = [$ourBlock];
        $group['innerContent'] = self::rebuildInnerContent($group['innerContent'], 1);
        return $group;
    }

    private static function rebuildInnerContent(array $innerContent, int $childCount): array
    {
        $strings = array_values(array_filter($innerContent, static fn($x) => $x !== null));
        $lead = $strings[0] ?? '';
        $trail = count($strings) > 1 ? end($strings) : '';

        $rebuilt = [$lead];
        for ($i = 0; $i < $childCount; $i++) {
            $rebuilt[] = null;
        }
        $rebuilt[] = $trail;
        return $rebuilt;
    }
}
