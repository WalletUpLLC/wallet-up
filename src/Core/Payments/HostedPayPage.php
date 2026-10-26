<?php
namespace WalletUpPro\Core\Payments;

use WalletUpPro\Core\Config\PaymentMethodsConfig;

if (!defined('ABSPATH')) {
    exit;
}

final class HostedPayPage
{
    const QUERY_VAR = 'walletup_paywith';
    const ROUTE     = 'paywith';

    public static function register(): void
    {
        add_action('template_redirect', [self::class, 'maybeRender'], 0);
    }

    public static function url(string $methodId, string $amount = '', string $reference = ''): string
    {
        $methodId = sanitize_key($methodId);
        if ($methodId === '' || !isset(PaymentMethodsConfig::PAYMENT_METHODS[$methodId])) {
            return '';
        }

        $args = [];
        if ($amount !== '' && (float) $amount > 0) {
            $args['amt'] = number_format((float) $amount, 2, '.', '');
        }
        if ($reference !== '') {
            $args['ref'] = substr(preg_replace('/[^A-Za-z0-9_-]/', '', $reference), 0, 40);
        }

        $base = get_option('permalink_structure')
            ? home_url('/' . self::ROUTE . '/' . $methodId . '/')
            : add_query_arg(self::QUERY_VAR, $methodId, home_url('/'));

        return $args ? add_query_arg($args, $base) : $base;
    }

