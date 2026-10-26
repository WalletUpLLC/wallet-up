<?php

declare(strict_types=1);

namespace WalletUpPro\Core\Support;

use WalletUpPro\Core\Security\PlainText;

if (!defined('ABSPATH')) {
    exit;
}

final class SetupHelp
{
    public const AJAX_REQUEST = 'wallet_up_setup_help_request';
    public const AJAX_OPTIONS = 'wallet_up_setup_help_options';
    public const NONCE        = 'wallet_up_admin_nonce';

    public const FILTER_ENDPOINT     = 'wallet_up_setup_help_endpoint';
    public const FILTER_SECRET       = 'wallet_up_setup_help_secret';
    public const FILTER_TEAM_EMAIL   = 'wallet_up_setup_help_team_email';
    public const FILTER_OWNER_ALERT  = 'wallet_up_setup_help_owner_alert';
    public const FILTER_BOOKING_URL  = 'wallet_up_setup_help_booking_url';

    private const OPTION_THROTTLE = 'wallet_up_setup_help_last';
    private const CAP             = 'manage_options';
    private const K_SUPPORT_LINK  = 'support_link';

    public const ALERT_TAG = 'WUP-SETUP';

    public const MESSAGE_MAX = 2000;
    private const NAME_MAX   = 120;
    private const EMAIL_MAX  = 254;
    private const FIELD_MAX  = 64;
    private const URL_MAX    = 2048;
    private const SUBJECT_MAX = 200;

    public static function register(): void
    {
        add_action('wp_ajax_' . self::AJAX_REQUEST, [self::class, 'handleRequest']);
        add_action('wp_ajax_' . self::AJAX_OPTIONS, [self::class, 'handleOptions']);
    }

    private static function endpoint(): string
    {
        return esc_url_raw((string) apply_filters(self::FILTER_ENDPOINT, ''));
    }

    private static function teamEmail(): string
    {
        return sanitize_email((string) apply_filters(self::FILTER_TEAM_EMAIL, 'walup@walletup.app')); // zi2-dry: ignore
    }

    private static function ownerAlert(): string
    {
        return sanitize_email((string) apply_filters(self::FILTER_OWNER_ALERT, 'walup@walletup.app')); // zi2-dry: ignore
    }

    private static function bookingUrl(): string
    {
        return esc_url_raw((string) apply_filters(self::FILTER_BOOKING_URL, 'https://zisquare.app/book/org-8ed1c63c/wup')); // zi2-dry: ignore
    }

    public static function handleOptions(): void
    {
        self::assertAllowed();
        $options = [
            'booking' => self::bookingUrl(),
            'phone'   => '',
        ];
        $limits = ['messageMax' => self::MESSAGE_MAX];
        $endpoint = self::endpoint();
        if ($endpoint !== '') {
            $res = wp_remote_get(
                add_query_arg('what', 'options', $endpoint),
                ['timeout' => 6, 'headers' => ['Accept' => 'application/json']]
            );
            if (!is_wp_error($res) && (int) wp_remote_retrieve_response_code($res) < 300) {
                $remote = json_decode((string) wp_remote_retrieve_body($res), true);
                if (is_array($remote)) {
                    $options = array_merge($options, array_intersect_key($remote, $options));
                    $options['booking'] = is_string($options['booking']) ? esc_url_raw($options['booking']) : self::bookingUrl();
                    $options['phone']   = is_string($options['phone']) ? preg_replace('/[^0-9+()\-.\s]/', '', $options['phone']) : '';
                }
            }
        }
        wp_send_json_success($options + $limits);
    }

    public static function handleRequest(): void
    {
        self::assertAllowed();

        $last = (int) get_option(self::OPTION_THROTTLE, 0);
        if ($last > time() - 120) {
            wp_send_json_success(['delivered' => true, 'booking' => self::bookingUrl(), 'throttled' => true]);
        }

        $payload   = self::collect();
        $delivered = self::deliver($payload);
        update_option(self::OPTION_THROTTLE, time(), false);

        wp_send_json_success([
            'delivered' => $delivered,
            'booking'   => self::bookingUrl(),
        ]);
    }

