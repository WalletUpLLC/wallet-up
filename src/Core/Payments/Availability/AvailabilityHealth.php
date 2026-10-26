<?php

declare(strict_types=1);

namespace WalletUpPro\Core\Payments\Availability;

use WalletUpPro\Core\Settings\SettingsRegistry;

if (!defined('ABSPATH')) {
    exit;
}

final class AvailabilityHealth
{
    public const STORE_EMPTY       = 'store_empty';
    public const CONTEXT_EMPTY     = 'context_empty';
    public const SHOWN_NOWHERE     = 'shown_nowhere';
    public const MEMBERSHIPS_CARDS = 'memberships_no_cards';
    public const PAGE_UPDATE       = 'page_update';
    public const BLOCKING          = 'blocking';
    public const SUGGESTION        = 'suggestion';
    private const BLOCKING_CODES   = [self::STORE_EMPTY, self::CONTEXT_EMPTY, self::MEMBERSHIPS_CARDS];
    public const DISMISSED_OPTION  = 'wallet_up_availability_dismissed';

    private const FINDINGS_MAX = 20;

    public static function findings(): array
    {
        if (!SettingsRegistry::isAvailabilityV2()) {
            return [];
        }
        $out = [];
        if (self::storeIsOn() && MethodAvailability::forSurface(SurfaceRegistry::STORE) === []) {
            $out[] = ['code' => self::STORE_EMPTY, 'surface' => SurfaceRegistry::STORE];
        }
        foreach (AvailabilityContexts::all() as $ctx) {
            if (count($out) >= self::FINDINGS_MAX) {
                break;
            }
            if (MethodAvailability::forSurface($ctx['surface'], $ctx['context'], $ctx['id']) === []) {
                $out[] = ['code' => self::CONTEXT_EMPTY, 'surface' => $ctx['surface'], 'context' => $ctx['context'], 'id' => $ctx['id'], 'label' => $ctx['label']];
            }
        }
        if (SettingsRegistry::get(SettingsRegistry::SURFACE_POLICY) === SettingsRegistry::POLICY_ASK) {
            foreach (array_keys(MethodAvailability::catalog()) as $methodId) {
                if (MethodAvailability::globalState($methodId) !== MethodAvailability::READY) {
                    continue;
                }
                $anywhere = false;
                foreach (SurfaceRegistry::ids() as $surface) {
                    if (MethodAvailability::state($methodId, $surface) === MethodAvailability::SHOWN) {
                        $anywhere = true;
                        break;
                    }
                }
                if (!$anywhere) {
                    $out[] = ['code' => self::SHOWN_NOWHERE, 'method' => $methodId];
                }
            }
        }
        $tiers = self::membershipTiers();
        if ($tiers !== [] && !self::membershipsPayable($tiers)) {
            $out[] = ['code' => self::MEMBERSHIPS_CARDS, 'surface' => SurfaceRegistry::MEMBERSHIPS];
        }
        if (class_exists('\WalletUpPro\Core\Onboarding\PaymentPageGenerator')) {
            foreach (\WalletUpPro\Core\Onboarding\PaymentPageGenerator::pagesNeedingUpdate(self::FINDINGS_MAX) as $page) {
                $out[] = ['code' => self::PAGE_UPDATE, 'surface' => SurfaceRegistry::PAGE_GENERATOR, 'page' => $page];
            }
        }
        $dismissed = self::dismissed();
        $out = array_map(static function (array $f) use ($dismissed): array {
            $f['severity'] = in_array($f['code'], self::BLOCKING_CODES, true) ? self::BLOCKING : self::SUGGESTION;
            if ($f['severity'] === self::SUGGESTION) {
                $f['key'] = self::keyFor($f);
                $f['dismissed'] = isset($dismissed[$f['key']]);
            }
            return $f;
        }, $out);
        usort($out, static fn(array $a, array $b): int => ($a['severity'] === self::BLOCKING ? 0 : 1) <=> ($b['severity'] === self::BLOCKING ? 0 : 1));
        return array_slice($out, 0, self::FINDINGS_MAX);
    }

    public static function dismiss(string $key): bool
    {
        $current = [];
        foreach (self::findings() as $f) {
            if (($f['severity'] ?? '') === self::SUGGESTION) {
                $current[$f['key']] = true;
            }
        }
        if ($key === '' || !isset($current[$key])) {
            return false;
        }
        $kept = array_intersect_key(self::dismissed(), $current);
        $kept[$key] = time();
        update_option(self::DISMISSED_OPTION, $kept, false);
        return true;
    }

    private static function dismissed(): array
    {
        $raw = get_option(self::DISMISSED_OPTION, []);
        return is_array($raw) ? $raw : [];
    }

    private static function keyFor(array $f): string
    {
        $page = is_array($f['page'] ?? null) ? $f['page'] : [];
        return md5((string) wp_json_encode([
            $f['code'],
            $page['id'] ?? ($f['id'] ?? ($f['method'] ?? ($f['surface'] ?? ''))),
            $page['gained'] ?? [],
            $page['lost'] ?? [],
        ]));
    }

    private static function storeIsOn(): bool
    {
        if (!class_exists('WooCommerce')) {
            return false;
        }
        $gateway = (array) get_option('woocommerce_wallet_up_settings', []);
        return ($gateway['enabled'] ?? 'no') === 'yes';
    }

    private static function membershipTiers(): array
    {
        if (!class_exists('\\WalletUpPro\\Core\\Memberships\\MembershipManager')) {
            return [];
        }
        try {
            return \WalletUpPro\Core\Memberships\MembershipManager::getInstance()->getTiers(true);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function membershipsPayable(array $tiers): bool
    {
        if (MethodAvailability::state('stripe', SurfaceRegistry::MEMBERSHIPS) === MethodAvailability::SHOWN) {
            return true;
        }
        if (!class_exists('\\WalletUpPro\\Core\\Memberships\\MembershipWallets')) {
            return false;
        }
        foreach ($tiers as $tier) {
            if (!\WalletUpPro\Core\Memberships\MembershipWallets::payableByWallet($tier)) {
                return false;
            }
        }
        return true;
    }
}
