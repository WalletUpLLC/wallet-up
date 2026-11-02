<?php
declare(strict_types=1);

namespace WalletUpPro\Core\Retention;

if (!defined('ABSPATH')) {
    exit;
}

final class SetupCheck
{
    public const UNCONFIGURED = 'unconfigured';
    public const INCOMPLETE   = 'incomplete';
    public const ENVIRONMENT  = 'environment';
    public const READY        = 'ready';

    private const PHP_MINIMUM = '8.1';

    private const PAGE_PAYMENTS = 'wallet-up-payments';

    private const GATEWAY_OPTIONS = [
        'wallet_up_stripe_config',
        'wallet_up_paypal_commerce_settings',
        'wallet_up_venmo_api_settings',
        'wallet_up_cashapp_api_settings',
    ];

    public static function run(): array
    {
        if (version_compare(PHP_VERSION, self::PHP_MINIMUM, '<')) {
            return self::finding(
                self::ENVIRONMENT,
                sprintf(
                    /* translators: 1: current PHP version, 2: required PHP version */
                    __('This site runs PHP %1$s. Wallet Up needs PHP %2$s or newer, which is why it could not start.', 'walletup'),
                    PHP_VERSION,
                    self::PHP_MINIMUM
                ),
                ''
            );
        }

        $enabled = self::enabledMethods();
        $apis    = self::readyAutomatedApis();
        $card    = self::cardStatus(isset($apis['stripe']));

        if ($enabled === [] && empty($card['enabled']) && $apis === []) {
            return self::finding(
                self::UNCONFIGURED,
                __('No payment method is switched on yet, so nothing would have appeared on your site. Switching one on takes about a minute.', 'walletup'),
                'wallet-up-onboarding'
            );
        }

        if (self::needsWooCommerce() && !self::wooCommerceActive()) {
            return self::finding(
                self::ENVIRONMENT,
                __('A WooCommerce payment method is switched on, but WooCommerce is not active on this site.', 'walletup'),
                self::PAGE_PAYMENTS
            );
        }

        if (!empty($card['enabled']) && empty($card['ready'])) {
            $cardText = ($card['reason'] ?? '') === 'mode_key_mismatch'
                ? __('Card payments are switched on, but the saved keys do not match the selected test or live mode, so cards may not appear. Open Payments and set the mode to match your keys.', 'walletup')
                : __('Card payments are switched on, but no card keys are saved yet, so cards could not appear.', 'walletup');
            return self::finding(self::INCOMPLETE, $cardText, self::PAGE_PAYMENTS);
        }

        if ($enabled !== [] && !self::hasAnyCredential($enabled) && $apis === []) {
            return self::finding(
                self::INCOMPLETE,
                sprintf(
                    /* translators: %d: number of payment methods switched on */
                    _n(
                        '%d payment method is switched on, but none has account details saved yet, so it had nothing to show.',
                        '%d payment methods are switched on, but none has account details saved yet, so they had nothing to show.',
                        count($enabled),
                        'walletup'
                    ),
                    count($enabled)
                ),
                self::PAGE_PAYMENTS
            );
        }

        return self::finding(
            self::READY,
            __('Your setup looks complete from here, so this is worth a look by a person. Send us what happened and we will work it out.', 'walletup'),
            ''
        );
    }