    private static function assertAllowed(): void
    {
        if (!check_ajax_referer(self::NONCE, 'nonce', false) || !current_user_can(self::CAP)) {
            wp_send_json_error(['message' => __('That request could not be verified.', 'walletup')], 403);
        }
    }

    private static function collect(): array
    {
        $user = wp_get_current_user();
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in assertAllowed().
        $name    = PlainText::line(wp_unslash($_POST['name'] ?? ''), self::NAME_MAX);
        $email   = self::email(wp_unslash($_POST['email'] ?? ''));
        $message = PlainText::text(wp_unslash($_POST['message'] ?? ''), self::MESSAGE_MAX);
        $support = PlainText::url(wp_unslash($_POST[self::K_SUPPORT_LINK] ?? ''), self::URL_MAX);
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        return [
            'site'         => PlainText::url(home_url(), self::URL_MAX),
            'name'         => $name !== '' ? $name : PlainText::line($user->display_name, self::NAME_MAX),
            'email'        => $email !== '' ? $email : self::email($user->user_email),
            'message'      => $message,
            'industry'     => PlainText::line(get_option('wallet_up_industry', ''), self::FIELD_MAX),
            'goal'         => PlainText::line(get_option('wallet_up_onboarding_goal', ''), self::FIELD_MAX),
            'version'      => defined('WALLET_UP_PRO_VERSION') ? PlainText::line(WALLET_UP_PRO_VERSION, self::FIELD_MAX) : '',
            'pro'          => defined('WALLET_UP_IS_PRO_BUILD') && WALLET_UP_IS_PRO_BUILD,
            self::K_SUPPORT_LINK => $support,
            'requested_at' => gmdate('c'),
        ];
    }

    private static function email($value): string
    {
        $email = sanitize_email(PlainText::line($value, self::EMAIL_MAX));
        return is_email($email) ? $email : '';
    }

    private static function deliver(array $payload): bool
    {
        $endpoint = self::endpoint();
        if ($endpoint !== '' && self::postToIntake($endpoint, $payload)) {
            return true;
        }
        return self::fallbackMail($payload);
    }

    private static function postToIntake(string $endpoint, array $payload): bool
    {
        $body    = wp_json_encode($payload);
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];

        $secret = (string) apply_filters(self::FILTER_SECRET, '');
        if ($secret !== '' && is_string($body)) {
            $headers['X-WalletUp-Signature'] = hash_hmac('sha256', $body, $secret);
        }

        $res = wp_remote_post($endpoint, [
            'timeout' => 8,
            'headers' => $headers,
            'body'    => $body,
        ]);

        return !is_wp_error($res) && (int) wp_remote_retrieve_response_code($res) < 300;
    }

    private static function fallbackMail(array $payload): bool
    {
        $subject = PlainText::line(sprintf('[Setup Help] %s - %s', $payload['site'], $payload['name']), self::SUBJECT_MAX);
        $plain   = ['Content-Type: text/plain; charset=UTF-8'];
        $lines   = [
            __('A customer asked for setup help.', 'walletup'),
            '',
            'Site: ' . $payload['site'],
            'Name: ' . $payload['name'],
            'Email: ' . $payload['email'],
            'Industry: ' . $payload['industry'],
            'Goal: ' . $payload['goal'],
            'Plugin: ' . $payload['version'] . ($payload['pro'] ? ' (Pro)' : ' (Free)'),
            'When: ' . $payload['requested_at'],
            '',
            'Message:',
            $payload['message'],
        ];
        if ($payload[self::K_SUPPORT_LINK] !== '') {
            $lines[] = '';
            $lines[] = 'Support access: ' . $payload[self::K_SUPPORT_LINK];
        }
        $body = implode("\n", $lines);

        $sent = wp_mail(self::teamEmail(), $subject, $body, $plain);

        $owner = self::ownerAlert();
        if ($owner !== '') {
            wp_mail(
                $owner,
                '[' . self::ALERT_TAG . '] ' . $subject,
                $body,
                array_merge($plain, ['X-Priority: 1', 'Importance: High', 'X-WalletUp-Alert: ' . self::ALERT_TAG])
            );
        }

        return (bool) $sent;
    }
}
