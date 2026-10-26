<?php
namespace WalletUpPro\Core\Memberships;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Single authority for "may this visitor see member-only content".
 *
 * The membership row in the database is the only thing that decides. A WordPress capability is
 * mirrored onto linked users so themes and other plugins can read it, but that mirror is never
 * trusted here — a stale capability left behind by a failed revoke must not grant anything.
 *
 * Members who never had a WordPress account are recognised by a token whose SHA-256 hash is the
 * only thing stored, compared in constant time.
 */
final class MembershipAccess
{
    const CAP_MEMBER   = 'walletup_member';
    const COOKIE       = 'walletup_member_token';
    const CLAIM_VAR    = 'walletup_member_access';
    const OPTION       = 'wallet_up_membership_access';
    const TOKEN_DAYS   = 180;

    const DEFAULTS = [
        'enabled'          => false,
        'configured'       => false,
        'grace_days'       => 0,
        'denied_message'   => '',
        'redirect_page_id' => 0,
        'link_wp_users'    => true,
    ];

    private static ?array $viewerCache = null;

    public static function settings(): array
    {
        $saved = get_option(self::OPTION, []);
        if (!is_array($saved)) {
            $saved = [];
        }
        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value = $saved[$key] ?? $default;
            if (is_bool($default)) {
                $out[$key] = (bool) $value;
            } elseif (is_int($default)) {
                $out[$key] = (int) $value;
            } else {
                $out[$key] = (string) $value;
            }
        }
        return $out;
    }

    public static function updateSettings(array $patch): array
    {
        $next = array_merge(self::settings(), array_intersect_key($patch, self::DEFAULTS));
        update_option(self::OPTION, $next, false);
        self::$viewerCache = null;
        return $next;
    }

    public static function isEnabled(): bool
    {
        return self::settings()['enabled'] === true;
    }

    public static function ensureDefaults(): void
    {
        if (get_option(self::OPTION, null) === null) {
            add_option(self::OPTION, self::DEFAULTS, '', false);
        }
    }

    /**
     * Active memberships for the current visitor, resolved fresh from the database.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function viewerMemberships(): array
    {
        if (self::$viewerCache !== null) {
            return self::$viewerCache;
        }
        if (!MembershipManager::tablesExist()) {
            return self::$viewerCache = [];
        }

        $rows = [];
        $userId = get_current_user_id();
        if ($userId > 0) {
            $rows = self::activeRowsWhere('user_id = %d', [$userId]);
            $user = wp_get_current_user();
            if (empty($rows) && !empty($user->user_email)) {
                $rows = self::activeRowsWhere('member_email = %s', [$user->user_email]);
            }
        }

        if (empty($rows)) {
            $token = self::cookieToken();
            if ($token !== '') {
                $rows = self::activeRowsWhere('access_token_hash = %s', [hash('sha256', $token)]);
            }
        }

        return self::$viewerCache = $rows;
    }

    /** @return int[] tier ids the visitor currently holds */
    public static function viewerTierIds(): array
    {
        return array_values(array_unique(array_map(
            static fn($m) => (int) $m['tier_id'],
            self::viewerMemberships()
        )));
    }

    public static function viewerIsMember(): bool
    {
        return self::viewerMemberships() !== [];
    }

    /**
     * @param int[]  $tierIds empty means "any active membership"
     * @param string $require 'any' | 'all'
     */
    public static function canView(array $tierIds = [], string $require = 'any'): bool
    {
        if (!self::isEnabled()) {
            return true;
        }
        $held = self::viewerTierIds();
        if ($held === []) {
            return false;
        }
        $tierIds = array_values(array_filter(array_map('intval', $tierIds)));
        if ($tierIds === []) {
            return true;
        }
        $matched = array_intersect($tierIds, $held);
        return $require === 'all' ? count($matched) === count($tierIds) : $matched !== [];
    }

    /**
     * Called when a membership becomes active. Links the WordPress account when one matches,
     * mints an access token for guests, and mirrors the capability.
     *
     * @return string raw token for a guest member (shown once), or '' when a user was linked
     */
    public static function grant(string $membershipId): string
    {
        global $wpdb;
        $table = MembershipManager::membersTable();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE membership_id = %s", $membershipId), ARRAY_A);
        if (!$row) {
            return '';
        }

        $userId = (int) ($row['user_id'] ?? 0);
        if ($userId === 0 && self::settings()['link_wp_users']) {
            $user = !empty($row['member_email']) ? get_user_by('email', $row['member_email']) : false;
            if ($user) {
                $userId = (int) $user->ID;
                $wpdb->update($table, ['user_id' => $userId], ['membership_id' => $membershipId]);
            }
        }

        if ($userId > 0) {
            self::syncCapability($userId);
            return '';
        }

        if (!empty($row['access_token_hash'])) {
            return '';
        }
        $token = bin2hex(random_bytes(32));
        $wpdb->update($table, ['access_token_hash' => hash('sha256', $token)], ['membership_id' => $membershipId]);
        return $token;
    }

    public static function revoke(string $membershipId): void
    {
        global $wpdb;
        $table = MembershipManager::membersTable();
        $userId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$table} WHERE membership_id = %s",
            $membershipId
        ));
        if ($userId > 0) {
            self::syncCapability($userId);
        }
        self::$viewerCache = null;
    }

    public static function syncCapability(int $userId): void
    {
        $user = get_user_by('id', $userId);
        if (!$user) {
            return;
        }
        $active = self::activeRowsWhere('user_id = %d', [$userId]) !== [];
        if ($active) {
            $user->add_cap(self::CAP_MEMBER);
        } else {
            $user->remove_cap(self::CAP_MEMBER);
        }
    }

    /**
     * Re-issue a guest member's access after they lose the cookie — a new device, a cleared
     * browser, a link that never arrived.
     *
     * Only the hash is stored, so the original token cannot be resent; a new one is minted and
     * the old one stops working. That is the point: this is a deliberate act by the member,
     * unlike a renewal, which must never disturb a token already in use.
     *
     * @return array{token:string, member:array}|null null when nothing active matches
     */
    public static function issueRecoveryToken(string $email): ?array
    {
        if (!is_email($email) || !MembershipManager::tablesExist()) {
            return null;
        }
        $rows = self::activeRowsWhere('member_email = %s', [$email]);
        if ($rows === []) {
            return null;
        }
        $guest = null;
        foreach ($rows as $row) {
            if ((int) ($row['user_id'] ?? 0) === 0) {
                $guest = $row;
                break;
            }
        }
        if ($guest === null) {
            return null;
        }

        global $wpdb;
        $token = bin2hex(random_bytes(32));
        $wpdb->update(
            MembershipManager::membersTable(),
            ['access_token_hash' => hash('sha256', $token)],
            ['membership_id' => $guest['membership_id']]
        );
        self::$viewerCache = null;
        return ['token' => $token, 'member' => $guest];
    }

    public static function accessLinkUrl(string $token): string
    {
        return add_query_arg(self::CLAIM_VAR, rawurlencode($token), home_url('/'));
    }

    public static function maybeClaimFromLink(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the token is the credential
        $raw = isset($_GET[self::CLAIM_VAR]) ? sanitize_text_field(wp_unslash($_GET[self::CLAIM_VAR])) : '';
        if ($raw === '' || preg_match('/^[a-f0-9]{64}$/', $raw) !== 1) {
            return;
        }
        if (self::activeRowsWhere('access_token_hash = %s', [hash('sha256', $raw)]) !== []) {
            self::rememberToken($raw);
        }
        wp_safe_redirect(remove_query_arg(self::CLAIM_VAR));
        exit;
    }

    public static function rememberToken(string $token): void
    {
        if ($token === '' || headers_sent()) {
            return;
        }
        setcookie(self::COOKIE, $token, [
            'expires'  => time() + (self::TOKEN_DAYS * DAY_IN_SECONDS),
            'path'     => defined('COOKIEPATH') ? COOKIEPATH : '/',
            'domain'   => defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        self::$viewerCache = null;
    }

    private static function cookieToken(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only identity lookup
        $raw = isset($_COOKIE[self::COOKIE]) ? sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE])) : '';
        return preg_match('/^[a-f0-9]{64}$/', $raw) === 1 ? $raw : '';
    }

    /**
     * Active means: status active, and either no expiry or an expiry still ahead once the
     * configured grace period is added.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function activeRowsWhere(string $clause, array $params): array
    {
        global $wpdb;
        $table = MembershipManager::membersTable();
        $grace = max(0, (int) self::settings()['grace_days']);

        $sql = "SELECT * FROM {$table}
                WHERE {$clause}
                  AND status = 'active'
                  AND (expires_at IS NULL OR expires_at > DATE_SUB(NOW(), INTERVAL %d DAY))";
        $params[] = $grace;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- clause is a literal above
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }
}
