<?php
namespace WalletUpPro\Core\Memberships;

if (!defined('ABSPATH')) {
    exit;
}

final class MembershipGate
{
    const META_ON    = '_walletup_access_on';
    const META_TIERS = '_walletup_required_tiers';
    const META_MODE  = '_walletup_required_mode';
    const CRON_HOOK  = 'walletup_membership_expire_lapsed';

    public static function register(): void
    {
        add_shortcode('walletup_members_only', [self::class, 'renderShortcode']);
        add_shortcode('walletup_member_access', [self::class, 'renderAccessForm']);

        add_action('template_redirect', [MembershipAccess::class, 'maybeClaimFromLink'], 1);

        add_filter('the_content', [self::class, 'filterContent'], 5);
        add_filter('the_excerpt', [self::class, 'filterExcerpt'], 5);
        foreach (self::protectableTypes() as $type) {
            add_filter("rest_prepare_{$type}", [self::class, 'filterRest'], 10, 3);
        }

        add_filter('the_content_feed', [self::class, 'filterFeed'], 5);
        add_filter('the_excerpt_rss', [self::class, 'filterFeed'], 5);

        self::registerMeta();

        add_action('add_meta_boxes', [self::class, 'addMetaBox']);
        add_action('save_post', [self::class, 'saveMetaBox'], 10, 2);

        add_action(self::CRON_HOOK, [self::class, 'expireLapsed']);
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    public static function renderShortcode($atts, $content = ''): string
    {
        $atts = shortcode_atts([
            'tier'    => '',
            'require' => 'any',
            'message' => '',
        ], is_array($atts) ? $atts : [], 'walletup_members_only');

        // everyone, so rendering $content here would publish the very material the author
        if (!MembershipAccess::isEnabled()) {
            return self::gateDisabledNotice($atts['message']);
        }

        if (MembershipAccess::canView(self::parseTiers($atts['tier']), $atts['require'] === 'all' ? 'all' : 'any')) {
            return do_shortcode((string) $content);
        }
        return self::denialNotice($atts['message']);
    }

    /**
     * Shown in place of gated content when Member Access is switched off. Visitors get the
     * author's own message so a misconfiguration is indistinguishable from a normal denial.
     */
    private static function gateDisabledNotice(string $override = ''): string
    {
        if (current_user_can('manage_options')) {
            $notice = __('Member Access is switched off, so this members-only content stays hidden. Turn it on under Wallet Up → Memberships → Access. Only administrators can see this notice.', 'walletup');
            return '<p class="walletup-members-only walletup-gate-off">'
                . esc_html((string) apply_filters('walletup_membership_gate_off_notice', $notice))
                . '</p>';
        }
        return self::denialNotice($override);
    }

    public static function filterContent(string $content): string
    {
        $post = get_post();
        if (!$post || !is_singular() || !in_the_loop() || !is_main_query()) {
            return $content;
        }
        $required = self::requirementFor((int) $post->ID);
        if ($required === null || MembershipAccess::canView($required['tiers'], $required['mode'])) {
            return $content;
        }
        return self::denialNotice('');
    }

    public static function filterExcerpt(string $excerpt): string
    {
        $post = get_post();
        if (!$post) {
            return $excerpt;
        }
        $required = self::requirementFor((int) $post->ID);
        if ($required === null || MembershipAccess::canView($required['tiers'], $required['mode'])) {
            return $excerpt;
        }
        return '';
    }

    public static function filterRest($response, $post, $request)
    {
        if (!is_object($response) || !isset($response->data) || !is_object($post)) {
            return $response;
        }
        $required = self::requirementFor((int) $post->ID);
        if ($required === null || current_user_can('edit_post', (int) $post->ID)) {
            return $response;
        }
        if (MembershipAccess::canView($required['tiers'], $required['mode'])) {
            return $response;
        }
        foreach (['content', 'excerpt'] as $field) {
            if (isset($response->data[$field]) && is_array($response->data[$field])) {
                $response->data[$field]['rendered'] = self::denialNotice('');
                unset($response->data[$field]['raw'], $response->data[$field]['protected']);
            }
        }
        return $response;
    }

    /**
     * @return array{tiers:int[], mode:string}|null null when the post is not protected
     */
    public static function requirementFor(int $postId): ?array
    {
        if (!MembershipAccess::isEnabled()) {
            return null;
        }
        $on  = get_post_meta($postId, self::META_ON, true);
        $raw = get_post_meta($postId, self::META_TIERS, true);
        if ($on !== '1' && ($raw === '' || $raw === null)) {
            return null;
        }
        $tiers = self::parseTiers(is_array($raw) ? implode(',', $raw) : (string) $raw);
        $mode = get_post_meta($postId, self::META_MODE, true) === 'all' ? 'all' : 'any';
        return ['tiers' => $tiers, 'mode' => $mode];
    }

    /**
     * Post types this gate can genuinely keep private.
     *
     * A WooCommerce product publishes its description through the Store API, the block
     * hydration payload and its schema.org block, none of which pass through the_content —
     * protecting one here would hide the body on screen while leaving it in the page source.
     * Rather than offer protection we cannot honour, those types are left out until each of
     * their surfaces is covered. A site that has handled that itself can opt one back in.
     *
     * @return string[]
     */
    public static function protectableTypes(): array
    {
        $types = [];
        foreach (get_post_types(['show_ui' => true], 'objects') as $type) {
            if (is_post_type_viewable($type)) {
                $types[] = $type->name;
            }
        }
        $unsupported = ['product', 'product_variation'];
        $types = array_values(array_diff($types, $unsupported));

        /** @return string[] */
        return (array) apply_filters('walletup_membership_protectable_post_types', $types);
    }

    public static function filterFeed(string $content): string
    {
        $post = get_post();
        if (!$post) {
            return $content;
        }
        $required = self::requirementFor((int) $post->ID);
        if ($required === null || MembershipAccess::canView($required['tiers'], $required['mode'])) {
            return $content;
        }
        return wp_strip_all_tags(self::denialNotice(''));
    }

    public static function renderAccessForm($atts = []): string
    {
        if (!MembershipAccess::isEnabled() || !MembershipManager::tablesExist()) {
            return '';
        }
        $id = 'walup-ma-' . wp_generate_password(6, false, false);
        ob_start();
        ?>
        <form class="walletup-member-access" id="<?php echo esc_attr($id); ?>" method="post"
              data-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
              data-nonce="<?php echo esc_attr(wp_create_nonce('walup_membership_access_link')); ?>">
            <label for="<?php echo esc_attr($id); ?>-email">
                <?php esc_html_e('Already a member? Enter your email and we will send your access link.', 'walletup'); ?>
            </label>
            <input type="email" id="<?php echo esc_attr($id); ?>-email" name="email" required
                   autocomplete="email" placeholder="<?php esc_attr_e('you@example.com', 'walletup'); ?>" />
            <button type="submit"><?php esc_html_e('Email my access link', 'walletup'); ?></button>
            <p class="walletup-member-access__msg" role="status" aria-live="polite"></p>
        </form>
        <script>
        (function () {
            var f = document.getElementById(<?php echo wp_json_encode($id); ?>);
            if (!f) { return; }
            f.addEventListener('submit', function (e) {
                e.preventDefault();
                var msg = f.querySelector('.walletup-member-access__msg');
                var btn = f.querySelector('button');
                btn.disabled = true;
                var body = new URLSearchParams({
                    action: 'walup_membership_access_link',
                    nonce: f.dataset.nonce,
                    email: f.querySelector('input[name=email]').value
                });
                fetch(f.dataset.ajax, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body
                }).then(function (r) { return r.json(); }).then(function (j) {
                    msg.textContent = (j && j.data && j.data.message) ? j.data.message : '';
                    btn.disabled = false;
                }).catch(function () { btn.disabled = false; });
            });
        })();
        </script>
        <?php
        return (string) ob_get_clean();
    }

