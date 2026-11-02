<?php
declare(strict_types=1);

namespace WalletUpPro\Core\Retention;

use WalletUpPro\Core\Config\PaymentMethodsConfig;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The free plan's answer to "did anyone pay me?" (6.6.0).
 *
 * A thirty-day ring of impressions, taps and confirmations in one option, plus
 * the emails that follow from it: the first tap, every confirmation, and one
 * digest after the first week. Pro keeps the full Activity module; this is a
 * number and a nudge, not a report.
 */
final class FreeCounter
{
    public const OPTION        = 'wallet_up_free_counts';
    public const CRON_HOOK     = 'wallet_up_free_daily';
    public const AJAX_EVENT    = 'wallet_up_payment_event';
    private const KEEP_DAYS    = 30;
    private const RECENT_MAX   = 3;

    public static function init(): void
    {
        add_action('wp_ajax_' . self::AJAX_EVENT, [self::class, 'handleEvent']);
        add_action('wp_ajax_nopriv_' . self::AJAX_EVENT, [self::class, 'handleEvent']);
        add_action('wallet_up_display_rendered', [self::class, 'onImpression'], 10, 3);
        add_action('wallet_up_payment_confirmed', [self::class, 'onConfirmation'], 10, 1);
        add_action(self::CRON_HOOK, [self::class, 'daily']);
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    public static function unschedule(): void
    {
        $ts = wp_next_scheduled(self::CRON_HOOK);
        if ($ts) {
            wp_unschedule_event($ts, self::CRON_HOOK);
        }
    }

    private static function premium(): bool
    {
        return function_exists('WalletUpPro\\wallet_up_premium_active') && \WalletUpPro\wallet_up_premium_active();
    }

    private static function load(): array
    {
        $o = get_option(self::OPTION, []);
        if (!is_array($o)) {
            $o = [];
        }
        $o += ['days' => [], 'recent' => [], 'first_click_at' => 0, 'first_click_email_at' => 0, 'digest_sent_at' => 0];
        if (!is_array($o['days'])) {
            $o['days'] = [];
        }
        if (!is_array($o['recent'])) {
            $o['recent'] = [];
        }
        return $o;
    }

    private static function save(array $o): void
    {
        $cut = gmdate('Y-m-d', time() - self::KEEP_DAYS * DAY_IN_SECONDS);
        foreach (array_keys($o['days']) as $day) {
            if ($day < $cut) {
                unset($o['days'][$day]);
            }
        }
        update_option(self::OPTION, $o, false);
    }

    /** @param 'i'|'c'|'f' $kind impressions, clicks (taps), confirmations */
    public static function bump(string $kind, string $method = ''): void
    {
        $o = self::load();
        $day = gmdate('Y-m-d');
        if (!isset($o['days'][$day])) {
            $o['days'][$day] = ['i' => 0, 'c' => 0, 'f' => 0];
        }
        $o['days'][$day][$kind] = (int) ($o['days'][$day][$kind] ?? 0) + 1;
        if ($kind === 'c' && empty($o['first_click_at'])) {
            $o['first_click_at'] = time();
            $o['first_click_method'] = $method;
        }
        self::save($o);
    }

    public static function totals(int $days = 7): array
    {
        $o = self::load();
        $cut = gmdate('Y-m-d', time() - max(0, $days - 1) * DAY_IN_SECONDS);
        $t = ['impressions' => 0, 'clicks' => 0, 'confirmations' => 0];
        foreach ($o['days'] as $day => $row) {
            if ($day < $cut) {
                continue;
            }
            $t['impressions']   += (int) ($row['i'] ?? 0);
            $t['clicks']        += (int) ($row['c'] ?? 0);
            $t['confirmations'] += (int) ($row['f'] ?? 0);
        }
        return $t;
    }

    public static function allTime(): array
    {
        return self::totals(self::KEEP_DAYS);
    }

    public static function recent(): array
    {
        return array_slice((array) self::load()['recent'], 0, self::RECENT_MAX);
    }

    public static function hasClicks(): bool
    {
        return !empty(self::load()['first_click_at']);
    }

    /* ---------------- events ---------------- */

    public static function onImpression(string $method, int $postId, string $mode): void
    {
        // 6.7.0: the owner previewing their own page is not a visitor; counting them turned every
        // "did anyone see it" signal green on the first click of "Create page".
        if (is_user_logged_in() && current_user_can('edit_posts')) {
            return;
        }
        if (self::premium()) {
            return;
        }
        self::bump('i', $method);
    }

    /** The sendBeacon from assets/js/wup-events.js on a tap of a code, button or link. */
    public static function handleEvent(): void
    {
        // 6.7.0: no nonce. This is a tap counter with per-IP dedupe and no state a visitor could
        // abuse; a nonce baked into a cached page expired after a day and silently froze "taps" at 0.
        if (is_user_logged_in() && current_user_can('edit_posts')) {
            wp_send_json_success(['ignored' => 'owner']);
        }
        $type   = isset($_POST['type']) ? sanitize_key(wp_unslash($_POST['type'])) : '';
        $method = isset($_POST['method']) ? sanitize_key(wp_unslash($_POST['method'])) : '';
        if ($type !== 'click' || $method === '' || !in_array($method, PaymentMethodsConfig::getAllMethodIds(), true)) {
            wp_send_json_error(['message' => 'ignored'], 400);
        }
        $postId = (int) ($_POST['post_id'] ?? 0);
        if ($postId && get_post_status($postId) === false) {
            $postId = 0; // only real posts take part in the dedupe key
        }
        $_POST['post_id'] = $postId;
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $perIp = 'wup_evt_ip_' . md5($ip);
        $n = (int) get_transient($perIp);
        if ($n >= 30) {
            wp_send_json_success(['deduped' => true]); // one address cannot inflate the counter
        }
        set_transient($perIp, $n + 1, HOUR_IN_SECONDS);
        $key = 'wup_evt_' . md5($ip . $method . $postId);
        if (get_transient($key)) {
            wp_send_json_success(['deduped' => true]);
        }
        set_transient($key, 1, 10 * MINUTE_IN_SECONDS);
        if (!self::premium()) {
            $before = self::hasClicks();
            self::bump('c', $method);
            if (!$before) {
                self::firstTapEmail($method, (int) ($_POST['post_id'] ?? 0));
            }
        }
        do_action('wallet_up_payment_event_recorded', $method, (int) ($_POST['post_id'] ?? 0));
        wp_send_json_success(['ok' => true]);
    }

    /** @param array{method:string,amount:float,name:string,email:string,reference:string,order_id:int,post_id:int} $p */
    public static function onConfirmation(array $p): void
    {
        if (self::premium()) {
            return;
        }
        $o = self::load();
        array_unshift($o['recent'], [
            'ts'     => time(),
            'name'   => sanitize_text_field((string) ($p['name'] ?? '')),
            'amount' => (float) ($p['amount'] ?? 0),
            'method' => sanitize_key((string) ($p['method'] ?? '')),
            'order'  => (int) ($p['order_id'] ?? 0),
        ]);
        $o['recent'] = array_slice($o['recent'], 0, self::RECENT_MAX);
        self::save($o);
        self::bump('f', (string) ($p['method'] ?? ''));
        self::confirmationEmail($p);
    }

    /* ---------------- emails ---------------- */

    public static function notifyEmail(): string
    {
        $e = (string) get_option('wallet_up_notify_email', '');
        return is_email($e) ? $e : '';
    }

    private static function methodName(string $id): string
    {
        return PaymentMethodsConfig::getMethodName($id) ?: ucfirst($id);
    }

    private static function pageLabel(int $postId): string
    {
        $t = $postId ? get_the_title($postId) : '';
        return $t !== '' ? $t : __('your site', 'walletup');
    }

    private static function send(string $subject, string $body, string $replyTo = ''): bool
    {
        $to = self::notifyEmail();
        if ($to === '') {
            return false;
        }
        $body .= "\n\n" . sprintf(/* translators: %s: dashboard URL */ __('Wallet Up dashboard: %s', 'walletup'), admin_url('admin.php?page=wallet-up'));
        $body .= "\n" . sprintf(/* translators: %s: Wallet Up admin URL */ __('Turn these emails off under Wallet Up → Settings → Notifications: %s', 'walletup'), admin_url('admin.php?page=wallet-up'));
        $headers = [];
        if ($replyTo !== '' && is_email($replyTo)) {
            $headers[] = 'Reply-To: ' . $replyTo;
        }
        return (bool) wp_mail($to, $subject, $body, $headers);
    }

    /**
     * At most ten confirmation emails a day, then one more saying the rest are on the dashboard;
     * identical confirmations (same IP, method and amount) within ten minutes send nothing.
     */
    private static function confirmationEmailAllowed(array $p): bool
    {
        $ip  = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $dup = 'wup_confmail_' . md5($ip . '|' . (string) ($p['method'] ?? '') . '|' . (string) ($p['amount'] ?? ''));
        if (get_transient($dup)) {
            return false;
        }
        set_transient($dup, 1, 10 * MINUTE_IN_SECONDS);
        $o   = self::load();
        $day = gmdate('Y-m-d');
        if (($o['mail_day'] ?? '') !== $day) {
            $o['mail_day'] = $day;
            $o['mail_count'] = 0;
        }
        $o['mail_count'] = (int) ($o['mail_count'] ?? 0) + 1;
        self::save($o);
        if ($o['mail_count'] > 11) {
            return false;
        }
        if ($o['mail_count'] === 11) {
            self::send(__('More payment confirmations today', 'walletup'), __('You have had more than ten payment confirmations today. The rest are listed on your Wallet Up dashboard; emails resume tomorrow.', 'walletup'));
            return false;
        }
        return true;
    }

    private static function firstTapEmail(string $method, int $postId): void
    {
        $o = self::load();
        if (!empty($o['first_click_email_at'])) {
            return;
        }
        $name = self::methodName($method);
        $sent = self::send(
            sprintf(/* translators: %s: method name */ __('Someone just tapped your %s link', 'walletup'), $name),
            sprintf(
                /* translators: 1: page title, 2: method name */
                __("A visitor on \"%1\$s\" opened your %2\$s payment details a moment ago. A tap means they opened the app or copied your details; if they paid, the money is already in your %2\$s account, so check the app.\n\nIf you want visitors to confirm on your site after paying, the \"I've sent it\" form is already under your code. You'll get an email each time it is used.", 'walletup'),
                self::pageLabel($postId),
                $name
            )
        );
        if ($sent) {
            $o['first_click_email_at'] = time(); // an owner who opts in later still gets the first-tap mail
            self::save($o);
        }
    }

    private static function confirmationEmail(array $p): void
    {
        if (!self::confirmationEmailAllowed($p)) {
            return;
        }
        $name   = sanitize_text_field((string) ($p['name'] ?? ''));
        $email  = sanitize_email((string) ($p['email'] ?? ''));
        $amount = (float) ($p['amount'] ?? 0);
        $method = self::methodName((string) ($p['method'] ?? ''));
        $cur    = PaymentMethodsConfig::currencySymbol(PaymentMethodsConfig::methodCurrency((string) ($p['method'] ?? '')));
        $who    = $name !== '' ? $name : ($email !== '' ? $email : __('A visitor', 'walletup'));
        $ref    = sanitize_text_field((string) ($p['reference'] ?? ''));
        $order  = (int) ($p['order_id'] ?? 0);
        $body   = sprintf(
            /* translators: 1: payer, 2: payer email, 3: currency symbol, 4: amount, 5: method name, 6: reference */
            __("%1\$s (%2\$s) confirmed a payment of %3\$s%4\$s via %5\$s. Reference: %6\$s. Check %5\$s to match it.", 'walletup'),
            $who,
            $email !== '' ? $email : '-',
            $cur,
            number_format($amount, 2),
            $method,
            $ref !== '' ? $ref : '-'
        );
        if ($order > 0 && function_exists('wc_get_order')) {
            $body .= "\n\n" . sprintf(/* translators: 1: order number, 2: edit link */ __('WooCommerce order #%1$s is on hold until you confirm it: %2$s', 'walletup'), $order, admin_url('post.php?post=' . $order . '&action=edit'));
        }
        if ($email !== '') {
            $body .= "\n" . __('Reply to this email to reach them.', 'walletup');
        }
        self::send(
            sprintf(/* translators: 1: payer, 2: currency symbol, 3: amount, 4: method */ __('%1$s says they sent you %2$s%3$s via %4$s', 'walletup'), $who, $cur, number_format($amount, 2), $method),
            $body,
            $email
        );
    }

    /** Daily cron: one digest, seven days after activation, only once. */
    public static function daily(): void
    {
        if (self::premium()) {
            return;
        }
        $o = self::load();
        $activated = (int) get_option('wallet_up_activated_at', 0);
        if ($activated <= 0 || !empty($o['digest_sent_at']) || time() - $activated < 7 * DAY_IN_SECONDS || self::notifyEmail() === '') {
            return;
        }
        $t = self::totals(7);
        $pageId = (int) get_option('wallet_up_payment_page_id', 0);
        $url = $pageId ? get_permalink($pageId) : home_url('/');
        if ($t['impressions'] > 0 || $t['clicks'] > 0 || $t['confirmations'] > 0) {
            $subject = sprintf(/* translators: 1: views, 2: taps */ __('Your first week with Wallet Up: %1$d views, %2$d taps', 'walletup'), $t['impressions'], $t['clicks']);
            $body = sprintf(
                /* translators: 1: views, 2: taps, 3: confirmations */
                __("Your payment code was seen %1\$d times, tapped %2\$d times and confirmed %3\$d times this week.\n\nTip: add the Payment Button block to your contact page so people who are already asking you a question can pay you there.", 'walletup'),
                $t['impressions'],
                $t['clicks'],
                $t['confirmations']
            );
        } elseif ($pageId && get_post_status($pageId) === 'publish') {
            $subject = __('Nobody has seen your payment code yet', 'walletup');
            $body = sprintf(
                /* translators: %s: page URL */
                __("Your code is live at %s but no visitor has opened that page this week. Three ways people get paid with Wallet Up: send the link in your next invoice email, add the Payment Button block to your contact page, or print the QR (Download QR in your dashboard) for the counter.", 'walletup'),
                $url
            );
        } else {
            $subject = __('Your payment code is not on a page yet', 'walletup');
            $body = sprintf(
                /* translators: %s: dashboard URL */
                __("A week in, your code is ready but no page shows it. Open your dashboard (%s) and click \"Create a page\", or add the Payment Button block to any page you already have. Then send that link in your next invoice or message.", 'walletup'),
                admin_url('admin.php?page=wallet-up')
            );
        }
        if (self::send($subject, $body)) {
            $o['digest_sent_at'] = time();
            self::save($o);
        }
    }

    /** Data for the dashboard "This week" card. */
    public static function dashboardData(): array
    {
        $pageId = (int) get_option('wallet_up_payment_page_id', 0);
        return [
            'week'    => self::totals(7),
            'recent'  => array_map(static function ($r) {
                return [
                    'when'   => human_time_diff((int) $r['ts'], time()),
                    'name'   => (string) ($r['name'] ?? ''),
                    'amount' => number_format((float) ($r['amount'] ?? 0), 2),
                    'symbol' => PaymentMethodsConfig::currencySymbol(PaymentMethodsConfig::methodCurrency((string) ($r['method'] ?? ''))),
                    'method' => self::methodName((string) ($r['method'] ?? '')),
                    'order'  => (int) ($r['order'] ?? 0),
                ];
            }, self::recent()),
            'pageUrl' => $pageId ? (string) get_permalink($pageId) : '',
            'notify'  => self::notifyEmail(),
        ];
    }
}
