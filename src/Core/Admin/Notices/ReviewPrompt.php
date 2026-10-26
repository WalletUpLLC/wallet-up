<?php
namespace WalletUpPro\Core\Admin\Notices;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ReviewPrompt {

    const MIN_DAYS_ACTIVE = 14;
    const REMIND_AFTER_DAYS = 30;
    const META_KEY = 'wallet_up_review_prompt_state';
    const REVIEW_URL = 'https://wordpress.org/support/plugin/wallet-up/reviews/?filter=5#new-post';

    public static function init(): void {
        if ( defined( 'WALLET_UP_IS_PRO_BUILD' ) && WALLET_UP_IS_PRO_BUILD ) {
            return;
        }
        add_action( 'admin_notices', [ __CLASS__, 'maybeRender' ] );
        add_action( 'wp_ajax_wallet_up_review_prompt_action', [ __CLASS__, 'handleAction' ] );
    }

    public static function maybeRender(): void {
        if ( class_exists( '\\WalletUpPro\\Core\\Retention\\DeactivationOffer' ) && \WalletUpPro\Core\Retention\DeactivationOffer::isPaused() ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! self::shouldShow() ) {
            return;
        }
        $days = self::daysActive();
        $nonce = wp_create_nonce( 'wallet_up_review_prompt' );
        ?>
        <div class="notice notice-info is-dismissible" data-walletup-review-prompt>
            <p style="font-size:14px;line-height:1.6;margin:8px 0 4px;">
                <strong><?php esc_html_e( 'Wallet Up', 'walletup' ); ?></strong>
                —
                <?php
                printf(
                    esc_html(
                        _n(
                            'thanks for using Wallet Up for the past %d day. If it has been useful, would you take a minute to leave a review on WordPress.org?',
                            'thanks for using Wallet Up for the past %d days. If it has been useful, would you take a minute to leave a review on WordPress.org?',
                            $days,
                            'walletup'
                        )
                    ),
                    (int) $days
                );
                ?>
            </p>
            <p style="margin:8px 0 12px;">
                <a href="<?php echo esc_url( self::REVIEW_URL ); ?>"
                   target="_blank" rel="noopener"
                   class="button button-primary"
                   data-action="leave"><?php esc_html_e( 'Leave a review', 'walletup' ); ?></a>
                <a href="https://walletup.app/reviews"
                   target="_blank" rel="noopener"
                   class="button"
                   data-action="leave"><?php esc_html_e( 'Share your experience', 'walletup' ); ?></a>
                <a href="#"
                   class="button"
                   data-action="later"><?php esc_html_e( 'Maybe later', 'walletup' ); ?></a>
                <a href="#"
                   class="button-link"
                   style="margin-left:8px;"
                   data-action="never"><?php esc_html_e( 'Already left one / no thanks', 'walletup' ); ?></a>
            </p>
            <script>
            (function () {
                var box = document.querySelector('[data-walletup-review-prompt]');
                if (!box) return;
                box.addEventListener('click', function (e) {
                    var t = e.target.closest('[data-action]');
                    if (!t) return;
                    var action = t.getAttribute('data-action');
                    if (action === 'leave' || action === 'later' || action === 'never') {
                        var fd = new FormData();
                        fd.append('action', 'wallet_up_review_prompt_action');
                        fd.append('_wpnonce', '<?php echo esc_js( $nonce ); ?>');
                        fd.append('choice', action);
                        fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: fd });
                        if (action !== 'leave') {
                            e.preventDefault();
                            box.style.display = 'none';
                        }
                    }
                });
            })();
            </script>
        </div>
        <?php
    }

    public static function handleAction(): void {
        if ( ! check_ajax_referer( 'wallet_up_review_prompt', '_wpnonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce' ], 403 );
        }
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }
        $choice = isset( $_POST['choice'] ) ? sanitize_key( wp_unslash( $_POST['choice'] ) ) : '';
        if ( ! in_array( $choice, [ 'leave', 'later', 'never' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Invalid choice' ], 400 );
        }
        $state = [
            'choice' => $choice,
            'ts'     => time(),
        ];
        update_user_meta( get_current_user_id(), self::META_KEY, $state );
        wp_send_json_success();
    }

    private static function shouldShow(): bool {
        if ( self::daysActive() < self::MIN_DAYS_ACTIVE ) {
            return false;
        }
        $state = get_user_meta( get_current_user_id(), self::META_KEY, true );
        if ( ! is_array( $state ) ) {
            return true;
        }
        $choice = $state['choice'] ?? '';
        if ( $choice === 'never' || $choice === 'leave' ) {
            return false;
        }
        if ( $choice === 'later' ) {
            $ts = (int) ( $state['ts'] ?? 0 );
            return ( time() - $ts ) >= ( self::REMIND_AFTER_DAYS * DAY_IN_SECONDS );
        }
        return true;
    }

    private static function daysActive(): int {
        $activated = (int) get_option( 'wallet_up_activated_at', 0 );
        if ( $activated <= 0 ) {
            return 0;
        }
        return (int) floor( ( time() - $activated ) / DAY_IN_SECONDS );
    }
}
