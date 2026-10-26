<?php
namespace WalletUpPro\Core\Memberships;

use WalletUpPro\Core\BaseSingleton;

if (!defined('ABSPATH')) {
    exit;
}

class MembershipManager extends BaseSingleton
{
    const SCHEMA_VERSION = '2';
    const SCHEMA_OPTION  = 'wallet_up_membership_schema_version';

    const INTERVALS = [
        'monthly'  => 'monthly',
        'yearly'   => 'yearly',
        'lifetime' => '',
    ];

    private string $tiers;
    private string $members;

    protected function initialize(): void
    {
        global $wpdb;
        $this->tiers   = $wpdb->prefix . 'walletup_membership_tiers';
        $this->members = $wpdb->prefix . 'walletup_memberships';

        add_action('walletup_recurring_payment_recorded', [$this, 'onRecurringPayment'], 10, 2);
        add_action('walletup_recurring_payment_failed', [$this, 'onRecurringFailed'], 10, 1);
        add_action('walletup_subscription_cancelled', [$this, 'onSubscriptionCancelled'], 10, 1);
    }

    public static function tiersTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'walletup_membership_tiers';
    }

    public static function membersTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'walletup_memberships';
    }

    public static function tablesExist(): bool
    {
        global $wpdb;
        $t = self::tiersTable();
        $m = self::membersTable();
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) === $t
            && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $m)) === $m;
    }

    public static function schemaCurrent(): bool
    {
        return get_option(self::SCHEMA_OPTION) === self::SCHEMA_VERSION && self::tablesExist();
    }

    public static function createTable(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $tiers   = self::tiersTable();
        $members = self::membersTable();

        dbDelta("CREATE TABLE {$tiers} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tier_key VARCHAR(64) NOT NULL,
            name VARCHAR(191) NOT NULL,
            description TEXT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            currency VARCHAR(3) NOT NULL DEFAULT 'USD',
            billing_interval VARCHAR(20) NOT NULL DEFAULT 'yearly',
            benefits TEXT NULL,
            badge_color VARCHAR(20) NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            metadata TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY tier_key (tier_key),
            KEY active (active)
        ) {$charset};");

        dbDelta("CREATE TABLE {$members} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            membership_id VARCHAR(50) NOT NULL,
            tier_id BIGINT UNSIGNED NOT NULL,
            member_name VARCHAR(191) NULL,
            member_email VARCHAR(191) NOT NULL,
            member_phone VARCHAR(40) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            currency VARCHAR(3) NOT NULL DEFAULT 'USD',
            billing_interval VARCHAR(20) NOT NULL DEFAULT 'yearly',
            is_recurring TINYINT(1) NOT NULL DEFAULT 0,
            subscription_id VARCHAR(64) NULL,
            user_id BIGINT UNSIGNED NULL,
            access_token_hash CHAR(64) NULL,
            started_at DATETIME NULL,
            expires_at DATETIME NULL,
            last_payment_at DATETIME NULL,
            metadata TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY membership_id (membership_id),
            KEY member_email (member_email),
            KEY tier_id (tier_id),
            KEY status (status),
            KEY subscription_id (subscription_id),
            KEY user_id (user_id),
            KEY access_token_hash (access_token_hash),
            KEY expires_at (expires_at)
        ) {$charset};");

        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, false);
    }

    public function ensureDefaultTiers(): void
    {
        global $wpdb;
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->tiers}");
        if ($count > 0) {
            return;
        }
        $now = current_time('mysql');
        $defaults = [
            ['supporter', __('Supporter', 'walletup'), 5.00, 'monthly',  '#7c3aed'],
            ['advocate',  __('Advocate', 'walletup'),  50.00, 'yearly',   '#2563eb'],
            ['patron',    __('Patron', 'walletup'),    500.00, 'lifetime', '#059669'],
        ];
        foreach ($defaults as $i => [$key, $name, $amount, $interval, $color]) {
            $wpdb->insert($this->tiers, \WalletUpPro\Core\Tenancy\Tenant::stamp([
                'tier_key' => $key,
                'name' => $name,
                'description' => '',
                'amount' => $amount,
                'currency' => 'USD',
                'billing_interval' => $interval,
                'benefits' => wp_json_encode([]),
                'badge_color' => $color,
                'active' => 1,
                'sort_order' => $i,
                'metadata' => wp_json_encode([]),
                'created_at' => $now,
                'updated_at' => $now,
            ], $this->tiers));
        }
    }

    public function getTiers(bool $activeOnly = false): array
    {
        global $wpdb;
        $where = 'WHERE 1=1' . ($activeOnly ? ' AND active = 1' : '') . \WalletUpPro\Core\Tenancy\Tenant::where();
        $rows = $wpdb->get_results("SELECT * FROM {$this->tiers} {$where} ORDER BY sort_order ASC, id ASC", ARRAY_A);
        return array_map([$this, 'shapeTier'], $rows ?: []);
    }

    public function getTier(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tiers} WHERE id = %d", $id), ARRAY_A);
        return $row ? $this->shapeTier($row) : null;
    }

    public function saveTier(array $data): array
    {
        global $wpdb;
        $now = current_time('mysql');
        $interval = isset(self::INTERVALS[$data['billing_interval'] ?? '']) ? $data['billing_interval'] : 'yearly';
        $fields = [
            'name' => sanitize_text_field($data['name'] ?? ''),
            'description' => wp_kses_post($data['description'] ?? ''),
            'amount' => round((float) ($data['amount'] ?? 0), 2),
            'currency' => strtoupper(substr(sanitize_text_field($data['currency'] ?? 'USD'), 0, 3)),
            'billing_interval' => $interval,
            'benefits' => wp_json_encode(array_values(array_filter(array_map('sanitize_text_field', (array) ($data['benefits'] ?? []))))),
            'badge_color' => sanitize_hex_color($data['badge_color'] ?? '') ?: '#7c3aed',
            'active' => !empty($data['active']) ? 1 : 0,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'updated_at' => $now,
        ];
        if ($fields['name'] === '') {
            return ['success' => false, 'error' => __('Tier name is required.', 'walletup')];
        }

        $id = (int) ($data['id'] ?? 0);
        if ($id > 0) {
            $wpdb->update($this->tiers, $fields, ['id' => $id]);
        } else {
            $fields['tier_key'] = $this->uniqueTierKey($fields['name']);
            $fields['metadata'] = wp_json_encode([]);
            $fields['created_at'] = $now;
            $wpdb->insert($this->tiers, \WalletUpPro\Core\Tenancy\Tenant::stamp($fields, $this->tiers));
            $id = (int) $wpdb->insert_id;
        }
        return ['success' => true, 'tier' => $this->getTier($id)];
    }

    public function deleteTier(int $id): array
    {
        global $wpdb;
        $wpdb->update($this->tiers, ['active' => 0, 'updated_at' => current_time('mysql')], ['id' => $id]);
        return ['success' => true];
    }

    public function enroll(array $data): array
    {
        global $wpdb;
        $tier = $this->getTier((int) ($data['tier_id'] ?? 0));
        if (!$tier || !$tier['active']) {
            return ['success' => false, 'error' => __('Selected membership tier is unavailable.', 'walletup')];
        }
        $email = sanitize_email($data['member_email'] ?? '');
        if (!is_email($email)) {
            return ['success' => false, 'error' => __('A valid email is required.', 'walletup')];
        }
        \WalletUpPro\Core\Tenancy\Tenant::enterTier((int) $tier['id']);
        $membershipId = 'mem_' . wp_generate_uuid4();
        $recurring = $tier['billing_interval'] !== 'lifetime';
        $wpdb->insert($this->members, \WalletUpPro\Core\Tenancy\Tenant::stamp([
            'membership_id' => $membershipId,
            'tier_id' => (int) $tier['id'],
            'member_name' => sanitize_text_field($data['member_name'] ?? ''),
            'member_email' => $email,
            'member_phone' => sanitize_text_field($data['member_phone'] ?? ''),
            'status' => 'pending',
            'amount' => $tier['amount'],
            'currency' => $tier['currency'],
            'billing_interval' => $tier['billing_interval'],
            'is_recurring' => $recurring ? 1 : 0,
            'metadata' => wp_json_encode((array) ($data['metadata'] ?? [])),
            'created_at' => current_time('mysql'),
        ], $this->members));
        return ['success' => true, 'membership_id' => $membershipId, 'tier' => $tier, 'is_recurring' => $recurring];
    }

    public function discardPending(string $membershipId): bool
    {
        global $wpdb;
        return (bool) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->members} WHERE membership_id = %s AND status = 'pending' AND (subscription_id IS NULL OR subscription_id = '')",
            $membershipId
        ));
    }

    public function getMembership(string $membershipId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->members} WHERE membership_id = %s", $membershipId), ARRAY_A);
        return $row ? $this->shapeMember($row) : null;
    }

    public function setSubscriptionId(string $membershipId, string $subscriptionId): void
    {
        if ($subscriptionId === '') {
            return;
        }
        global $wpdb;
        $wpdb->update(
            $this->members,
            ['subscription_id' => sanitize_text_field($subscriptionId)],
            ['membership_id' => $membershipId]
        );
    }

    public function activate(string $membershipId, array $opts = []): bool
    {
        global $wpdb;
        $m = $this->getMembership($membershipId);
        if (!$m) {
            return false;
        }
        $now = current_time('mysql');
        $update = [
            'status' => 'active',
            'started_at' => $m['started_at'] ?: $now,
            'expires_at' => $this->computeExpiry($m['billing_interval'], $now),
            'last_payment_at' => $now,
        ];
        if (!empty($opts['subscription_id'])) {
            $update['subscription_id'] = sanitize_text_field($opts['subscription_id']);
        }
        $wpdb->update($this->members, $update, ['membership_id' => $membershipId]);
        MembershipAccess::grant($membershipId);
        $this->email('membership_welcome', $this->getMembership($membershipId));
        return true;
    }

    public function activateFromPayment(string $membershipId, $amount, $currency): bool
    {
        $m = $this->getMembership($membershipId);
        if (!$m || $m['status'] === 'active' || $m['awaiting_confirmation']) {
            return false;
        }
        if (!is_numeric($amount) || strtolower((string) $currency) !== strtolower((string) $m['currency'])) {
            return false;
        }
        $paid = \WalletUpPro\Core\Payments\PaymentGatewayHandler::toMinorUnits((float) $amount, (string) $m['currency']);
        $due = \WalletUpPro\Core\Payments\PaymentGatewayHandler::toMinorUnits((float) $m['amount'], (string) $m['currency']);
        if ($paid < $due) {
            return false;
        }
        return $this->activate($membershipId);
    }

    public function findWalletClaim(int $tierId, string $email): string
    {
        global $wpdb;
        if ($email === '') {
            return '';
        }
        return (string) $wpdb->get_var($wpdb->prepare(
            "SELECT membership_id FROM {$this->members} WHERE tier_id = %d AND member_email = %s AND status = 'pending' AND metadata LIKE %s",
            $tierId,
            $email,
            '%' . $wpdb->esc_like('"awaiting":"' . MembershipWallets::AWAITING . '"') . '%'
        ) . \WalletUpPro\Core\Tenancy\Tenant::where() . ' ORDER BY id DESC LIMIT 1');
    }

    public function confirmWalletPayment(string $membershipId): bool
    {
        $m = $this->getMembership($membershipId);
        if (!$m || $m['status'] !== 'pending' || !$m['awaiting_confirmation']) {
            return false;
        }
        return $this->activate($membershipId);
    }

    public function storeMethodLabel(string $membershipId, string $intentId): void
    {
        if (!class_exists('\\WalletUpPro\\Core\\Config\\StripeMethodCatalog') || strpos($intentId, 'pi_') !== 0) {
            return;
        }
        $label = \WalletUpPro\Core\Config\StripeMethodCatalog::labelForIntent($intentId);
        if ($label === '') {
            return;
        }
        $m = $this->getMembership($membershipId);
        if (!$m) {
            return;
        }
        $meta = json_decode((string) ($m['metadata'] ?? '{}'), true);
        if (!is_array($meta)) {
            $meta = [];
        }
        $meta['method_label'] = $label;
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write operation.
        $wpdb->update($this->members, ['metadata' => wp_json_encode($meta)], ['membership_id' => $membershipId]);
    }

    public function recordLedgerPayment(string $membershipId, string $intentId): void
    {
        $this->storeMethodLabel($membershipId, $intentId);
        if (!class_exists('\\WalletUpPro\\Services\\PaymentProcessor') || strpos($intentId, 'pi_') !== 0) {
            return;
        }
        $m = $this->getMembership($membershipId);
        if (!$m) {
            return;
        }
        $tier = $this->getTier((int) ($m['tier_id'] ?? 0));
        if (!$tier) {
            return;
        }
        $currency = (string) ($tier['currency'] ?? 'USD');
        $amountMinor = \WalletUpPro\Core\Payments\PaymentGatewayHandler::toMinorUnits((float) ($tier['amount'] ?? 0), $currency);
        $label = class_exists('\\WalletUpPro\\Core\\Config\\StripeMethodCatalog')
            ? \WalletUpPro\Core\Config\StripeMethodCatalog::labelForIntent($intentId) : '';
        \WalletUpPro\Services\PaymentProcessor::getInstance()->recordCompletedPayment([
            'provider'                => 'stripe',
            'method'                  => 'stripe',
            'provider_transaction_id' => $intentId,
            'amount_minor'            => $amountMinor,
            'currency'                => $currency,
            'description'             => 'Membership: ' . (string) ($tier['name'] ?? ''),
            'customer'                => [
                'email' => (string) ($m['member_email'] ?? ''),
                'first' => (string) ($m['member_name'] ?? ''),
            ],
            'metadata'                => [
                'source'        => 'membership',
                'membership_id' => $membershipId,
                'method_label'  => $label,
            ],
        ]);
    }

    public function onRecurringPayment($sub, $paymentData): void
    {
        if (!is_object($sub) || ($sub->source_type ?? '') !== 'membership') {
            return;
        }
        global $wpdb;
        $m = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->members} WHERE subscription_id = %s", $sub->subscription_id), ARRAY_A);
        if (!$m) {
            return;
        }
        $now = current_time('mysql');
        $base = (!empty($m['expires_at']) && strtotime($m['expires_at']) > time()) ? $m['expires_at'] : $now;
        $wpdb->update($this->members, [
            'status' => 'active',
            'expires_at' => $this->computeExpiry($m['billing_interval'], $base),
            'last_payment_at' => $now,
        ], ['id' => $m['id']]);
        MembershipAccess::grant((string) $m['membership_id']);
        $this->email('membership_renewed', $this->shapeMember(array_merge($m, ['last_payment_at' => $now])));
    }

    public function onRecurringFailed($sub): void
    {
        if (!is_object($sub) || ($sub->source_type ?? '') !== 'membership') {
            return;
        }
        global $wpdb;
        $wpdb->update($this->members, ['status' => 'past_due'], ['subscription_id' => $sub->subscription_id]);
        $this->syncAccessForSubscription((string) $sub->subscription_id);
    }

    public function onSubscriptionCancelled($sub): void
    {
        if (!is_object($sub) || ($sub->source_type ?? '') !== 'membership') {
            return;
        }
        global $wpdb;
        $wpdb->update($this->members, ['status' => 'cancelled'], ['subscription_id' => $sub->subscription_id]);
        $this->syncAccessForSubscription((string) $sub->subscription_id);
    }

    private function syncAccessForSubscription(string $subscriptionId): void
    {
        global $wpdb;
        $membershipId = $wpdb->get_var($wpdb->prepare(
            "SELECT membership_id FROM {$this->members} WHERE subscription_id = %s",
            $subscriptionId
        ));
        if ($membershipId) {
            MembershipAccess::revoke((string) $membershipId);
        }
    }

    public function cancel(string $membershipId): array
    {
        global $wpdb;
        $wpdb->update($this->members, ['status' => 'cancelled'], ['membership_id' => $membershipId]);
        MembershipAccess::revoke($membershipId);
        return ['success' => true];
    }

    /** @return array{0:string,1:array} [where clause, prepared params] shared by list + count so both filter identically. */
    private function memberWhere(array $filters): array
    {
        global $wpdb;
        $where = [];
        $params = [];
        $tenantScope = \WalletUpPro\Core\Tenancy\Tenant::scope();
        if ($tenantScope !== '') {
            $where[] = 'm.tenant_id = %s';
            $params[] = $tenantScope;
        }
        if (!empty($filters['status'])) {
            $where[] = 'm.status = %s';
            $params[] = sanitize_text_field($filters['status']);
        }
        if (!empty($filters['tier_id'])) {
            $where[] = 'm.tier_id = %d';
            $params[] = (int) $filters['tier_id'];
        }
        if (!empty($filters['search'])) {
            $like = '%' . $wpdb->esc_like(sanitize_text_field($filters['search'])) . '%';
            $where[] = '(m.member_email LIKE %s OR m.member_name LIKE %s OR m.membership_id LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        return [$where ? ('WHERE ' . implode(' AND ', $where)) : '', $params];
    }

    public function getMembers(array $filters = []): array
    {
        global $wpdb;
        [$clause, $params] = $this->memberWhere($filters);
        $limit  = min(200, max(1, (int) ($filters['per_page'] ?? 25)));
        $page   = max(1, (int) ($filters['page'] ?? 1));
        $offset = ($page - 1) * $limit;
        $sql = "SELECT m.*, t.name AS tier_name, t.badge_color AS tier_color
                FROM {$this->members} m LEFT JOIN {$this->tiers} t ON t.id = m.tier_id
                {$clause} ORDER BY m.created_at DESC LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        return array_map([$this, 'shapeMember'], $rows ?: []);
    }

    public function countMembers(array $filters = []): int
    {
        global $wpdb;
        [$clause, $params] = $this->memberWhere($filters);
        $sql = "SELECT COUNT(*) FROM {$this->members} m {$clause}";
        return (int) ($params ? $wpdb->get_var($wpdb->prepare($sql, $params)) : $wpdb->get_var($sql));
    }

    public function getMemberDetail(string $membershipId): ?array
    {
        $m = $this->getMembership($membershipId);
        if (!$m) {
            return null;
        }
        $tier = $this->getTier((int) $m['tier_id']);
        $m['tier'] = $tier;
        $m['tier_name'] = $tier['name'] ?? $m['tier_name'];
        $m['tier_color'] = $tier['badge_color'] ?? $m['tier_color'];
        $m['benefits'] = $tier['benefits'] ?? [];
        return $m;
    }

    public function getStats(): array
    {
        global $wpdb;
        $scope = \WalletUpPro\Core\Tenancy\Tenant::where();
        $active = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->members} WHERE status = 'active'" . $scope);
        $mrr = (float) $wpdb->get_var(
            "SELECT COALESCE(SUM(CASE billing_interval WHEN 'monthly' THEN amount WHEN 'yearly' THEN amount/12 ELSE 0 END),0)
             FROM {$this->members} WHERE status = 'active'" . $scope
        );
        $stats = [
            'active_members' => $active,
            'total_members' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->members} WHERE 1=1" . $scope),
            'mrr' => round($mrr, 2),
            'tiers' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->tiers} WHERE active = 1" . $scope),
        ];
        if (\WalletUpPro\Core\Settings\SettingsRegistry::isAvailabilityV2()) {
            $stats['awaiting'] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->members} WHERE status = 'pending' AND metadata LIKE %s",
                '%' . $wpdb->esc_like('"awaiting":"' . MembershipWallets::AWAITING . '"') . '%'
            ) . $scope);
        }
        return $stats;
    }

    public function computeExpiry(string $interval, string $from): ?string
    {
        if ($interval === 'lifetime') {
            return null;
        }
        $modifier = $interval === 'monthly' ? '+1 month' : '+1 year';
        return gmdate('Y-m-d H:i:s', strtotime($modifier, strtotime($from)));
    }

    public function subscriptionFrequency(string $interval): string
    {
        return self::INTERVALS[$interval] ?? '';
    }

    private function uniqueTierKey(string $name): string
    {
        global $wpdb;
        $base = sanitize_title($name) ?: 'tier';
        $key = $base;
        $i = 2;
        while ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tiers} WHERE tier_key = %s", $key))) {
            $key = $base . '-' . $i++;
        }
        return $key;
    }

    private function email(string $slug, ?array $member): void
    {
        if (!$member || !class_exists('\\WalletUpPro\\Core\\Emails\\EmailTemplateHandler')) {
            return;
        }
        \WalletUpPro\Core\Emails\EmailTemplateHandler::sendMembershipEmail($slug, $member);
    }

    private function shapeTier(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'tier_key' => $r['tier_key'],
            'name' => $r['name'],
            'description' => $r['description'] ?? '',
            'amount' => (float) $r['amount'],
            'currency' => $r['currency'],
            'billing_interval' => $r['billing_interval'],
            'benefits' => json_decode($r['benefits'] ?? '[]', true) ?: [],
            'badge_color' => $r['badge_color'] ?: '#7c3aed',
            'active' => (int) $r['active'] === 1,
            'sort_order' => (int) $r['sort_order'],
        ];
    }

    private function shapeMember(array $r): array
    {
        $meta = json_decode((string) ($r['metadata'] ?? ''), true);
        $meta = is_array($meta) ? $meta : [];
        return [
            'payment_method' => (string) ($meta['payment_method'] ?? ''),
            'awaiting_confirmation' => ($r['status'] ?? '') === 'pending' && ($meta['awaiting'] ?? '') === MembershipWallets::AWAITING,
            'membership_id' => $r['membership_id'],
            'tier_id' => (int) $r['tier_id'],
            'tier_name' => $r['tier_name'] ?? '',
            'tier_color' => $r['tier_color'] ?? '#7c3aed',
            'member_name' => $r['member_name'] ?? '',
            'member_email' => $r['member_email'] ?? '',
            'member_phone' => $r['member_phone'] ?? '',
            'status' => $r['status'],
            'amount' => (float) $r['amount'],
            'currency' => $r['currency'],
            'billing_interval' => $r['billing_interval'],
            'is_recurring' => (int) $r['is_recurring'] === 1,
            'subscription_id' => $r['subscription_id'] ?? '',
            'started_at' => $r['started_at'] ?? null,
            'expires_at' => $r['expires_at'] ?? null,
            'last_payment_at' => $r['last_payment_at'] ?? null,
            'created_at' => $r['created_at'] ?? null,
        ];
    }
}
