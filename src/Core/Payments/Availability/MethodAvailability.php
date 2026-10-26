<?php

declare(strict_types=1);

namespace WalletUpPro\Core\Payments\Availability;

use WalletUpPro\Core\Config\AutomatedApiConfig;
use WalletUpPro\Core\Config\PaymentMethodsConfig;
use WalletUpPro\Core\Payments\MethodStatusResolver;
use WalletUpPro\Core\Settings\SettingsRegistry;

if (!defined('ABSPATH')) {
    exit;
}

final class MethodAvailability
{
    public const SHOWN         = 'shown';
    public const HIDDEN_HERE   = 'hidden_here';
    public const NEEDS_SETUP   = 'needs_setup';
    public const OFF           = 'off';
    public const NOT_SUPPORTED = 'not_supported';
    public const NOT_ON_PLAN   = 'not_on_plan';
    public const ELSEWHERE     = 'decided_elsewhere';
    public const READY         = 'ready';

    public const AUTOMATED_SWITCH_OPTION = 'wallet_up_automated_enabled';

    public const INPUT_OPTIONS = [
        'wallet_up_enabled_methods',
        'wallet_up_transfer_call',
        'wallet_up_mobile_money_settings',
        'wallet_up_stripe_config',
        'woocommerce_wallet_up_card_settings',
        'wallet_up_paypal_commerce_settings',
        'wallet_up_venmo_api_settings',
        self::AUTOMATED_SWITCH_OPTION,
        SurfaceStore::OPTION,
        SettingsRegistry::OPTION,
    ];

    private static array $memo = [];

    public static function register(): void
    {
        foreach (self::INPUT_OPTIONS as $option) {
            add_action('update_option_' . $option, [self::class, 'flush']);
            add_action('add_option_' . $option, [self::class, 'flush']);
        }
        add_action('update_option_wallet_up_enabled_methods', [self::class, 'onEnabledMethodsChanged'], 10, 2);
        add_action('update_option_' . SettingsRegistry::OPTION, [self::class, 'onSettingsChanged'], 10, 2);
        add_action('add_option_' . SettingsRegistry::OPTION, static function ($option, $value) {
            self::onSettingsChanged([], $value);
        }, 10, 2);
    }

    public static function onSettingsChanged($old, $new): void
    {
        $wasOn = is_array($old) && !empty($old[SettingsRegistry::AVAILABILITY_V2]);
        $isOn = is_array($new) && !empty($new[SettingsRegistry::AVAILABILITY_V2]);
        if ($isOn && !$wasOn && !AvailabilityMigration::isDone()) {
            self::flush();
            if (!SettingsRegistry::isStored(SettingsRegistry::SURFACE_POLICY)) {
                SettingsRegistry::set(SettingsRegistry::SURFACE_POLICY, SettingsRegistry::defaultPolicy());
            }
            AvailabilityMigration::apply((string) SettingsRegistry::get(SettingsRegistry::SURFACE_POLICY));
        }
    }

    public static function catalog(): array
    {
        if (isset(self::$memo['catalog'])) {
            return self::$memo['catalog'];
        }
        $out = [];
        foreach (PaymentMethodsConfig::PAYMENT_METHODS as $id => $config) {
            $out[(string) $id] = (string) ($config['type'] ?? SurfaceRegistry::TYPE_WALLET);
        }
        foreach (AutomatedApiConfig::getAllKeys() as $key) {
            $out[(string) $key] = $key === 'stripe' ? SurfaceRegistry::TYPE_CARD : SurfaceRegistry::TYPE_PROVIDER;
        }
        return self::$memo['catalog'] = $out;
    }

    public static function isAutomated(string $methodId): bool
    {
        $type = self::catalog()[$methodId] ?? '';
        return $type === SurfaceRegistry::TYPE_CARD || $type === SurfaceRegistry::TYPE_PROVIDER;
    }

    public static function automatedSwitchedOn(string $methodId): bool
    {
        $switches = (array) get_option(self::AUTOMATED_SWITCH_OPTION, []);
        return !array_key_exists($methodId, $switches) || MethodStatusResolver::flag($switches[$methodId]);
    }

