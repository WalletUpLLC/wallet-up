<?php

declare(strict_types=1);

namespace WalletUpPro\Core\Payments\Availability;

if (!defined('ABSPATH')) {
    exit;
}

final class AvailabilityContexts
{
    public static function all(): array
    {
        return array_merge(self::forms(), self::campaigns(), self::donationPages(), self::payPages());
    }

    public static function forSurface(string $surface): array
    {
        return array_values(array_filter(self::all(), static function (array $c) use ($surface) {
            return $c['surface'] === $surface;
        }));
    }

    private static function forms(): array
    {
        $out = [];
        foreach ((array) get_option('wallet_up_donation_forms', []) as $form) {
            if (!is_array($form) || empty($form['id'])) {
                continue;
            }
            $out[] = self::row(SurfaceRegistry::DONATIONS, 'form', (string) $form['id'], (string) ($form['name'] ?? $form['title'] ?? $form['id']),
                $form['settings']['enabled_payment_methods'] ?? $form['enabled_payment_methods'] ?? null);
        }
        return $out;
    }

    private static function campaigns(): array
    {
        $out = [];
        foreach ((array) get_option('wallet_up_campaigns', []) as $campaign) {
            if (!is_array($campaign) || empty($campaign['id'])) {
                continue;
            }
            $out[] = self::row(SurfaceRegistry::DONATIONS, 'campaign', (string) $campaign['id'], (string) ($campaign['name'] ?? $campaign['title'] ?? $campaign['id']),
                $campaign['enabled_payment_methods'] ?? null);
        }
        return $out;
    }

    private static function donationPages(): array
    {
        $out = [];
        foreach ((array) get_option('wallet_up_donation_settings', []) as $id => $settings) {
            if (!is_array($settings)) {
                continue;
            }
            $out[] = self::row(SurfaceRegistry::DONATIONS, 'page', (string) $id, (string) ($settings['title'] ?? $settings['name'] ?? $id), $settings['enabledMethods'] ?? null);
        }
        return $out;
    }

    private static function payPages(): array
    {
        $out = [];
        foreach ((array) get_option('wallet_up_payer_settings', []) as $id => $settings) {
            if (!is_array($settings)) {
                continue;
            }
            $out[] = self::row(SurfaceRegistry::PAY_PAGES, 'payer', (string) $id, (string) ($settings['title'] ?? $settings['name'] ?? $id), $settings['enabledMethods'] ?? null);
        }
        return $out;
    }

    private static function row(string $surface, string $context, string $id, string $label, $legacy): array
    {
        $map = [];
        if (is_array($legacy)) {
            foreach ($legacy as $method => $on) {
                $map[sanitize_key((string) $method)] = \WalletUpPro\Core\Payments\MethodStatusResolver::flag($on);
            }
        }
        return [
            'surface' => $surface,
            'context' => $context,
            'id'      => $id,
            'label'   => sanitize_text_field($label),
            'key'     => SurfaceStore::key($surface, $context, $id),
            'legacy'  => is_array($legacy) ? $map : null,
        ];
    }
}
