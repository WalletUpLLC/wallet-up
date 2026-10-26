<?php
namespace WalletUpPro\Core\Memberships;

if (!defined('ABSPATH')) {
    exit;
}

class MembershipFileGuard
{
    const VAR         = 'walletup_file';
    const SIZE_VAR    = 'walletup_size';
    const MARKER      = 'WalletUp Protected Media';
    const SYNC_HOOK   = 'walletup_membership_sync_htaccess';
    const STATUS_OPTION     = 'wallet_up_membership_media_status';
    const VERIFY_TRANSIENT  = 'wallet_up_membership_media_verified';
    const CANARY            = 'walletup-access-canary.txt';

    public static function register(): void
    {
        add_filter('query_vars', static function ($vars) {
            $vars[] = self::VAR;
            $vars[] = self::SIZE_VAR;
            return $vars;
        });

        add_action('template_redirect', [self::class, 'maybeServe'], 0);

        add_filter('wp_get_attachment_url', [self::class, 'filterUrl'], 10, 2);
        add_filter('wp_get_attachment_image_src', [self::class, 'filterImageSrc'], 10, 2);
        add_filter('wp_calculate_image_srcset', [self::class, 'filterSrcset'], 10, 5);

        add_action(self::SYNC_HOOK, [self::class, 'syncHtaccess']);
        add_action('delete_attachment', [self::class, 'onAttachmentDeleted']);
        add_action('updated_post_meta', [self::class, 'onMetaChange'], 10, 3);
        add_action('added_post_meta', [self::class, 'onMetaChange'], 10, 3);
        add_action('deleted_post_meta', [self::class, 'onMetaChange'], 10, 3);
    }

    public static function isProtected(int $attachmentId): bool
    {
        if ($attachmentId <= 0 || get_post_type($attachmentId) !== 'attachment') {
            return false;
        }
        return MembershipGate::requirementFor($attachmentId) !== null;
    }

    public static function gatedUrl(int $attachmentId, string $size = ''): string
    {
        $args = [self::VAR => $attachmentId];
        if ($size !== '' && $size !== 'full') {
            $args[self::SIZE_VAR] = $size;
        }
        return add_query_arg($args, home_url('/'));
    }

    public static function filterUrl($url, $attachmentId)
    {
        return self::isProtected((int) $attachmentId) ? self::gatedUrl((int) $attachmentId) : $url;
    }

    public static function filterImageSrc($image, $attachmentId)
    {
        if (!is_array($image) || !self::isProtected((int) $attachmentId)) {
            return $image;
        }
        $size = self::sizeNameFor((int) $attachmentId, (int) ($image[1] ?? 0), (int) ($image[2] ?? 0));
        $image[0] = self::gatedUrl((int) $attachmentId, $size);
        return $image;
    }

    public static function filterSrcset($sources, $sizeArray, $imageSrc, $imageMeta, $attachmentId)
    {
        return self::isProtected((int) $attachmentId) ? [] : $sources;
    }

    private static function sizeNameFor(int $attachmentId, int $w, int $h): string
    {
        $meta = wp_get_attachment_metadata($attachmentId);
        if (!is_array($meta) || empty($meta['sizes']) || $w <= 0) {
            return '';
        }
        foreach ($meta['sizes'] as $name => $s) {
            if ((int) ($s['width'] ?? 0) === $w && (int) ($s['height'] ?? 0) === $h) {
                return (string) $name;
            }
        }
        return '';
    }

    public static function maybeServe(): void
    {
        $id = absint(get_query_var(self::VAR));
        if ($id <= 0) {
            return;
        }

        if (get_post_type($id) !== 'attachment') {
            self::deny(404);
        }

        $req = MembershipGate::requirementFor($id);
        if ($req === null) {
            wp_safe_redirect(wp_get_attachment_url($id), 302);
            exit;
        }

        if (!MembershipAccess::canView($req['tiers'], $req['mode'])) {
            self::deny(403);
        }

        $size = sanitize_key((string) get_query_var(self::SIZE_VAR));
        $path = self::resolvePath($id, $size);
        if ($path === '') {
            self::deny(404);
        }

        self::stream($path);
    }

