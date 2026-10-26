<?php
namespace WalletUpPro\Core\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * One place for a stuck customer to unblock themselves without ever handing over
 * a password:
 *
 *  - See exactly where their license stands (free / paid-not-activated / Pro active).
 *  - Mint a temporary, expiring, revocable passwordless login link to paste into a
 *    support reply, so our team can fix activation on their site directly.
 *  - Copy a diagnostics snapshot so support can often solve it without logging in at all.
 *
 * The login link grants a real admin session, so it is defended like a credential:
 * 256-bit token stored only as a SHA-256 hash, timing-safe compare, hard expiry,
 * per-IP rate limiting, a single active link at a time, an audit trail, and a
 * dedicated throwaway user that is deleted on revoke/expiry.
 */
class SupportAccessManager {

    const OPT         = 'wallet_up_support_access';
    const AUDIT_OPT   = 'wallet_up_support_audit';
    const LOGIN_PARAM = 'wallet_up_support_login';
    const NONCE       = 'wallet_up_support_access';
    const CLEANUP_HOOK = 'walletup_support_access_cleanup';
    const MAX_ATTEMPTS = 8;
    const ALLOWED_TTL  = [ 1, 3, 7 ];

    public static function init(): void {
        add_action( 'init', [ __CLASS__, 'maybeLogin' ], 1 );
        add_action( 'wp_ajax_wallet_up_support_status', [ __CLASS__, 'ajaxStatus' ] );
        add_action( 'wp_ajax_wallet_up_support_generate', [ __CLASS__, 'ajaxGenerate' ] );
        add_action( 'wp_ajax_wallet_up_support_revoke', [ __CLASS__, 'ajaxRevoke' ] );
        add_action( self::CLEANUP_HOOK, [ __CLASS__, 'cleanupExpired' ] );
        add_action( 'admin_init', [ __CLASS__, 'cleanupExpired' ] );
        if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
        }
    }

    /** Feeds the React SupportPage: license state, active-link status, diagnostics, and a
     *  fresh support-scoped nonce for the generate/revoke calls. */
    public static function ajaxStatus(): void {
        if ( ! check_ajax_referer( 'wallet_up_admin_nonce', 'nonce', false ) || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'code' => 'forbidden' ], 403 );
        }
        $existing = get_option( self::OPT );
        $hasLink  = is_array( $existing ) && ! empty( $existing['expires'] ) && time() < (int) $existing['expires'];
        wp_send_json_success( [
            'license'       => self::licenseState(),
            'has_link'      => $hasLink,
            'expires_human' => $hasLink ? sprintf(
                /* translators: %s: human-readable time difference, e.g. "3 days". */
                __( 'Expires in %s', 'walletup' ),
                human_time_diff( time(), (int) $existing['expires'] )
            ) : '',
            'diagnostics'   => self::diagnostics(),
            'activate_url'  => 'https://walletup.app/activate',
            'support_nonce' => wp_create_nonce( self::NONCE ),
        ] );
    }

    public static function maybeLogin(): void {
        if ( empty( $_GET[ self::LOGIN_PARAM ] ) ) {
            return;
        }

        $token = sanitize_text_field( wp_unslash( $_GET[ self::LOGIN_PARAM ] ) );
        $ip    = self::clientIp();
        $rlKey = 'wu_support_attempts_' . md5( $ip );

        if ( (int) get_transient( $rlKey ) >= self::MAX_ATTEMPTS ) {
            self::denyLogin();
        }

        $data = get_option( self::OPT );
        if ( ! is_array( $data ) || empty( $data['token_hash'] ) || empty( $data['user_id'] ) ) {
            self::bumpAttempts( $rlKey );
            self::denyLogin();
        }

        if ( time() > (int) $data['expires'] ) {
            self::revokeInternal( 'expired' );
            self::denyLogin();
        }

        if ( ! hash_equals( (string) $data['token_hash'], hash( 'sha256', $token ) ) ) {
            self::bumpAttempts( $rlKey );
            self::denyLogin();
        }

        $user = get_user_by( 'id', (int) $data['user_id'] );
        if ( ! $user ) {
            self::revokeInternal( 'missing_user' );
            self::denyLogin();
        }

        wp_set_current_user( $user->ID );
        wp_set_auth_cookie( $user->ID, false );

        $data['uses']    = (int) ( $data['uses'] ?? 0 ) + 1;
        $data['used_at'] = time();
        update_option( self::OPT, $data, false );
        delete_transient( $rlKey );
        self::audit( 'login', [ 'ip' => $ip, 'uses' => $data['uses'] ] );

        wp_safe_redirect( admin_url( 'admin.php?page=wallet-up' ) );
        exit;
    }

    public static function ajaxGenerate(): void {
        self::guardAjax();

        $days = isset( $_POST['days'] ) ? (int) $_POST['days'] : 3;
        if ( ! in_array( $days, self::ALLOWED_TTL, true ) ) {
            $days = 3;
        }

        self::revokeInternal( 'regenerated' );

        $userId = self::createTempUser( $days );
        if ( is_wp_error( $userId ) || ! $userId ) {
            wp_send_json_error( [ 'code' => 'user_create_failed' ] );
        }

        $token   = bin2hex( random_bytes( 32 ) );
        $expires = time() + ( $days * DAY_IN_SECONDS );

        update_option( self::OPT, [
            'token_hash' => hash( 'sha256', $token ),
            'user_id'    => $userId,
            'expires'    => $expires,
            'created'    => time(),
            'created_by' => get_current_user_id(),
            'uses'       => 0,
            'used_at'    => null,
        ], false );

        self::audit( 'generate', [ 'days' => $days, 'by' => get_current_user_id() ] );

        wp_send_json_success( [
            'url'        => add_query_arg( self::LOGIN_PARAM, $token, home_url( '/' ) ),
            'expiresIso' => gmdate( 'c', $expires ),
            'expiresHuman' => sprintf(
                /* translators: %s: human-readable time difference, e.g. "3 days". */
                __( 'Expires in %s', 'walletup' ),
                human_time_diff( time(), $expires )
            ),
        ] );
    }

    public static function ajaxRevoke(): void {
        self::guardAjax();
        self::revokeInternal( 'manual' );
        wp_send_json_success();
    }

    public static function cleanupExpired(): void {
        $data = get_option( self::OPT );
        if ( is_array( $data ) && ! empty( $data['expires'] ) && time() > (int) $data['expires'] ) {
            self::revokeInternal( 'expired' );
        }
    }

    private static function guardAjax(): void {
        if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'code' => 'forbidden' ], 403 );
        }
    }

    private static function createTempUser( int $days ) {
        require_once ABSPATH . 'wp-admin/includes/user.php';

        $suffix = bin2hex( random_bytes( 4 ) );
        $userId = wp_insert_user( [
            'user_login'   => 'wu_support_' . $suffix,
            'user_pass'    => wp_generate_password( 40, true, true ),
            'user_email'   => 'wu-support-' . $suffix . '@no-reply.walletup.invalid',
            'display_name' => __( 'Wallet Up Support (temporary)', 'walletup' ),
            'role'         => 'administrator',
        ] );

        if ( ! is_wp_error( $userId ) ) {
            update_user_meta( $userId, 'wallet_up_support_temp', time() + ( $days * DAY_IN_SECONDS ) );
        }

        return $userId;
    }

    private static function revokeInternal( string $reason ): void {
        $data = get_option( self::OPT );
        if ( is_array( $data ) && ! empty( $data['user_id'] ) ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            if ( get_user_by( 'id', (int) $data['user_id'] ) ) {
                wp_delete_user( (int) $data['user_id'] );
            }
            self::audit( 'revoke', [ 'reason' => $reason ] );
        }
        delete_option( self::OPT );
    }

    private static function bumpAttempts( string $key ): void {
        set_transient( $key, (int) get_transient( $key ) + 1, HOUR_IN_SECONDS );
    }

    private static function denyLogin(): void {
        self::audit( 'login_denied', [ 'ip' => self::clientIp() ] );
        wp_die(
            esc_html__( 'This support link is invalid or has expired. Please generate a new one from your site.', 'walletup' ),
            esc_html__( 'Support access', 'walletup' ),
            [ 'response' => 403 ]
        );
    }

    private static function clientIp(): string {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
        return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
    }

    private static function audit( string $event, array $context = [] ): void {
        $log   = get_option( self::AUDIT_OPT );
        $log   = is_array( $log ) ? $log : [];
        $log[] = [ 'event' => $event, 'at' => time(), 'context' => $context ];
        if ( count( $log ) > 100 ) {
            $log = array_slice( $log, -100 );
        }
        update_option( self::AUDIT_OPT, $log, false );
    }

    private static function licenseState(): array {
        if ( ! function_exists( 'WalletUpPro\\wallet_up_fs' ) ) {
            return [ 'key' => 'free', 'label' => __( 'Free', 'walletup' ) ];
        }
        $fs = \WalletUpPro\wallet_up_fs();
        if ( ! is_object( $fs ) ) {
            return [ 'key' => 'free', 'label' => __( 'Free', 'walletup' ) ];
        }
        $isPremium  = method_exists( $fs, 'is_premium' ) && $fs->is_premium();
        $isEntitled = method_exists( $fs, 'can_use_premium_code' ) && $fs->can_use_premium_code();

        if ( $isPremium ) {
            return [ 'key' => 'pro', 'label' => __( 'Pro active', 'walletup' ) ];
        }
        if ( $isEntitled ) {
            return [ 'key' => 'paid_not_installed', 'label' => __( 'Licensed, Pro version not installed yet', 'walletup' ) ];
        }
        return [ 'key' => 'free', 'label' => __( 'Free — no license activated on this site', 'walletup' ) ];
    }

    private static function diagnostics(): string {
        global $wp_version;
        $active = [];
        foreach ( (array) get_option( 'active_plugins', [] ) as $plugin ) {
            $file = WP_PLUGIN_DIR . '/' . $plugin;
            if ( is_readable( $file ) ) {
                $d = get_file_data( $file, [ 'Name' => 'Plugin Name', 'Version' => 'Version' ] );
                $active[] = trim( $d['Name'] . ' ' . $d['Version'] );
            }
        }
        $state = self::licenseState();

        $lines = [
            'Wallet Up: ' . ( defined( 'WALLET_UP_PRO_VERSION' ) ? WALLET_UP_PRO_VERSION : 'unknown' ) . ' (' . $state['label'] . ')',
            'Site: ' . home_url( '/' ),
            'Admin email: ' . get_option( 'admin_email' ),
            'WordPress: ' . $wp_version . ( is_multisite() ? ' (multisite)' : '' ),
            'PHP: ' . PHP_VERSION,
            'WooCommerce: ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : 'not active' ),
            'Theme: ' . ( wp_get_theme() ? wp_get_theme()->get( 'Name' ) . ' ' . wp_get_theme()->get( 'Version' ) : 'unknown' ),
            'Memory limit: ' . ( ini_get( 'memory_limit' ) ?: 'unknown' ),
            'Active plugins:',
        ];
        foreach ( $active as $p ) {
            $lines[] = '  - ' . $p;
        }
        return implode( "\n", $lines );
    }
}