    public static function automatedOff(string $methodId): bool
    {
        return SettingsRegistry::isAvailabilityV2() && self::isAutomated($methodId) && !self::automatedSwitchedOn($methodId);
    }

    public static function setAutomatedSwitch(string $methodId, bool $on): void
    {
        if (!self::isAutomated($methodId)) {
            throw new \InvalidArgumentException('Not an automated method ' . $methodId);
        }
        $switches = (array) get_option(self::AUTOMATED_SWITCH_OPTION, []);
        $switches[$methodId] = $on;
        update_option(self::AUTOMATED_SWITCH_OPTION, $switches, false);
        self::flush();
    }

    public static function globalState(string $methodId): string
    {
        $memoKey = 'global:' . $methodId;
        if (isset(self::$memo[$memoKey])) {
            return self::$memo[$memoKey];
        }
        $catalog = self::catalog();
        if (!isset($catalog[$methodId])) {
            return self::$memo[$memoKey] = self::NOT_SUPPORTED;
        }
        if (self::isAutomated($methodId)) {
            if (!self::automatedSwitchedOn($methodId)) {
                $state = self::OFF;
            } else {
                $state = isset(self::automatedReady()[$methodId]) ? self::READY : self::NEEDS_SETUP;
            }
            return self::$memo[$memoKey] = $state;
        }
        if (!in_array($methodId, PaymentMethodsConfig::getAvailableMethodIds(), true)) {
            $state = self::NOT_ON_PLAN;
        } elseif (!MethodStatusResolver::isEnabled($methodId)) {
            $state = self::OFF;
        } elseif (!MethodStatusResolver::isConfigured($methodId)) {
            $state = self::NEEDS_SETUP;
        } else {
            $state = self::READY;
        }
        return self::$memo[$memoKey] = $state;
    }

    public static function state(string $methodId, string $surface, ?string $context = null, ?string $contextId = null): string
    {
        $memoKey = 'state:' . $methodId . '|' . SurfaceStore::key($surface, $context, $contextId);
        if (isset(self::$memo[$memoKey])) {
            return self::$memo[$memoKey];
        }
        $type = self::catalog()[$methodId] ?? null;
        if ($type === null || !SurfaceRegistry::supportsType($surface, $type)) {
            return self::$memo[$memoKey] = self::NOT_SUPPORTED;
        }
        if (SurfaceRegistry::isReadOnly($surface)) {
            return self::$memo[$memoKey] = self::ELSEWHERE;
        }
        $global = self::globalState($methodId);
        if ($global !== self::READY) {
            return self::$memo[$memoKey] = $global;
        }
        return self::$memo[$memoKey] = self::choiceShows($methodId, $surface, $context, $contextId) ? self::SHOWN : self::HIDDEN_HERE;
    }

    public static function forSurface(string $surface, ?string $context = null, ?string $contextId = null): array
    {
        $memoKey = 'list:' . SurfaceStore::key($surface, $context, $contextId);
        if (isset(self::$memo[$memoKey])) {
            return self::$memo[$memoKey];
        }
        $out = [];
        foreach (array_keys(self::catalog()) as $methodId) {
            if (self::state($methodId, $surface, $context, $contextId) === self::SHOWN) {
                $out[] = $methodId;
            }
        }
        return self::$memo[$memoKey] = $out;
    }

    public static function canStart(string $methodId, string $surface, ?string $context = null, ?string $contextId = null): bool
    {
        if (!SettingsRegistry::isAvailabilityV2()) {
            return true;
        }
        if (!isset(self::catalog()[$methodId])) {
            if (class_exists('\\WalletUpPro\\Core\\Error\\ErrorHandler')) {
                \WalletUpPro\Core\Error\ErrorHandler::getInstance()->log('Availability: payment started with an unlisted method id ' . $methodId . ' on ' . $surface);
            }
            return true;
        }
        return self::state($methodId, $surface, $context, $contextId) === self::SHOWN;
    }

