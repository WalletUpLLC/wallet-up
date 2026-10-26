<?php

declare(strict_types=1);

namespace WalletUpPro\Core\Payments\Availability;

use WalletUpPro\Core\Settings\SettingsRegistry;

if (!defined('ABSPATH')) {
    exit;
}

final class AvailabilityAdmin
{
    public const NONCE = 'wallet_up_admin_nonce';

    private const ERR_TARGET   = 'INVALID_TARGET';
    private const ERR_SETTINGS = 'INVALID_SETTINGS';
    private const ERR_FORBIDDEN = 'FORBIDDEN';
    private const ERR_PAGE      = 'PAGE_NOT_SWAPPED';
    private const PAGES_MAX     = 50;

    private const MERCHANT_SETTINGS = [
        SettingsRegistry::SURFACE_POLICY,
        SettingsRegistry::ASK_INCLUDES_EXISTING,
        SettingsRegistry::MEMBERSHIPS_WALLETS,
        SettingsRegistry::GRID_SHOWS_LINKS,
        SettingsRegistry::KEEP_CHOICES_WHEN_OFF,
    ];

    public static function register(): void
    {
        add_action('wp_ajax_wallet_up_availability_get', [self::class, 'handleGet']);
        add_action('wp_ajax_wallet_up_availability_set', [self::class, 'handleSet']);
        add_action('wp_ajax_wallet_up_availability_settings', [self::class, 'handleSettings']);
        add_action('wp_ajax_wallet_up_availability_checklist', [self::class, 'handleChecklist']);
        add_action('wp_ajax_wallet_up_availability_preview', [self::class, 'handlePreview']);
        add_action('wp_ajax_wallet_up_availability_global', [self::class, 'handleGlobal']);
        add_action('wp_ajax_wallet_up_availability_pages', [self::class, 'handlePages']);
        add_action('wp_ajax_wallet_up_availability_page_swap', [self::class, 'handlePageSwap']);
        add_action('wp_ajax_wallet_up_availability_dismiss', [self::class, 'handleDismiss']);
    }

    public static function handlePreview(): void
    {
        self::assertAllowed();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in assertAllowed().
        $policy = sanitize_key(wp_unslash($_POST['policy'] ?? ''));
        if (!in_array($policy, [SettingsRegistry::POLICY_FOLLOW, SettingsRegistry::POLICY_ASK], true)) {
            wp_send_json_error(['code' => self::ERR_SETTINGS], 400);
        }
        $changes = array_map(static function (array $item) {
            unset($item['row']);
            return $item;
        }, AvailabilityMigration::changes(AvailabilityMigration::plan($policy)));
        wp_send_json_success(['policy' => $policy, 'changes' => $changes]);
    }

    public static function payload(?string $surface = null, ?string $context = null, ?string $contextId = null): array
    {
        $grid = MethodAvailability::grid();
        $out = [
            'enabled'  => SettingsRegistry::isAvailabilityV2(),
            'settings' => array_intersect_key(SettingsRegistry::publicValues(), array_flip(self::MERCHANT_SETTINGS)),
            'grid'     => $grid,
            'findings' => AvailabilityHealth::findings(),
        ];
        if ($surface !== null && SurfaceRegistry::exists($surface)) {
            $states = [];
            foreach (array_keys(MethodAvailability::catalog()) as $methodId) {
                $states[$methodId] = MethodAvailability::state($methodId, $surface, $context, $contextId);
            }
            $out['surface'] = ['id' => $surface, 'context' => $context, 'contextId' => $contextId, 'states' => $states];
        }
        return $out;
    }

    public static function handleGet(): void
    {
        self::assertAllowed();
        [$surface, $context, $contextId] = self::target();
        wp_send_json_success(self::payload($surface, $context, $contextId));
    }

    public static function handleSet(): void
    {
        self::assertAllowed();
        [$surface, $context, $contextId] = self::target();
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in assertAllowed().
        $method = sanitize_key(wp_unslash($_POST['method'] ?? ''));
        $show = \WalletUpPro\Core\Payments\MethodStatusResolver::flag(wp_unslash($_POST['show'] ?? ''));
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        if ($surface === null || !isset(MethodAvailability::catalog()[$method]) || SurfaceRegistry::isReadOnly($surface)) {
            wp_send_json_error(['code' => self::ERR_TARGET], 400);
        }
        if ($context !== null && !SurfaceRegistry::supportsContext($surface, $context)) {
            wp_send_json_error(['code' => self::ERR_TARGET], 400);
        }
        MethodAvailability::setShown($method, $show, $surface, $context, $contextId);
        self::audit('availability_set');
        wp_send_json_success(self::payload($surface, $context, $contextId));
    }

