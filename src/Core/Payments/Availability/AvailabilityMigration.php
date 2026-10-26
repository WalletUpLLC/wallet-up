<?php

declare(strict_types=1);

namespace WalletUpPro\Core\Payments\Availability;

use WalletUpPro\Core\Payments\MethodStatusResolver;
use WalletUpPro\Core\Settings\SettingsRegistry;

if (!defined('ABSPATH')) {
    exit;
}

final class AvailabilityMigration
{
    public const DONE_OPTION = 'wallet_up_availability_migrated';

    private const LIST_SURFACES = [SurfaceRegistry::STORE, SurfaceRegistry::WPFORMS];

    public static function plan(string $policy): array
    {
        return self::isDone() ? self::conversionPlan($policy) : self::firstPlan($policy);
    }

    private static function conversionPlan(string $policy): array
    {
        $targets = [];
        foreach (SurfaceRegistry::ids() as $surface) {
            if (!SurfaceRegistry::isReadOnly($surface)) {
                $targets[] = ['key' => $surface, 'surface' => $surface, 'context' => null, 'id' => null, 'label' => $surface];
            }
        }
        foreach (AvailabilityContexts::all() as $ctx) {
            if (SurfaceStore::has($ctx['key'])) {
                $targets[] = $ctx;
            }
        }
        $rows = [];
        foreach ($targets as $t) {
            $before = MethodAvailability::forSurface($t['surface'], $t['context'], $t['id']);
            $supported = self::supportedReady($t['surface']);
            $row = $policy === SettingsRegistry::POLICY_ASK
                ? ['hidden' => [], 'shown' => $before]
                : ['hidden' => array_values(array_diff($supported, $before))];
            $after = $policy === SettingsRegistry::POLICY_ASK
                ? array_values(array_intersect($supported, $before))
                : array_values(array_diff($supported, $row['hidden']));
            $rows[] = [
                'key' => $t['key'], 'surface' => $t['surface'], 'context' => $t['context'], 'id' => $t['id'], 'label' => $t['label'],
                'before' => $before, 'after' => $after,
                'added' => array_values(array_diff($after, $before)), 'removed' => array_values(array_diff($before, $after)),
                'row' => $row,
            ];
        }
        return $rows;
    }

    private static function firstPlan(string $policy): array
    {
        $rows = [];
        foreach (self::targets() as $target) {
            $before = $target['before'];
            $row = self::rowFor($policy, $target);
            $after = self::afterFor($policy, $row, $target);
            $rows[] = [
                'key'     => $target['key'],
                'surface' => $target['surface'],
                'context' => $target['context'],
                'id'      => $target['id'],
                'label'   => $target['label'],
                'before'  => $before,
                'after'   => $after,
                'added'   => array_values(array_diff($after, $before)),
                'removed' => array_values(array_diff($before, $after)),
                'row'     => $row,
            ];
        }
        return $rows;
    }

    public static function apply(string $policy): array
    {
        $plan = self::plan($policy);
        foreach ($plan as $item) {
            if ($item['row'] === null) {
                continue;
            }
            $previous = SurfaceStore::has($item['key']) ? SurfaceStore::read($item['key']) : null;
            SurfaceStore::write($item['key'], $item['row'] + ['previous' => ['policy' => self::currentPolicy(), 'row' => $previous]]);
        }
        update_option(self::DONE_OPTION, ['at' => gmdate('c'), 'policy' => $policy], false);
        MethodAvailability::flush();
        return $plan;
    }

    public static function switchPolicy(string $policy): array
    {
        $plan = self::apply($policy);
        SettingsRegistry::set(SettingsRegistry::SURFACE_POLICY, $policy);
        return $plan;
    }

    public static function isDone(): bool
    {
        return (bool) get_option(self::DONE_OPTION, false);
    }

    public static function changes(array $plan): array
    {
        return array_values(array_filter($plan, static function (array $item) {
            return $item['added'] !== [] || $item['removed'] !== [];
        }));
    }

    private static function currentPolicy(): string
    {
        return (string) SettingsRegistry::get(SettingsRegistry::SURFACE_POLICY);
    }