    /**
     * 6.7.0: nine yes/no checks that cover every reason a free install has gone quiet in the
     * uninstall exports. Each row says what was checked, whether it passed, one line of
     * detail and where to fix it. `ok` is null for a check that does not apply here.
     *
     * @return array{items: array<int, array{id:string,label:string,ok:?bool,detail:string,url:string}>, passed:int, failed:int, total:int, critical:bool}
     */
    public static function checks(): array
    {
        $items = [];
        $admin = static function (string $page): string {
            return admin_url('admin.php?page=' . $page);
        };

        // 1. PHP
        $phpOk = version_compare(PHP_VERSION, self::PHP_MINIMUM, '>=');
        $items[] = self::item('php', __('PHP version', 'walletup'), $phpOk,
            $phpOk
                ? sprintf(/* translators: %s: PHP version */ __('PHP %s.', 'walletup'), PHP_VERSION)
                : sprintf(/* translators: 1: current PHP, 2: minimum PHP */ __('PHP %1$s is too old; Wallet Up needs %2$s or newer. Change it in your hosting panel.', 'walletup'), PHP_VERSION, self::PHP_MINIMUM),
            '');

        // 2. QR engine
        $gd = extension_loaded('gd');
        $im = extension_loaded('imagick');
        $items[] = self::item('qr', __('QR code engine', 'walletup'), $gd || $im,
            $gd || $im
                ? ($gd ? __('GD is available, so QR images render on the server.', 'walletup') : __('Imagick is available, so QR images render on the server.', 'walletup'))
                : __('Neither GD nor Imagick is installed, so QR images cannot be drawn. Pay links and buttons still work. Ask your host to enable GD.', 'walletup'),
            '');

        // 3. A method is switched on
        $enabled = self::enabledMethods();
        $items[] = self::item('method', __('A payment method is switched on', 'walletup'), $enabled !== [],
            $enabled !== []
                ? sprintf(/* translators: %s: comma-separated method names */ __('On: %s.', 'walletup'), implode(', ', array_map([self::class, 'methodName'], $enabled)))
                : __('No method is on yet, so nothing can appear on your site.', 'walletup'),
            $admin($enabled !== [] ? self::PAGE_PAYMENTS : 'wallet-up-onboarding'));

        // 4. Account details saved for the methods that are on
        $transfer = (array) get_option('wallet_up_transfer_call', []);
        $missing = [];
        foreach ($enabled as $m) {
            if (trim((string) ($transfer[$m . '_holder'] ?? '')) === '') {
                $missing[] = self::methodName($m);
            }
        }
        $items[] = self::item('holder', __('Account details saved', 'walletup'), $enabled === [] ? null : $missing === [],
            $enabled === []
                ? __('Checked once a method is on.', 'walletup')
                : ($missing === []
                    ? __('Every method that is on has a username or handle saved.', 'walletup')
                    : sprintf(/* translators: %s: method names */ __('%s is on but has no username saved, so visitors see a "not configured" note instead of a code.', 'walletup'), implode(', ', $missing))),
            $admin(self::PAGE_PAYMENTS));

        // 5. Payment page published
        $pageId = (int) get_option('wallet_up_payment_page_id', 0);
        $page   = $pageId ? get_post($pageId) : null;
        $pageOk = $page instanceof \WP_Post && $page->post_status === 'publish';
        $items[] = self::item('page', __('A payment page is published', 'walletup'), $pageOk,
            $pageOk
                ? sprintf(/* translators: %s: page title */ __('"%s" is live.', 'walletup'), get_the_title($page))
                : ($page instanceof \WP_Post
                    ? __('Your payment page exists but is not published, so the link does not work yet.', 'walletup')
                    : __('No page carries your code yet. Setup can publish one in a click; you can also add the Payment Button block to any page.', 'walletup')),
            $page instanceof \WP_Post ? (string) get_edit_post_link($pageId, 'raw') : $admin('wallet-up-onboarding'));

        // 6. Someone has opened it
        $all = class_exists('\\WalletUpPro\\Core\\Retention\\FreeCounter') ? \WalletUpPro\Core\Retention\FreeCounter::allTime() : ['impressions' => 0, 'clicks' => 0, 'confirmations' => 0];
        $seen = (int) $all['impressions'] > 0 || (int) $all['clicks'] > 0;
        $activatedAt = (int) get_option('wallet_up_activated_at', 0);
        $firstDay = $activatedAt > 0 && (time() - $activatedAt) < DAY_IN_SECONDS;
        // A brand-new install has not had time to be seen; the row turns red only after the first day.
        $items[] = self::item('seen', __('Visitors have seen your code', 'walletup'), !($pageOk || $enabled !== []) ? null : ($seen ? true : ($firstDay ? null : false)),
            $seen
                ? sprintf(/* translators: 1: views, 2: taps, 3: confirmations */ __('%1$d views, %2$d taps and %3$d confirmed payments so far.', 'walletup'), (int) $all['impressions'], (int) $all['clicks'], (int) $all['confirmations'])
                : ($firstDay
                    ? __('Nobody has opened a page with your code yet; checked again after your first day. Send the link in your next invoice or message.', 'walletup')
                    : __('Nobody has opened a page with your code yet. Send the link in your next invoice or message.', 'walletup')),
            $pageOk ? (string) get_permalink($pageId) : '');

        // 7. Admin screens load
        $failedAt = (int) get_option('wallet_up_admin_mount_failed_at', 0);
        $recent   = $failedAt > 0 && (time() - $failedAt) < 7 * DAY_IN_SECONDS;
        $items[] = self::item('admin', __('Wallet Up screens load', 'walletup'), !$recent,
            $recent
                ? sprintf(/* translators: %s: human time */ __('The visual admin did not load %s ago. Another plugin or a browser extension may be blocking its script; the simple forms on this screen work without it.', 'walletup'), human_time_diff($failedAt, time()))
                : __('The visual admin has loaded normally.', 'walletup'),
            $admin('wallet-up-onboarding') . '&fallback=1');

        // 8. Scheduled tasks run
        $cronOff  = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $next     = wp_next_scheduled('wallet_up_free_daily');
        $late     = $next !== false && $next < time() - 2 * DAY_IN_SECONDS;
        $cronOk   = !$late && ($next !== false || $cronOff);
        $items[] = self::item('cron', __('Scheduled tasks run', 'walletup'), $cronOk,
            $late
                ? __('A daily task is more than two days overdue, so the weekly summary email may not go out. Your host may have WP-Cron turned off without a replacement.', 'walletup')
                : ($cronOff
                    ? __('WP-Cron is disabled in wp-config.php, so the daily task relies on a server cron job. Everything else works without it.', 'walletup')
                    : __('The daily task is scheduled.', 'walletup')),
            '');

        // 9. WooCommerce checkout
        $wooActive = class_exists('WooCommerce');
        $gw = (array) get_option('woocommerce_wallet_up_p2p_settings', []);
        $gwOn = ($gw['enabled'] ?? 'no') === 'yes';
        $gwMethod = (string) ($gw['method'] ?? '');
        $gwReady = $gwOn && $gwMethod !== '' && in_array($gwMethod, $enabled, true) && trim((string) ($transfer[$gwMethod . '_holder'] ?? '')) !== '';
        $items[] = self::item('woo', __('WooCommerce checkout', 'walletup'), !$wooActive ? null : (!$gwOn ? null : $gwReady),
            !$wooActive
                ? __('WooCommerce is not active, so nothing to check.', 'walletup')
                : (!$gwOn
                    ? __('Wallet Up is not at your checkout. Turn it on under WooCommerce → Settings → Payments if you want one method there.', 'walletup')
                    : ($gwReady
                        ? sprintf(/* translators: %s: method name */ __('%s shows at checkout.', 'walletup'), self::methodName($gwMethod))
                        : __('Wallet Up is on at checkout, but the chosen method is off or has no username saved, so customers will not see it.', 'walletup'))),
            $wooActive ? admin_url('admin.php?page=wc-settings&tab=checkout&section=wallet_up_p2p') : '');

        $passed = count(array_filter($items, static fn($i) => $i['ok'] === true));
        $failed = count(array_filter($items, static fn($i) => $i['ok'] === false));
        $criticalIds = ['php', 'qr', 'admin'];
        $critical = (bool) array_filter($items, static fn($i) => $i['ok'] === false && in_array($i['id'], $criticalIds, true));
        return ['items' => $items, 'passed' => $passed, 'failed' => $failed, 'total' => count($items), 'critical' => $critical];
    }

