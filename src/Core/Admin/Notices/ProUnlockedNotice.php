<?php
namespace WalletUpPro\Core\Admin\Notices;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ProUnlockedNotice {

    const WAS_FREE_OPTION = 'wallet_up_was_free';
    const DISMISS_META    = 'walletup_pro_unlocked_dismissed';

    public static function init(): void {
        if ( ! function_exists( 'WalletUpPro\\wallet_up_fs' ) ) {
            return;
        }
        add_action( 'admin_init', [ __CLASS__, 'trackTier' ] );
        add_action( 'admin_init', [ __CLASS__, 'maybeDismiss' ] );
        add_action( 'admin_notices', [ __CLASS__, 'maybeRender' ] );
    }

    private static function isPremium(): bool {
        $fs = \WalletUpPro\wallet_up_fs();
        return is_object( $fs ) && method_exists( $fs, 'is_premium' ) && $fs->is_premium();
    }

    public static function trackTier(): void {
        if ( ! self::isPremium() && get_option( self::WAS_FREE_OPTION ) !== 'yes' ) {
            update_option( self::WAS_FREE_OPTION, 'yes', false );
        }
    }

    public static function maybeDismiss(): void {
        if ( empty( $_GET['walletup_dismiss_pro_unlocked'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'walletup_dismiss_pro_unlocked' ) ) {
            return;
        }
        update_user_meta( get_current_user_id(), self::DISMISS_META, 1 );
        wp_safe_redirect( remove_query_arg( [ 'walletup_dismiss_pro_unlocked', '_wpnonce' ] ) );
        exit;
    }

    public static function maybeRender(): void {
        if ( class_exists( '\\WalletUpPro\\Core\\Retention\\DeactivationOffer' ) && \WalletUpPro\Core\Retention\DeactivationOffer::isPaused() ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! self::isPremium() || get_option( self::WAS_FREE_OPTION ) !== 'yes' ) {
            return;
        }
        $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
        if ( strpos( $page, 'wallet-up' ) !== 0 ) {
            return;
        }
        if ( get_user_meta( get_current_user_id(), self::DISMISS_META, true ) ) {
            return;
        }

        $paymentsUrl = admin_url( 'admin.php?page=wallet-up-payments' );
        $dismissUrl  = wp_nonce_url( add_query_arg( 'walletup_dismiss_pro_unlocked', '1' ), 'walletup_dismiss_pro_unlocked' );
        ?>
        <div class="notice notice-info" style="border-left-color:#7c3aed;">
            <p style="font-size:14px;line-height:1.6;margin:8px 0 4px;">
                <strong><?php esc_html_e( 'Your Pro upgrade unlocked new ways to get paid.', 'walletup' ); ?></strong>
                <?php esc_html_e( 'Instant card payments and additional payment methods are now available. Turn on the ones you want in Payments — your existing setup is untouched.', 'walletup' ); ?>
            </p>
            <p style="margin:8px 0 12px;">
                <a href="<?php echo esc_url( $paymentsUrl ); ?>" class="button button-primary"><?php esc_html_e( 'See my new payment options', 'walletup' ); ?></a>
                <a href="<?php echo esc_url( $dismissUrl ); ?>" class="button button-link" style="margin-left:6px;"><?php esc_html_e( 'Dismiss', 'walletup' ); ?></a>
            </p>
        </div>
        <?php
    }
}
