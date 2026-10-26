<?php
declare(strict_types=1);

namespace WalletUpPro\Core\Tenancy;

if (!defined('ABSPATH')) {
    exit;
}

final class Tenant
{
    public const FILTER_RESOLVE    = 'wallet_up_tenant_resolve';
    public const FILTER_OPTIONS    = 'wallet_up_tenant_options';
    public const FILTER_TRANSIENTS = 'wallet_up_tenant_transients';
    public const FILTER_LISTS      = 'wallet_up_tenant_lists';
    public const FILTER_ENABLED    = 'wallet_up_tenancy_enabled';
    public const FILTER_BLOCK      = 'wallet_up_tenant_block_writes';
    public const ACTION_BLOCKED    = 'wallet_up_tenant_option_blocked';
    public const ACTION_LIST_ADDED = 'wallet_up_tenant_list_added';
    public const ACTION_ENTERED    = 'wallet_up_tenant_entered';
    public const ACTION_LEFT       = 'wallet_up_tenant_left';
    public const COLUMN            = 'tenant_id';
    public const META_ORDER        = '_wallet_up_tenant';
    public const META_USER         = 'wallet_up_tenant_id';
    public const QUERY_ADMIN       = 'wallet_up_tenant';
    public const ROLE              = 'wallet_up_tenant';
    public const CAP               = 'wallet_up_tenant';
    public const MAX_LENGTH        = 64;
    private const BOOT_PRIORITY    = 1;
    private const REQUEST_KEYS     = [
        'form_id', 'campaign_id', 'donation_id', 'order_id', 'order-received', 'wc_order_id',
        'membership_id', 'tier_id', 'payer_id', 'post_id', 'p', 'payment_id', 'subscription_id',
        'wpforms_form_id', 'entry_id',
    ];

    private static string $current = '';
    private static array $stack = [];
    private static ?TenantOverlay $overlay = null;
    private static bool $booted = false;
    private static ?bool $enabled = null;

    public static function enabled(): bool
    {
        if (self::$enabled === null) {
            $on = (defined('WALLET_UP_TENANCY') && WALLET_UP_TENANCY) || has_filter(self::FILTER_RESOLVE);
            self::$enabled = (bool) apply_filters(self::FILTER_ENABLED, $on);
        }
        return self::$enabled;
    }

