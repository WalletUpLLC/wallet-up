<?php
declare(strict_types=1);

namespace WalletUpPro\Core\Tenancy;

if (!defined('ABSPATH')) {
    exit;
}

final class TenantOverlay
{
    private const PRIORITY = 1;

    private array $filters = [];

    public static function apply(string $tenantId): self
    {
        $overlay = new self();
        $options = apply_filters(Tenant::FILTER_OPTIONS, [], $tenantId);
        foreach ((array) $options as $name => $value) {
            $name = (string) $name;
            $overlay->attach('pre_option_' . $name, static fn() => $value, 1);
            $overlay->attach(
                'pre_update_option_' . $name,
                static function (mixed $incoming, mixed $stored) use ($name, $tenantId): mixed {
                    if (!apply_filters(Tenant::FILTER_BLOCK, true, $name, $tenantId)) {
                        return $incoming;
                    }
                    do_action(Tenant::ACTION_BLOCKED, $name, $tenantId);
                    return $stored;
                },
                2
            );
            $overlay->attach('pre_add_option_' . $name, static fn() => $value, 1);
        }
        $transients = apply_filters(Tenant::FILTER_TRANSIENTS, [], $tenantId);
        foreach ((array) $transients as $name => $value) {
            $overlay->attach('pre_transient_' . (string) $name, static fn() => $value, 1);
            $overlay->attach('pre_set_transient_' . (string) $name, static fn() => false, 1);
        }
        $lists = apply_filters(Tenant::FILTER_LISTS, [], $tenantId);
        foreach ((array) $lists as $name => $rule) {
            $name = (string) $name;
            $rule = (array) $rule;
            $key  = (string) ($rule['key'] ?? 'id');
            $ids  = array_fill_keys(array_map('strval', (array) ($rule['ids'] ?? [])), true);
            $keyed = !empty($rule['keyed']);
            $overlay->attach('option_' . $name, static fn(mixed $stored): mixed => self::subset($stored, $key, $ids, $keyed), 1);
            $overlay->attach(
                'pre_update_option_' . $name,
                static function (mixed $incoming, mixed $stored) use ($name, $key, $ids, $keyed, $tenantId): mixed {
                    return self::merge($name, $incoming, $stored, $key, $ids, $keyed, $tenantId);
                },
                2
            );
        }
        return $overlay;
    }

    public function release(): void
    {
        foreach ($this->filters as [$name, $callback]) {
            remove_filter($name, $callback, self::PRIORITY);
        }
        $this->filters = [];
    }

    private function attach(string $name, callable $callback, int $args): void
    {
        add_filter($name, $callback, self::PRIORITY, $args);
        $this->filters[] = [$name, $callback];
    }

    private static function subset(mixed $stored, string $key, array $ids, bool $keyed): mixed
    {
        if (!is_array($stored)) {
            return $stored;
        }
        $out = [];
        foreach ($stored as $index => $item) {
            $id = $keyed ? (string) $index : (string) (is_array($item) ? ($item[$key] ?? '') : '');
            if ($id !== '' && isset($ids[$id])) {
                $out[$index] = $item;
            }
        }
        return $keyed ? $out : array_values($out);
    }

    private static function merge(string $name, mixed $incoming, mixed $stored, string $key, array $ids, bool $keyed, string $tenantId): mixed
    {
        if (!is_array($incoming)) {
            return $stored;
        }
        $stored = is_array($stored) ? $stored : [];
        $mine   = [];
        $added  = [];
        foreach ($incoming as $index => $item) {
            $id = $keyed ? (string) $index : (string) (is_array($item) ? ($item[$key] ?? '') : '');
            if ($id === '') {
                continue;
            }
            $mine[$id] = $item;
            if (!isset($ids[$id])) {
                $added[] = $id;
            }
        }
        $out = [];
        foreach ($stored as $index => $item) {
            $id = $keyed ? (string) $index : (string) (is_array($item) ? ($item[$key] ?? '') : '');
            if ($id !== '' && isset($ids[$id])) {
                continue;
            }
            if ($keyed) {
                $out[$index] = $item;
            } else {
                $out[] = $item;
            }
        }
        foreach ($mine as $id => $item) {
            if ($keyed) {
                $out[$id] = $item;
            } else {
                $out[] = $item;
            }
        }
        if ($added !== []) {
            do_action(Tenant::ACTION_LIST_ADDED, $name, $added, $tenantId);
        }
        return $out;
    }
}
