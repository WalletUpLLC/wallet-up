<?php
namespace WalletUpPro\Core\Admin\Notices;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Bridges the two gaps in the free -> Pro handoff that generate support tickets:
 *
 *  A. License activated on the site, Pro code not installed yet (is_paying but
 *     not is_premium) — one step left, point at the install.
 *  B. Bought Pro on checkout but the license was never activated on THIS site.
 *     From the free build this state is indistinguishable from a genuine free
 *     user (both report is_paying() === false), so the buyer only ever sees
 *     "Upgrade to Pro" and no field to enter the key they already own. Freemius
 *     omits its own activation link from the free build of a plugin that has a
 *     premium version, so we surface the path ourselves — subtle, dismissible,
 *     and only on Wallet Up's own screens so it never nags genuine free users.
 */
class PremiumActivationNotice {

    const DISMISS_META = 'walletup_activate_help_dismissed';
    const SELF_SERVE_URL = 'https://walletup.app/activate';

    public static function init(): void {
        if ( ! function_exists( 'WalletUpPro\\wallet_up_fs' ) ) {
            return;
        }
        add_action( 'admin_notices', [ __CLASS__, 'maybeRender' ] );
        add_action( 'admin_init', [ __CLASS__, 'maybeDismiss' ] );
    }

    private static function onOwnScreen(): bool {
        global $pagenow;
        if ( 'plugins.php' === $pagenow ) {
            return true;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which screen we are on.
        $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
        return strpos( $page, 'wallet-up' ) === 0;
    }

    public static function unusedLicenceOnAccount( $fs ): bool {
        if ( ! is_object( $fs ) || ! method_exists( $fs, 'is_registered' ) || ! $fs->is_registered() ) {
            return false;
        }
        if ( ! method_exists( $fs, 'has_any_license' ) || ! $fs->has_any_license( false ) ) {
            return false;
        }
        return ! ( method_exists( $fs, 'has_active_valid_license' ) && $fs->has_active_valid_license() );
    }

    public static function paidButNotInstalled(): bool {
        if ( ! function_exists( 'WalletUpPro\\wallet_up_fs' ) ) {
            return false;
        }
        $fs = \WalletUpPro\wallet_up_fs();
        if ( ! is_object( $fs ) ) {
            return false;
        }
        if ( method_exists( $fs, 'is_premium' ) && $fs->is_premium() ) {
            return false;
        }
        // A trial is not a purchase and an expired licence is not a live one.
        if ( method_exists( $fs, 'is_trial' ) && $fs->is_trial() ) {
            return false;
        }
        if ( method_exists( $fs, 'has_active_valid_license' ) ) {
            return (bool) $fs->has_active_valid_license();
        }

        return method_exists( $fs, 'is_paying' ) && $fs->is_paying();
    }

    public static function maybeDismiss(): void {
        if ( empty( $_GET['walletup_dismiss_activate_help'] ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'walletup_dismiss_activate_help' ) ) {
            return;
        }
        update_user_meta( get_current_user_id(), self::DISMISS_META, 1 );
        wp_safe_redirect( remove_query_arg( [ 'walletup_dismiss_activate_help', '_wpnonce' ] ) );
        exit;
    }

    public static function maybeRender(): void {
        if ( class_exists( '\\WalletUpPro\\Core\\Retention\\DeactivationOffer' ) && \WalletUpPro\Core\Retention\DeactivationOffer::isPaused() ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( ! self::onOwnScreen() ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which screen we are on.
        $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
        if ( 'wallet-up-onboarding' === $page ) {
            return;
        }

        $fs = \WalletUpPro\wallet_up_fs();
        if ( ! is_object( $fs ) ) {
            return;
        }

        if ( method_exists( $fs, 'is_premium' ) && $fs->is_premium() ) {
            return;
        }

        if ( self::paidButNotInstalled() ) {
            if ( class_exists( __NAMESPACE__ . '\\ProInstallModal' ) && ProInstallModal::isOwed() ) {
                return;
            }
            self::renderInstallStep( $fs );
            return;
        }

        self::renderActivateHelp( $fs );
    }

    /**
     * State A: license active on the site, premium code still missing.
     */
    private static function renderInstallStep( $fs ): void {
        $downloadUrl = method_exists( $fs, 'get_account_url' ) ? $fs->get_account_url( 'download_latest' ) : admin_url( 'admin.php?page=wallet-up-account' );
        $accountUrl  = method_exists( $fs, 'get_account_url' ) ? $fs->get_account_url() : admin_url( 'admin.php?page=wallet-up-account' );
        ?>
        <div class="notice notice-warning is-dismissible">
            <p style="font-size:14px;line-height:1.6;margin:8px 0 4px;">
                <strong><?php /* translators: %s: Pro product name */ echo esc_html( sprintf( __( 'Thanks for upgrading to %s!', 'walletup' ), WALLET_UP_PRO_PRODUCT_NAME ) ); ?></strong>
                <?php esc_html_e( 'Your purchase is confirmed. One step is left before the Pro features unlock: download the Pro version and install it from Plugins, Add New, Upload. Your license and all your settings carry over automatically.', 'walletup' ); ?>
            </p>
            <p style="margin:8px 0 12px;">
                <a href="<?php echo esc_url( $downloadUrl ); ?>" class="button button-primary" target="_blank" rel="noopener"><?php esc_html_e( 'Download the Pro version', 'walletup' ); ?></a>
                <a href="<?php echo esc_url( $accountUrl ); ?>" class="button button-link" style="margin-left:6px;"><?php esc_html_e( 'Go to my account', 'walletup' ); ?></a>
            </p>
        </div>
        <?php
    }

    private static function renderActivateHelp( $fs = null ): void {
        if ( function_exists('\WalletUpPro\wallet_up_is_preview_sandbox') && \WalletUpPro\wallet_up_is_preview_sandbox() ) {
            return;
        }

        if ( get_user_meta( get_current_user_id(), self::DISMISS_META, true ) ) {
            return;
        }

        if ( ! self::unusedLicenceOnAccount( $fs ) ) {
            return;
        }

        $activateUrl = ( is_object( $fs ) && method_exists( $fs, 'get_activation_url' ) ) ? $fs->get_activation_url() : self::SELF_SERVE_URL;
        $dismissUrl = wp_nonce_url( add_query_arg( 'walletup_dismiss_activate_help', '1' ), 'walletup_dismiss_activate_help' );
        ?>
        <div class="notice notice-info" style="border-left-color:#7c3aed;">
            <p style="font-size:14px;line-height:1.6;margin:8px 0 4px;">
                <strong><?php /* translators: %s: Pro product name */ echo esc_html( sprintf( __( 'Your %s licence is ready for this site.', 'walletup' ), WALLET_UP_PRO_PRODUCT_NAME ) ); ?></strong>
                <?php esc_html_e( 'Your account has a licence that is not active here yet. Activate it to unlock Pro and get the Pro download. There is nothing more to pay.', 'walletup' ); ?>
            </p>
            <p style="margin:8px 0 12px;">
                <a href="<?php echo esc_url( $activateUrl ); ?>" class="button button-primary"><?php esc_html_e( 'Activate my license', 'walletup' ); ?></a>
                <a href="<?php echo esc_url( $dismissUrl ); ?>" class="button button-link" style="margin-left:6px;"><?php esc_html_e( 'Dismiss', 'walletup' ); ?></a>
            </p>
        </div>
        <?php
    }
}
