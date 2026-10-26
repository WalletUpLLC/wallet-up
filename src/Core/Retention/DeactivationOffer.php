<?php
declare(strict_types=1);

namespace WalletUpPro\Core\Retention;

if (!defined('ABSPATH')) {
    exit;
}

final class DeactivationOffer
{
    public const AJAX_CHECK  = 'wallet_up_retention_check';
    public const AJAX_PAUSE  = 'wallet_up_retention_pause';
    public const AJAX_RESUME = 'wallet_up_retention_resume';
    public const NONCE       = 'wallet_up_retention';
    public const FILTER_PANELS = 'wallet_up_retention_panels';
    public const FILTER_OFFER  = 'wallet_up_retention_offer';
    public const FILTER_PAUSED = 'wallet_up_is_paused';

    public const OPTION_PAUSED_UNTIL = 'wallet_up_paused_until';

    private const CAP = 'activate_plugins';

    private const SCRIPT_HANDLE = 'wallet-up-retention';

    private const PAGE_PAYMENTS    = 'wallet-up-payments';
    private const PAGE_DONATIONS   = 'wallet-up-donations';
    private const PAGE_MEMBERSHIPS = 'wallet-up-memberships';
    private const PAGE_ONBOARDING  = 'wallet-up-onboarding';

    private const UI_BRAND  = '#6c5ce7'; // zi2-dry: ignore
    private const UI_INK    = '#1e1e2d'; // zi2-dry: ignore
    private const UI_TEXT   = '#4a4560'; // zi2-dry: ignore
    private const UI_BORDER = '#e6e2f5'; // zi2-dry: ignore
    private const UI_BG     = '#faf9ff'; // zi2-dry: ignore

    private const REASON_NO_LONGER_NEEDED    = 1;
    private const REASON_FOUND_BETTER        = 2;
    private const REASON_SHORT_PERIOD        = 3;
    private const REASON_CANT_PAY            = 6;
    private const REASON_BROKE_SITE          = 4;
    private const REASON_STOPPED_WORKING     = 5;
    private const REASON_OTHER               = 7;
    private const REASON_DIDNT_WORK          = 8;
    private const REASON_COULDNT_MAKE_IT     = 10;
    private const REASON_NEED_FEATURE        = 11;
    private const REASON_NOT_WORKING         = 12;
    private const REASON_NOT_LOOKING_FOR     = 13;
    private const REASON_NOT_AS_EXPECTED     = 14;
    private const REASON_TEMPORARY           = 15;

    public static function register(): void
    {
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('wp_ajax_' . self::AJAX_CHECK, [self::class, 'handleCheck']);
        add_action('wp_ajax_' . self::AJAX_PAUSE, [self::class, 'handlePause']);
        add_action('admin_post_' . self::AJAX_RESUME, [self::class, 'handleResume']);
        add_action('admin_notices', [self::class, 'pausedNotice']);
        self::hookFreemius();
    }

    public static function isPaused(): bool
    {
        $until  = (int) get_option(self::OPTION_PAUSED_UNTIL, 0);
        $paused = $until > time();
        return (bool) apply_filters(self::FILTER_PAUSED, $paused);
    }

    private static function pricingPage(): string
    {
        return function_exists('WalletUpPro\\wallet_up_page_slug') ? \WalletUpPro\wallet_up_page_slug('pricing') : '';
    }

    private static function pauseDays(): int
    {
        return max(1, (int) apply_filters('wallet_up_pause_days', 30));
    }

    private static function isProBuild(): bool
    {
        return (bool) apply_filters('wallet_up_is_pro_build', defined('WALLET_UP_IS_PRO_BUILD') && WALLET_UP_IS_PRO_BUILD);
    }

    public static function handlePause(): void
    {
        if (!check_ajax_referer(self::NONCE, '_wpnonce', false) || !current_user_can(self::CAP)) {
            wp_send_json_error(['message' => __('That request could not be verified.', 'walletup')], 403);
        }
        $until = time() + (self::pauseDays() * DAY_IN_SECONDS);
        update_option(self::OPTION_PAUSED_UNTIL, $until, false);
        wp_send_json_success(['until' => date_i18n((string) get_option('date_format', 'Y-m-d'), $until)]);
    }

