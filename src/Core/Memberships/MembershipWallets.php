<?php

declare(strict_types=1);

namespace WalletUpPro\Core\Memberships;

use WalletUpPro\Core\Config\AutomatedApiConfig;
use WalletUpPro\Core\Onboarding\PaymentPageGenerator;
use WalletUpPro\Core\Payments\Availability\MethodAvailability;
use WalletUpPro\Core\Payments\Availability\SurfaceRegistry;
use WalletUpPro\Core\Payments\MethodStatusResolver;
use WalletUpPro\Core\Settings\SettingsRegistry;

if (!defined('ABSPATH')) {
    exit;
}

final class MembershipWallets
{
    public const AWAITING = 'wallet';
    public const ONE_TIME_INTERVAL = 'lifetime';
    private const OFFERS_CACHE_PREFIX = 'wu_mw_offers_';
    private const OFFERS_CACHE_SECONDS = 300;

    public static function offered(): bool
    {
        return SettingsRegistry::isAvailabilityV2() && (bool) SettingsRegistry::get(SettingsRegistry::MEMBERSHIPS_WALLETS);
    }

    public static function oneTime(array $tier): bool
    {
        return ($tier['billing_interval'] ?? '') === self::ONE_TIME_INTERVAL;
    }

    public static function cardsShown(): bool
    {
        return MethodAvailability::shows(AutomatedApiConfig::STRIPE, SurfaceRegistry::MEMBERSHIPS);
    }

    public static function methodIds(): array
    {
        if (!self::offered()) {
            return [];
        }
        return array_values(array_filter(
            MethodAvailability::forSurface(SurfaceRegistry::MEMBERSHIPS),
            static fn(string $id): bool => !MethodAvailability::isAutomated($id)
        ));
    }

    public static function payableByWallet(array $tier): bool
    {
        if (!self::oneTime($tier)) {
            return false;
        }
        foreach (self::methodIds() as $id) {
            if (MethodStatusResolver::holderFor($id) !== '') {
                return true;
            }
        }
        return false;
    }

    public static function offersFor(array $tier): array
    {
        if (!self::oneTime($tier)) {
            return [];
        }
        $amount = (string) ($tier['amount'] ?? '');
        $holders = [];
        foreach (self::methodIds() as $id) {
            $holder = MethodStatusResolver::holderFor($id);
            if ($holder !== '') {
                $holders[$id] = $holder;
            }
        }
        $cacheKey = self::OFFERS_CACHE_PREFIX . md5((string) wp_json_encode([$amount, $holders]));
        $cached = get_transient($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
        $out = [];
        foreach ($holders as $id => $holder) {
            $url = PaymentPageGenerator::buildPaymentUrl($id, $holder, $amount);
            $out[] = [
                'id'     => $id,
                'label'  => PaymentPageGenerator::methodLabel($id),
                'holder' => $holder,
                'url'    => $url,
                'qr'     => PaymentPageGenerator::qrFor($id, $url, $holder, $amount),
            ];
        }
        set_transient($cacheKey, $out, self::OFFERS_CACHE_SECONDS);
        return $out;
    }

    public static function allows(array $tier, string $methodId): bool
    {
        if (!self::offered() || !self::oneTime($tier) || !in_array($methodId, self::methodIds(), true)) {
            return false;
        }
        return MethodStatusResolver::holderFor($methodId) !== ''
            && MethodAvailability::canStart($methodId, SurfaceRegistry::MEMBERSHIPS);
    }
}
