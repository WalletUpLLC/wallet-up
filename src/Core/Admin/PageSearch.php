<?php

namespace WalletUpPro\Core\Admin;

if (!defined('ABSPATH')) {
    exit;
}

final class PageSearch
{
    public const ACTION = 'wallet_up_search_pages';
    public const LIMIT = 20;

    public static function register(): void
    {
        add_action('wp_ajax_' . self::ACTION, [self::class, 'handle']);
    }

    public static function handle(): void
    {
        if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('Invalid security token', 'walletup')], 403);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'walletup')], 403);
        }
        $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
        $include = isset($_POST['include']) ? absint(wp_unslash($_POST['include'])) : 0;
        wp_send_json_success(['pages' => self::find($search, $include)]);
    }

    public static function find(string $search, int $include = 0): array
    {
        $ids = get_posts([
            'post_type'        => 'page',
            'post_status'      => 'publish',
            's'                => $search,
            'orderby'          => $search === '' ? 'title' : 'relevance',
            'order'            => 'ASC',
            'posts_per_page'   => self::LIMIT,
            'fields'           => 'ids',
            'no_found_rows'    => true,
            'suppress_filters' => false,
        ]);
        $ids = array_map('intval', is_array($ids) ? $ids : []);
        if ($include > 0 && !in_array($include, $ids, true) && get_post_type($include) === 'page' && get_post_status($include) === 'publish') {
            array_unshift($ids, $include);
        }
        return array_map(static fn(int $id) => [
            'id'    => $id,
            'title' => html_entity_decode(get_the_title($id), ENT_QUOTES, 'UTF-8'),
        ], $ids);
    }
}