    public static function maybeRender(): void
    {
        $method = (string) get_query_var(self::QUERY_VAR, '');
        if ($method === '') {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public routing only
            $method = isset($_GET[self::QUERY_VAR]) ? sanitize_key(wp_unslash($_GET[self::QUERY_VAR])) : '';
        }
        if ($method === '' || !isset(PaymentMethodsConfig::PAYMENT_METHODS[$method])) {
            return;
        }
        if (!\WalletUpPro\Core\Payments\MethodStatusResolver::isReady($method)) {
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- public routing only
        $amount = isset($_GET['amt']) ? (string) (float) wp_unslash($_GET['amt']) : '';
        $ref    = isset($_GET['ref'])
            ? substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string) wp_unslash($_GET['ref'])), 0, 40)
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        status_header(200);
        nocache_headers();
        self::render($method, $amount, $ref);
        exit;
    }

    /**
     * These rails publish no merchant-mintable link, but the same bank transfer is chargeable
     * through Stripe once the connected account's country supports it — Canada settles them as
     * pre-authorised debit, Australia as BECS or PayTo. Stripe picks the method, so the payer can
     * finish here instead of copying details into a banking app.
     */
    private static function payNowAssets(string $method, string $amount, string $reference): string
    {
        if ($amount === '' || (float) $amount <= 0) {
            return '';
        }
        if (!\WalletUpPro\Core\Payments\MethodStatusResolver::isReady($method)) {
            return '';
        }
        if (!class_exists('\\WalletUpPro\\Core\\Config\\AutomatedApiConfig')) {
            return '';
        }
        $r = \WalletUpPro\Core\Config\AutomatedApiConfig::stripe();
        if (empty($r['ready'])) {
            return '';
        }

        $manifest = WALLET_UP_PATH . 'walup-react/dist/.vite/manifest.json';
        if (!file_exists($manifest)) {
            return '';
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local read
        $m = json_decode(file_get_contents($manifest), true);
        if (empty($m['src/hostedPay.jsx']['file'])) {
            return '';
        }

        $base = WALLET_UP_URL . 'walup-react/dist/';
        foreach (($m['src/hostedPay.jsx']['css'] ?? []) as $i => $css) {
            wp_enqueue_style('walletup-hosted-pay-' . $i, $base . $css, [], WALLET_UP_VERSION);
        }
        wp_enqueue_script('walletup-hosted-pay', $base . $m['src/hostedPay.jsx']['file'], [], WALLET_UP_VERSION, true);
        add_filter('script_loader_tag', static function ($tag, $handle, $src) {
            return $handle === 'walletup-hosted-pay'
                ? sprintf('<script type="module" src="%s" id="%s-js"></script>', esc_url($src), esc_attr($handle))
                : $tag;
        }, 10, 3);

        // pre-authorised debit needs CAD, BECS and PayTo need AUD. That is a reason to withhold
        $settles = strtoupper((string) (PaymentMethodsConfig::PAYMENT_METHODS[$method]['settlement_currency'] ?? ''));
        $currency = strtoupper(self::storeCurrency());
        if ($settles === '' || $currency !== $settles) {
            return '';
        }

        return sprintf(
            '<div data-walletup-hosted-pay data-method="%s" data-amount="%s" data-currency="%s"'
            . ' data-reference="%s" data-nonce="%s" data-record-nonce="%s" data-ajax="%s"></div>',
            esc_attr($method),
            esc_attr($amount),
            esc_attr($currency),
            esc_attr($reference),
            esc_attr(wp_create_nonce('wallet_up_create_stripe_payment_intent')),
            esc_attr(wp_create_nonce('wallet_up_stripe_payment_success')),
            esc_url(admin_url('admin-ajax.php'))
        );
    }

    private static function storeCurrency(): string
    {
        if (function_exists('get_woocommerce_currency')) {
            return (string) get_woocommerce_currency();
        }
        $donation = get_option('wallet_up_donation_advanced', []);
        if (is_array($donation) && !empty($donation['currency'])) {
            return (string) $donation['currency'];
        }
        return (string) get_option('woocommerce_currency', 'USD');
    }

    private static function render(string $method, string $amount, string $reference): void
    {
        $name  = PaymentMethodsConfig::PAYMENT_METHODS[$method]['name'] ?? $method;
        $brand = PaymentMethodsConfig::PAYMENT_METHODS[$method]['brand_color'] ?? '#674FBF';
        $payNow = self::payNowAssets($method, $amount, $reference);

        $atts = 'scan="no"';
        if ($amount !== '') {
            $atts .= ' amount="' . esc_attr($amount) . '"';
        }
        if ($reference !== '') {
            $atts .= ' note="' . esc_attr($reference) . '"';
        }
        add_filter('walletup_suppress_qr', '__return_true');
        $body = do_shortcode('[' . $method . ' ' . $atts . ']');
        remove_filter('walletup_suppress_qr', '__return_true');

        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php
    /* translators: %1$s: payment method, %2$s: site name */
    echo esc_html(sprintf(__('Pay with %1$s · %2$s', 'walletup'), $name, get_bloginfo('name')));
?></title>
<?php wp_head(); ?>
<style>
.walup-hosted{max-width:560px;margin:0 auto;padding:24px 16px 48px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#0f172a}
.walup-hosted__brand{display:flex;align-items:center;gap:10px;margin-bottom:18px}
.walup-hosted__dot{width:10px;height:10px;border-radius:999px;background:<?php echo esc_attr($brand); ?>}
.walup-hosted__site{font-size:.8125rem;color:#64748b}
.walup-hosted__title{margin:0 0 4px;font-size:1.375rem;font-weight:700;letter-spacing:-.02em}
.walup-hosted__lede{margin:0 0 20px;font-size:.875rem;color:#64748b}
.walup-hosted__card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:18px}
.walup-hosted__pay{margin-bottom:14px}
/* The code is the first thing a payer looks for, so it sits centred in the card whatever
   alignment the shared shortcode markup brings with it. */
.walup-hosted__card .walletup-qr-link{text-align:center}
.walup-hosted__card img[data-walletup-qr]{display:block;margin-left:auto;margin-right:auto;float:none}
.walup-hosted__ok{margin:0;padding:12px 14px;border-radius:10px;background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;font-size:.875rem;font-weight:600;text-align:center}
.walup-hosted__error{margin:0 0 10px;padding:10px 12px;border-radius:10px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;font-size:.8125rem}
.walup-hosted__loading{margin:0;font-size:.8125rem;color:#64748b;text-align:center}
.walup-hosted__or{margin:0 0 14px;text-align:center;font-size:.75rem;color:#94a3b8;text-transform:uppercase;letter-spacing:.06em}
.walup-hosted__foot{margin-top:20px;font-size:.75rem;color:#94a3b8;text-align:center}
</style>
</head>
<body <?php body_class('walup-hosted-page'); ?>>
<div class="walup-hosted">
    <div class="walup-hosted__brand">
        <span class="walup-hosted__dot"></span>
        <span class="walup-hosted__site"><?php echo esc_html(get_bloginfo('name')); ?></span>
    </div>
    <h1 class="walup-hosted__title"><?php
        /* translators: %s: payment method name */
        echo esc_html(sprintf(__('Pay with %s', 'walletup'), $name));
    ?></h1>
    <p class="walup-hosted__lede"><?php
        esc_html_e('Use the details below in your banking app to complete this payment.', 'walletup');
    ?></p>
    <?php if ($payNow !== '') : ?>
        <div class="walup-hosted__card walup-hosted__pay">
            <?php echo $payNow; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>
        <p class="walup-hosted__or"><?php esc_html_e('or transfer it yourself', 'walletup'); ?></p>
    <?php endif; ?>
    <div class="walup-hosted__card"><?php
        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    ?></div>
    <p class="walup-hosted__foot"><?php
        esc_html_e('Payments are made directly to the account shown above.', 'walletup');
    ?></p>
</div>
<?php wp_footer(); ?>
</body>
</html><?php
    }
}