    private static function denialNotice(string $override): string
    {
        $settings = MembershipAccess::settings();

        $redirect = (int) $settings['redirect_page_id'];
        if ($redirect > 0 && !is_admin() && !wp_doing_ajax()) {
            $url = get_permalink($redirect);
            if ($url) {
                return sprintf(
                    '<p class="walletup-members-only"><a href="%s">%s</a></p>',
                    esc_url($url),
                    esc_html__('Join to read this', 'walletup')
                );
            }
        }

        $message = $override !== '' ? $override : (string) $settings['denied_message'];
        if ($message === '') {
            $message = __('This is for members. Join or sign in to read it.', 'walletup');
        }
        $message = (string) apply_filters('walletup_membership_denied_message', $message, $override);
        return '<p class="walletup-members-only">' . esc_html($message) . '</p>'
            . self::renderAccessForm();
    }

    private static function registerMeta(): void
    {
        $auth = static function ($allowed, $meta_key, $object_id) {
            return current_user_can('edit_post', (int) $object_id);
        };
        $fields = [
            self::META_ON    => static function ($v) { return $v === '1' || $v === 1 || $v === true ? '1' : ''; },
            self::META_TIERS => static function ($v) {
                $raw = is_array($v) ? $v : explode(',', (string) $v);
                $ids = array_filter(array_map('intval', $raw), static function ($n) { return $n > 0; });
                return implode(',', array_values(array_unique($ids)));
            },
            self::META_MODE  => static function ($v) { return $v === 'all' ? 'all' : 'any'; },
        ];

        foreach (self::protectableTypes() as $type) {
            foreach ($fields as $key => $sanitize) {
                register_post_meta($type, $key, [
                    'type'              => 'string',
                    'single'            => true,
                    'default'           => '',
                    'show_in_rest'      => true,
                    'sanitize_callback' => $sanitize,
                    'auth_callback'     => $auth,
                ]);
            }
        }
    }

    public static function addMetaBox(): void
    {
        if (!MembershipManager::tablesExist()) {
            return;
        }
        foreach (self::protectableTypes() as $type) {
            add_meta_box(
                'walletup-membership-access',
                __('Member access', 'walletup'),
                [self::class, 'renderMetaBox'],
                $type,
                'side'
            );
        }
    }

