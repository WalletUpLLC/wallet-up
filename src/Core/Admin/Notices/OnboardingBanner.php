<?php
namespace WalletUpPro\Core\Admin\Notices;

use WalletUpPro\Core\Admin\OnboardingHandler;

if (!defined('ABSPATH')) {
    exit;
}

class OnboardingBanner
{
    const USER_META_DISMISSED_AT = 'wallet_up_onboarding_banner_dismissed_at';
    const SUPPRESS_DAYS = 30;

    public static function init(): void
    {
        add_action('admin_notices', [__CLASS__, 'render']);
        add_action('wp_ajax_wallet_up_dismiss_onboarding_banner', [__CLASS__, 'handleDismiss']);
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page check
        if (isset($_GET['page']) && $_GET['page'] === 'wallet-up-onboarding') {
            return;
        }

        $finding = self::setupFinding();
        $needsAttention = $finding !== null && ($finding['state'] ?? '') !== 'ready';
        $onboardingPending = !get_option(OnboardingHandler::OPTION_COMPLETED) && OnboardingHandler::getPending() !== '';

        if (!$needsAttention && !$onboardingPending) {
            return;
        }

        $dismissedAt = (int) get_user_meta(get_current_user_id(), self::USER_META_DISMISSED_AT, true);
        if ($dismissedAt && (time() - $dismissedAt) < (self::SUPPRESS_DAYS * DAY_IN_SECONDS)) {
            return;
        }

        if ($needsAttention) {
            $pageSlug = ($finding['page'] ?? '') !== '' ? (string) $finding['page'] : 'wallet-up-onboarding';
            $heading  = esc_html__('Finish setting up Wallet Up', 'walletup');
            $body     = esc_html((string) ($finding['text'] ?? ''));
            $cta      = esc_html__('Fix setup', 'walletup');
            $url      = esc_url(OnboardingHandler::pageUrl($pageSlug));
        } else {
            $heading  = esc_html__('Finish setting up Wallet Up', 'walletup');
            $body     = esc_html__('Get your first payment page ready in about 2 minutes.', 'walletup');
            $cta      = esc_html__('Finish setup', 'walletup');
            $url      = esc_url(OnboardingHandler::pageUrl('wallet-up-onboarding'));
        }

        $nonce   = wp_create_nonce('wallet_up_dismiss_onboarding_banner');
        $ajaxUrl = esc_url(admin_url('admin-ajax.php'));
        ?>
        <div class="notice notice-info is-dismissible" id="wallet-up-onboarding-banner">
            <p style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:8px 0;">
                <strong><?php echo $heading; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped on assignment ?></strong>
                <span style="color:#50575e;"><?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped on assignment ?></span>
                <a href="<?php echo $url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url above ?>" class="button button-primary" style="margin-left:auto;">
                    <?php echo $cta; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped on assignment ?>
                </a>
            </p>
            <script>
            (function(){
                var n = document.getElementById('wallet-up-onboarding-banner');
                if (!n) return;
                n.addEventListener('click', function(e){
                    if (!e.target.classList.contains('notice-dismiss')) return;
                    var fd = new FormData();
                    fd.append('action', 'wallet_up_dismiss_onboarding_banner');
                    fd.append('nonce', '<?php echo esc_js($nonce); ?>');
                    fetch('<?php echo $ajaxUrl; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url above ?>', { method: 'POST', credentials: 'same-origin', body: fd });
                });
            })();
            </script>
        </div>
        <?php
    }

    private static function setupFinding(): ?array
    {
        if (!class_exists('\\WalletUpPro\\Core\\Retention\\SetupCheck')) {
            return null;
        }
        return \WalletUpPro\Core\Retention\SetupCheck::run();
    }

    public static function handleDismiss(): void
    {
        if (!check_ajax_referer('wallet_up_dismiss_onboarding_banner', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid token'], 400);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
        }
        update_user_meta(get_current_user_id(), self::USER_META_DISMISSED_AT, time());
        wp_send_json_success();
    }
}
