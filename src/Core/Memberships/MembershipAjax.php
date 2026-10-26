<?php
namespace WalletUpPro\Core\Memberships;

if (!defined('ABSPATH')) {
    exit;
}

class MembershipAjax
{
    public static function register(): void
    {
        $admin = [
            'wallet_up_membership_tiers'        => 'listTiers',
            'wallet_up_membership_save_tier'    => 'saveTier',
            'wallet_up_membership_delete_tier'  => 'deleteTier',
            'wallet_up_membership_members'      => 'listMembers',
            'wallet_up_membership_member'       => 'memberDetail',
            'wallet_up_membership_stats'        => 'stats',
            'wallet_up_membership_cancel'       => 'cancelMember',
            'wallet_up_membership_presets'      => 'listPresets',
            'wallet_up_membership_apply_preset' => 'applyPreset',
            'wallet_up_membership_create_page'  => 'createPage',
            'wallet_up_membership_pages'        => 'listPages',
            'wallet_up_membership_page_live'    => 'togglePageLive',
            'wallet_up_membership_access'       => 'getAccessSettings',
            'wallet_up_membership_save_access'  => 'saveAccessSettings',
            'wallet_up_membership_messages'     => 'listMessages',
            'wallet_up_membership_email'        => 'emailMember',
            'wallet_up_membership_confirm_wallet' => 'confirmWallet',
        ];
        foreach ($admin as $action => $method) {
            add_action("wp_ajax_{$action}", [self::class, $method]);
        }

        add_action('wp_ajax_walup_submit_membership', [self::class, 'submitEnrollment']);
        add_action('wp_ajax_nopriv_walup_submit_membership', [self::class, 'submitEnrollment']);
        add_action('wp_ajax_walup_membership_access_link', [self::class, 'requestAccessLink']);
        add_action('wp_ajax_nopriv_walup_membership_access_link', [self::class, 'requestAccessLink']);
        add_action('wp_ajax_walup_membership_tiers_public', [self::class, 'publicTiers']);
        add_action('wp_ajax_nopriv_walup_membership_tiers_public', [self::class, 'publicTiers']);
        add_action('wp_ajax_walup_membership_wallet_claim', [self::class, 'walletClaim']);
        add_action('wp_ajax_nopriv_walup_membership_wallet_claim', [self::class, 'walletClaim']);
        add_action('wp_ajax_walup_membership_intent', [self::class, 'createIntent']);
        add_action('wp_ajax_nopriv_walup_membership_intent', [self::class, 'createIntent']);
        add_action('walletup_backend_payment_completed', [self::class, 'settleMembershipFromBackend'], 10, 2);
        add_action('walletup_payment_resolved', [self::class, 'settleMembershipResolved'], 10, 2);
        add_action('wp_ajax_walup_membership_confirm', [self::class, 'confirmIntent']);
        add_action('wp_ajax_nopriv_walup_membership_confirm', [self::class, 'confirmIntent']);

        add_action('walletup_payment_status_changed', [self::class, 'onPaymentStatusChanged'], 10, 3);
    }

