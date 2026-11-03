<?php
namespace WalletUpPro\Core\Assets;

if (!defined('ABSPATH')) {
    exit;
}

final class ViteManifest
{
    public static function css(array $manifest, string $entry): array
    {
        $files = [];
        $seen = [];
        $walk = function (string $key) use (&$walk, $manifest, &$files, &$seen): void {
            if (empty($manifest[$key]) || isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            foreach ((array) ($manifest[$key]['imports'] ?? []) as $import) {
                $walk((string) $import);
            }
            foreach ((array) ($manifest[$key]['css'] ?? []) as $css) {
                $files[(string) $css] = true;
            }
        };
        $walk($entry);
        return array_keys($files);
    }
}
