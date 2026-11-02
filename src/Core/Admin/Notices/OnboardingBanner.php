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
        // 6.4.1: this reminder appears on the WordPress Dashboard, the Plugins screen
        // and Wallet Up's own screens only, not on every admin page.
        global $pagenow;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page check
        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
        if (!in_array($pagenow, ['index.php', 'plugins.php'], true) && strpos($page, 'wallet-up') !== 0) {
            return;
        }

        // 6.6.0: once a visitor has tapped a code or link the reminder has done its job.
        if (class_exists('\\WalletUpPro\\Core\\Retention\\FreeCounter') && \WalletUpPro\Core\Retention\FreeCounter::hasClicks()) {
            return;
        }

        $finding = self::setupFinding();
        $needsAttention = $finding !== null && ($finding['state'] ?? '') !== 'ready';
        $onboardingPending = !get_option(OnboardingHandler::OPTION_COMPLETED) && OnboardingHandler::getPending() !== '';
        $neverViewed = null;
        if (!$needsAttention && !$onboardingPending && class_exists('\\WalletUpPro\\Core\\Retention\\FreeCounter')) {
            $pageId = (int) get_option('wallet_up_payment_page_id', 0);
            $activated = (int) get_option('wallet_up_activated_at', 0);
            $all = \WalletUpPro\Core\Retention\FreeCounter::allTime();
            if ($pageId > 0 && get_post_status($pageId) === 'publish' && (int) $all['impressions'] === 0 && $activated > 0 && time() - $activated > DAY_IN_SECONDS) {
                $neverViewed = (string) get_permalink($pageId);
            }
        }

        if (!$needsAttention && !$onboardingPending && $neverViewed === null) {
            return;
        }

        $dismissedAt = (int) get_user_meta(get_current_user_id(), self::USER_META_DISMISSED_AT, true);
        if ($dismissedAt && (time() - $dismissedAt) < (self::SUPPRESS_DAYS * DAY_IN_SECONDS)) {
            return;
        }

        if ($neverViewed !== null) {
            $heading  = esc_html__('Your payment page is live, but nobody has opened it yet.', 'walletup');
            $body     = esc_html__('Send the link in your next invoice or message, or add the Payment Button block to a page people already visit.', 'walletup');
            $cta      = esc_html__('Copy page link', 'walletup');
            $url      = esc_url($neverViewed);
        } elseif ($needsAttention) {
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
                <?php if ($neverViewed !== null) : // 6.7.0: copies only; opening the page would count the owner as a visitor ?>
                <button type="button" class="button button-primary" style="margin-left:auto;" data-wup-copy="<?php echo esc_attr($neverViewed); ?>"><?php echo $cta; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped on assignment ?></button>
                <?php else : ?>
                <a href="<?php echo $url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url above ?>" class="button button-primary" style="margin-left:auto;">
                    <?php echo $cta; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped on assignment ?>
                </a>
                <?php endif; ?>
            </p>
            <script>
            (function(){
                var n = document.getElementById('wallet-up-onboarding-banner');
                if (!n) return;
                n.addEventListener('click', function(e){
                    var cp = e.target.closest('[data-wup-copy]');
                    if (cp && navigator.clipboard) { navigator.clipboard.writeText(cp.getAttribute('data-wup-copy')).then(function(){ cp.textContent = '<?php echo esc_js(__('Copied', 'walletup')); ?>'; }); }
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
