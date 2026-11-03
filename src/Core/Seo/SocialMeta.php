<?php
namespace WalletUpPro\Core\Seo;

if (!defined('ABSPATH')) {
    exit;
}

final class SocialMeta
{
    public const LOGO_SIZE = 'full';
    public const ICON_SIZE = 512;
    public const LARGE_CARD_MIN = [300, 157];

    public static function render(array $page): string
    {
        $title = trim(wp_strip_all_tags((string) ($page['title'] ?? '')));
        $description = trim(wp_strip_all_tags((string) ($page['description'] ?? '')));
        $url = (string) ($page['url'] ?? '');
        $image = self::image((array) ($page['images'] ?? []));
        $site = (string) get_bloginfo('name');

        $tags = [
            ['property', 'og:type', 'website'],
            ['property', 'og:site_name', $site],
            ['property', 'og:title', $title],
            ['property', 'og:description', $description],
            ['property', 'og:url', $url],
            ['name', 'twitter:card', self::largeCard($image) ? 'summary_large_image' : 'summary'],
            ['name', 'twitter:title', $title],
            ['name', 'twitter:description', $description],
        ];
        if ($image) {
            $tags[] = ['property', 'og:image', $image['url']];
            $tags[] = ['property', 'og:image:secure_url', $image['url']];
            if ($image['width'] && $image['height']) {
                $tags[] = ['property', 'og:image:width', (string) $image['width']];
                $tags[] = ['property', 'og:image:height', (string) $image['height']];
            }
            $tags[] = ['property', 'og:image:alt', $title];
            $tags[] = ['name', 'twitter:image', $image['url']];
        }

        $out = '';
        foreach ($tags as [$attr, $key, $value]) {
            if ($value === '') {
                continue;
            }
            $out .= sprintf('<meta %s="%s" content="%s" />', $attr, esc_attr($key), esc_attr($value)) . "\n";
        }
        return $out;
    }

    private static function largeCard(?array $image): bool
    {
        if (!$image) {
            return false;
        }
        if (!$image['width'] || !$image['height']) {
            return true;
        }
        return $image['width'] >= self::LARGE_CARD_MIN[0] && $image['height'] >= self::LARGE_CARD_MIN[1];
    }

    public static function image(array $candidates): ?array
    {
        foreach ($candidates as $candidate) {
            $url = is_string($candidate) ? trim($candidate) : '';
            if ($url !== '' && wp_http_validate_url(self::absolute($url))) {
                return self::describe(self::absolute($url));
            }
        }
        $logo = (int) get_theme_mod('custom_logo');
        if ($logo) {
            $src = wp_get_attachment_image_src($logo, self::LOGO_SIZE);
            if ($src) {
                return ['url' => $src[0], 'width' => (int) $src[1], 'height' => (int) $src[2]];
            }
        }
        $icon = get_site_icon_url(self::ICON_SIZE);
        return $icon ? ['url' => $icon, 'width' => self::ICON_SIZE, 'height' => self::ICON_SIZE] : null;
    }

    private static function absolute(string $url): string
    {
        return strpos($url, '//') === 0 ? 'https:' . $url : (strpos($url, '/') === 0 ? home_url($url) : $url);
    }

    private static function describe(string $url): array
    {
        $id = attachment_url_to_postid($url);
        $src = $id ? wp_get_attachment_image_src($id, 'full') : false;
        return ['url' => $url, 'width' => $src ? (int) $src[1] : 0, 'height' => $src ? (int) $src[2] : 0];
    }
}
