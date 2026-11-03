<?php
namespace WalletUpPro\Core;

if (!defined('ABSPATH')) {
    exit;
}

final class Brand
{
    public const HEX = '#674FBF';
    public const DARK_HEX = '#503AA1';
    public const ON_BRAND_HEX = '#FFFFFF';
    public const RGB = '103, 79, 191';

    public static function buttonStyle(): string
    {
        return sprintf('background:%1$s;border-color:%1$s;color:%2$s;', self::HEX, self::ON_BRAND_HEX);
    }

    public static function rgbComponents(): array
    {
        return array_map('hexdec', str_split(substr(self::HEX, 1), 2));
    }

    public static function outlineStyle(): string
    {
        return sprintf('border-color:%1$s;color:%1$s;', self::HEX);
    }
}
