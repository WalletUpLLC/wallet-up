<?php

declare(strict_types=1);

namespace WalletUpPro\Core\Payments\Availability;

use WalletUpPro\Core\Settings\SettingsRegistry;

if (!defined('ABSPATH')) {
    exit;
}

final class SurfaceRegistry
{
    public const DONATIONS      = 'donations';
    public const STORE          = 'store';
    public const EXPRESS        = 'express';
    public const WPFORMS        = 'wpforms';
    public const PAY_PAGES      = 'pay_pages';
    public const MEMBERSHIPS    = 'memberships';
    public const TIP_JAR        = 'tip_jar';
    public const PAGE_GENERATOR = 'page_generator';
    public const PAYMENT_LINKS  = 'payment_links';

    public const TYPE_WALLET   = 'traditional';
    public const TYPE_CRYPTO   = 'crypto';
    public const TYPE_MOBILE   = 'mobile_money';
    public const TYPE_CARD     = 'card';
    public const TYPE_PROVIDER = 'provider';

    private const WALLET_TYPES = [self::TYPE_WALLET, self::TYPE_CRYPTO, self::TYPE_MOBILE];
    private const ALL_TYPES    = [self::TYPE_WALLET, self::TYPE_CRYPTO, self::TYPE_MOBILE, self::TYPE_CARD, self::TYPE_PROVIDER];

    public static function all(): array
    {
        return [
            self::DONATIONS      => ['types' => self::ALL_TYPES, 'contexts' => ['form', 'campaign', 'page'], 'edit' => 'wallet-up-donations', 'readonly' => false],
            self::STORE          => ['types' => self::ALL_TYPES, 'contexts' => [], 'edit' => 'wallet-up-seller', 'readonly' => false],
            self::EXPRESS        => ['types' => self::ALL_TYPES, 'contexts' => [], 'edit' => 'wallet-up-seller', 'readonly' => false],
            self::WPFORMS        => ['types' => self::ALL_TYPES, 'contexts' => [], 'edit' => 'wallet-up-shortcodes', 'readonly' => false],
            self::PAY_PAGES      => ['types' => self::WALLET_TYPES, 'contexts' => ['payer'], 'edit' => 'wallet-up-payments', 'readonly' => false],
            self::MEMBERSHIPS    => ['types' => [self::TYPE_CARD], 'contexts' => [], 'edit' => 'wallet-up-memberships', 'readonly' => false],
            self::TIP_JAR        => ['types' => self::WALLET_TYPES, 'contexts' => [], 'edit' => 'wallet-up-shortcodes', 'readonly' => false],
            self::PAGE_GENERATOR => ['types' => self::ALL_TYPES, 'contexts' => [], 'edit' => 'wallet-up', 'readonly' => false],
            self::PAYMENT_LINKS  => ['types' => self::ALL_TYPES, 'contexts' => [], 'edit' => 'wallet-up-payments', 'readonly' => true],
        ];
    }

    public static function ids(): array
    {
        return array_keys(self::all());
    }

    public static function exists(string $surface): bool
    {
        return isset(self::all()[$surface]);
    }

    public static function get(string $surface): array
    {
        $all = self::all();
        if (!isset($all[$surface])) {
            throw new \InvalidArgumentException('Unknown surface ' . $surface);
        }
        return $all[$surface];
    }

    public static function supportsType(string $surface, string $type): bool
    {
        $types = self::get($surface)['types'];
        if ($surface === self::MEMBERSHIPS && SettingsRegistry::get(SettingsRegistry::MEMBERSHIPS_WALLETS)) {
            $types = array_merge($types, self::WALLET_TYPES);
        }
        return in_array($type, $types, true);
    }

    public static function supportsContext(string $surface, string $context): bool
    {
        return in_array($context, self::get($surface)['contexts'], true);
    }

    public static function isReadOnly(string $surface): bool
    {
        return !empty(self::get($surface)['readonly']);
    }
}