    private static function targets(): array
    {
        $ready = self::ready();
        $targets = [];
        foreach (self::LIST_SURFACES as $surface) {
            $targets[] = [
                'key' => $surface, 'surface' => $surface, 'context' => null, 'id' => null,
                'label' => $surface, 'legacy' => self::surfaceLegacy($surface),
                'before' => self::shownToday($surface, null, $ready),
            ];
        }
        foreach (AvailabilityContexts::all() as $ctx) {
            $targets[] = $ctx + ['before' => self::shownToday($ctx['surface'], $ctx, $ready)];
        }
        return $targets;
    }

    private static function rowFor(string $policy, array $target): ?array
    {
        $legacy = $target['legacy'];
        if (!is_array($legacy) || array_filter($legacy) === []) {
            return null;
        }
        $supported = self::supportedReady($target['surface']);
        if ($policy === SettingsRegistry::POLICY_ASK) {
            return ['hidden' => [], 'shown' => array_values(array_intersect($target['before'], $supported))];
        }
        $hidden = [];
        foreach ($legacy as $method => $on) {
            if (!$on && in_array($method, $supported, true)) {
                $hidden[] = $method;
            }
        }
        return ['hidden' => $hidden];
    }

    private static function afterFor(string $policy, ?array $row, array $target): array
    {
        $supported = self::supportedReady($target['surface']);
        if ($row === null) {
            return $supported;
        }
        if ($policy === SettingsRegistry::POLICY_ASK) {
            return array_values(array_intersect($supported, $row['shown'] ?? []));
        }
        return array_values(array_diff($supported, $row['hidden']));
    }

    private static function shownToday(string $surface, ?array $ctx, array $ready): array
    {
        $legacy = $ctx !== null ? $ctx['legacy'] : self::surfaceLegacy($surface);
        $supported = ($ctx !== null && in_array($ctx['context'], ['payer', 'page'], true))
            ? self::supportedSwitchedOn($surface)
            : self::supportedReady($surface);
        if (!is_array($legacy) || array_filter($legacy) === []) {
            return $supported;
        }
        return array_values(array_filter($supported, static function ($m) use ($legacy) {
            return !empty($legacy[$m]);
        }));
    }

    private static function surfaceLegacy(string $surface): ?array
    {
        if ($surface === SurfaceRegistry::STORE) {
            $gateway = (array) get_option('woocommerce_wallet_up_settings', []);
            $map = [];
            foreach (array_keys(MethodAvailability::catalog()) as $methodId) {
                $map[$methodId] = MethodStatusResolver::flag($gateway['enable_' . $methodId] ?? 'yes');
            }
            return $map;
        }
        if ($surface === SurfaceRegistry::WPFORMS) {
            $settings = (array) get_option('wallet_up_wpforms_settings', []);
            $methods = (array) ($settings['paymentSettings']['methods'] ?? []);
            if ($methods === []) {
                return null;
            }
            $map = [];
            foreach (array_keys(MethodAvailability::catalog()) as $methodId) {
                $map[$methodId] = isset($methods[$methodId]) ? MethodStatusResolver::flag($methods[$methodId]['enabled'] ?? false) : true;
            }
            return $map;
        }
        return null;
    }

    private static function supportedReady(string $surface): array
    {
        $out = [];
        foreach (MethodAvailability::catalog() as $methodId => $type) {
            if (SurfaceRegistry::supportsType($surface, $type) && MethodAvailability::globalState($methodId) === MethodAvailability::READY) {
                $out[] = $methodId;
            }
        }
        return $out;
    }

    private static function supportedSwitchedOn(string $surface): array
    {
        $out = [];
        foreach (MethodAvailability::catalog() as $methodId => $type) {
            $state = MethodAvailability::globalState($methodId);
            if (SurfaceRegistry::supportsType($surface, $type) && in_array($state, [MethodAvailability::READY, MethodAvailability::NEEDS_SETUP], true)) {
                $out[] = $methodId;
            }
        }
        return $out;
    }

    private static function ready(): array
    {
        $out = [];
        foreach (array_keys(MethodAvailability::catalog()) as $methodId) {
            if (MethodAvailability::globalState($methodId) === MethodAvailability::READY) {
                $out[] = $methodId;
            }
        }
        return $out;
    }
}