    public static function renderMetaBox($post): void
    {
        $required = get_post_meta((int) $post->ID, self::META_TIERS, true);
        $isProtected = get_post_meta((int) $post->ID, self::META_ON, true) === '1'
            || ($required !== '' && $required !== null);
        $selected = self::parseTiers(is_array($required) ? implode(',', $required) : (string) $required);
        $mode = get_post_meta((int) $post->ID, self::META_MODE, true) === 'all' ? 'all' : 'any';
        $tiers = MembershipManager::getInstance()->getTiers(true);

        wp_nonce_field('walletup_membership_access', 'walletup_membership_access_nonce');

        if (!MembershipAccess::isEnabled()) {
            printf(
                '<p style="margin:0 0 10px;padding:8px;background:#fcf9e8;border-left:3px solid #dba617;font-size:12px;">%s <a href="%s">%s</a></p>',
                esc_html__('Member Access is off, so this is not enforced yet.', 'walletup'),
                esc_url(admin_url('admin.php?page=wallet-up-memberships')),
                esc_html__('Turn it on', 'walletup')
            );
        }
        ?>
        <p>
            <label>
                <input type="checkbox" name="walletup_access_on" value="1" <?php checked($isProtected); ?> />
                <?php esc_html_e('Members only', 'walletup'); ?>
            </label>
        </p>
        <p class="description"><?php esc_html_e('Leave every tier unticked to allow any active member.', 'walletup'); ?></p>
        <?php foreach ($tiers as $tier) : ?>
            <p style="margin:2px 0">
                <label>
                    <input type="checkbox" name="walletup_access_tiers[]" value="<?php echo esc_attr((string) $tier['id']); ?>"
                        <?php checked(in_array((int) $tier['id'], $selected, true)); ?> />
                    <?php echo esc_html((string) $tier['name']); ?>
                </label>
            </p>
        <?php endforeach; ?>
        <p>
            <label for="walletup_access_mode"><?php esc_html_e('Requires', 'walletup'); ?></label>
            <select name="walletup_access_mode" id="walletup_access_mode">
                <option value="any" <?php selected($mode, 'any'); ?>><?php esc_html_e('Any ticked tier', 'walletup'); ?></option>
                <option value="all" <?php selected($mode, 'all'); ?>><?php esc_html_e('Every ticked tier', 'walletup'); ?></option>
            </select>
        </p>
        <?php
    }

    public static function saveMetaBox($postId, $post): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified on the next line
        $nonce = isset($_POST['walletup_membership_access_nonce'])
            ? sanitize_text_field(wp_unslash($_POST['walletup_membership_access_nonce']))
            : '';
        if (!wp_verify_nonce($nonce, 'walletup_membership_access')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', (int) $postId)) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above
        if (empty($_POST['walletup_access_on'])) {
            delete_post_meta((int) $postId, self::META_ON);
            delete_post_meta((int) $postId, self::META_TIERS);
            delete_post_meta((int) $postId, self::META_MODE);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above
        $tiers = isset($_POST['walletup_access_tiers']) ? (array) wp_unslash($_POST['walletup_access_tiers']) : [];
        $tiers = array_values(array_filter(array_map('intval', $tiers)));
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above
        $mode = isset($_POST['walletup_access_mode']) && $_POST['walletup_access_mode'] === 'all' ? 'all' : 'any';

        update_post_meta((int) $postId, self::META_ON, '1');
        update_post_meta((int) $postId, self::META_TIERS, implode(',', $tiers));
        update_post_meta((int) $postId, self::META_MODE, $mode);
    }

    public static function expireLapsed(): void
    {
        if (!MembershipManager::tablesExist()) {
            return;
        }
        global $wpdb;
        $table = MembershipManager::membersTable();
        $grace = max(0, (int) MembershipAccess::settings()['grace_days']);

        $lapsed = $wpdb->get_col($wpdb->prepare(
            "SELECT membership_id FROM {$table}
             WHERE status = 'active'
               AND expires_at IS NOT NULL
               AND expires_at <= DATE_SUB(NOW(), INTERVAL %d DAY)
             LIMIT 500",
            $grace
        ));
        foreach ((array) $lapsed as $membershipId) {
            \WalletUpPro\Core\Tenancy\Tenant::with(\WalletUpPro\Core\Tenancy\Tenant::ofMember((string) $membershipId), static function () use ($wpdb, $table, $membershipId): void {
                $wpdb->update($table, ['status' => 'expired'], ['membership_id' => $membershipId]);
                MembershipAccess::revoke((string) $membershipId);
            });
        }
    }

    /** @return int[] */
    private static function parseTiers(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }
        $out = [];
        foreach (explode(',', $raw) as $piece) {
            $piece = trim($piece);
            if ($piece === '') {
                continue;
            }
            if (ctype_digit($piece)) {
                $out[] = (int) $piece;
                continue;
            }
            foreach (MembershipManager::getInstance()->getTiers(false) as $tier) {
                if (strcasecmp((string) $tier['tier_key'], $piece) === 0) {
                    $out[] = (int) $tier['id'];
                    break;
                }
            }
        }
        return array_values(array_unique($out));
    }
}