    public static function handleResume(): void
    {
        if (!current_user_can(self::CAP) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), self::AJAX_RESUME)) {
            wp_die(esc_html__('That request could not be verified.', 'walletup'), '', ['response' => 403]);
        }
        delete_option(self::OPTION_PAUSED_UNTIL);
        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    private static function resumeUrl(): string
    {
        return wp_nonce_url(admin_url('admin-post.php?action=' . self::AJAX_RESUME), self::AJAX_RESUME);
    }

    public static function pausedNotice(): void
    {
        if (!self::isPaused() || !current_user_can(self::CAP)) {
            return;
        }
        $until = date_i18n((string) get_option('date_format', 'Y-m-d'), (int) get_option(self::OPTION_PAUSED_UNTIL, 0));
        $message = sprintf(
            /* translators: %s: the date the pause ends. */
            __('Wallet Up is paused until %s. It keeps processing payments and stays quiet until then.', 'walletup'),
            $until
        );
        echo '<div class="notice notice-info" style="border-left-color:' . self::UI_BRAND . '">'
            . '<p>' . esc_html($message)
            . ' <a href="' . esc_url(self::resumeUrl()) . '" class="button button-small" style="margin-left:6px">' . esc_html__('Resume now', 'walletup') . '</a></p>'
            . '</div>';
    }

    private static function hookFreemius(): void
    {
        if (!function_exists('\\WalletUpPro\\wallet_up_fs')) {
            return;
        }
        try {
            $fs = \WalletUpPro\wallet_up_fs();
        } catch (\Throwable $e) {
            return;
        }
        if (is_object($fs) && method_exists($fs, 'add_filter')) {
            $fs->add_filter('uninstall_reasons', [self::class, 'decorate']);
        }
    }

    public static function decorate(mixed $reasons): mixed
    {
        if (!is_array($reasons)) {
            return $reasons;
        }
        $panels = self::panels();
        foreach ($reasons as $userType => $list) {
            if (!is_array($list)) {
                continue;
            }
            foreach ($list as $index => $reason) {
                $id = isset($reason['id']) ? (int) $reason['id'] : 0;
                if ($id === 0 || !isset($panels[$id])) {
                    continue;
                }
                $existing = (string) ($reason['internal_message'] ?? '');
                if (str_contains($existing, 'data-wu-panel')) {
                    continue;
                }
                $reasons[$userType][$index]['internal_message'] = $existing . self::render($panels[$id]);
            }
        }
        return $reasons;
    }

    public static function panels(): array
    {
        $keepsEverything = [
            'title' => __('Nothing is deleted', 'walletup'),
            'body'  => __('Your payment methods, forms and history stay exactly as they are. Turn it back on whenever you like and everything is where you left it.', 'walletup'),
        ];

        $findOutWhy = [
            'title' => __('Let us find out why', 'walletup'),
            'body'  => __('This is usually one setting, and we can tell you which one in a few seconds.', 'walletup'),
            'check' => true,
        ];

        $panels = [
            self::REASON_SHORT_PERIOD     => $keepsEverything,
            self::REASON_BROKE_SITE       => $findOutWhy,
            self::REASON_STOPPED_WORKING  => $findOutWhy,
            self::REASON_DIDNT_WORK       => $findOutWhy,
            self::REASON_NOT_WORKING      => $findOutWhy,
            self::REASON_COULDNT_MAKE_IT  => [
                'title' => __('It is usually one unfinished step', 'walletup'),
                'body'  => __('Nine times in ten the thing that makes it look broken is a setting that was never switched on. We can tell you which one.', 'walletup'),
                'check' => true,
            ],
            self::REASON_NOT_LOOKING_FOR  => [
                'title' => __('It may already be here', 'walletup'),
                'body'  => __('Tell us what you were after in the box above. It reaches a person, and it is the main thing that decides what we build next.', 'walletup'),
                'page'  => self::PAGE_ONBOARDING,
                'action' => __('See what it does', 'walletup'),
            ],
            self::REASON_NOT_AS_EXPECTED  => [
                'title' => __('Tell us what you expected', 'walletup'),
                'body'  => __('What you type above is read here, and a mismatch between what you expected and what you got is the most useful thing you can send us.', 'walletup'),
                'page'  => self::PAGE_ONBOARDING,
                'action' => __('Walk through the setup', 'walletup'),
            ],
            self::REASON_FOUND_BETTER     => [
                'title' => __('Which one, and what did it do better?', 'walletup'),
                'body'  => __('Tell us above and it reaches the people who decide what gets built. What the other one did better is the most useful thing you can send us.', 'walletup'),
            ],
            self::REASON_NEED_FEATURE     => [
                'title' => __('Tell us which feature', 'walletup'),
                'body'  => __('Name it in the box above. Requests that come in this way are the ones we build first.', 'walletup'),
            ],
            self::REASON_CANT_PAY         => [
                'title' => __('You keep your setup', 'walletup'),
                'body'  => __('Your payment links and QR codes keep working, and nothing is deleted. The premium tools pause until the licence is active again, and come straight back when it is.', 'walletup'),
                'url'    => self::accountUrl(),
                'action' => __('Manage your plan', 'walletup'),
            ],
            self::REASON_NO_LONGER_NEEDED => [
                'title' => __('Understood', 'walletup'),
                'body'  => __('Your records stay in your own database and nothing is sent anywhere. If you want a copy first, you can export it from the Wallet Up screen.', 'walletup'),
            ],
            self::REASON_OTHER            => [
                'title' => __('We read every one of these', 'walletup'),
                'body'  => __('Whatever you write above is read by a person here. It is the main thing that decides what we fix next.', 'walletup'),
            ],
        ];

        if (self::canOfferPro()) {
            $panels[self::REASON_NEED_FEATURE]['page']   = self::pricingPage();
            $panels[self::REASON_NEED_FEATURE]['action'] = __('See what Pro adds', 'walletup');
            $panels[self::REASON_FOUND_BETTER]['page']   = self::pricingPage();
            $panels[self::REASON_FOUND_BETTER]['action'] = __('Compare what is included', 'walletup');
        }

        $ctx = self::context();
        $pitchReasons = [
            self::REASON_NO_LONGER_NEEDED,
            self::REASON_NOT_LOOKING_FOR,
            self::REASON_NOT_AS_EXPECTED,
            self::REASON_SHORT_PERIOD,
        ];
        $offerReasons = [
            self::REASON_CANT_PAY,
            self::REASON_FOUND_BETTER,
            self::REASON_NEED_FEATURE,
        ];

        $pitch = self::pitch($ctx);
        if (!empty($pitch)) {
            foreach ($pitchReasons as $rid) {
                if (!isset($panels[$rid])) {
                    continue;
                }
                $panels[$rid]['extra'] = $pitch['body'];
                if (empty($panels[$rid]['page']) && empty($panels[$rid]['url']) && empty($panels[$rid]['check'])) {
                    $panels[$rid]['page']   = $pitch['page'];
                    $panels[$rid]['action'] = $pitch['action'];
                }
            }
        }

        $offer = apply_filters(self::FILTER_OFFER, [], $ctx);
        if (is_array($offer) && !empty($offer['url']) && !empty($offer['label'])) {
            foreach ($offerReasons as $rid) {
                if (isset($panels[$rid])) {
                    $panels[$rid]['offer'] = [
                        'url'   => (string) $offer['url'],
                        'label' => (string) $offer['label'],
                    ];
                }
            }
        }

        return (array) apply_filters(self::FILTER_PANELS, $panels);
    }

    private static function accountUrl(): string
    {
        if (function_exists('\\WalletUpPro\\wallet_up_fs')) {
            try {
                $fs = \WalletUpPro\wallet_up_fs();
                if (is_object($fs) && method_exists($fs, 'get_account_url')) {
                    return (string) $fs->get_account_url();
                }
            } catch (\Throwable $e) {
                return admin_url('admin.php?page=wallet-up-account');
            }
        }
        return admin_url('admin.php?page=wallet-up-account');
    }

    private static function canOfferPro(): bool
    {
        if (!function_exists('\\WalletUpPro\\wallet_up_fs')) {
            return false;
        }
        try {
            $fs = \WalletUpPro\wallet_up_fs();
        } catch (\Throwable $e) {
            return false;
        }
        return is_object($fs) && method_exists($fs, 'can_use_premium_code') && !$fs->can_use_premium_code();
    }

    private static function context(): array
    {
        $accounts = (array) get_option('wallet_up_transfer_call', []);
        $methods = 0;
        foreach ($accounts as $key => $value) {
            if (str_ends_with((string) $key, '_holder') && trim((string) $value) !== '') {
                $methods++;
            }
        }

        return [
            'industry'    => (string) get_option('wallet_up_industry', ''),
            'goal'        => (string) get_option('wallet_up_onboarding_goal', ''),
            'has_woo'     => class_exists('WooCommerce'),
            'methods'     => $methods,
            'can_upgrade' => self::canOfferPro(),
        ];
    }

    private static function pitch(array $ctx): array
    {
        $map = self::pitchMap();
        $pitch = $map[$ctx['industry']] ?? self::pitchFromGoal($ctx);
        if (empty($pitch)) {
            return [];
        }

        $page   = $pitch['page'];
        $action = $pitch['action'];

        $proBuild    = self::isProBuild();
        $v2OnlyPage  = in_array($page, [self::PAGE_PAYMENTS, self::PAGE_DONATIONS, self::PAGE_MEMBERSHIPS], true);

        if (!$proBuild && $v2OnlyPage) {
            if (!empty($pitch['pro'])) {
                $page   = self::pricingPage();
                $action = __('See it in Pro', 'walletup');
            } else {
                $page   = self::PAGE_ONBOARDING;
                $action = __('Set it up', 'walletup');
            }
        } elseif (!empty($pitch['pro']) && $ctx['can_upgrade']) {
            $page   = self::pricingPage();
            $action = __('See it in Pro', 'walletup');
        }

        return ['body' => $pitch['body'], 'page' => $page, 'action' => $action];
    }

    private static function pitchMap(): array
    {
        return [
            'nonprofit' => [
                'body'   => __('You set Wallet Up up for a nonprofit. It runs the whole donation side: a Tip Jar, a donation form with recurring gifts and tax receipts, and campaigns with a live running total.', 'walletup'),
                'page'   => self::PAGE_DONATIONS,
                'action' => __('Open your donation tools', 'walletup'),
                'pro'    => true,
            ],
            'restaurant' => [
                'body'   => __('You set Wallet Up up for food service. It takes tips and quick payments at the counter or the table from one scannable code, with no card reader to buy.', 'walletup'),
                'page'   => self::PAGE_PAYMENTS,
                'action' => __('Open your payment tools', 'walletup'),
            ],
            'retail' => [
                'body'   => __('You set Wallet Up up for a shop. It takes card and quick payments at your store, with a share-ready link and QR for every method, settled straight to your own account.', 'walletup'),
                'page'   => self::PAGE_PAYMENTS,
                'action' => __('Open your payment tools', 'walletup'),
            ],
            'services' => [
                'body'   => __('You set Wallet Up up for services. It collects one-off payments through a shareable link, a QR for invoices and quotes, and a payment button for any page.', 'walletup'),
                'page'   => self::PAGE_PAYMENTS,
                'action' => __('Open your payment tools', 'walletup'),
            ],
            'events' => [
                'body'   => __('You set Wallet Up up for events. It sells tickets and class fees from a fixed-price button and a ready-made page your guests pay from.', 'walletup'),
                'page'   => self::PAGE_PAYMENTS,
                'action' => __('Open your payment tools', 'walletup'),
            ],
            'creator' => [
                'body'   => __('You set Wallet Up up as a creator. It supports you with tips, recurring memberships with tiers, and supporter payments to your own account.', 'walletup'),
                'page'   => self::PAGE_MEMBERSHIPS,
                'action' => __('Open your membership tools', 'walletup'),
                'pro'    => true,
            ],
        ];
    }

    private static function pitchFromGoal(array $ctx): array
    {
        if ($ctx['goal'] === 'donations') {
            return [
                'body'   => __('You set Wallet Up up to accept donations. It gives you a Tip Jar, a donation form with recurring gifts and tax receipts, and campaigns.', 'walletup'),
                'page'   => self::PAGE_DONATIONS,
                'action' => __('Open your donation tools', 'walletup'),
                'pro'    => true,
            ];
        }
        if ($ctx['has_woo']) {
            return [
                'body'   => __('Your store runs on WooCommerce. Wallet Up puts card checkout and express pay into that checkout, settled to your own account.', 'walletup'),
                'page'   => self::PAGE_PAYMENTS,
                'action' => __('Open your payment tools', 'walletup'),
            ];
        }
        if ($ctx['methods'] > 0) {
            return [
                'body'   => __('You already have payment methods set up here. A share-ready link and QR for each one is on the payments screen.', 'walletup'),
                'page'   => self::PAGE_PAYMENTS,
                'action' => __('Open your payment tools', 'walletup'),
            ];
        }
        return [];
    }

    public static function render(array $panel): string
    {
        $title = (string) ($panel['title'] ?? '');
        $body  = (string) ($panel['body'] ?? '');
        $out  = '<div data-wu-panel style="margin:6px 0 2px;padding:11px 13px;border:1px solid ' . self::UI_BORDER . ';border-left:3px solid ' . self::UI_BRAND . ';border-radius:0 5px 5px 0;background:' . self::UI_BG . ';font-size:13px;line-height:1.6;color:' . self::UI_TEXT . '">';
        $out .= '<span style="display:block;font-weight:600;color:' . self::UI_INK . ';margin-bottom:3px">' . esc_html($title) . '</span>';
        $out .= '<span style="display:block">' . esc_html($body) . '</span>';

        if (!empty($panel['extra'])) {
            $out .= '<span style="display:block;margin-top:5px">' . esc_html((string) $panel['extra']) . '</span>';
        }

        if (!empty($panel['check'])) {
            $out .= '<button type="button" data-wu-check style="margin-top:9px;padding:6px 13px;border:0;border-radius:5px;background:' . self::UI_BRAND . ';color:#fff;font-size:12px;font-weight:600;cursor:pointer">'
                . esc_html__('Check this site', 'walletup') . '</button>';
        } elseif (!empty($panel['url']) && !empty($panel['action'])) {
            $out .= '<a href="' . esc_url((string) $panel['url']) . '" style="display:inline-block;margin-top:9px;padding:6px 13px;border-radius:5px;background:' . self::UI_BRAND . ';color:#fff;font-size:12px;font-weight:600;text-decoration:none">'
                . esc_html((string) $panel['action']) . '</a>';
        } elseif (!empty($panel['page']) && !empty($panel['action'])) {
            $out .= '<a href="' . esc_url(\WalletUpPro\Core\Admin\OnboardingHandler::pageUrl((string) $panel['page'])) . '" style="display:inline-block;margin-top:9px;padding:6px 13px;border-radius:5px;background:' . self::UI_BRAND . ';color:#fff;font-size:12px;font-weight:600;text-decoration:none">'
                . esc_html((string) $panel['action']) . '</a>';
        }

        if (!empty($panel['offer']['url']) && !empty($panel['offer']['label'])) {
            $out .= ' <a href="' . esc_url((string) $panel['offer']['url']) . '" style="display:inline-block;margin-top:9px;margin-left:6px;padding:6px 13px;border-radius:5px;background:#fff;border:1px solid ' . self::UI_BRAND . ';color:' . self::UI_BRAND . ';font-size:12px;font-weight:600;text-decoration:none">'
                . esc_html((string) $panel['offer']['label']) . '</a>';
        }

        return $out . '</div>';
    }

    private static function gateModalHtml(): string
    {
        $ctx   = self::context();
        $pitch = self::pitch($ctx);

        $body = $pitch['body']
            ?? __('Wallet Up keeps your payment methods, links and records exactly as they are. You can turn it back on at any time.', 'walletup');
        $page = $pitch['page'] ?? self::PAGE_ONBOARDING;
        $action = $pitch['action'] ?? __('Open Wallet Up', 'walletup');

        $title    = __('Before you turn off Wallet Up', 'walletup');
        $dataNote = __('Your records stay in your own database and nothing is sent anywhere. Turning it off does not delete anything.', 'walletup');
        $keep     = __('Keep Wallet Up', 'walletup');
        $remove   = __('Deactivate anyway', 'walletup');
        $pause    = sprintf(
            /* translators: %d: number of days. */
            __('Pause for %d days', 'walletup'),
            self::pauseDays()
        );

        $cta = '<a href="' . esc_url(\WalletUpPro\Core\Admin\OnboardingHandler::pageUrl($page)) . '" style="display:inline-block;padding:9px 16px;border-radius:6px;background:' . self::UI_BRAND . ';color:#fff;font-size:13px;font-weight:600;text-decoration:none">'
            . esc_html($action) . '</a>';

        $offer = apply_filters(self::FILTER_OFFER, [], $ctx);
        if (is_array($offer) && !empty($offer['url']) && !empty($offer['label'])) {
            $cta .= ' <a href="' . esc_url((string) $offer['url']) . '" style="display:inline-block;padding:9px 16px;border-radius:6px;background:#fff;border:1px solid ' . self::UI_BRAND . ';color:' . self::UI_BRAND . ';font-size:13px;font-weight:600;text-decoration:none">'
                . esc_html((string) $offer['label']) . '</a>';
        }

        $out  = '<div id="wu-retention-gate" style="display:none;position:fixed;inset:0;z-index:100010;background:rgba(20,18,40,.55);align-items:center;justify-content:center">';
        $out .= '<div role="dialog" aria-modal="true" style="background:#fff;max-width:470px;width:92%;border-radius:11px;padding:24px 26px;box-shadow:0 14px 50px rgba(0,0,0,.3);font-size:14px;line-height:1.6;color:' . self::UI_TEXT . '">';
        $out .= '<div style="font-size:18px;font-weight:700;color:' . self::UI_INK . ';margin-bottom:9px">' . esc_html($title) . '</div>';
        $out .= '<p style="margin:0 0 11px">' . esc_html($body) . '</p>';
        $out .= '<p style="margin:0 0 16px;color:#777;font-size:13px">' . esc_html($dataNote) . '</p>';
        $out .= '<div style="display:flex;gap:8px;flex-wrap:wrap">' . $cta . '</div>';
        $out .= '<div style="display:flex;gap:14px;align-items:center;justify-content:flex-end;margin-top:20px;border-top:1px solid #eee;padding-top:15px">';
        $out .= '<button type="button" data-wu-gate-go style="background:none;border:0;color:#777;font-size:13px;cursor:pointer;padding:6px">' . esc_html($remove) . '</button>';
        $out .= '<button type="button" data-wu-gate-pause style="background:#fff;border:1px solid ' . self::UI_BRAND . ';color:' . self::UI_BRAND . ';font-size:13px;font-weight:600;cursor:pointer;padding:9px 16px;border-radius:6px">' . esc_html($pause) . '</button>';
        $out .= '<button type="button" data-wu-gate-keep style="background:' . self::UI_BRAND . ';border:0;color:#fff;font-size:13px;font-weight:600;cursor:pointer;padding:9px 18px;border-radius:6px">' . esc_html($keep) . '</button>';
        $out .= '</div></div></div>';

        return $out;
    }

    public static function assets(string $hook): void
    {
        if ($hook !== 'plugins.php' || !current_user_can(self::CAP)) {
            return;
        }
        $config = [
            'ajax'       => admin_url('admin-ajax.php'),
            'action'     => self::AJAX_CHECK,
            'pauseAction' => self::AJAX_PAUSE,
            'nonce'      => wp_create_nonce(self::NONCE),
            'checking'   => __('Checking', 'walletup'),
            'retry'      => __('Try again', 'walletup'),
            'gate'       => self::gateModalHtml(),
        ];
        wp_register_script(self::SCRIPT_HANDLE, '', [], WALLET_UP_PRO_VERSION, true);
        wp_enqueue_script(self::SCRIPT_HANDLE);
        wp_add_inline_script(
            self::SCRIPT_HANDLE,
            'window.walletUpRetention=' . wp_json_encode($config) . ';' . self::script()
        );
    }

    private static function script(): string
    {
        return <<<'JS'
(function(){
var c=window.walletUpRetention;
if(!c){return;}
var modal=null,bypass=false;
function ensureModal(){
if(modal){return modal;}
var w=document.createElement('div');
w.innerHTML=c.gate||'';
modal=w.firstChild;
if(modal){document.body.appendChild(modal);}
return modal;
}
function closeModal(){if(modal){modal.style.display='none';}}
function deactivateButton(){return document.querySelector('.fs-modal-deactivation-feedback.active .button-deactivate')||document.querySelector('.button-deactivate');}
document.addEventListener('click',function(e){
var t=e.target;
if(!t||!t.closest){return;}
var b=t.closest('[data-wu-check]');
if(b){
e.preventDefault();
e.stopPropagation();
b.disabled=true;
var label=b.textContent;
b.textContent=c.checking;
var body='action='+encodeURIComponent(c.action)+'&_wpnonce='+encodeURIComponent(c.nonce);
fetch(c.ajax,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
.then(function(r){return r.json();})
.then(function(res){
var host=b.closest('[data-wu-panel]');
if(host&&res&&res.success&&res.data&&res.data.html){host.outerHTML=res.data.html;return;}
b.disabled=false;b.textContent=label;
})
.catch(function(){b.disabled=false;b.textContent=c.retry;});
return;
}
if(t.closest('[data-wu-gate-keep]')){e.preventDefault();e.stopPropagation();closeModal();return;}
var pauseBtn=t.closest('[data-wu-gate-pause]');
if(pauseBtn){
e.preventDefault();e.stopPropagation();pauseBtn.disabled=true;
fetch(c.ajax,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action='+encodeURIComponent(c.pauseAction)+'&_wpnonce='+encodeURIComponent(c.nonce)})
.then(function(r){return r.json();})
.then(function(){closeModal();var cl=document.querySelector('.fs-modal-deactivation-feedback.active .button-close');if(cl){cl.click();}location.reload();})
.catch(function(){pauseBtn.disabled=false;});
return;
}
if(t.closest('[data-wu-gate-go]')){e.preventDefault();e.stopPropagation();closeModal();var db=deactivateButton();if(db){bypass=true;db.click();}return;}
if(modal&&t===modal){e.preventDefault();closeModal();return;}
var skip=t.closest('.button-deactivate');
if(skip){
if(bypass){bypass=false;return;}
var scope=skip.closest('.fs-modal-deactivation-feedback')||skip.closest('.fs-modal');
if(!scope||!scope.querySelector('[data-wu-panel]')){return;}
if(scope.querySelector('input[name="selected-reason"]:checked')){return;}
var m=ensureModal();
if(m){e.preventDefault();e.stopImmediatePropagation();m.style.display='flex';var fk=m.querySelector('[data-wu-gate-keep]');if(fk){fk.focus();}}
}
},true);
document.addEventListener('keydown',function(e){if((e.key==='Escape'||e.keyCode===27)&&modal&&modal.style.display==='flex'){closeModal();}});
})();
JS;
    }

    public static function handleCheck(): void
    {
        if (!check_ajax_referer(self::NONCE, '_wpnonce', false) || !current_user_can(self::CAP)) {
            wp_send_json_error(['message' => __('That request could not be verified.', 'walletup')], 403);
        }
        $finding = SetupCheck::run();
        wp_send_json_success(['html' => self::render([
            'title'  => self::headline($finding['state']),
            'body'   => (string) $finding['text'],
            'page'   => (string) $finding['page'],
            'action' => $finding['page'] !== '' ? __('Take me there', 'walletup') : '',
        ])]);
    }

    private static function headline(string $state): string
    {
        return match ($state) {
            SetupCheck::UNCONFIGURED => __('Setup was never finished', 'walletup'),
            SetupCheck::INCOMPLETE   => __('Almost set up', 'walletup'),
            SetupCheck::ENVIRONMENT  => __('It is the server, not you', 'walletup'),
            default                  => __('Worth a human look', 'walletup'),
        };
    }
}
