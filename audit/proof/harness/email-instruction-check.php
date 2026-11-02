<?php
$_SERVER['HTTP_HOST']='127.0.0.1:8080'; $_SERVER['REQUEST_URI']='/'; $_SERVER['SERVER_NAME']='127.0.0.1';
define('WP_USE_THEMES', false); define('DOING_CRON', true);
require getenv('WP_CORE') . '/wp-load.php'; // path of the local WordPress checkout
WC()->mailer();
$order = wc_get_order(17);
ob_start(); do_action('woocommerce_email_before_order_table', $order, false, true); $out = ob_get_clean();
echo "status=" . $order->get_status() . " method=" . $order->get_payment_method() . "\n";
echo "occurrences=" . substr_count($out, 'using the code and link') . "\n" . trim($out) . "\n";
echo "gateway_instances_hooked=" . (has_action('woocommerce_email_before_order_table') ? 'yes' : 'no') . "\n";