    private static function resolvePath(int $id, string $size): string
    {
        $file = get_attached_file($id);
        if (!$file) {
            return '';
        }

        if ($size !== '') {
            $meta = wp_get_attachment_metadata($id);
            if (is_array($meta) && !empty($meta['sizes'][$size]['file'])) {
                $candidate = trailingslashit(dirname($file)) . $meta['sizes'][$size]['file'];
                if (is_readable($candidate)) {
                    $file = $candidate;
                }
            }
        }

        $real = realpath($file);
        if ($real === false || !is_readable($real)) {
            return '';
        }

        $uploads = wp_get_upload_dir();
        $base    = realpath($uploads['basedir'] ?? '');
        if ($base === false || strpos($real, $base . DIRECTORY_SEPARATOR) !== 0) {
            return '';
        }
        return $real;
    }

    private static function stream(string $path): void
    {
        $type = wp_check_filetype(basename($path))['type'] ?: 'application/octet-stream';

        nocache_headers();
        header('Content-Type: ' . $type);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: inline; filename="' . rawurlencode(basename($path)) . '"');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow');

        if (function_exists('wp_ob_end_flush_all')) {
            wp_ob_end_flush_all();
        }
        readfile($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a verified local file
        exit;
    }

    private static function deny(int $code): void
    {
        status_header($code);
        nocache_headers();
        wp_die(
            $code === 403
                ? esc_html__('This file is for members.', 'walletup')
                : esc_html__('File not found.', 'walletup'),
            '',
            ['response' => $code]
        );
    }

    public static function onAttachmentDeleted($attachmentId): void
    {
        if (get_post_meta((int) $attachmentId, MembershipGate::META_ON, true) !== '1') {
            return;
        }
        if (!wp_next_scheduled(self::SYNC_HOOK)) {
            wp_schedule_single_event(time() + 5, self::SYNC_HOOK);
        }
    }

    public static function onMetaChange($metaId, $objectId, $metaKey): void
    {
        if (!in_array($metaKey, [MembershipGate::META_ON, MembershipGate::META_TIERS], true)) {
            return;
        }
        if (get_post_type((int) $objectId) !== 'attachment') {
            return;
        }
        if (!wp_next_scheduled(self::SYNC_HOOK)) {
            wp_schedule_single_event(time() + 5, self::SYNC_HOOK);
        }
    }

    public static function syncHtaccess(): void
    {
        if (!apply_filters('walletup_membership_protect_uploads_rules', true)) {
            return;
        }
        if (!function_exists('insert_with_markers')) {
            require_once ABSPATH . 'wp-admin/includes/misc.php';
        }

        $uploads = wp_get_upload_dir();
        $basedir = $uploads['basedir'] ?? '';
        if ($basedir === '' || !is_dir($basedir) || !is_writable($basedir)) {
            return;
        }

        $paths = self::protectedRelativePaths();

        self::ensureCanary($basedir);
        $paths[] = self::CANARY;

        insert_with_markers(trailingslashit($basedir) . '.htaccess', self::MARKER, self::htaccessLines($paths));

        $webConfig = trailingslashit($basedir) . 'web.config';
        if ($paths === []) {
            if (file_exists($webConfig)) {
                wp_delete_file($webConfig);
            }
        } else {
            self::writeFile($webConfig, self::webConfigXml($paths));
        }

        update_option(self::STATUS_OPTION, [
            'files'      => count($paths),
            'written_at' => time(),
        ], false);

        self::purgeCaches($paths);
    }

    /**
     * A file that was fetched publicly before being protected stays in the page/CDN cache,
     * which keeps serving 200 no matter what the deny rules say. Nudge the caches we can
     * reach and expose an action so any stack can hook its own purge.
     *
     * @param string[] $relPaths
     */
    private static function purgeCaches(array $relPaths): void
    {
        $uploads = wp_get_upload_dir();
        $baseurl = trailingslashit((string) ($uploads['baseurl'] ?? ''));
        $urls    = [];
        foreach ($relPaths as $rel) {
            $urls[] = $baseurl . ltrim($rel, '/');
        }
        if ($urls === []) {
            return;
        }

        foreach ($urls as $u) {
            do_action('litespeed_purge_url', $u);
        }
        if (function_exists('rocket_clean_files')) {
            rocket_clean_files($urls);
        }
        if (function_exists('w3tc_flush_url')) {
            foreach ($urls as $u) {
                w3tc_flush_url($u);
            }
        }
        do_action('walletup_membership_purge_urls', $urls);
    }

    public static function webConfigXml(array $relPaths): string
    {
        $rules = '';
        foreach ($relPaths as $i => $rel) {
            $rules .= '<rule name="WalletUpProtected' . (int) $i . '" stopProcessing="true">'
                . '<match url="^' . esc_attr(preg_quote(ltrim($rel, '/'), '')) . '$" />'
                . '<action type="CustomResponse" statusCode="403" statusDescription="Forbidden" />'
                . '</rule>';
        }
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<configuration><system.webServer><rewrite><rules>'
            . $rules
            . '</rules></rewrite></system.webServer></configuration>';
    }

