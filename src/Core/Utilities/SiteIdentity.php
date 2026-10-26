<?php

declare(strict_types=1);

namespace WalletUpPro\Core\Utilities;

if (!defined('ABSPATH')) {
    exit;
}

final class SiteIdentity
{
    public const FILTER_NAME = 'wallet_up_pro_site_name_text';

    public static function name(): string
    {
        return self::asText((string) get_bloginfo('name'));
    }

    public static function tagline(): string
    {
        return self::asText((string) get_bloginfo('description'));
    }

    public static function asText(string $value): string
    {
        $text = wp_specialchars_decode($value, ENT_QUOTES);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(wp_strip_all_tags($text));
    }

    public static function nameForDisplay(): string
    {
        return (string) apply_filters(self::FILTER_NAME, self::name());
    }
}