    private static function guardAdmin(): void
    {
        if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('Invalid security token', 'walletup')], 403);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'walletup')], 403);
        }
    }

    private static function mgr(): MembershipManager
    {
        return MembershipManager::getInstance();
    }

    public static function settleMembershipFromBackend($paymentId, $paymentData): void
    {
        self::settleMembershipResolved('completed', is_array($paymentData) ? $paymentData : []);
    }

    public static function settleMembershipResolved($state, $data): void
    {
        if (!is_array($data)) {
            return;
        }
        $membershipId = (string) ($data['membership_id'] ?? '');
        if ($membershipId === '' && isset($data['metadata']) && is_array($data['metadata'])) {
            $membershipId = (string) ($data['metadata']['membership_id'] ?? '');
        }
        if ($membershipId === '') {
            return;
        }
        $m = self::mgr()->getMembership($membershipId);
        if (!$m) {
            return;
        }
        $cur = (string) ($m['status'] ?? '');
        if ($state === 'completed') {
            if ($cur !== 'active' && !self::mgr()->activateFromPayment($membershipId, $data['amount'] ?? null, $data['currency'] ?? '')) {
                return;
            }
            self::mgr()->recordLedgerPayment($membershipId, (string) ($data['provider_transaction_id'] ?? ''));
        } elseif ($state === 'refunded') {
            if ($cur !== 'cancelled') {
                self::mgr()->cancel($membershipId);
            }
        } elseif ($state === 'failed') {
            if ($cur === 'pending') {
                self::mgr()->cancel($membershipId);
            }
        }
    }

    public static function listMessages(): void
    {
        self::guardAdmin();
        wp_send_json_success([
            'templates' => MembershipMessages::all(),
            'tokens'    => MembershipMessages::TOKENS,
        ]);
    }

    public static function emailMember(): void
    {
        self::guardAdmin();

        $id      = sanitize_text_field(wp_unslash($_POST['membership_id'] ?? ''));
        \WalletUpPro\Core\Tenancy\Tenant::scopeMember($id);
        $subject = sanitize_text_field(wp_unslash($_POST['subject'] ?? ''));
        $message = sanitize_textarea_field(wp_unslash($_POST['message'] ?? ''));

        if ($id === '' || trim($subject) === '' || trim($message) === '') {
            wp_send_json_error(['message' => __('Add a subject and a message.', 'walletup')], 400);
        }

        $member = self::mgr()->getMemberDetail($id);
        if (!$member || empty($member['member_email']) || !is_email($member['member_email'])) {
            wp_send_json_error(['message' => __('This member has no valid email address.', 'walletup')], 404);
        }

        $tokens  = self::messageTokens($member);
        $subject = strtr($subject, $tokens);
        $message = strtr($message, $tokens);

        $paragraphs = preg_split('/\n{2,}/', trim($message)) ?: [];
        $bodyHtml   = '';
        foreach ($paragraphs as $p) {
            $bodyHtml .= \WalletUpPro\Core\Emails\EmailRenderer::paragraph(nl2br(esc_html(trim($p))));
        }
        $bodyHtml .= \WalletUpPro\Core\Emails\EmailRenderer::detailTable(self::messageRows($member));

        $res = \WalletUpPro\Core\Emails\EmailRouter::send(
            $member['member_email'],
            $subject,
            \WalletUpPro\Core\Emails\EmailRenderer::wrap($subject, $bodyHtml),
            [],
            [
                'type'     => 'membership_message',
                'reply_to' => \WalletUpPro\Core\Emails\EmailTemplateHandler::replyTo(),
                'idempotency_key' => 'mship_msg_' . md5($id . '|' . $subject . '|' . $message),
            ]
        );

        if (empty($res['sent'])) {
            wp_send_json_error([
                'message' => $res['error'] ?: __('The message could not be sent.', 'walletup'),
            ], 502);
        }

        wp_send_json_success([
            'to'       => $member['member_email'],
            'provider' => $res['provider'] ?? '',
            'deduped'  => !empty($res['deduped']),
        ]);
    }

    /** @return array<string,string> */
    private static function messageTokens(array $m): array
    {
        return [
            '{member_name}' => (string) ($m['member_name'] ?: __('there', 'walletup')),
            '{tier_name}'   => (string) ($m['tier_name'] ?? ''),
            '{amount}'      => self::formatAmount($m),
            '{renews_on}'   => self::formatDate($m['expires_at'] ?? ''),
            '{site_name}'   => (string) get_bloginfo('name'),
        ];
    }

    /** @return array<int,array{label:string,value:string,strong?:bool}> */
    private static function messageRows(array $m): array
    {
        $rows = [
            ['label' => __('Membership', 'walletup'), 'value' => esc_html((string) ($m['tier_name'] ?? '')), 'strong' => true],
            ['label' => __('Amount', 'walletup'),     'value' => esc_html(self::formatAmount($m))],
        ];
        if (!empty($m['expires_at'])) {
            $rows[] = ['label' => __('Renews / expires', 'walletup'), 'value' => esc_html(self::formatDate($m['expires_at']))];
        }
        if (!empty($m['benefits']) && is_array($m['benefits'])) {
            $rows[] = [
                'label' => __('Includes', 'walletup'),
                'value' => implode('<br>', array_map('esc_html', $m['benefits'])),
            ];
        }
        return $rows;
    }

    private static function formatAmount(array $m): string
    {
        $amount   = number_format_i18n((float) ($m['amount'] ?? 0), 2);
        $currency = strtoupper((string) ($m['currency'] ?? 'USD'));
        $interval = self::mgr()->subscriptionFrequency((string) ($m['billing_interval'] ?? ''));
        $money    = $currency . ' ' . $amount;
        return $interval !== '' ? $money . ' / ' . $interval : $money;
    }

    private static function formatDate(string $sql): string
    {
        $ts = $sql !== '' ? strtotime($sql) : false;
        return $ts ? date_i18n(get_option('date_format'), $ts) : '';
    }

    private static function rateLimit(string $key, int $max, int $window): void
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'cli';
        $bucket = 'wu_rl_' . $key . '_' . md5($ip);
        $n = (int) get_transient($bucket);
        if ($n >= $max) {
            wp_send_json_error(['message' => __('Too many attempts — please wait a moment and try again.', 'walletup')], 429);
        }
        set_transient($bucket, $n + 1, $window);
    }

    public static function listTiers(): void
    {
        self::guardAdmin();
        wp_send_json_success(['tiers' => self::mgr()->getTiers(false)]);
    }

    public static function saveTier(): void
    {
        self::guardAdmin();
        $raw = isset($_POST['tier']) ? wp_unslash($_POST['tier']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded + field-sanitized in saveTier().
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            wp_send_json_error(['message' => __('Invalid tier data', 'walletup')], 400);
        }
        $res = self::mgr()->saveTier($data);
        if (empty($res['success'])) {
            wp_send_json_error(['message' => $res['error'] ?? 'error'], 400);
        }
        $res['access_armed'] = self::armAccessOnFirstTier();
        wp_send_json_success($res);
    }

    private static function armAccessOnFirstTier(): bool
    {
        $settings = MembershipAccess::settings();
        if (!empty($settings['configured']) || !empty($settings['enabled'])) {
            return false;
        }
        if (count(self::mgr()->getTiers(true)) !== 1) {
            return false;
        }
        MembershipAccess::updateSettings(['enabled' => true]);
        return true;
    }

    public static function deleteTier(): void
    {
        self::guardAdmin();
        $id = (int) ($_POST['id'] ?? 0);
        wp_send_json_success(self::mgr()->deleteTier($id));
    }

    public static function listMembers(): void
    {
        self::guardAdmin();
        $perPage = min(100, max(1, (int) ($_POST['per_page'] ?? 25)));
        $page    = max(1, (int) ($_POST['page'] ?? 1));
        $filters = [
            'status'   => sanitize_text_field(wp_unslash($_POST['status'] ?? '')),
            'tier_id'  => (int) ($_POST['tier_id'] ?? 0),
            'search'   => sanitize_text_field(wp_unslash($_POST['search'] ?? '')),
            'per_page' => $perPage,
            'page'     => $page,
        ];
        $total = self::mgr()->countMembers($filters);
        wp_send_json_success([
            'members'     => self::mgr()->getMembers($filters),
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => max(1, (int) ceil($total / $perPage)),
        ]);
    }

    public static function memberDetail(): void
    {
        self::guardAdmin();
        $id = sanitize_text_field(wp_unslash($_POST['membership_id'] ?? ''));
        \WalletUpPro\Core\Tenancy\Tenant::scopeMember($id);
        $member = self::mgr()->getMemberDetail($id);
        if (!$member) {
            wp_send_json_error(['message' => __('Member not found', 'walletup')], 404);
        }
        wp_send_json_success(['member' => $member]);
    }

    public static function stats(): void
    {
        self::guardAdmin();
        wp_send_json_success(self::mgr()->getStats());
    }

    public static function cancelMember(): void
    {
        self::guardAdmin();
        $id = sanitize_text_field(wp_unslash($_POST['membership_id'] ?? ''));
        \WalletUpPro\Core\Tenancy\Tenant::scopeMember($id);
        wp_send_json_success(self::mgr()->cancel($id));
    }

    const PAGES_OPTION = 'wallet_up_membership_pages';

    public static function listPresets(): void
    {
        self::guardAdmin();
        wp_send_json_success(['presets' => MembershipPresets::all()]);
    }

    public static function applyPreset(): void
    {
        self::guardAdmin();
        $key = sanitize_key(wp_unslash($_POST['preset'] ?? ''));
        $preset = null;
        foreach (MembershipPresets::all() as $p) {
            if ($p['key'] === $key) {
                $preset = $p;
                break;
            }
        }
        if (!$preset) {
            wp_send_json_error(['message' => __('Unknown preset', 'walletup')], 400);
        }
        $created = [];
        $base = count(self::mgr()->getTiers(false));
        foreach ($preset['tiers'] as $i => $tier) {
            $res = self::mgr()->saveTier([
                'name'             => $tier['name'],
                'amount'           => $tier['amount'],
                'billing_interval' => $tier['billing_interval'],
                'benefits'         => $tier['benefits'] ?? [],
                'badge_color'      => $tier['badge_color'] ?? '#7c3aed',
                'active'           => true,
                'sort_order'       => $base + $i,
            ]);
            if (!empty($res['success'])) {
                $created[] = $res['tier'];
            }
        }
        wp_send_json_success(['created' => $created, 'tiers' => self::mgr()->getTiers(false)]);
    }

    public static function createPage(): void
    {
        self::guardAdmin();
        if (!current_user_can('publish_pages')) {
            wp_send_json_error(['message' => __('You cannot publish pages.', 'walletup')], 403);
        }
        $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
        if ($title === '') {
            $title = __('Become a Member', 'walletup');
        }
        $intro = sanitize_textarea_field(wp_unslash($_POST['intro'] ?? ''));
        $color = sanitize_hex_color(wp_unslash($_POST['color'] ?? '')) ?: '#7c3aed';

        $pages = get_option(self::PAGES_OPTION, []);
        if (!is_array($pages)) {
            $pages = [];
        }

        foreach ($pages as $pid => $meta) {
            $existing = get_post((int) $pid);
            if ($existing && $existing->post_status !== 'trash' && $existing->post_title === $title) {
                wp_send_json_success(self::pagePayload((int) $pid));
            }
        }

        $content = '';
        if ($intro !== '') {
            $content .= '<!-- wp:paragraph --><p>' . esc_html($intro) . '</p><!-- /wp:paragraph -->' . "\n";
        }
        $content .= '<!-- wp:shortcode -->[walletup_membership_form color="' . esc_attr($color) . '"]<!-- /wp:shortcode -->';

        $pageId = wp_insert_post([
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_author'  => get_current_user_id(),
        ], true);
        if (is_wp_error($pageId) || !$pageId) {
            wp_send_json_error(['message' => __('Could not create the page. Please try again.', 'walletup')], 500);
        }

        $pages[(int) $pageId] = ['title' => $title, 'created_at' => current_time('mysql')];
        update_option(self::PAGES_OPTION, $pages, false);

        wp_send_json_success(self::pagePayload((int) $pageId));
    }

    public static function listPages(): void
    {
        self::guardAdmin();
        $pages = get_option(self::PAGES_OPTION, []);
        if (!is_array($pages)) {
            $pages = [];
        }
        $out = [];
        $changed = false;
        foreach ($pages as $pid => $meta) {
            $post = get_post((int) $pid);
            if (!$post || $post->post_status === 'trash') {
                unset($pages[$pid]);
                $changed = true;
                continue;
            }
            $out[] = self::pagePayload((int) $pid, false);
        }
        if ($changed) {
            update_option(self::PAGES_OPTION, $pages, false);
        }
        wp_send_json_success(['pages' => $out]);
    }

    public static function togglePageLive(): void
    {
        self::guardAdmin();
        $pageId = (int) ($_POST['page_id'] ?? 0);
        $live = filter_var(wp_unslash($_POST['live'] ?? ''), FILTER_VALIDATE_BOOLEAN);
        $pages = get_option(self::PAGES_OPTION, []);
        if (!is_array($pages) || !isset($pages[$pageId])) {
            wp_send_json_error(['message' => __('Unknown page', 'walletup')], 404);
        }
        wp_update_post(['ID' => $pageId, 'post_status' => $live ? 'publish' : 'draft']);
        wp_send_json_success(self::pagePayload($pageId, false));
    }

    private static function pagePayload(int $pageId, bool $created = true): array
    {
        $post = get_post($pageId);
        return [
            'success'   => true,
            'page_id'   => $pageId,
            'title'     => $post ? $post->post_title : '',
            'live'      => $post && $post->post_status === 'publish',
            'view_url'  => get_permalink($pageId),
            'edit_url'  => get_edit_post_link($pageId, 'raw'),
            'created'   => $created,
        ];
    }

    public static function publicTiers(): void
    {
        $tiers = self::mgr()->getTiers(true);
        if (!MembershipWallets::offered()) {
            wp_send_json_success(['tiers' => $tiers]);
        }
        $tiers = array_map(static function (array $tier): array {
            $tier['wallets'] = MembershipWallets::offersFor($tier);
            return $tier;
        }, $tiers);
        wp_send_json_success(['tiers' => $tiers, 'cards' => MembershipWallets::cardsShown()]);
    }

    public static function walletClaim(): void
    {
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        if (!wp_verify_nonce($nonce, 'walup_submit_membership')) {
            wp_send_json_error(['message' => __('Invalid security token', 'walletup')], 403);
        }
        self::rateLimit('membership_wallet_claim', 4, 300);
        $tier = self::mgr()->getTier((int) ($_POST['tier_id'] ?? 0));
        $method = sanitize_key(wp_unslash($_POST['payment_method'] ?? ''));
        if (!$tier || empty($tier['active']) || !MembershipWallets::allows($tier, $method)) {
            wp_send_json_error(['message' => \WalletUpPro\Core\Payments\Availability\MethodAvailability::refusal()], 400);
        }
        $email = sanitize_email(wp_unslash($_POST['member_email'] ?? ''));
        $existing = self::mgr()->findWalletClaim((int) $tier['id'], $email);
        if ($existing !== '') {
            wp_send_json_success(['membership_id' => $existing, 'status' => 'awaiting']);
        }
        $enroll = self::mgr()->enroll([
            'tier_id'      => (int) $tier['id'],
            'member_name'  => sanitize_text_field(wp_unslash($_POST['member_name'] ?? '')),
            'member_email' => $email,
            'metadata'     => ['payment_method' => $method, 'awaiting' => MembershipWallets::AWAITING],
        ]);
        if (empty($enroll['success'])) {
            wp_send_json_error(['message' => $enroll['error'] ?? __('Enrollment failed.', 'walletup')], 400);
        }
        wp_send_json_success(['membership_id' => $enroll['membership_id'], 'status' => 'awaiting']);
    }

    public static function confirmWallet(): void
    {
        self::guardAdmin();
        $id = sanitize_text_field(wp_unslash($_POST['membership_id'] ?? ''));
        \WalletUpPro\Core\Tenancy\Tenant::scopeMember($id);
        if (!self::mgr()->confirmWalletPayment($id)) {
            wp_send_json_error(['message' => __('This membership is not waiting for a payment confirmation.', 'walletup')], 409);
        }
        if (class_exists('\\WalletUpPro\\Core\\Security\\AuditLog')) {
            \WalletUpPro\Core\Security\AuditLog::record('membership_wallet_confirmed', $id);
        }
        wp_send_json_success(['member' => self::mgr()->getMemberDetail($id)]);
    }

    public static function createIntent(): void
    {
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        if (!wp_verify_nonce($nonce, 'walup_submit_membership') && !wp_verify_nonce($nonce, 'wallet_up_admin_nonce')) {
            wp_send_json_error(['message' => __('Invalid security token', 'walletup')], 403);
        }
        self::rateLimit('membership_intent', 8, 60);
        if (!\WalletUpPro\Core\Payments\Availability\MethodAvailability::canStart('stripe', 'memberships')) {
            wp_send_json_error(['message' => \WalletUpPro\Core\Payments\Availability\MethodAvailability::refusal()], 400);
        }
        $enroll = self::mgr()->enroll([
            'tier_id'      => (int) ($_POST['tier_id'] ?? 0),
            'member_name'  => sanitize_text_field(wp_unslash($_POST['member_name'] ?? '')),
            'member_email' => sanitize_email(wp_unslash($_POST['member_email'] ?? '')),
        ]);
        if (empty($enroll['success'])) {
            wp_send_json_error(['message' => $enroll['error'] ?? __('Enrollment failed.', 'walletup')], 400);
        }
        $membershipId = $enroll['membership_id'];
        $tier = $enroll['tier'];
        $fail = static function (string $message, int $status) use ($membershipId): void {
            self::mgr()->discardPending($membershipId);
            wp_send_json_error(['message' => $message], $status);
        };
        if (!class_exists('\\WalletUpPro\\Core\\Payments\\PaymentGatewayHandler')) {
            $fail(__('Payments are unavailable.', 'walletup'), 500);
        }
        $member = self::mgr()->getMembership($membershipId);

        if (!empty($enroll['is_recurring'])) {
            $sub = \WalletUpPro\Core\Payments\RecurringCheckout::start([
                'amount'      => $tier['amount'],
                'currency'    => strtolower($tier['currency']),
                'frequency'   => self::mgr()->subscriptionFrequency($tier['billing_interval']),
                'email'       => $member['member_email'],
                'name'        => $member['member_name'],
                'source_type' => 'membership',
                'source_id'   => $membershipId,
            ]);
            if (empty($sub['ok']) || $sub['client_secret'] === '') {
                $fail($sub['error'] ?: __('Could not start the membership.', 'walletup'), 400);
            }
            self::mgr()->setSubscriptionId($membershipId, $sub['subscription_id']);
            wp_send_json_success([
                'membership_id'   => $membershipId,
                'client_secret'   => $sub['client_secret'],
                'publishable_key' => $sub['publishable_key'],
                'recurring'       => true,
            ]);
        }

        $intent = \WalletUpPro\Core\Payments\PaymentGatewayHandler::createCardIntent([
            'amount'        => \WalletUpPro\Core\Payments\PaymentGatewayHandler::toMinorUnits((float) $tier['amount'], (string) $tier['currency']),
            'currency'      => strtolower($tier['currency']),
            'description'   => 'Membership ' . $tier['name'],
            'receipt_email' => $member['member_email'],
            'metadata'      => ['membership_id' => $membershipId, 'type' => 'membership'],
            'source'        => 'membership',
        ]);
        if (is_wp_error($intent)) {
            $fail($intent->get_error_message(), 400);
        }
        wp_send_json_success([
            'membership_id'     => $membershipId,
            'client_secret'     => $intent['client_secret'] ?? '',
            'publishable_key'   => $intent['publishable_key'] ?? '',
            'connected_account' => $intent['connected_account'] ?? '',
            'payment_method_types' => $intent['payment_method_types'] ?? [],
        ]);
    }

    public static function confirmIntent(): void
    {
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        if (!wp_verify_nonce($nonce, 'walup_submit_membership') && !wp_verify_nonce($nonce, 'wallet_up_admin_nonce')) {
            wp_send_json_error(['message' => __('Invalid security token', 'walletup')], 403);
        }
        self::rateLimit('membership_confirm', 12, 60);

        $membershipId = sanitize_text_field(wp_unslash($_POST['membership_id'] ?? ''));
        \WalletUpPro\Core\Tenancy\Tenant::scopeMember($membershipId);
        $intentId = sanitize_text_field(wp_unslash($_POST['payment_intent_id'] ?? ''));
        $member = $membershipId !== '' ? self::mgr()->getMembership($membershipId) : null;
        if (!$member) {
            wp_send_json_error(['message' => __('Membership not found.', 'walletup')], 404);
        }
        if (($member['status'] ?? '') === 'active') {
            wp_send_json_success(['membership_id' => $membershipId, 'status' => 'active']);
        }

        if (!class_exists('\\WalletUpPro\\Core\\Payments\\PaymentGatewayHandler')) {
            wp_send_json_error(['message' => __('Payments are unavailable.', 'walletup')], 500);
        }
        $tier = self::mgr()->getTier((int) $member['tier_id']);
        if (!$tier) {
            wp_send_json_error(['message' => __('Membership tier not found.', 'walletup')], 404);
        }

        $gate = \WalletUpPro\Core\Payments\PaymentGatewayHandler::assertPaidFor([
            'payment_intent_id' => $intentId,
            'bind_key'          => 'membership_id',
            'bind_value'        => $membershipId,
            'expected_amount'   => \WalletUpPro\Core\Payments\PaymentGatewayHandler::toMinorUnits(
                (float) $tier['amount'],
                (string) $tier['currency']
            ),
            'expected_currency' => (string) $tier['currency'],
        ]);

        if (!empty($gate['paid'])) {
            self::mgr()->activate($membershipId);
            self::mgr()->recordLedgerPayment($membershipId, $intentId);
            $token = MembershipAccess::grant($membershipId);
            if ($token !== '') {
                MembershipAccess::rememberToken($token);
            }
            wp_send_json_success(['membership_id' => $membershipId, 'status' => 'active']);
        }
        if (!empty($gate['processing'])) {
            wp_send_json_success(['membership_id' => $membershipId, 'status' => 'processing']);
        }
        wp_send_json_error(['message' => $gate['error']], $gate['status'] === 'claimed' ? 409 : 400);
    }

    public static function getAccessSettings(): void
    {
        self::guardAdmin();
        wp_send_json_success([
            'settings' => MembershipAccess::settings(),
            'pages'    => array_map(
                static fn($p) => ['id' => (int) $p->ID, 'title' => (string) $p->post_title],
                get_pages(['number' => 100, 'sort_column' => 'post_title']) ?: []
            ),
        ]);
    }

    public static function saveAccessSettings(): void
    {
        self::guardAdmin();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guardAdmin() verified it
        $post = wp_unslash($_POST);
        wp_send_json_success(['settings' => MembershipAccess::updateSettings([
            'enabled'          => !empty($post['enabled']) && $post['enabled'] !== 'false',
            'grace_days'       => isset($post['grace_days']) ? max(0, min(365, (int) $post['grace_days'])) : 0,
            'denied_message'   => isset($post['denied_message']) ? sanitize_text_field($post['denied_message']) : '',
            'redirect_page_id' => isset($post['redirect_page_id']) ? absint($post['redirect_page_id']) : 0,
            'link_wp_users'    => !empty($post['link_wp_users']) && $post['link_wp_users'] !== 'false',
            'configured'       => true,
        ])]);
    }

    public static function requestAccessLink(): void
    {
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        if (!wp_verify_nonce($nonce, 'walup_membership_access_link')) {
            wp_send_json_error(['message' => __('Invalid security token', 'walletup')], 403);
        }
        if (class_exists('\\WalletUpPro\\Core\\Donations\\DonationSystemHandler')
            && method_exists('\\WalletUpPro\\Core\\Donations\\DonationSystemHandler', 'enforceRateLimit')) {
            \WalletUpPro\Core\Donations\DonationSystemHandler::enforceRateLimit('membership_access_link', 4, 900);
        }

        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $sent = __('If that address has an active membership, the link is on its way.', 'walletup');

        $issued = MembershipAccess::issueRecoveryToken($email);
        if ($issued !== null) {
            $member = $issued['member'];
            $member['access_link'] = MembershipAccess::accessLinkUrl($issued['token']);
            $tier = self::mgr()->getTier((int) ($member['tier_id'] ?? 0));
            $member['tier_name'] = $tier['name'] ?? '';
            if (class_exists('\\WalletUpPro\\Core\\Emails\\EmailTemplateHandler')) {
                \WalletUpPro\Core\Emails\EmailTemplateHandler::sendMembershipEmail('membership_access_link', $member);
            }
        }

        wp_send_json_success(['message' => $sent]);
    }

    public static function submitEnrollment(): void
    {
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        if (!wp_verify_nonce($nonce, 'walup_submit_membership') && !wp_verify_nonce($nonce, 'wallet_up_admin_nonce')) {
            wp_send_json_error(['message' => __('Invalid security token', 'walletup')], 403);
        }
        if (class_exists('\\WalletUpPro\\Core\\Donations\\DonationSystemHandler')
            && method_exists('\\WalletUpPro\\Core\\Donations\\DonationSystemHandler', 'enforceRateLimit')) {
            \WalletUpPro\Core\Donations\DonationSystemHandler::enforceRateLimit('membership_submit', 8, 60);
        }

        if (!\WalletUpPro\Core\Payments\Availability\MethodAvailability::canStart('stripe', 'memberships')) {
            wp_send_json_error(['message' => \WalletUpPro\Core\Payments\Availability\MethodAvailability::refusal()], 400);
        }
        $enroll = self::mgr()->enroll([
            'tier_id'      => (int) ($_POST['tier_id'] ?? 0),
            'member_name'  => sanitize_text_field(wp_unslash($_POST['member_name'] ?? '')),
            'member_email' => sanitize_email(wp_unslash($_POST['member_email'] ?? '')),
            'member_phone' => sanitize_text_field(wp_unslash($_POST['member_phone'] ?? '')),
        ]);
        if (empty($enroll['success'])) {
            wp_send_json_error(['message' => $enroll['error'] ?? __('Enrollment failed.', 'walletup')], 400);
        }

        $membershipId = $enroll['membership_id'];
        $tier = $enroll['tier'];
        $paymentMethodId = sanitize_text_field(wp_unslash($_POST['payment_method_id'] ?? ''));

        if (!empty($enroll['is_recurring'])) {
            self::startRecurring($membershipId, $tier, $paymentMethodId);
        } else {
            self::startOneTime($membershipId, $tier, $paymentMethodId);
        }
    }

    private static function startRecurring(string $membershipId, array $tier, string $paymentMethodId): void
    {
        if (!class_exists('\\WalletUpPro\\Core\\Subscriptions\\SubscriptionManager')) {
            wp_send_json_error(['message' => __('Recurring payments are unavailable.', 'walletup')], 500);
        }
        $frequency = self::mgr()->subscriptionFrequency($tier['billing_interval']);
        $sub = \WalletUpPro\Core\Subscriptions\SubscriptionManager::getInstance()->createStripeSubscription([
            'amount'            => $tier['amount'],
            'currency'          => strtolower($tier['currency']),
            'frequency'         => $frequency,
            'email'             => self::mgr()->getMembership($membershipId)['member_email'],
            'name'              => self::mgr()->getMembership($membershipId)['member_name'],
            'payment_method_id' => $paymentMethodId,
            'source_type'       => 'membership',
            'source_id'         => $membershipId,
            'metadata'          => ['membership_id' => $membershipId, 'tier' => $tier['tier_key']],
        ]);
        $subStatus = (string) ($sub['status'] ?? '');
        $needsAction = !empty($sub['requires_action']);
        if (empty($sub['subscription_id']) || (!$needsAction && !in_array($subStatus, ['active', 'trialing'], true))) {
            wp_send_json_error(['message' => $sub['error'] ?? __('Could not start the membership.', 'walletup')], 400);
        }
        if (!$needsAction) {
            self::mgr()->activate($membershipId, ['subscription_id' => $sub['subscription_id'] ?? '']);
        }
        wp_send_json_success([
            'membership_id' => $membershipId,
            'status'        => 'active',
            'requires_action' => !empty($sub['requires_action']),
            'client_secret' => $sub['client_secret'] ?? '',
        ]);
    }

    private static function startOneTime(string $membershipId, array $tier, string $paymentMethodId): void
    {
        if (!class_exists('\\WalletUpPro\\Core\\Payments\\PaymentGatewayHandler')) {
            wp_send_json_error(['message' => __('Card payments are unavailable.', 'walletup')], 500);
        }
        $member = self::mgr()->getMembership($membershipId);
        $intent = \WalletUpPro\Core\Payments\PaymentGatewayHandler::createCardIntent([
            'amount'         => \WalletUpPro\Core\Payments\PaymentGatewayHandler::toMinorUnits((float) $tier['amount'], (string) $tier['currency']),
            'currency'       => strtolower($tier['currency']),
            'payment_method' => $paymentMethodId,
            'description'    => 'Membership ' . $tier['name'],
            'receipt_email'  => $member['member_email'],
            'metadata'       => ['membership_id' => $membershipId, 'type' => 'membership'],
            'source'         => 'membership',
        ]);
        if (is_wp_error($intent)) {
            wp_send_json_error(['message' => \WalletUpPro\Core\Payments\BackendErrors::forCustomer($intent, 'membership')], 400);
        }
        $status = $intent['status'] ?? 'processing';
        if ($status === 'succeeded') {
            self::mgr()->activate($membershipId);
        }
        wp_send_json_success([
            'membership_id'   => $membershipId,
            'status'          => $status,
            'requires_action' => $status === 'requires_action',
            'client_secret'   => $intent['client_secret'] ?? '',
            'publishable_key' => $intent['publishable_key'] ?? '',
            'connected_account' => $intent['connected_account'] ?? '',
        ]);
    }

    public static function onPaymentStatusChanged($paymentId, $status, $meta = []): void
    {
        if ($status !== 'completed' && $status !== 'succeeded') {
            return;
        }
        $membershipId = '';
        if (is_array($meta)) {
            $membershipId = $meta['membership_id'] ?? '';
        }
        if ($membershipId === '') {
            return;
        }
        self::mgr()->activateFromPayment($membershipId, $meta['amount'] ?? null, $meta['currency'] ?? '');
    }
}
