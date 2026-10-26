<?php

declare(strict_types=1);

namespace WalletUpPro\Core\Payments\Availability;

if (!defined('ABSPATH')) {
    exit;
}

final class SurfaceStore
{
    public const OPTION = 'wallet_up_surface_methods';

    private static ?array $cache = null;

    public static function key(string $surface, ?string $context = null, ?string $id = null): string
    {
        if ($context === null || $id === null || $id === '') {
            return $surface;
        }
        return $surface . ':' . $context . ':' . $id;
    }

    public static function read(string $key): array
    {
        $all = self::load();
        $row = isset($all[$key]) && is_array($all[$key]) ? $all[$key] : [];
        return [
            'hidden' => self::ids($row['hidden'] ?? []),
            'shown'  => array_key_exists('shown', $row) && is_array($row['shown']) ? self::ids($row['shown']) : null,
            'previous' => isset($row['previous']) && is_array($row['previous']) ? $row['previous'] : null,
        ];
    }

    public static function has(string $key): bool
    {
        return isset(self::load()[$key]);
    }

    public static function write(string $key, array $row): void
    {
        $all = self::load();
        $clean = ['hidden' => self::ids($row['hidden'] ?? [])];
        if (array_key_exists('shown', $row)) {
            $clean['shown'] = is_array($row['shown']) ? self::ids($row['shown']) : null;
        }
        if (isset($row['previous']) && is_array($row['previous'])) {
            $clean['previous'] = $row['previous'];
        }
        $all[$key] = $clean;
        self::save($all);
    }

    public static function keys(): array
    {
        return array_keys(self::load());
    }

    public static function forgetMethod(string $methodId): void
    {
        $all = self::load();
        foreach ($all as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            $row['hidden'] = array_values(array_diff(self::ids($row['hidden'] ?? []), [$methodId]));
            if (isset($row['shown']) && is_array($row['shown'])) {
                $row['shown'] = array_values(array_diff(self::ids($row['shown']), [$methodId]));
            }
            $all[$key] = $row;
        }
        self::save($all);
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    private static function ids($list): array
    {
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $id) {
            $id = sanitize_key((string) $id);
            if ($id !== '' && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        return $out;
    }

    private static function load(): array
    {
        if (self::$cache === null) {
            $raw = get_option(self::OPTION, []);
            self::$cache = is_array($raw) ? $raw : [];
        }
        return self::$cache;
    }

    private static function save(array $all): void
    {
        update_option(self::OPTION, $all, false);
        self::$cache = $all;
        MethodAvailability::flush();
    }
}
