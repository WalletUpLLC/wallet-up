<?php
namespace WalletUpPro\Core\Admin\Notices;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ProInstallModal {

    const DISMISSED_META    = 'walletup_pro_install_prompts_dismissed';
    const REQUIRED_PROMPTS  = 2;
    const DISMISS_ARG       = 'walletup_pro_install_later';

    public static function init(): void {
        if ( ! function_exists( 'WalletUpPro\\wallet_up_fs' ) ) {
            return;
        }
        add_action( 'admin_init', [ __CLASS__, 'maybeRecordDismissal' ] );
        add_action( 'admin_footer', [ __CLASS__, 'maybeRender' ] );
    }

    /**
     * Owed a prompt: the licence is paid, current and un-expired, the premium code is
     * not installed, and the merchant has not yet dismissed this the required number
     * of times. PremiumActivationNotice defers to this so the two never both fire.
     */
    public static function isOwed(): bool {
        if ( ! current_user_can( 'manage_options' ) ) {
            return false;
        }
        if ( ! PremiumActivationNotice::paidButNotInstalled() ) {
            return false;
        }

        return self::dismissals() < self::REQUIRED_PROMPTS;
    }

    private static function dismissals(): int {
        return (int) get_user_meta( get_current_user_id(), self::DISMISSED_META, true );
    }

    private static function onOwnScreen(): bool {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which screen we are on.
        $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

        return strpos( $page, 'wallet-up' ) === 0 && strpos( $page, 'account' ) === false;
    }

    public static function maybeRecordDismissal(): void {
        if ( empty( $_GET[ self::DISMISS_ARG ] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), self::DISMISS_ARG ) ) {
            return;
        }

        update_user_meta( get_current_user_id(), self::DISMISSED_META, self::dismissals() + 1 );
        wp_safe_redirect( remove_query_arg( [ self::DISMISS_ARG, '_wpnonce' ] ) );
        exit;
    }

    public static function maybeRender(): void {
        if ( wp_doing_ajax() || ! self::onOwnScreen() || ! self::isOwed() ) {
            return;
        }

        $fs = \WalletUpPro\wallet_up_fs();
        $downloadUrl = method_exists( $fs, '_get_latest_download_local_url' )
            ? $fs->_get_latest_download_local_url()
            : ( method_exists( $fs, 'get_account_url' ) ? $fs->get_account_url() : '' );
        $accountUrl = method_exists( $fs, 'get_account_url' ) ? $fs->get_account_url() : '';
        $laterUrl   = wp_nonce_url( add_query_arg( self::DISMISS_ARG, '1' ), self::DISMISS_ARG );
        $remaining  = self::REQUIRED_PROMPTS - self::dismissals() - 1;
        ?>
        <div class="wup-pro-modal" role="dialog" aria-modal="true"
             aria-labelledby="wup-pro-modal-title" aria-describedby="wup-pro-modal-body">
            <div class="wup-pro-modal__card">
                <span class="wup-pro-modal__mark" aria-hidden="true">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 3v12"/><path d="m7 12 5 5 5-5"/><path d="M4 20h16"/>
                    </svg>
                </span>

                <h2 id="wup-pro-modal-title"><?php esc_html_e( 'Your Pro licence is active. One step left.', 'walletup' ); ?></h2>

                <p id="wup-pro-modal-body">
                    <?php /* translators: %s: Pro product name */ echo esc_html( sprintf( __( 'This site is still running the free version. Download %s and install it over this one to unlock your Pro features. Your licence, settings and payment methods all carry across.', 'walletup' ), WALLET_UP_PRO_PRODUCT_NAME ) ); ?>
                </p>

                <ol class="wup-pro-modal__steps">
                    <li><?php esc_html_e( 'Download the Pro zip', 'walletup' ); ?></li>
                    <li><?php esc_html_e( 'Go to Plugins, Add New, Upload Plugin', 'walletup' ); ?></li>
                    <li><?php esc_html_e( 'Choose the zip and replace the current version', 'walletup' ); ?></li>
                </ol>

                <div class="wup-pro-modal__actions">
                    <?php if ( $downloadUrl ) : ?>
                        <a class="wup-pro-modal__cta" href="<?php echo esc_url( $downloadUrl ); ?>" autofocus>
                            <?php /* translators: %s: Pro product name */ echo esc_html( sprintf( __( 'Download %s', 'walletup' ), WALLET_UP_PRO_PRODUCT_NAME ) ); ?>
                        </a>
                    <?php endif; ?>
                    <?php if ( $accountUrl ) : ?>
                        <a class="wup-pro-modal__link" href="<?php echo esc_url( $accountUrl ); ?>">
                            <?php esc_html_e( 'Open my account', 'walletup' ); ?>
                        </a>
                    <?php endif; ?>
                    <a class="wup-pro-modal__later" id="wup-pro-modal-later" href="<?php echo esc_url( $laterUrl ); ?>">
                        <?php esc_html_e( 'Not now', 'walletup' ); ?>
                    </a>
                </div>

                <?php if ( $remaining > 0 ) : ?>
                    <p class="wup-pro-modal__note"><?php esc_html_e( 'We will show this once more so it is not missed.', 'walletup' ); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <style>
            .wup-pro-modal{position:fixed;inset:0;z-index:160000;display:flex;align-items:center;
                justify-content:center;padding:24px;background:rgba(15,23,42,.55)}
            .wup-pro-modal__card{background:#fff;border-radius:14px;max-width:520px;width:100%;
                padding:32px;box-shadow:0 24px 60px rgba(15,23,42,.28);text-align:left}
            .wup-pro-modal__mark{display:inline-flex;align-items:center;justify-content:center;
                width:46px;height:46px;border-radius:12px;background:#f1edfd;color:#674FBF;margin-bottom:18px}
            .wup-pro-modal__card h2{margin:0 0 10px;font-size:20px;line-height:1.35;color:#0f172a}
            .wup-pro-modal__card p{margin:0 0 16px;font-size:14px;line-height:1.65;color:#475569}
            .wup-pro-modal__steps{margin:0 0 22px 18px;padding:0;font-size:13px;line-height:1.9;color:#475569}
            .wup-pro-modal__actions{display:flex;align-items:center;flex-wrap:wrap;gap:14px}
            .wup-pro-modal__cta{background:#674FBF;color:#fff;border-radius:8px;padding:11px 20px;
                font-size:14px;font-weight:600;text-decoration:none;display:inline-block}
            .wup-pro-modal__cta:hover,.wup-pro-modal__cta:focus{background:#563da8;color:#fff}
            .wup-pro-modal__link{font-size:13px;color:#674FBF;text-decoration:none}
            .wup-pro-modal__later{font-size:13px;color:#64748b;text-decoration:underline;margin-left:auto}
            .wup-pro-modal__note{margin:18px 0 0;font-size:12px;color:#94a3b8}
            .wup-pro-modal :focus-visible{outline:2px solid #674FBF;outline-offset:2px}
        </style>
        <script>
            ( function () {
                var modal = document.querySelector( '.wup-pro-modal' );
                var later = document.getElementById( 'wup-pro-modal-later' );
                if ( ! modal || ! later ) { return; }
                modal.addEventListener( 'click', function ( e ) {
                    if ( e.target === modal ) { window.location.href = later.href; }
                } );
                document.addEventListener( 'keydown', function ( e ) {
                    if ( 'Escape' === e.key ) { window.location.href = later.href; }
                } );
            }() );
        </script>
        <?php
    }
}
