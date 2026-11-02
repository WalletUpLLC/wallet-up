<?php
/**
 * Plugin Name: Wallet Up test harness (sandbox only, never shipped)
 * Logs every wp_mail to a file and exposes a few read-only option lookups to administrators.
 */
add_filter('pre_wp_mail', function ($null, $atts) {
    $line = wp_json_encode(['t' => gmdate('c'), 'to' => $atts['to'] ?? '', 'subject' => $atts['subject'] ?? '', 'message' => mb_substr((string) ($atts['message'] ?? ''), 0, 2000)]);
    file_put_contents(WP_CONTENT_DIR . '/mail.log', $line . "\n", FILE_APPEND);
    return true;
}, 10, 2);
add_action('rest_api_init', function () {
    register_rest_route('wup-test', '/option/(?P<name>[a-zA-Z0-9_]+)', [
        'methods' => 'GET',
        'permission_callback' => fn() => current_user_can('manage_options'),
        'callback' => fn($req) => rest_ensure_response(['name' => $req['name'], 'value' => get_option($req['name'])]),
    ]);
    register_rest_route('wup-test', '/set-option', [
        'methods' => 'POST',
        'permission_callback' => fn() => current_user_can('manage_options'),
        'callback' => function ($req) { $p = $req->get_json_params(); update_option($p['name'], $p['value']); return rest_ensure_response(['ok' => true]); },
    ]);
    register_rest_route('wup-test', '/cron/(?P<hook>[a-zA-Z0-9_]+)', [
        'methods' => 'POST',
        'permission_callback' => fn() => current_user_can('manage_options'),
        'callback' => function ($req) { do_action($req['hook']); return rest_ensure_response(['ran' => $req['hook']]); },
    ]);
    register_rest_route('wup-test', '/footprint', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function () { $f = get_included_files(); $mine = array_filter($f, fn($x) => strpos($x, '/plugins/wallet-up/') !== false); return rest_ensure_response(['files' => count($f), 'plugin_files' => count($mine), 'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1)]); },
    ]);
});

// Front-end footprint probe: /?wup_footprint=1[&list=1] prints what a plain page view loaded.
add_action('template_redirect', function () {
    if (empty($_GET['wup_footprint'])) { return; }
    $f = get_included_files();
    $mine = array_values(array_filter($f, fn($x) => strpos($x, '/plugins/wallet-up/') !== false));
    $bytes = 0; foreach ($mine as $m) { $bytes += @filesize($m); }
    $out = ['total_files' => count($f), 'plugin_files' => count($mine), 'plugin_kb' => (int) round($bytes / 1024), 'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1), 'freemius_loaded' => class_exists('Freemius', false)];
    if (!empty($_GET['list'])) { $out['files'] = array_map(fn($m) => [(int) round(@filesize($m) / 1024), str_replace(WP_PLUGIN_DIR . '/wallet-up/', '', $m)], $mine); usort($out['files'], fn($a, $b) => $b[0] <=> $a[0]); }
    header('Content-Type: application/json'); echo wp_json_encode($out); exit;
});
