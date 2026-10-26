<?php

namespace WalletUpPro\Core\Admin\Integrations;

if (!defined('ABSPATH')) {
    exit;
}

class OnboardingPromoter
{
    const GATEWAY_ID = 'wallet_up';

    public static function init()
    {
        $self = new self();

        add_filter('woocommerce_admin_payment_gateway_suggestion_specs', [$self, 'filterSuggestionSpecs'], 20);
        add_filter('experimental_woocommerce_admin_payment_gateway_suggestions', [$self, 'filterSuggestionSpecs'], 20);

        add_action('admin_enqueue_scripts', [$self, 'renderPaymentsScreenPromo']);

        add_filter('woocommerce_admin_onboarding_tasks', [$self, 'markPaymentsCompleteWhenEnabled'], 99);
    }

    public function markPaymentsCompleteWhenEnabled($json)
    {
        if (!is_array($json) || !self::isEnabled()) {
            return $json;
        }
        foreach ($json as &$list) {
            if (!empty($list['tasks']) && is_array($list['tasks'])) {
                foreach ($list['tasks'] as &$task) {
                    if (($task['id'] ?? '') === 'payments') {
                        $task['isComplete'] = true;
                        if (array_key_exists('inProgressLabel', $task)) {
                            $task['inProgressLabel'] = '';
                        }
                    }
                }
                unset($task);
            }
        }
        unset($list);
        return $json;
    }

    public static function isEnabled()
    {
        $settings = get_option('woocommerce_wallet_up_settings');
        if (is_array($settings) && isset($settings['enabled']) && $settings['enabled'] === 'yes') {
            return true;
        }
        global $wpdb;
        $rows = $wpdb->get_col("SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'woocommerce_wallet_up_%_settings'");
        foreach ((array) $rows as $raw) {
            $v = maybe_unserialize($raw);
            if (is_array($v) && isset($v['enabled']) && $v['enabled'] === 'yes') {
                return true;
            }
        }
        return false;
    }

    private static function logoUrl()
    {
        return defined('WALLET_UP_PRO_URL') ? WALLET_UP_PRO_URL . 'assets/images/walup-logo.png' : '';
    }

    private static function setupUrl()
    {
        return admin_url('admin.php?page=wallet-up#payments');
    }

    public function filterSuggestionSpecs($specs)
    {
        if (!is_array($specs) || self::isEnabled()) {
            return $specs;
        }
        foreach ($specs as $s) {
            $id = is_object($s) ? ($s->id ?? '') : ($s['id'] ?? '');
            if ($id === self::GATEWAY_ID) {
                return $specs;
            }
        }

        $spec = (object) [
            'id'                      => self::GATEWAY_ID,
            'title'                   => __('WalletUp', 'walletup'),
            'content'                 => __('Already installed on your store. Accept cards, PayPal, Cash App, Venmo, QR codes and more — enable it in one click.', 'walletup'),
            'image'                   => self::logoUrl(),
            'image_72x72'             => self::logoUrl(),
            'square_image'            => self::logoUrl(),
            'plugins'                 => array(), // already installed — no wp.org install step
            'is_visible'              => array(), // globally visible
            'is_built_in'             => false,
            'recommendation_priority' => 1,       // ascending → shown first
        ];

        array_unshift($specs, $spec);
        return $specs;
    }

    public function renderPaymentsScreenPromo(): void
    {
        if (self::isEnabled()) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || strpos((string) $screen->id, 'wc-settings') === false) {
            return;
        }
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
        if ($tab !== 'checkout') {
            return;
        }

        $handle = 'wallet-up-onboarding-promo';
        wp_register_style($handle, false, [], defined('WALLET_UP_PRO_VERSION') ? WALLET_UP_PRO_VERSION : false);
        wp_enqueue_style($handle);
        $script_handle = (wp_script_is('jquery', 'enqueued') || wp_script_is('jquery', 'registered')) ? 'jquery' : 'wp-dom-ready';
        if ($script_handle === 'wp-dom-ready') {
            wp_enqueue_script($script_handle);
        }

        $css = '
            .wup-promo-banner {
                display:flex; align-items:center; gap:12px; flex-wrap:wrap;
                margin:16px 0; padding:14px 18px;
                background:rgba(103,79,191,0.06);
                border:1px solid rgba(103,79,191,0.22);
                border-left:4px solid #674FBF; border-radius:8px;
                color:#2c2540;
            }
            .wup-promo-banner .wup-promo-strong { font-weight:600; color:#674FBF; }
            .wup-promo-banner .wup-promo-msg { flex:1 1 240px; }
            .wup-promo-banner .button-primary { background:#674FBF; border-color:#674FBF; box-shadow:none; }
            .wup-promo-banner .button-primary:hover { background:#5a45a8; border-color:#5a45a8; }
            .wup-promo-banner .button-primary[disabled] { opacity:0.7; }
        ';
        wp_add_inline_style($handle, $css);

        $data = [
            'title'    => __('Recommended: WalletUp', 'walletup'),
            'message'  => __('Already installed on your store. Accept cards, PayPal, Cash App, Venmo, QR codes and crypto — turn it on in one click.', 'walletup'),
            'cta'      => __('Enable WalletUp', 'walletup'),
            'enabling' => __('Enabling…', 'walletup'),
            'ajaxurl'  => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('wallet_up_admin_nonce'),
        ];
        $js = 'window.walletUpPromo = ' . wp_json_encode($data) . ';' . "\n" . <<<'JS'
        (function(){
          var d = window.walletUpPromo || {};
          function mount(){
            if (document.querySelector('.wup-promo-banner')) return;
            var anchor = document.querySelector('.woocommerce-list, .settings-payments-main, #wc_payments_settings, .woocommerce-layout__main, form#mainform');
            if (!anchor || !anchor.parentNode) return;
            var wrap = document.createElement('div'); wrap.className = 'wup-promo-banner';
            var t = document.createElement('span'); t.className = 'wup-promo-strong'; t.textContent = d.title || '';
            var m = document.createElement('span'); m.className = 'wup-promo-msg'; m.textContent = d.message || '';
            var btn = document.createElement('button'); btn.type = 'button';
            btn.className = 'button button-primary'; btn.style.marginLeft = 'auto'; btn.textContent = d.cta || '';
            btn.addEventListener('click', function(){
              btn.disabled = true; btn.textContent = d.enabling || '';
              var body = 'action=wallet_up_enable_wc_gateway&nonce=' + encodeURIComponent(d.nonce || '');
              fetch(d.ajaxurl, { method:'POST', credentials:'same-origin',
                headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body })
                .then(function(r){ return r.json().catch(function(){ return {}; }); })
                .then(function(){ window.location.reload(); })
                .catch(function(){ btn.disabled = false; btn.textContent = d.cta || ''; });
            });
            wrap.appendChild(t); wrap.appendChild(m); wrap.appendChild(btn);
            anchor.parentNode.insertBefore(wrap, anchor);
          }
          var elapsed = 0;
          var timer = setInterval(function(){
            mount(); elapsed += 400;
            if (document.querySelector('.wup-promo-banner') || elapsed >= 15000) clearInterval(timer);
          }, 400);
          mount();
        })();
        JS;
        wp_add_inline_script($script_handle, $js);
    }
}