    private static function writeFile(string $path, string $contents): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir) || !is_writable($dir)) {
            return false;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- see docblock
        $fp = @fopen($path, 'wb');
        if (!$fp) {
            return false;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
        fwrite($fp, $contents);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($fp);
        return true;
    }

    private static function ensureCanary(string $basedir): void
    {
        $path = trailingslashit($basedir) . self::CANARY;
        if (!file_exists($path)) {
            self::writeFile($path, 'WalletUp access canary. Safe to delete; it is recreated automatically.');
        }
    }

    public static function verifyEnforcement(bool $force = false): array
    {
        $cached = get_transient(self::VERIFY_TRANSIENT);
        if (!$force && is_array($cached)) {
            return $cached;
        }

        $uploads = wp_get_upload_dir();
        $basedir = trailingslashit((string) ($uploads['basedir'] ?? ''));
        $baseurl = trailingslashit((string) ($uploads['baseurl'] ?? ''));
        $result  = ['enforced' => false, 'reason' => 'unknown', 'checked_at' => time()];

        if ($basedir === '' || !is_writable($basedir)) {
            $result['reason'] = 'uploads_not_writable';
            set_transient(self::VERIFY_TRANSIENT, $result, DAY_IN_SECONDS);
            return $result;
        }

        self::ensureCanary($basedir);
        if (!file_exists($basedir . '.htaccess')
            || strpos((string) @file_get_contents($basedir . '.htaccess'), self::MARKER) === false) {
            self::syncHtaccess();
        }

        $res  = wp_remote_get($baseurl . self::CANARY, ['timeout' => 10, 'sslverify' => false, 'redirection' => 0]);
        $code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);

        if ($code === 0) {
            $result['reason'] = 'loopback_failed';
        } elseif ($code === 403 || $code === 404) {
            $result['enforced'] = true;
            $result['reason']   = 'denied_' . $code;
        } else {
            $result['reason'] = 'served_' . $code;
        }

        set_transient(self::VERIFY_TRANSIENT, $result, HOUR_IN_SECONDS);
        return $result;
    }

    public static function nginxSnippet(): string
    {
        $uploads = wp_get_upload_dir();
        $path    = wp_parse_url((string) ($uploads['baseurl'] ?? ''), PHP_URL_PATH) ?: '/wp-content/uploads';
        return "location ^~ " . trailingslashit($path) . "walletup-protected/ { deny all; }";
    }

    /** @return string[] paths relative to the uploads basedir */
    public static function protectedRelativePaths(): array
    {
        $ids  = [];
        $page = 1;
        do {
            $batch = get_posts([
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'fields'         => 'ids',
                'posts_per_page' => 200,
                'paged'          => $page,
                'no_found_rows'  => true,
                'meta_query'     => [['key' => MembershipGate::META_ON, 'value' => '1']],
            ]);
            $ids = array_merge($ids, $batch);
            $page++;
        } while (count($batch) === 200 && $page < 500);

        $uploads = wp_get_upload_dir();
        $base    = trailingslashit((string) ($uploads['basedir'] ?? ''));
        $out     = [];

        foreach ($ids as $id) {
            $file = get_attached_file((int) $id);
            if (!$file || strpos($file, $base) !== 0) {
                continue;
            }
            $rel = substr($file, strlen($base));
            $out[] = $rel;

            $meta = wp_get_attachment_metadata((int) $id);
            if (is_array($meta) && !empty($meta['sizes'])) {
                $dir = trailingslashit(dirname($rel));
                foreach ($meta['sizes'] as $s) {
                    if (!empty($s['file'])) {
                        $out[] = ltrim($dir, './') . $s['file'];
                    }
                }
            }
        }
        return array_values(array_unique($out));
    }

    /** @param string[] $relPaths @return string[] */
    public static function htaccessLines(array $relPaths): array
    {
        if (empty($relPaths)) {
            return [];
        }
        $lines = ['<IfModule mod_rewrite.c>', 'RewriteEngine On'];
        foreach ($relPaths as $rel) {
            $lines[] = 'RewriteRule ^' . preg_quote(ltrim($rel, '/'), '') . '$ - [F,L]';
        }
        $lines[] = '</IfModule>';
        return $lines;
    }
}