    public static function handleSettings(): void
    {
        self::assertAllowed();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in assertAllowed(); decoded JSON is validated by SettingsRegistry.
        $incoming = json_decode(wp_unslash($_POST['settings'] ?? '{}'), true);
        if (!is_array($incoming)) {
            wp_send_json_error(['code' => self::ERR_SETTINGS], 400);
        }
        $allowed = array_intersect_key($incoming, array_flip(self::MERCHANT_SETTINGS));
        $policy = $allowed[SettingsRegistry::SURFACE_POLICY] ?? null;
        unset($allowed[SettingsRegistry::SURFACE_POLICY]);
        try {
            SettingsRegistry::setMany($allowed);
            if ($policy !== null && $policy !== SettingsRegistry::get(SettingsRegistry::SURFACE_POLICY)) {
                if (!in_array($policy, [SettingsRegistry::POLICY_FOLLOW, SettingsRegistry::POLICY_ASK], true)) {
                    throw new \InvalidArgumentException('policy');
                }
                AvailabilityMigration::switchPolicy($policy);
            }
        } catch (\InvalidArgumentException $e) {
            wp_send_json_error(['code' => self::ERR_SETTINGS], 400);
        }
        self::audit('availability_settings');
        wp_send_json_success(self::payload());
    }

    public static function handleGlobal(): void
    {
        self::assertAllowed();
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in assertAllowed().
        $method = sanitize_key(wp_unslash($_POST['method'] ?? ''));
        $on = \WalletUpPro\Core\Payments\MethodStatusResolver::flag(wp_unslash($_POST['on'] ?? ''));
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        if (!MethodAvailability::isAutomated($method)) {
            wp_send_json_error(['code' => self::ERR_TARGET], 400);
        }
        MethodAvailability::setAutomatedSwitch($method, $on);
        self::audit('availability_global');
        wp_send_json_success(self::payload());
    }

    public static function handlePages(): void
    {
        self::assertAllowed();
        wp_send_json_success(['pages' => \WalletUpPro\Core\Onboarding\PaymentPageGenerator::pagesNeedingUpdate(self::PAGES_MAX)]);
    }

    public static function handlePageSwap(): void
    {
        self::assertAllowed();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in assertAllowed().
        $pageId = absint(wp_unslash($_POST['page_id'] ?? 0));
        if ($pageId === 0 || !current_user_can('edit_post', $pageId) || !\WalletUpPro\Core\Onboarding\PaymentPageGenerator::swapToLive($pageId)) {
            wp_send_json_error(['code' => self::ERR_PAGE], 400);
        }
        self::audit('availability_page_swap');
        wp_send_json_success(['pages' => \WalletUpPro\Core\Onboarding\PaymentPageGenerator::pagesNeedingUpdate(self::PAGES_MAX), 'findings' => AvailabilityHealth::findings()]);
    }

    public static function handleDismiss(): void
    {
        self::assertAllowed();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in assertAllowed().
        $key = sanitize_key(wp_unslash($_POST['key'] ?? ''));
        if (!AvailabilityHealth::dismiss($key)) {
            wp_send_json_error(['code' => self::ERR_TARGET], 400);
        }
        self::audit('availability_dismiss');
        wp_send_json_success(self::payload());
    }

    public static function handleChecklist(): void
    {
        self::assertAllowed();
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in assertAllowed().
        $method = sanitize_key(wp_unslash($_POST['method'] ?? ''));
        $surfaces = array_map('sanitize_key', (array) json_decode(wp_unslash($_POST['surfaces'] ?? '[]'), true));
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        if (!isset(MethodAvailability::catalog()[$method])) {
            wp_send_json_error(['code' => self::ERR_TARGET], 400);
        }
        self::applyChecklist($method, $surfaces);
        self::audit('availability_checklist');
        wp_send_json_success(self::payload());
    }

    public static function applyChecklist(string $method, array $surfaces): void
    {
        $includeExisting = (bool) SettingsRegistry::get(SettingsRegistry::ASK_INCLUDES_EXISTING);
        foreach ($surfaces as $surface) {
            if (!SurfaceRegistry::exists($surface) || SurfaceRegistry::isReadOnly($surface)) {
                continue;
            }
            MethodAvailability::setShown($method, true, $surface);
            if (!$includeExisting) {
                continue;
            }
            foreach (AvailabilityContexts::forSurface($surface) as $ctx) {
                if (SurfaceStore::has($ctx['key'])) {
                    MethodAvailability::setShown($method, true, $surface, $ctx['context'], $ctx['id']);
                }
            }
        }
    }

    private static function target(): array
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in assertAllowed().
        $surface = sanitize_key(wp_unslash($_POST['surface'] ?? ''));
        $context = sanitize_key(wp_unslash($_POST['context'] ?? ''));
        $contextId = sanitize_text_field(wp_unslash($_POST['context_id'] ?? ''));
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        return [
            $surface !== '' && SurfaceRegistry::exists($surface) ? $surface : null,
            $context !== '' ? $context : null,
            $contextId !== '' ? $contextId : null,
        ];
    }

    private static function assertAllowed(): void
    {
        if (!check_ajax_referer(self::NONCE, 'nonce', false) || !current_user_can('manage_options')) {
            wp_send_json_error(['code' => self::ERR_FORBIDDEN], 403);
        }
    }

    private static function audit(string $event): void
    {
        if (class_exists('\\WalletUpPro\\Core\\Security\\AuditLog')) {
            \WalletUpPro\Core\Security\AuditLog::record($event);
        }
    }
}