    private static function item(string $id, string $label, ?bool $ok, string $detail, string $url): array
    {
        return ['id' => $id, 'label' => $label, 'ok' => $ok, 'detail' => $detail, 'url' => $url];
    }

    private static function methodName(string $id): string
    {
        if (class_exists('\\WalletUpPro\\Core\\Config\\PaymentMethodsConfig')) {
            $n = \WalletUpPro\Core\Config\PaymentMethodsConfig::getMethodName($id);
            if ($n !== '') {
                return $n;
            }
        }
        return ucfirst($id);
    }

    public static function enabledMethods(): array
    {
        $out = [];
        foreach ((array) get_option('wallet_up_enabled_methods', []) as $method => $value) {
            if (!empty($value) && $value !== 'false' && $value !== '0') {
                $out[] = (string) $method;
            }
        }
        return $out;
    }

    private static function readyAutomatedApis(): array
    {
        if (!class_exists('\\WalletUpPro\\Core\\Config\\AutomatedApiConfig')) {
            return [];
        }
        return \WalletUpPro\Core\Config\AutomatedApiConfig::getEnabledApis(true);
    }

    private static function cardStatus(bool $alreadyReady): array
    {
        if ($alreadyReady) {
            return ['enabled' => true, 'ready' => true, 'reason' => ''];
        }
        if (!class_exists('\\WalletUpPro\\Core\\Config\\AutomatedApiConfig')) {
            return ['enabled' => false, 'ready' => false, 'reason' => ''];
        }
        $s = \WalletUpPro\Core\Config\AutomatedApiConfig::stripe(true);
        return [
            'enabled' => !empty($s['enabled']),
            'ready'   => !empty($s['ready']),
            'reason'  => (string) ($s['reason'] ?? ''),
        ];
    }

    private static function hasAnyCredential(array $enabled): bool
    {
        $transfer = (array) get_option('wallet_up_transfer_call', []);
        foreach ($enabled as $method) {
            if (trim((string) ($transfer[$method . '_holder'] ?? '')) !== '') {
                return true;
            }
        }
        foreach (self::GATEWAY_OPTIONS as $option) {
            foreach ((array) get_option($option, []) as $key => $value) {
                if (is_scalar($value) && trim((string) $value) !== '' && str_contains((string) $key, 'key')) {
                    return true;
                }
                if (is_scalar($value) && trim((string) $value) !== '' && str_contains((string) $key, 'secret')) {
                    return true;
                }
            }
        }
        return (array) get_option('wallet_up_mobile_money_settings', []) !== [];
    }

    private static function needsWooCommerce(): bool
    {
        return (array) get_option('wallet_up_wc_settings', []) !== []
            || get_option('wallet_up_wc_display_mode', '') !== '';
    }

    private static function wooCommerceActive(): bool
    {
        return class_exists('WooCommerce');
    }

    private static function finding(string $state, string $text, string $page): array
    {
        return ['state' => $state, 'text' => $text, 'page' => $page];
    }
}