    public static function register(): void
    {
        if (!self::enabled()) {
            return;
        }
        add_action('init', [TenantAccess::class, 'ensureRole'], 5);
        add_action('woocommerce_checkout_create_order', [self::class, 'stampOrder'], 10, 1);
        add_action('woocommerce_store_api_checkout_order_processed', [self::class, 'stampOrder'], 10, 1);
        TenantSchema::ensure();
        if (did_action('plugins_loaded') || doing_action('plugins_loaded')) {
            self::boot();
            return;
        }
        add_action('plugins_loaded', [self::class, 'boot'], self::BOOT_PRIORITY);
    }

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        $id = self::resolve(self::hints());
        if ($id !== '') {
            self::enter($id);
        }
    }

    public static function current(): string
    {
        return self::$current;
    }

    public static function active(): bool
    {
        return self::$current !== '';
    }

    public static function normalize(mixed $id): string
    {
        $id = is_scalar($id) ? trim((string) $id) : '';
        return substr(preg_replace('/[^A-Za-z0-9_:.-]/', '', $id) ?? '', 0, self::MAX_LENGTH);
    }

    public static function resolve(array $hints): string
    {
        if (!self::enabled()) {
            return '';
        }
        $id = self::normalize(apply_filters(self::FILTER_RESOLVE, '', $hints));
        return $id;
    }

    private static function activate(string $id): void
    {
        if ($id === self::$current) {
            return;
        }
        if (self::$overlay instanceof TenantOverlay) {
            self::$overlay->release();
            self::$overlay = null;
        }
        $left = self::$current;
        self::$current = $id;
        if ($id !== '') {
            self::$overlay = TenantOverlay::apply($id);
        }
        self::refreshEngine();
        if ($left !== '') {
            do_action(self::ACTION_LEFT, $left);
        }
        if ($id !== '') {
            do_action(self::ACTION_ENTERED, $id);
        }
    }

    public static function enter(string $id): void
    {
        if (!self::enabled()) {
            return;
        }
        $id = self::normalize($id);
        if ($id === '' || $id === self::$current) {
            return;
        }
        self::$stack[] = self::$current;
        self::activate($id);
    }

    public static function leave(): void
    {
        if (self::$stack === []) {
            self::activate('');
            return;
        }
        self::activate((string) array_pop(self::$stack));
    }

    public static function with(string $id, callable $callback): mixed
    {
        if (!self::enabled()) {
            return $callback();
        }
        $id = self::normalize($id);
        if ($id === self::$current) {
            return $callback();
        }
        self::$stack[] = self::$current;
        self::activate($id);
        try {
            return $callback();
        } finally {
            self::leave();
        }
    }

    public static function withOrder(mixed $order, callable $callback): mixed
    {
        return self::with(self::ofOrder($order), $callback);
    }

    public static function scopeOrder(mixed $order): string
    {
        if (!self::enabled()) {
            return '';
        }
        $id = self::ofOrder($order);
        self::activate($id);
        return $id;
    }

    public static function scopeMember(string $membershipId): string
    {
        if (!self::enabled()) {
            return '';
        }
        $id = self::ofMember($membershipId);
        self::activate($id);
        return $id;
    }

    public static function enterHints(array $hints): string
    {
        if (!self::enabled()) {
            return '';
        }
        $id = self::resolve(array_merge(self::hints(), $hints));
        if ($id !== '') {
            self::enter($id);
        }
        return $id;
    }

    public static function enterOrder(mixed $orderId): string
    {
        $id = self::ofOrder($orderId);
        if ($id !== '') {
            self::enter($id);
        }
        return $id;
    }

    public static function enterDonation(string $donationId): string
    {
        $id = self::ofDonation($donationId);
        if ($id !== '') {
            self::enter($id);
        }
        return $id;
    }

    public static function enterMember(string $membershipId): string
    {
        $id = self::ofMember($membershipId);
        if ($id !== '') {
            self::enter($id);
        }
        return $id;
    }

    public static function enterTier(int $tierId): string
    {
        $id = self::ofTier($tierId);
        if ($id !== '') {
            self::enter($id);
        }
        return $id;
    }

    public static function enterPayment(string $paymentId): string
    {
        $id = self::ofPayment($paymentId);
        if ($id !== '') {
            self::enter($id);
        }
        return $id;
    }

    public static function enterWebhook(array $body): string
    {
        if (!self::enabled()) {
            return '';
        }
        $paths = [
            'tenant'   => ['data.object.metadata.tenant_id', 'resource.custom.tenant_id', 'metadata.tenant_id', 'tenant_id'],
            'order'    => ['data.object.metadata.order_id', 'data.object.metadata.wc_order_id', 'resource.custom_id', 'resource.purchase_units.0.custom_id', 'order_id'],
            'donation' => ['data.object.metadata.donation_id', 'data.object.metadata.source_id', 'resource.custom_id', 'donation_id'],
            'member'   => ['data.object.metadata.source_id', 'resource.custom_id'],
            'provider' => ['data.object.id', 'data.object.payment_intent', 'data.object.subscription', 'resource.id', 'resource.billing_agreement_id', 'resource.subscription_id', 'TransID', 'transaction_id'],
        ];
        foreach ($paths as $kind => $list) {
            foreach ($list as $path) {
                $value = self::dig($body, $path);
                if (!is_scalar($value) || (string) $value === '') {
                    continue;
                }
                $value = (string) $value;
                $id = match ($kind) {
                    'tenant' => self::normalize($value),
                    'order' => ctype_digit($value) ? self::ofOrder((int) $value) : '',
                    'donation' => self::ofDonation($value),
                    'member' => self::ofMember($value),
                    default => self::ofProviderReference($value),
                };
                if ($id !== '') {
                    self::enter($id);
                    return $id;
                }
            }
        }
        return self::enterHints(['webhook' => $body]);
    }

    public static function stamp(array $row, string $table = ''): array
    {
        if (!self::enabled()) {
            return $row;
        }
        if (self::$current === '' || isset($row[self::COLUMN])) {
            return $row;
        }
        if ($table !== '' && !TenantSchema::has($table)) {
            return $row;
        }
        $row[self::COLUMN] = self::$current;
        return $row;
    }

    public static function metadata(array $metadata): array
    {
        if (self::$current !== '' && empty($metadata['tenant_id'])) {
            $metadata['tenant_id'] = self::$current;
        }
        return $metadata;
    }

    public static function stampOrder(mixed $order): void
    {
        if (self::$current === '' || !is_object($order) || !method_exists($order, 'update_meta_data')) {
            return;
        }
        if ((string) $order->get_meta(self::META_ORDER) === '') {
            $order->update_meta_data(self::META_ORDER, self::$current);
            if (method_exists($order, 'save') && did_action('woocommerce_store_api_checkout_order_processed')) {
                $order->save();
            }
        }
    }

    public static function ofOrder(mixed $orderId): string
    {
        if (!self::enabled()) {
            return '';
        }
        if (!function_exists('wc_get_order')) {
            return '';
        }
        $order = is_object($orderId) ? $orderId : wc_get_order(absint($orderId));
        if (!is_object($order) || !method_exists($order, 'get_meta')) {
            return '';
        }
        $id = self::normalize($order->get_meta(self::META_ORDER));
        if ($id === '' && method_exists($order, 'get_parent_id') && (int) $order->get_parent_id() > 0) {
            $id = self::ofOrder((int) $order->get_parent_id());
        }
        if ($id === '') {
            $context = ['order' => $order];
            if (!self::machineContext()) {
                $context = array_merge(self::hints(), $context);
            }
            $id = self::resolve($context);
        }
        return $id;
    }

    public static function ofDonation(string $donationId): string
    {
        if (!self::enabled()) {
            return '';
        }
        global $wpdb;
        $table = $wpdb->prefix . 'wallet_up_donations';
        if ($donationId === '' || !TenantSchema::has($table)) {
            return '';
        }
        $row = $wpdb->get_row($wpdb->prepare("SELECT tenant_id, form_id, campaign_id FROM {$table} WHERE donation_id = %s OR id = %d LIMIT 1", $donationId, ctype_digit($donationId) ? (int) $donationId : 0), ARRAY_A);
        if (!is_array($row)) {
            return '';
        }
        $id = self::normalize($row['tenant_id'] ?? '');
        if ($id === '') {
            $id = self::resolve(['form_id' => (string) ($row['form_id'] ?? ''), 'campaign_id' => (string) ($row['campaign_id'] ?? '')]);
        }
        return $id;
    }

    public static function ofMember(string $membershipId): string
    {
        if (!self::enabled()) {
            return '';
        }
        global $wpdb;
        $table = $wpdb->prefix . 'walletup_memberships';
        if ($membershipId === '' || !TenantSchema::has($table)) {
            return '';
        }
        $row = $wpdb->get_row($wpdb->prepare("SELECT tenant_id, tier_id FROM {$table} WHERE membership_id = %s LIMIT 1", $membershipId), ARRAY_A);
        if (!is_array($row)) {
            return '';
        }
        $id = self::normalize($row['tenant_id'] ?? '');
        return $id !== '' ? $id : self::ofTier((int) ($row['tier_id'] ?? 0));
    }

    public static function ofTier(int $tierId): string
    {
        if (!self::enabled()) {
            return '';
        }
        global $wpdb;
        $table = $wpdb->prefix . 'walletup_membership_tiers';
        if ($tierId < 1 || !TenantSchema::has($table)) {
            return '';
        }
        return self::normalize($wpdb->get_var($wpdb->prepare("SELECT tenant_id FROM {$table} WHERE id = %d", $tierId)));
    }

    public static function ofPayment(string $paymentId): string
    {
        if (!self::enabled()) {
            return '';
        }
        global $wpdb;
        $table = $wpdb->prefix . 'walletup_payment_transactions';
        if ($paymentId === '' || !TenantSchema::has($table)) {
            return '';
        }
        $row = $wpdb->get_row($wpdb->prepare("SELECT tenant_id, wc_order_id FROM {$table} WHERE payment_id = %s LIMIT 1", $paymentId), ARRAY_A);
        if (!is_array($row)) {
            return '';
        }
        $id = self::normalize($row['tenant_id'] ?? '');
        return $id !== '' ? $id : ((int) ($row['wc_order_id'] ?? 0) > 0 ? self::ofOrder((int) $row['wc_order_id']) : '');
    }

    public static function ofProviderReference(string $reference): string
    {
        if (!self::enabled()) {
            return '';
        }
        global $wpdb;
        $reference = trim($reference);
        if ($reference === '') {
            return '';
        }
        $lookups = [
            [$wpdb->prefix . 'walletup_payment_transactions', 'provider_transaction_id'],
            [$wpdb->prefix . 'walletup_subscriptions', 'provider_subscription_id'],
            [$wpdb->prefix . 'wallet_up_donations', 'provider_transaction_id'],
        ];
        foreach ($lookups as [$table, $column]) {
            if (!TenantSchema::has($table) || !TenantSchema::hasColumn($table, $column)) {
                continue;
            }
            $id = self::normalize($wpdb->get_var($wpdb->prepare("SELECT tenant_id FROM {$table} WHERE {$column} = %s AND tenant_id <> '' LIMIT 1", $reference)));
            if ($id !== '') {
                return $id;
            }
        }
        return '';
    }

    public static function adminScope(): string
    {
        if (!self::enabled()) {
            return '';
        }
        $user = TenantAccess::userTenant();
        if ($user !== '') {
            return $user;
        }
        if (is_admin() && current_user_can('manage_options')) {
            return self::normalize(self::query()[self::QUERY_ADMIN] ?? '');
        }
        return '';
    }

    public static function scope(): string
    {
        return self::$current !== '' ? self::$current : self::adminScope();
    }

    public static function where(string $alias = '', string $tenant = ''): string
    {
        if (!self::enabled()) {
            return '';
        }
        global $wpdb;
        $tenant = $tenant !== '' ? self::normalize($tenant) : self::scope();
        if ($tenant === '') {
            return '';
        }
        $column = ($alias !== '' ? $alias . '.' : '') . self::COLUMN;
        return $wpdb->prepare(" AND {$column} = %s", $tenant);
    }

    public static function hints(): array
    {
        $hints = [];
        $query = self::query();
        foreach (self::REQUEST_KEYS as $key) {
            $value = self::posted($key);
            if ($value === '' && isset($query[$key])) {
                $value = (string) $query[$key];
            }
            if ($value !== '') {
                $hints[$key] = sanitize_text_field($value);
            }
        }
        $hints['path'] = self::path();
        $hints['host'] = strtolower(sanitize_text_field(isset($_SERVER['HTTP_HOST']) ? (string) wp_unslash($_SERVER['HTTP_HOST']) : ''));
        $hints['referer'] = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw((string) wp_unslash($_SERVER['HTTP_REFERER'])) : '';
        $hints['ajax'] = wp_doing_ajax();
        $hints['rest'] = str_starts_with($hints['path'], rest_get_url_prefix());
        $hints['admin'] = is_admin();
        $hints['cron'] = wp_doing_cron();
        $body = self::jsonBody();
        if ($body !== []) {
            $hints['body'] = $body;
        }
        $admin = self::adminScope();
        if ($admin !== '') {
            $hints['admin_tenant'] = $admin;
        }
        return $hints;
    }

    private static function refreshEngine(): void
    {
        if (class_exists('\\WalletUpPro\\Core\\PaymentRegistry') && method_exists('\\WalletUpPro\\Core\\PaymentRegistry', 'resetInstance')) {
            \WalletUpPro\Core\PaymentRegistry::resetInstance();
        }
    }

    private static function query(): array
    {
        $query = [];
        wp_parse_str((string) wp_parse_url(isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REQUEST_URI'])) : '', PHP_URL_QUERY), $query);
        return array_filter($query, 'is_scalar');
    }

    private static function posted(string $key): string
    {
        $value = filter_input(INPUT_POST, $key, FILTER_DEFAULT);
        return is_string($value) ? wp_unslash($value) : '';
    }

    private static function path(): string
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        $path = trim((string) wp_parse_url($uri, PHP_URL_PATH), '/');
        $home = trim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        if ($home !== '' && str_starts_with($path, $home)) {
            $path = trim(substr($path, strlen($home)), '/');
        }
        return sanitize_text_field($path);
    }

    private static function machineContext(): bool
    {
        if (wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
            return true;
        }
        $path = trim((string) wp_parse_url(isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '', PHP_URL_PATH), '/');
        return str_starts_with($path, rest_get_url_prefix());
    }

    private static function jsonBody(): array
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            return [];
        }
        $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
        if ($type !== '' && !str_contains($type, 'json')) {
            return [];
        }
        $raw = (string) file_get_contents('php://input', false, null, 0, 262144);
        if ($raw === '' || !str_starts_with(ltrim($raw), '{')) {
            return [];
        }
        $body = json_decode($raw, true);
        return is_array($body) ? $body : [];
    }

    private static function dig(array $data, string $path): mixed
    {
        $node = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }
        return $node;
    }
}
