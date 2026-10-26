<?php
namespace WalletUpPro\Core\Admin\Notices;

if (!defined('ABSPATH')) {
    exit;
}

class IntegrationDetector
{
    const USER_META_PREFIX = 'wallet_up_integration_dismissed_';
    const SUPPRESS_DAYS = 30;
    const DISMISS_NONCE = 'wallet_up_dismiss_integration_notice';

    public static function init(): void
    {
        add_action('admin_notices', [__CLASS__, 'maybeRender']);
        add_action('wp_ajax_wallet_up_dismiss_integration_notice', [__CLASS__, 'handleDismiss']);
    }

    public static function maybeRender(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (function_exists('\WalletUpPro\wallet_up_is_preview_sandbox') && \WalletUpPro\wallet_up_is_preview_sandbox()) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $integration = self::matchScreen($screen, $page);
        if ($integration === null) {
            return;
        }

        if (self::isDismissed($integration['id'])) {
            return;
        }

        $state = (isset($integration['checkoutState']) && is_callable($integration['checkoutState']))
            ? (string) call_user_func($integration['checkoutState'])
            : null;
        $isConnected = $state !== null
            ? ($state === 'serving')
            : ((isset($integration['alreadyConnected']) && is_callable($integration['alreadyConnected']))
                ? (bool) call_user_func($integration['alreadyConnected'])
                : false);

        $isPremium = defined('WALLET_UP_IS_PRO_BUILD') && WALLET_UP_IS_PRO_BUILD;

        if ($isConnected) {
            $body     = $integration['enabledBody']   ?? __('Wallet Up is enabled at your WooCommerce checkout.', 'walletup');
            $ctaLabel = $integration['enabledCta']    ?? __('Disable', 'walletup');
            $hasProAction = !empty($integration['enabledAction']);
            $ctaUrl    = $hasProAction ? '#' : ($integration['freeUrl'] ?? '#');
            $ctaData   = $hasProAction
                ? sprintf(' data-wup-attach="%s" data-wup-attach-nonce="%s"', esc_attr($integration['enabledAction']), esc_attr(wp_create_nonce('wallet_up_admin_nonce')))
                : '';
            $ctaTarget = '';
            $noticeClass = 'notice-success';   // green stripe for "enabled" state
            $ctaClass    = 'button button-secondary';
        } elseif ($state === 'on_empty') {
            $body     = $integration['onEmptyBody'] ?? __('Wallet Up is on, but no payment methods are set to show at checkout yet.', 'walletup');
            $ctaLabel = $integration['onEmptyCta']  ?? __('Set up methods', 'walletup');
            $ctaUrl   = $integration['onEmptyUrl']  ?? admin_url('admin.php?page=wallet-up-seller');
            $hasProAction = false;
            $ctaData   = '';
            $ctaTarget = '';
            $noticeClass = 'notice-warning';
            $ctaClass    = 'button button-primary';
        } else {
            $body     = $isPremium ? $integration['proBody'] : $integration['freeBody'];
            $ctaLabel = $isPremium ? $integration['proCta']  : $integration['freeCta'];
            $hasProAction = $isPremium && !empty($integration['proAction']);
            $ctaUrl    = $hasProAction ? '#' : ($isPremium ? $integration['freeUrl'] : $integration['freeUrl']);
            $ctaData   = $hasProAction
                ? sprintf(' data-wup-attach="%s" data-wup-attach-nonce="%s"', esc_attr($integration['proAction']), esc_attr(wp_create_nonce('wallet_up_admin_nonce')))
                : '';
            $ctaTarget = ($isPremium || $hasProAction) ? '' : ' target="_blank" rel="noopener noreferrer"';
            $noticeClass = 'notice-info';
            $ctaClass    = 'button button-primary';
        }

        $nonce = wp_create_nonce(self::DISMISS_NONCE);
        $ajaxUrl = esc_url(admin_url('admin-ajax.php'));
        $id = esc_attr($integration['id']);
        ?>
        <div class="notice <?php echo esc_attr($noticeClass); ?> is-dismissible" data-wup-integration-notice="<?php echo $id; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">
            <p style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:8px 0;padding-right:40px;">
                <strong>Wallet Up</strong>
                <span style="color:#50575e;"><?php echo esc_html($body); ?></span>
                <a href="<?php echo esc_url($ctaUrl); ?>" class="<?php echo esc_attr($ctaClass); ?>" style="margin-left:auto;"<?php echo $ctaTarget; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo $ctaData; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                    <?php echo esc_html($ctaLabel); ?>
                </a>
            </p>
            <script>
            (function(){
                var n = document.querySelector('[data-wup-integration-notice="<?php echo esc_js($id); ?>"]');
                if (!n) return;
                var ajaxUrl = '<?php echo $ajaxUrl; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url above ?>';
                n.addEventListener('click', function(e){
                    var attach = e.target.closest('[data-wup-attach]');
                    if (attach) {
                        e.preventDefault();
                        var action = attach.getAttribute('data-wup-attach');
                        var atNonce = attach.getAttribute('data-wup-attach-nonce');
                        if (!action || !atNonce) return;
                        var orig = attach.innerHTML;
                        attach.innerHTML = '<?php echo esc_js(__('Connecting…', 'walletup')); ?>';
                        attach.style.pointerEvents = 'none';
                        var fda = new FormData();
                        fda.append('action', action);
                        fda.append('nonce', atNonce);
                        fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fda })
                            .then(function(r){ return r.json(); })
                            .then(function(json){
                                if (json && json.success && json.data && json.data.redirect) {
                                    window.location.href = json.data.redirect;
                                } else {
                                    attach.innerHTML = orig;
                                    attach.style.pointerEvents = '';
                                }
                            })
                            .catch(function(){ attach.innerHTML = orig; attach.style.pointerEvents = ''; });
                        return;
                    }
                    if (!e.target.classList.contains('notice-dismiss')) return;
                    var fd = new FormData();
                    fd.append('action', 'wallet_up_dismiss_integration_notice');
                    fd.append('integration', '<?php echo esc_js($id); ?>');
                    fd.append('nonce', '<?php echo esc_js($nonce); ?>');
                    fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd });
                });
            })();
            </script>
        </div>
        <?php
    }

    private static function wcCheckoutState(): string
    {
        $grouped = get_option('woocommerce_wallet_up_settings', []);
        $grouped = is_array($grouped) ? $grouped : [];
        $masterOn = ($grouped['enabled'] ?? 'no') === 'yes';

        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name <> %s",
            'woocommerce_wallet_up_%_settings',
            'woocommerce_wallet_up_settings'
        ));
        foreach ((array) $rows as $row) {
            $v = maybe_unserialize($row->option_value);
            if (is_array($v) && ($v['enabled'] ?? 'no') === 'yes') {
                return 'serving';
            }
        }

        if (!$masterOn) {
            return 'off';
        }
        foreach ($grouped as $k => $val) {
            if (strpos((string) $k, 'enable_') === 0 && $val === 'yes') {
                return 'serving';
            }
        }
        return 'on_empty';
    }

    public static function handleDismiss(): void
    {
        if (!check_ajax_referer(self::DISMISS_NONCE, 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid token'], 400);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
        }
        $integration = isset($_POST['integration']) ? sanitize_key(wp_unslash($_POST['integration'])) : '';
        if ($integration === '') {
            wp_send_json_error(['message' => 'Missing integration id'], 400);
        }
        if (!isset(self::integrations()[$integration])) {
            wp_send_json_error(['message' => 'Unknown integration'], 400);
        }
        update_user_meta(get_current_user_id(), self::USER_META_PREFIX . $integration, time());
        wp_send_json_success();
    }

    private static function matchScreen($screen, string $page): ?array
    {
        foreach (self::integrations() as $id => $def) {
            if (empty($def['active']) || !call_user_func($def['active'])) {
                continue;
            }
            if (!empty($def['pageMatch']) && call_user_func($def['pageMatch'], $page, $screen)) {
                $def['id'] = $id;
                return $def;
            }
        }
        return null;
    }

    private static function isDismissed(string $integrationId): bool
    {
        $dismissedAt = (int) get_user_meta(get_current_user_id(), self::USER_META_PREFIX . $integrationId, true);
        if (!$dismissedAt) {
            return false;
        }
        return (time() - $dismissedAt) < (self::SUPPRESS_DAYS * DAY_IN_SECONDS);
    }

    private static function integrations(): array
    {
        $upgrade = function ($slug) {
            unset($slug);
            return \WalletUpPro\wallet_up_upgrade_url();
        };
        return [
            'wpforms' => [
                'active'    => function () { return defined('WPFORMS_VERSION') || class_exists('WPForms\\WPForms') || class_exists('WPForms'); },
                'pageMatch' => function ($page, $screen) {
                    return $page !== '' && strpos($page, 'wpforms') === 0;
                },
                'freeBody' => __('We integrate with WPForms. Add a Wallet Up payment field to any form with Pro.', 'walletup'),
                'freeCta'  => __('See Pro', 'walletup'),
                'freeUrl'  => $upgrade('wpforms'),
                'proBody'  => __('Add a Wallet Up payment field to WPForms in one click.', 'walletup'),
                'proCta'   => __('Connect to WPForms', 'walletup'),
                'proAction'=> 'wallet_up_attach_wpforms',
            ],
            'woocommerce' => [
                'active'    => function () { return class_exists('WooCommerce'); },
                'pageMatch' => function ($page, $screen) {
                    if ($page !== '' && (strpos($page, 'wc-') === 0 || $page === 'woocommerce')) {
                        return true;
                    }
                    if ($screen && isset($screen->id)) {
                        if (strpos((string) $screen->id, 'woocommerce_page_') === 0) return true;
                        if ($screen->id === 'toplevel_page_woocommerce') return true;
                    }
                    return false;
                },
                'alreadyConnected' => function () {
                    $grouped = get_option('woocommerce_wallet_up_settings', []);
                    if (is_array($grouped) && ($grouped['enabled'] ?? 'no') === 'yes') {
                        return true;
                    }
                    global $wpdb;
                    $sql = "SELECT option_name, option_value FROM {$wpdb->options}
                            WHERE option_name LIKE %s";
                    $rows = $wpdb->get_results($wpdb->prepare($sql, 'woocommerce_wallet_up_%_settings'));
                    foreach ((array) $rows as $row) {
                        $v = maybe_unserialize($row->option_value);
                        if (is_array($v) && ($v['enabled'] ?? 'no') === 'yes') {
                            return true;
                        }
                    }
                    return false;
                },
                'checkoutState' => function () { return self::wcCheckoutState(); },
                'onEmptyBody'   => __('Wallet Up is on, but no payment methods are set to show at your WooCommerce checkout yet.', 'walletup'),
                'onEmptyCta'    => __('Set up methods', 'walletup'),
                'onEmptyUrl'    => admin_url('admin.php?page=wallet-up-seller'),
                'freeBody' => __('We integrate with WooCommerce checkout. Make Wallet Up a payment option in your store with Pro.', 'walletup'),
                'freeCta'  => __('See Pro', 'walletup'),
                'freeUrl'  => $upgrade('woocommerce'),
                'proBody'  => __('Enable Wallet Up at your WooCommerce checkout — one click.', 'walletup'),
                'proCta'   => __('Enable in checkout', 'walletup'),
                'proAction'=> 'wallet_up_enable_wc_gateway',
                'enabledBody'   => __('Wallet Up is enabled at your WooCommerce checkout.', 'walletup'),
                'enabledCta'    => __('Disable', 'walletup'),
                'enabledAction' => 'wallet_up_disable_wc_gateway',
            ],
            'elementor' => [
                'active'    => function () { return did_action('elementor/loaded') || class_exists('\\Elementor\\Plugin'); },
                'pageMatch' => function ($page, $screen) {
                    if ($page !== '' && strpos($page, 'elementor') === 0) return true;
                    if (isset($_GET['action']) && wp_unslash($_GET['action']) === 'elementor') return true; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                    return false;
                },
                'freeBody' => __('Building in Elementor? You can drop a Wallet Up block on any page from the block library.', 'walletup'),
                'freeCta'  => __('See blocks', 'walletup'),
                'freeUrl'  => admin_url('admin.php?page=wallet-up-shortcodes'),
                'proBody'  => __('Drop a Wallet Up block on any Elementor page from the block library.', 'walletup'),
                'proCta'   => __('See blocks', 'walletup'),
                'proAction'=> '', // No one-click action; Elementor uses native blocks.
            ],
            'gravityforms' => [
                'active'    => function () { return class_exists('GFForms') || class_exists('GFCommon'); },
                'pageMatch' => function ($page, $screen) {
                    return $page !== '' && (strpos($page, 'gf_') === 0 || $page === 'gf_edit_forms' || $page === 'gravityforms_settings');
                },
                'freeBody' => __('Using Gravity Forms? Wallet Up renders as a block or shortcode you can place beside any form.', 'walletup'),
                'freeCta'  => __('See how', 'walletup'),
                'freeUrl'  => admin_url('admin.php?page=wallet-up-shortcodes'),
                'proBody'  => __('Wallet Up works alongside Gravity Forms — block or shortcode beside any form.', 'walletup'),
                'proCta'   => __('See how', 'walletup'),
                'proAction'=> '',
            ],
            'fluentforms' => [
                'active'    => function () { return defined('FLUENTFORM') || class_exists('FluentForm\\Framework\\Foundation\\Application'); },
                'pageMatch' => function ($page, $screen) {
                    return $page !== '' && strpos($page, 'fluent_forms') === 0;
                },
                'freeBody' => __('Using Fluent Forms? Wallet Up renders as a block or shortcode you can place beside any form.', 'walletup'),
                'freeCta'  => __('See how', 'walletup'),
                'freeUrl'  => admin_url('admin.php?page=wallet-up-shortcodes'),
                'proBody'  => __('Wallet Up works alongside Fluent Forms — block or shortcode beside any form.', 'walletup'),
                'proCta'   => __('See how', 'walletup'),
                'proAction'=> '',
            ],
            'forminator' => [
                'active'    => function () { return class_exists('Forminator_API') || defined('FORMINATOR_VERSION'); },
                'pageMatch' => function ($page, $screen) {
                    return $page !== '' && strpos($page, 'forminator-') === 0;
                },
                'freeBody' => __('Using Forminator? Wallet Up renders as a block or shortcode you can place beside any form.', 'walletup'),
                'freeCta'  => __('See how', 'walletup'),
                'freeUrl'  => admin_url('admin.php?page=wallet-up-shortcodes'),
                'proBody'  => __('Wallet Up works alongside Forminator — block or shortcode beside any form.', 'walletup'),
                'proCta'   => __('See how', 'walletup'),
                'proAction'=> '',
            ],
        ];
    }
}
