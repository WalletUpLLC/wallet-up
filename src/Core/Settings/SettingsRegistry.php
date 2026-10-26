<?php

declare(strict_types=1);

namespace WalletUpPro\Core\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class SettingsRegistry
{
    public const OPTION = 'wallet_up_settings_v2';

    public const AVAILABILITY_V2        = 'payments.availability_v2';
    public const SURFACE_POLICY         = 'payments.surface_policy';
    public const ASK_INCLUDES_EXISTING  = 'payments.ask_includes_existing';
    public const MEMBERSHIPS_WALLETS    = 'payments.memberships_wallets';
    public const GRID_SHOWS_LINKS       = 'payments.grid_shows_links';
    public const KEEP_CHOICES_WHEN_OFF  = 'payments.keep_choices_when_off';

    public const POLICY_FOLLOW = 'follow';
    public const POLICY_ASK    = 'ask';

    private const EXISTING_SITE_SIGNALS = [
        'wallet_up_donation_forms',
        'wallet_up_campaigns',
        'wallet_up_payer_settings',
        'wallet_up_donation_settings',
        'wallet_up_wpforms_settings',
    ];

    private static ?array $cache = null;

    public static function definitions(): array
    {
        return [
            self::AVAILABILITY_V2 => ['type' => 'bool', 'default' => false, 'public' => true],
            self::SURFACE_POLICY => [
                'type'    => 'enum',
                'values'  => [self::POLICY_FOLLOW, self::POLICY_ASK],
                'default' => [self::class, 'defaultPolicy'],
                'public'  => true,
            ],
            self::ASK_INCLUDES_EXISTING => ['type' => 'bool', 'default' => true, 'public' => true],
            self::MEMBERSHIPS_WALLETS   => ['type' => 'bool', 'default' => false, 'public' => true],
            self::GRID_SHOWS_LINKS      => ['type' => 'bool', 'default' => true, 'public' => true],
            self::KEEP_CHOICES_WHEN_OFF => ['type' => 'bool', 'default' => true, 'public' => true],
        ];
    }

    public static function get(string $key)
    {
        $def = self::definition($key);
        $stored = self::stored();
        if (array_key_exists($key, $stored)) {
            $valid = self::validate($def, $stored[$key]);
            if ($valid[0]) {
                return $valid[1];
            }
        }
        return is_callable($def['default']) ? call_user_func($def['default']) : $def['default'];
    }

    public static function set(string $key, $value): void
    {
        self::setMany([$key => $value]);
    }

    public static function setMany(array $values): void
    {
        $clean = [];
        foreach ($values as $key => $value) {
            $def = self::definition((string) $key);
            $valid = self::validate($def, $value);
            if (!$valid[0]) {
                throw new \InvalidArgumentException('Invalid value for ' . $key);
            }
            $clean[(string) $key] = $valid[1];
        }
        self::write($clean);
    }

    public static function all(): array
    {
        $out = [];
        foreach (array_keys(self::definitions()) as $key) {
            $out[$key] = self::get($key);
        }
        return $out;
    }

    public static function publicValues(): array
    {
        $out = [];
        foreach (self::definitions() as $key => $def) {
            if (!empty($def['public'])) {
                $out[$key] = self::get($key);
            }
        }
        return $out;
    }

    public static function isStored(string $key): bool
    {
        self::definition($key);
        return array_key_exists($key, self::stored());
    }

    public static function isAvailabilityV2(): bool
    {
        return (bool) self::get(self::AVAILABILITY_V2);
    }

    public static function defaultPolicy(): string
    {
        foreach (self::EXISTING_SITE_SIGNALS as $option) {
            $value = get_option($option, null);
            if (!empty($value)) {
                return self::POLICY_ASK;
            }
        }
        return self::POLICY_FOLLOW;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    private static function definition(string $key): array
    {
        $defs = self::definitions();
        if (!isset($defs[$key])) {
            throw new \InvalidArgumentException('Unknown setting ' . $key);
        }
        return $defs[$key];
    }

    private static function validate(array $def, $value): array
    {
        switch ($def['type']) {
            case 'bool':
                if (is_bool($value)) {
                    return [true, $value];
                }
                if (in_array($value, [1, 0, '1', '0', 'true', 'false', 'yes', 'no'], true)) {
                    return [true, in_array($value, [1, '1', 'true', 'yes'], true)];
                }
                return [false, null];
            case 'enum':
                return in_array($value, $def['values'], true) ? [true, $value] : [false, null];
        }
        return [false, null];
    }

    private static function stored(): array
    {
        if (self::$cache === null) {
            $raw = get_option(self::OPTION, []);
            self::$cache = is_array($raw) ? $raw : [];
        }
        return self::$cache;
    }

    private static function write(array $values): void
    {
        $next = array_merge(self::stored(), $values);
        self::$cache = $next;
        update_option(self::OPTION, $next, false);
    }
}