    public static function shows(string $methodId, string $surface, ?string $context = null, ?string $contextId = null): bool
    {
        if (!SettingsRegistry::isAvailabilityV2()) {
            return true;
        }
        return self::state($methodId, $surface, $context, $contextId) === self::SHOWN;
    }

    public static function refusal(): string
    {
        return __('That payment method isn’t available right now. Please choose another one.', 'walletup');
    }

    public static function grid(?array $surfaces = null): array
    {
        $surfaces = $surfaces ?? SurfaceRegistry::ids();
        if (!SettingsRegistry::get(SettingsRegistry::GRID_SHOWS_LINKS)) {
            $surfaces = array_values(array_diff($surfaces, [SurfaceRegistry::PAYMENT_LINKS]));
        }
        $rows = [];
        foreach (array_keys(self::catalog()) as $methodId) {
            $row = ['method' => $methodId, 'type' => self::catalog()[$methodId], 'global' => self::globalState($methodId), 'surfaces' => []];
            foreach ($surfaces as $surface) {
                $row['surfaces'][$surface] = self::state($methodId, $surface);
            }
            $rows[] = $row;
        }
        return ['surfaces' => $surfaces, 'rows' => $rows, 'policy' => SettingsRegistry::get(SettingsRegistry::SURFACE_POLICY)];
    }

    public static function setShown(string $methodId, bool $show, string $surface, ?string $context = null, ?string $contextId = null): void
    {
        if (SurfaceRegistry::isReadOnly($surface)) {
            throw new \InvalidArgumentException('Read-only surface ' . $surface);
        }
        $key = SurfaceStore::key($surface, $context, $contextId);
        $row = SurfaceStore::read($key);
        if (SettingsRegistry::get(SettingsRegistry::SURFACE_POLICY) === SettingsRegistry::POLICY_FOLLOW) {
            $row['hidden'] = $show ? array_values(array_diff($row['hidden'], [$methodId])) : array_values(array_unique(array_merge($row['hidden'], [$methodId])));
        } else {
            $shown = $row['shown'] ?? self::forSurface($surface, $context, $contextId);
            $row['shown'] = $show ? array_values(array_unique(array_merge($shown, [$methodId]))) : array_values(array_diff($shown, [$methodId]));
        }
        SurfaceStore::write($key, $row);
    }

    public static function onEnabledMethodsChanged($old, $new): void
    {
        if (SettingsRegistry::get(SettingsRegistry::KEEP_CHOICES_WHEN_OFF)) {
            return;
        }
        $old = is_array($old) ? $old : [];
        $new = is_array($new) ? $new : [];
        foreach ($old as $methodId => $was) {
            if (MethodStatusResolver::flag($was) && !MethodStatusResolver::flag($new[$methodId] ?? false)) {
                SurfaceStore::forgetMethod((string) $methodId);
            }
        }
    }

    public static function flush(): void
    {
        self::$memo = [];
    }

    private static function choiceShows(string $methodId, string $surface, ?string $context, ?string $contextId): bool
    {
        $rows = [];
        if ($context !== null && $contextId !== null && $contextId !== '') {
            $contextKey = SurfaceStore::key($surface, $context, $contextId);
            if (SurfaceStore::has($contextKey)) {
                $rows[] = SurfaceStore::read($contextKey);
            }
        }
        $rows[] = SurfaceStore::read($surface);
        $row = $rows[0];
        if (SettingsRegistry::get(SettingsRegistry::SURFACE_POLICY) === SettingsRegistry::POLICY_FOLLOW) {
            return !in_array($methodId, $row['hidden'], true);
        }
        foreach ($rows as $candidate) {
            if (is_array($candidate['shown'])) {
                return in_array($methodId, $candidate['shown'], true);
            }
        }
        return !in_array($methodId, $row['hidden'], true);
    }

    private static function automatedReady(): array
    {
        if (!isset(self::$memo['automated'])) {
            self::$memo['automated'] = AutomatedApiConfig::getEnabledApis(true);
        }
        return self::$memo['automated'];
    }
}
