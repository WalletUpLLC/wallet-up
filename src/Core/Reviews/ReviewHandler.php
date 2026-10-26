<?php
namespace WalletUpPro\Core\Reviews;

if (!defined('ABSPATH')) {
    exit;
}

class ReviewHandler
{
    private const RATE_LIMIT = 5;      // submissions per IP per window
    private const RATE_WINDOW = 300;   // seconds

    public static function init(): void
    {
        $public = [
            'wallet_up_submit_review'        => 'handleSubmit',
            'wallet_up_get_approved_reviews' => 'handleGetApproved',
        ];
        foreach ($public as $action => $method) {
            add_action("wp_ajax_{$action}", [__CLASS__, $method]);
            add_action("wp_ajax_nopriv_{$action}", [__CLASS__, $method]);
        }
        $admin = [
            'wallet_up_get_reviews'    => 'handleList',
            'wallet_up_moderate_review' => 'handleModerate',
        ];
        foreach ($admin as $action => $method) {
            add_action("wp_ajax_{$action}", [__CLASS__, $method]);
        }

        add_shortcode('walletup_reviews', [__CLASS__, 'renderShortcode']);
    }

    private static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'wallet_up_reviews';
    }

    private static function checkRateLimit(): bool
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '0.0.0.0';
        $key = 'wallet_up_review_rate_' . md5($ip);
        $count = get_transient($key);
        if ($count === false) {
            set_transient($key, 1, self::RATE_WINDOW);
            return true;
        }
        if ((int) $count >= self::RATE_LIMIT) {
            return false;
        }
        set_transient($key, (int) $count + 1, self::RATE_WINDOW);
        return true;
    }

    private static function verifyAdminNonce(): bool
    {
        if (check_ajax_referer('wallet_up_admin_nonce', 'nonce', false) !== false) {
            return true;
        }
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? $_REQUEST['nonce'] ?? ''));
        return !empty($nonce) && wp_verify_nonce($nonce, 'wallet_up_admin_nonce') !== false;
    }

    public static function handleSubmit(): void
    {
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        $nonceOk = wp_verify_nonce($nonce, 'walletup_review_submit')
            || wp_verify_nonce($nonce, 'walup_get_form_config')
            || wp_verify_nonce($nonce, 'wallet_up_admin_nonce');
        if (!$nonceOk) {
            wp_send_json_error(['message' => __('Invalid security token. Please refresh the page.', 'walletup')], 403);
        }

        if (!empty($_POST['website']) || !empty($_POST['hp'])) {
            wp_send_json_success(['message' => __('Thank you for your review!', 'walletup')]);
        }

        if (!self::checkRateLimit()) {
            wp_send_json_error(['message' => __('Too many submissions. Please try again later.', 'walletup')], 429);
        }

        $content = sanitize_textarea_field(wp_unslash($_POST['content'] ?? ''));
        if (trim($content) === '') {
            wp_send_json_error(['message' => __('Please write a short review.', 'walletup')]);
        }
        $rating = (int) ($_POST['rating'] ?? 5);
        $rating = max(1, min(5, $rating));
        $email  = sanitize_email(wp_unslash($_POST['donor_email'] ?? ''));

        global $wpdb;
        $ok = $wpdb->insert(self::table(), [
            'review_id'   => 'rev_' . wp_generate_uuid4(),
            'donation_id' => sanitize_text_field(wp_unslash($_POST['donation_id'] ?? '')),
            'campaign_id' => sanitize_text_field(wp_unslash($_POST['campaign_id'] ?? '')),
            'form_id'     => sanitize_text_field(wp_unslash($_POST['form_id'] ?? '')),
            'donor_name'  => sanitize_text_field(wp_unslash($_POST['donor_name'] ?? '')),
            'donor_email' => is_email($email) ? $email : '',
            'rating'      => $rating,
            'title'       => sanitize_text_field(wp_unslash($_POST['title'] ?? '')),
            'content'     => $content,
            'status'      => 'pending',
            'donor_ip'    => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
            'created_at'  => current_time('mysql'),
        ]);

        if ($ok === false) {
            wp_send_json_error(['message' => __('Could not save your review. Please try again.', 'walletup')]);
        }
        wp_send_json_success(['message' => __('Thank you! Your review will appear once approved.', 'walletup')]);
    }

    public static function handleGetApproved(): void
    {
        global $wpdb;
        $table = self::table();
        $campaignId = sanitize_text_field(wp_unslash($_POST['campaign_id'] ?? $_GET['campaign_id'] ?? ''));
        $formId     = sanitize_text_field(wp_unslash($_POST['form_id'] ?? $_GET['form_id'] ?? ''));
        $limit      = max(1, min(50, (int) ($_POST['limit'] ?? $_GET['limit'] ?? 10)));

        wp_send_json_success(['reviews' => self::approvedReviews($campaignId, $formId, $limit)]);
    }

    public static function approvedReviews(string $campaignId = '', string $formId = '', int $limit = 10): array
    {
        global $wpdb;
        $table = self::table();
        $where = "status = 'approved'";
        $params = [];
        if ($campaignId !== '') { $where .= ' AND campaign_id = %s'; $params[] = $campaignId; }
        if ($formId !== '')     { $where .= ' AND form_id = %s';     $params[] = $formId; }
        $params[] = $limit;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table from prefix; values prepared.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT review_id, donor_name, rating, title, content, is_featured, created_at
             FROM {$table} WHERE {$where}
             ORDER BY is_featured DESC, created_at DESC LIMIT %d",
            ...$params
        ), ARRAY_A);

        return array_map(function ($r) {
            return [
                'id'          => $r['review_id'],
                'donor_name'  => $r['donor_name'] !== '' ? $r['donor_name'] : __('Anonymous', 'walletup'),
                'rating'      => (int) $r['rating'],
                'title'       => $r['title'],
                'content'     => $r['content'],
                'is_featured' => (bool) $r['is_featured'],
                'date'        => $r['created_at'],
            ];
        }, (array) $rows);
    }

    public static function handleList(): void
    {
        if (!self::verifyAdminNonce()) {
            wp_send_json_error(['message' => __('Invalid security token. Please refresh the page.', 'walletup')], 403);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized', 'walletup')], 403);
        }

        global $wpdb;
        $table = self::table();
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified above.
        $status = sanitize_text_field(wp_unslash($_POST['status'] ?? 'all'));
        $page   = max(1, (int) ($_POST['page'] ?? 1));
        $limit  = max(1, min(100, (int) ($_POST['limit'] ?? 20)));
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        $offset = ($page - 1) * $limit;

        $where = '1=1';
        $params = [];
        if ($status !== 'all' && in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $where .= ' AND status = %s';
            $params[] = $status;
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table from prefix; values prepared.
        $listParams = array_merge($params, [$limit, $offset]);
        $reviews = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
            ...$listParams
        ), ARRAY_A);
        $total = (int) $wpdb->get_var(
            $params
                ? $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where}", ...$params)
                : "SELECT COUNT(*) FROM {$table} WHERE {$where}"
        );
        $counts = $wpdb->get_row("SELECT
            SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status='rejected' THEN 1 ELSE 0 END) as rejected,
            COUNT(*) as total,
            COALESCE(AVG(CASE WHEN status='approved' THEN rating ELSE NULL END),0) as avg_rating
            FROM {$table}", ARRAY_A);
        // phpcs:enable

        $campaignNames = [];
        foreach ((array) get_option('wallet_up_campaigns', []) as $c) {
            if (!empty($c['id'])) {
                $campaignNames[$c['id']] = $c['name'] ?? $c['title'] ?? $c['id'];
            }
        }
        $formNames = [];
        foreach ((array) get_option('wallet_up_donation_forms', []) as $f) {
            if (!empty($f['id'])) {
                $formNames[$f['id']] = $f['name'] ?? $f['title'] ?? $f['id'];
            }
        }

        wp_send_json_success([
            'reviews' => array_map(function ($r) use ($campaignNames, $formNames) {
                $source = ['type' => 'site', 'id' => '', 'label' => __('Website', 'walletup')];
                if (!empty($r['campaign_id'])) {
                    $source = ['type' => 'campaign', 'id' => $r['campaign_id'], 'label' => $campaignNames[$r['campaign_id']] ?? $r['campaign_id']];
                } elseif (!empty($r['form_id'])) {
                    $source = ['type' => 'form', 'id' => $r['form_id'], 'label' => $formNames[$r['form_id']] ?? $r['form_id']];
                } elseif (!empty($r['donation_id'])) {
                    $source = ['type' => 'donation', 'id' => $r['donation_id'], 'label' => $r['donation_id']];
                }
                return [
                    'id'          => $r['review_id'],
                    'donor_name'  => $r['donor_name'],
                    'donor_email' => $r['donor_email'],
                    'rating'      => (int) $r['rating'],
                    'title'       => $r['title'],
                    'content'     => $r['content'],
                    'status'      => $r['status'],
                    'is_featured' => (bool) $r['is_featured'],
                    'campaign_id' => $r['campaign_id'],
                    'form_id'     => $r['form_id'],
                    'donation_id' => $r['donation_id'],
                    'source'      => $source,
                    'created_at'  => $r['created_at'],
                ];
            }, (array) $reviews),
            'total' => $total,
            'pages' => (int) ceil($total / $limit),
            'page'  => $page,
            'counts' => [
                'pending'    => (int) ($counts['pending'] ?? 0),
                'approved'   => (int) ($counts['approved'] ?? 0),
                'rejected'   => (int) ($counts['rejected'] ?? 0),
                'total'      => (int) ($counts['total'] ?? 0),
                'avg_rating' => round((float) ($counts['avg_rating'] ?? 0), 1),
            ],
        ]);
    }

    public static function handleModerate(): void
    {
        if (!self::verifyAdminNonce()) {
            wp_send_json_error(['message' => __('Invalid security token. Please refresh the page.', 'walletup')], 403);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized', 'walletup')], 403);
        }

        global $wpdb;
        $table = self::table();
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified above.
        $reviewId = sanitize_text_field(wp_unslash($_POST['review_id'] ?? ''));
        $action   = sanitize_text_field(wp_unslash($_POST['moderation_action'] ?? ''));
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        if ($reviewId === '') {
            wp_send_json_error(['message' => __('Missing review id', 'walletup')]);
        }

        switch ($action) {
            case 'approve':
                $wpdb->update($table, ['status' => 'approved', 'approved_at' => current_time('mysql')], ['review_id' => $reviewId]);
                break;
            case 'reject':
                $wpdb->update($table, ['status' => 'rejected'], ['review_id' => $reviewId]);
                break;
            case 'feature':
                $wpdb->update($table, ['is_featured' => 1, 'status' => 'approved', 'approved_at' => current_time('mysql')], ['review_id' => $reviewId]);
                break;
            case 'unfeature':
                $wpdb->update($table, ['is_featured' => 0], ['review_id' => $reviewId]);
                break;
            case 'delete':
                $wpdb->delete($table, ['review_id' => $reviewId]);
                break;
            default:
                wp_send_json_error(['message' => __('Unknown action', 'walletup')]);
        }

        wp_send_json_success(['message' => __('Review updated', 'walletup')]);
    }

    public static function renderShortcode($atts): string
    {
        $atts = shortcode_atts([
            'campaign_id' => '',
            'form_id'     => '',
            'limit'       => 10,
            'show_form'   => 'yes', // set to 'no' to display reviews only
        ], $atts, 'walletup_reviews');

        $reviews  = self::approvedReviews((string) $atts['campaign_id'], (string) $atts['form_id'], (int) $atts['limit']);
        $showForm = !in_array(strtolower((string) $atts['show_form']), ['no', '0', 'false'], true);
        $uid      = 'walup-reviews-' . wp_unique_id();

        $out = '<div class="walup-reviews-wrap" id="' . esc_attr($uid) . '">';

        if (!empty($reviews)) {
            $out .= '<div class="walup-reviews">';
            foreach ($reviews as $r) {
                $stars = str_repeat('★', $r['rating']) . str_repeat('☆', 5 - $r['rating']);
                $out .= '<div class="walup-review">';
                $out .= '<div class="walup-review-stars" aria-label="' . esc_attr($r['rating']) . '/5">' . esc_html($stars) . '</div>';
                if (($r['title'] ?? '') !== '') {
                    $out .= '<div class="walup-review-title">' . esc_html($r['title']) . '</div>';
                }
                $out .= '<div class="walup-review-content">' . esc_html($r['content']) . '</div>';
                $out .= '<div class="walup-review-author">— ' . esc_html($r['donor_name']) . '</div>';
                $out .= '</div>';
            }
            $out .= '</div>';
        }

        if ($showForm) {
            $ajaxUrl = esc_url(admin_url('admin-ajax.php'));
            $nonce   = esc_attr(wp_create_nonce('walletup_review_submit'));
            $cid     = esc_attr((string) $atts['campaign_id']);
            $fid     = esc_attr((string) $atts['form_id']);
            $out .= '<form class="walup-review-form" data-ajax="' . $ajaxUrl . '" data-nonce="' . $nonce . '" data-campaign="' . $cid . '" data-form="' . $fid . '">';
            $out .= '<h4 class="walup-review-form-title">' . esc_html__('Leave a Review', 'walletup') . '</h4>';
            $out .= '<div class="walup-review-rating" role="radiogroup" aria-label="' . esc_attr__('Rating', 'walletup') . '">';
            for ($i = 1; $i <= 5; $i++) {
                $out .= '<button type="button" class="walup-star" data-val="' . $i . '" aria-label="' . esc_attr($i) . '">★</button>';
            }
            $out .= '</div><input type="hidden" name="rating" value="5">';
            $out .= '<input type="text" name="donor_name" placeholder="' . esc_attr__('Your name', 'walletup') . '" required>';
            $out .= '<input type="email" name="donor_email" placeholder="' . esc_attr__('Email (optional)', 'walletup') . '">';
            $out .= '<input type="text" name="title" placeholder="' . esc_attr__('Headline (optional)', 'walletup') . '">';
            $out .= '<textarea name="content" rows="4" placeholder="' . esc_attr__('Share your experience…', 'walletup') . '" required></textarea>';
            $out .= '<input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">';
            $out .= '<button type="submit" class="walup-review-submit">' . esc_html__('Submit Review', 'walletup') . '</button>';
            $out .= '<div class="walup-review-msg" role="status" aria-live="polite"></div>';
            $out .= '</form>';
            add_action('wp_footer', [__CLASS__, 'printFormAssets']);
        }

        $out .= '</div>';
        return $out;
    }

    public static function printFormAssets(): void
    {
        static $printed = false;
        if ($printed) {
            return;
        }
        $printed = true;
        $submitting = esc_js(__('Submitting…', 'walletup'));
        $submit     = esc_js(__('Submit Review', 'walletup'));
        $failed     = esc_js(__('Could not submit. Please try again.', 'walletup'));
        ?>
<style>
.walup-reviews-wrap{max-width:640px;margin:0 auto}
.walup-review{padding:12px 0;border-bottom:1px solid #eee}
.walup-review-stars{color:#f5a623;letter-spacing:2px}
.walup-review-title{font-weight:600;margin:4px 0}
.walup-review-author{color:#64748b;font-size:13px;margin-top:4px}
.walup-review-form{margin-top:20px;padding:20px;border:1px solid #e2e8f0;border-radius:12px;background:#fff}
.walup-review-form input,.walup-review-form textarea{display:block;width:100%;margin:8px 0;padding:10px;border:1px solid #cbd5e1;border-radius:8px;box-sizing:border-box}
.walup-review-rating{display:flex;gap:4px;font-size:26px}
.walup-review-rating .walup-star{background:none;border:none;cursor:pointer;color:#cbd5e1;padding:0;line-height:1}
.walup-review-rating .walup-star.on{color:#f5a623}
.walup-review-submit{background:#5B45B0;color:#fff;border:none;border-radius:8px;padding:10px 18px;cursor:pointer;font-weight:600}
.walup-review-submit:disabled{opacity:.6;cursor:default}
.walup-review-msg{margin-top:10px;font-size:14px}
</style>
<script>
(function(){
  document.addEventListener('click',function(e){
    var star=e.target.closest('.walup-review-rating .walup-star'); if(!star)return;
    var group=star.parentElement, val=parseInt(star.getAttribute('data-val'),10);
    group.parentElement.querySelector('input[name="rating"]').value=val;
    group.querySelectorAll('.walup-star').forEach(function(s){ s.classList.toggle('on', parseInt(s.getAttribute('data-val'),10)<=val); });
  });
  document.addEventListener('submit',function(e){
    var form=e.target.closest('.walup-review-form'); if(!form)return; e.preventDefault();
    var btn=form.querySelector('.walup-review-submit'), msg=form.querySelector('.walup-review-msg');
    btn.disabled=true; btn.textContent='<?php echo $submitting; ?>';
    var fd=new FormData(form);
    fd.append('action','wallet_up_submit_review');
    fd.append('nonce',form.getAttribute('data-nonce'));
    fd.append('campaign_id',form.getAttribute('data-campaign')||'');
    fd.append('form_id',form.getAttribute('data-form')||'');
    fetch(form.getAttribute('data-ajax'),{method:'POST',credentials:'same-origin',body:fd})
      .then(function(r){return r.json();})
      .then(function(j){
        msg.textContent=(j&&j.data&&j.data.message)?j.data.message:'<?php echo $submit; ?>';
        msg.style.color=(j&&j.success)?'#16a34a':'#dc2626';
        if(j&&j.success){ form.reset(); form.querySelectorAll('.walup-star').forEach(function(s){s.classList.remove('on');}); }
        btn.disabled=false; btn.textContent='<?php echo $submit; ?>';
      })
      .catch(function(){ msg.textContent='<?php echo $failed; ?>'; msg.style.color='#dc2626'; btn.disabled=false; btn.textContent='<?php echo $submit; ?>'; });
  });
})();
</script>
        <?php
    }
}
