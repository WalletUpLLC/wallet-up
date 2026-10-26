<?php

namespace WalletUpPro\WooCommerce;

use WalletUpPro\Core\PaymentRegistry;
use WalletUpPro\Core\Display\DisplayHandler;

if (!defined('ABSPATH')) {
    exit;
}

class ConfirmationPage
{
    public const SECTIONS = [
        'thank_you',
        'order_summary',
        'payment',
        'how_to_pay',
        'email_notice',
        'view_orders',
        'order_details',
        'merchant_contact',
        'billing_address',
    ];

    private const DEFAULT_ORDER = [
        'order_summary',
        'thank_you',
        'payment',
        'how_to_pay',
        'email_notice',
        'view_orders',
        'order_details',
        'merchant_contact',
        'billing_address',
    ];

    public static function buildFromOrder(\WC_Order $order): string
    {
        \WalletUpPro\Core\Tenancy\Tenant::enterOrder($order);
        $ctx = self::resolveContext($order);
        return $ctx ? self::render($ctx) : '';
    }

    public static function buildPaymentSectionFromOrder(\WC_Order $order): string
    {
        \WalletUpPro\Core\Tenancy\Tenant::enterOrder($order);
        $ctx = self::resolveContext($order);
        if (!$ctx) {
            return '';
        }
        return self::compose($ctx, self::settingsEnabled(), self::savedOrder(), self::savedConfig(), ['payment', 'how_to_pay', 'email_notice']);
    }

    public static function rendersOrder(\WC_Order $order): bool
    {
        return self::resolveContext($order) !== null;
    }

    private static function resolveContext(\WC_Order $order): ?array
    {
        $paymentMethod = $order->get_payment_method();
        if ($paymentMethod !== 'wallet_up' && strpos($paymentMethod, 'wallet_up_') !== 0) {
            return null;
        }

        $methodId = $order->get_meta('_wallet_up_payment_method');
        if (!$methodId && strpos($paymentMethod, 'wallet_up_') === 0) {
            $methodId = substr($paymentMethod, strlen('wallet_up_'));
        }
        if (!$methodId) {
            return null;
        }

        $method = PaymentRegistry::getInstance()->getMethod($methodId);
        if (!$method) {
            return self::contextFromOrder($order, $order->get_payment_method_title(), '', '', ['lines' => [], 'custom' => '']);
        }

        $total = $order->get_total();
        $currency = $order->get_currency();
        $note = WooCommerceMethodSettings::getPaymentNote($methodId, [
            'order'    => $order,
            'amount'   => $total,
            'currency' => $currency,
        ]);

        $gwSettings = get_option('woocommerce_wallet_up_settings', []);
        $displayMode = is_array($gwSettings) && !empty($gwSettings['display_mode']) ? $gwSettings['display_mode'] : 'qr';

        $display = DisplayHandler::getInstance()->generateDisplay(
            $displayMode,
            $method->getPaymentUrl($total, $note),
            $methodId,
            get_option('wallet_up_display_settings', []),
            ['amount' => $total, 'order_id' => $order->get_id(), 'currency' => $currency]
        );

        $automated = self::automatedButtons($order, $methodId);

        $perMethod = WooCommerceMethodSettings::get($methodId, 'instructions', '');
        $custom = !empty($perMethod) ? $perMethod : (is_array($gwSettings) ? ($gwSettings['instructions'] ?? '') : '');
        $defaultInstr = WooCommerceMethodSettings::getDefaultCustomerInstructions($methodId);
        $ctxVars = ['amount' => $total, 'currency' => $currency, 'order' => $order];
        $defaultInstr = WooCommerceMethodSettings::processVariables($defaultInstr, $methodId, $ctxVars);
        if (!empty($custom)) {
            $custom = WooCommerceMethodSettings::processVariables($custom, $methodId, $ctxVars);
        }
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $defaultInstr))));

        return self::contextFromOrder($order, $method, $display, $automated, ['lines' => $lines, 'custom' => $custom]);
    }

    private static function settingsEnabled(): bool
    {
        return get_option('wallet_up_confirmation_display_enabled', 'no') === 'yes';
    }

    private static function savedOrder(): array
    {
        $o = get_option('wallet_up_confirmation_block_order', []);
        return is_array($o) ? $o : [];
    }

    private static function savedConfig(): array
    {
        $c = get_option('wallet_up_confirmation_block_config', []);
        return is_array($c) ? $c : [];
    }

    private static function automatedButtons(\WC_Order $order, string $methodId): string
    {
        $paymentId = $order->get_meta('_wallet_up_payment_id');
        $html = '';
        if ($methodId === 'cashapp' && !empty(get_option('wallet_up_cashapp_api_settings', [])['enabled'])) {
            $html .= '<div style="text-align:center;margin-top:14px;padding:15px;background:#e8f5e9;border-radius:12px;border:1px solid #c8e6c9;">'
                . '<p style="color:#2e7d32;font-weight:600;margin:0 0 10px;">' . esc_html__('Or pay instantly with Cash App Pay', 'walletup') . '</p>'
                . '<div id="wallet-up-cashapp-api-button" data-amount="' . esc_attr($order->get_total()) . '" data-currency="' . esc_attr($order->get_currency()) . '" data-reference-id="' . esc_attr($paymentId) . '" data-order-id="' . esc_attr($order->get_id()) . '"></div></div>';
        }
        if ($methodId === 'venmo' && !empty(get_option('wallet_up_venmo_api_settings', [])['enabled'])) {
            $html .= '<div style="text-align:center;margin-top:14px;padding:15px;background:#e3f2fd;border-radius:12px;border:1px solid #bbdefb;">'
                . '<p style="color:#1565c0;font-weight:600;margin:0 0 10px;">' . esc_html__('Or pay instantly with Venmo', 'walletup') . '</p>'
                . '<div id="wallet-up-venmo-api-button" data-amount="' . esc_attr($order->get_total()) . '" data-order-id="' . esc_attr($paymentId) . '" data-email="' . esc_attr($order->get_billing_email()) . '" data-first-name="' . esc_attr($order->get_billing_first_name()) . '" data-last-name="' . esc_attr($order->get_billing_last_name()) . '"></div></div>';
        }
        return $html;
    }

    public static function contextFromOrder(\WC_Order $order, $method, string $displayHtml, string $automatedHtml, array $instructions): array
    {
        $items = [];
        foreach ($order->get_items() as $item) {
            $items[] = [
                'name'  => $item->get_name(),
                'qty'   => $item->get_quantity(),
                'total' => wc_price($item->get_total()),
            ];
        }

        $merchant = get_option('wallet_up_transfer_call', []);

        $totals = [];
        foreach ($order->get_order_item_totals() as $row) {
            if (!empty($row['label'])) {
                $totals[] = ['label' => $row['label'], 'value' => $row['value']];
            }
        }

        return [
            'is_preview'     => false,
            'order_number'   => $order->get_order_number(),
            'date'           => $order->get_date_created() ? $order->get_date_created()->date_i18n(get_option('date_format')) : '',
            'email'          => $order->get_billing_email(),
            'total'          => wc_price($order->get_total()),
            'subtotal'       => wc_price($order->get_subtotal()),
            'totals'         => $totals,
            'method_name'    => self::methodLabel($order, $method),
            'is_paid'        => in_array($order->get_status(), ['processing', 'completed'], true),
            'pay_state'      => self::payState($order),
            'display'        => $displayHtml,
            'automated'      => $automatedHtml,
            'instr_lines'    => $instructions['lines'] ?? [],
            'instr_custom'   => $instructions['custom'] ?? '',
            'orders_url'     => wc_get_endpoint_url('orders', '', wc_get_page_permalink('myaccount')),
            'items'          => $items,
            'merchant_name'  => $merchant['holder_owner'] ?? '',
            'merchant_email' => $merchant['holder_email'] ?? '',
            'merchant_phone' => $merchant['holder_no'] ?? '',
            'billing_html'   => trim($order->get_formatted_billing_address() . '<br>' . esc_html($order->get_billing_phone()) . '<br>' . esc_html($order->get_billing_email())),
        ];
    }

    private static function payState(\WC_Order $order): string
    {
        $status = $order->get_status();
        if (in_array($status, ['processing', 'completed'], true)) {
            return 'paid';
        }
        if ($status === 'on-hold') {
            return 'processing';
        }
        if ($status === 'pending' && strpos((string) $order->get_meta('_wallet_up_payment_id'), 'pi_') === 0) {
            return 'processing';
        }
        return 'unpaid';
    }

    private static function methodLabel(\WC_Order $order, $method): string
    {
        return self::resolveMethodLabel($order, is_object($method) ? $method->getMethodName() : (string) $method);
    }

    public static function resolveMethodLabel(\WC_Order $order, string $fallback = ''): string
    {
        $stored = trim((string) $order->get_meta('_wallet_up_pay_method_label'));
        if ($stored !== '') {
            return $stored;
        }
        if (!function_exists('is_order_received_page') || !is_order_received_page()) {
            return $fallback;
        }
        $intent = (string) $order->get_meta('_wallet_up_payment_id');
        if (strpos($intent, 'pi_') === 0 && class_exists('\\WalletUpPro\\Core\\Config\\StripeMethodCatalog')) {
            $live = \WalletUpPro\Core\Config\StripeMethodCatalog::labelForIntent($intent);
            if ($live !== '') {
                return $live;
            }
        }
        return $fallback;
    }

    public static function filterPaymentMethodTitle($title, $order)
    {
        if (!$order instanceof \WC_Order || strpos((string) $order->get_payment_method(), 'wallet_up') !== 0) {
            return $title;
        }
        $resolved = self::resolveMethodLabel($order, (string) $title);
        return $resolved !== '' ? $resolved : $title;
    }

    public static function sampleContext(string $displayHtml, string $methodName): array
    {
        return [
            'is_preview'     => true,
            'order_number'   => '1024',
            'date'           => date_i18n(get_option('date_format')),
            'email'          => 'customer@example.com',
            'total'          => wc_price(82.40),
            'subtotal'       => wc_price(103.00),
            'totals'         => [
                ['label' => __('Subtotal', 'walletup'), 'value' => wc_price(103.00)],
                ['label' => __('Discount', 'walletup'), 'value' => '-' . wc_price(20.60)],
                ['label' => __('Payment method', 'walletup'), 'value' => $methodName],
                ['label' => __('Total', 'walletup'), 'value' => wc_price(82.40)],
            ],
            'method_name'    => $methodName,
            'display'        => $displayHtml,
            'automated'      => '',
            'instr_lines'    => [
                'Tap or scan the code to open your payment app.',
                'Send the total shown to complete your payment.',
                'Tap "I\'ve sent my payment" so we can verify and complete your order.',
            ],
            'instr_custom'   => '',
            'orders_url'     => '#',
            'items'          => [['name' => 'Sample Product', 'qty' => 1, 'total' => wc_price(103.00)]],
            'merchant_name'  => get_bloginfo('name'),
            'merchant_email' => get_option('admin_email'),
            'merchant_phone' => '',
            'billing_html'   => 'Jane Doe<br>123 Market Street<br>Springfield, IL 62704<br>customer@example.com',
        ];
    }

    public static function render(array $ctx): string
    {
        $default = self::DEFAULT_ORDER;
        try {
            $enabled = get_option('wallet_up_confirmation_display_enabled', 'no') === 'yes';
            $config  = get_option('wallet_up_confirmation_block_config', []);
            $order   = get_option('wallet_up_confirmation_block_order', []);
            return self::compose($ctx, $enabled, is_array($order) ? $order : [], is_array($config) ? $config : []);
        } catch (\Throwable $e) {
            return self::compose($ctx, false, [], []);
        }
    }

    public static function renderWith(array $ctx, bool $enabled, array $order, array $config): string
    {
        return self::compose($ctx, $enabled, $order, $config);
    }

    private static function compose(array $ctx, bool $enabled, array $savedOrder, array $config, ?array $only = null): string
    {
        $default = $only !== null ? array_values(array_intersect(self::DEFAULT_ORDER, $only)) : self::DEFAULT_ORDER;

        $order = $default;
        if ($enabled && !empty($savedOrder)) {
            $order = array_values(array_intersect($savedOrder, $default));
        }
        foreach ($default as $key) {
            if (!in_array($key, $order, true)) {
                $order[] = $key;
            }
        }

        $isPaid = !empty($ctx['is_paid']);
        $inner = '';
        foreach ($order as $key) {
            if ($isPaid && ($key === 'payment' || $key === 'how_to_pay')) {
                continue;
            }
            $cfg = ($enabled && isset($config[$key]) && is_array($config[$key])) ? $config[$key] : [];
            if ($enabled && $key !== 'payment' && isset($cfg['visible']) && $cfg['visible'] === false) {
                continue;
            }
            $html = self::section($key, $ctx);
            if ($html === '') {
                continue;
            }
            $intro = $enabled ? self::intro($cfg) : '';
            $inner .= $intro . $html;
        }

        return '<div class="walletup-thankyou-premium" style="max-width:760px;margin:0 auto;padding:32px 20px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#1f2937;">'
            . $inner
            . '</div>';
    }

    private static function intro(array $cfg): string
    {
        $heading = trim((string) ($cfg['heading'] ?? ''));
        $note = trim((string) ($cfg['note'] ?? ''));
        if ($heading === '' && $note === '') {
            return '';
        }
        $html = '<div style="margin:0 0 8px;">';
        if ($heading !== '') {
            $html .= '<div style="font-weight:600;color:#111827;margin-bottom:2px;">' . esc_html($heading) . '</div>';
        }
        if ($note !== '') {
            $html .= '<div style="color:#4b5563;font-size:14px;line-height:1.5;">' . nl2br(esc_html($note)) . '</div>';
        }
        return $html . '</div>';
    }

    private static function card(string $body, string $bg = '#ffffff', string $border = '#e5e7eb'): string
    {
        return '<div style="background:' . esc_attr($bg) . ';border:1px solid ' . esc_attr($border) . ';border-radius:14px;padding:22px;margin-bottom:16px;">' . $body . '</div>';
    }

    private static function sectionHeading(string $text, string $color = '#111827'): string
    {
        return '<h2 style="margin:0 0 14px;font-size:0.8rem;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;color:' . esc_attr($color) . ';">' . esc_html($text) . '</h2>';
    }

    private static function section(string $key, array $ctx): string
    {
        switch ($key) {
            case 'thank_you':
                $state = $ctx['pay_state'] ?? (!empty($ctx['is_paid']) ? 'paid' : 'unpaid');
                if ($state === 'paid') {
                    $subline = esc_html__('Your payment was received — your order is confirmed.', 'walletup');
                } elseif ($state === 'processing') {
                    $subline = esc_html__('Your payment is processing. We will confirm your order as soon as it clears.', 'walletup');
                } else {
                    $subline = esc_html__('Complete your payment to confirm your order.', 'walletup');
                }
                return '<div style="text-align:center;margin-bottom:24px;">'
                    . '<div style="display:inline-flex;align-items:center;justify-content:center;width:56px;height:56px;background:linear-gradient(135deg,#7c3aed,#6d28d9);border-radius:50%;margin-bottom:14px;box-shadow:0 8px 20px -6px rgba(124,58,237,0.5);">'
                    . '<svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>'
                    . '</div>'
                    . '<h1 style="margin:0 0 6px;font-size:1.7rem;font-weight:700;color:#111827;">' . esc_html__('Thank you for your order!', 'walletup') . '</h1>'
                    . '<p style="margin:0;font-size:1rem;color:#6b7280;">' . $subline . '</p>'
                    . '</div>';

            case 'order_summary':
                $rows = [
                    [__('Order number', 'walletup'), esc_html($ctx['order_number'])],
                    [__('Date', 'walletup'), esc_html($ctx['date'])],
                    [__('Email', 'walletup'), esc_html($ctx['email'])],
                    [__('Total', 'walletup'), wp_kses_post($ctx['total'])],
                    [__('Payment method', 'walletup'), esc_html($ctx['method_name'])],
                ];
                $body = self::sectionHeading(__('Order summary', 'walletup'), '#4c1d95');
                foreach ($rows as $r) {
                    $body .= '<div style="display:flex;justify-content:space-between;gap:16px;padding:7px 0;border-bottom:1px solid #ede9fe;font-size:0.95rem;">'
                        . '<span style="color:#6b7280;">' . esc_html($r[0]) . '</span>'
                        . '<span style="color:#111827;font-weight:500;text-align:right;">' . $r[1] . '</span>'
                        . '</div>';
                }
                return self::card($body, '#faf5ff', '#e9d5ff');

            case 'payment':
                if (trim((string) ($ctx['display'] ?? '')) === '' && empty($ctx['automated'])) {
                    return '';
                }
                // phpcs:ignore -- server-generated markup
                $inner = '<div style="max-width:420px;margin:0 auto;display:flex;flex-direction:column;align-items:center;gap:14px;">' . $ctx['display'] . '</div>';
                if (!empty($ctx['automated'])) {
                    $inner .= $ctx['automated'];
                }
                return self::card($inner, '#ffffff', '#e9d5ff');

            case 'how_to_pay':
                $lines = is_array($ctx['instr_lines']) ? $ctx['instr_lines'] : [];
                if (empty($lines) && empty($ctx['instr_custom'])) {
                    return '';
                }
                $body = self::sectionHeading(__('How to pay', 'walletup'), '#92400e');
                if (!empty($lines)) {
                    $body .= '<ol style="margin:0;padding-left:20px;font-size:0.95rem;color:#78350f;line-height:1.7;">';
                    foreach ($lines as $line) {
                        $line = trim(preg_replace('/^\d+\.\s*/', '', (string) $line));
                        if ($line === '') {
                            continue;
                        }
                        $body .= '<li style="margin-bottom:4px;">' . wp_kses_post(wptexturize($line)) . '</li>';
                    }
                    $body .= '</ol>';
                }
                if (!empty($ctx['instr_custom'])) {
                    $body .= '<div style="margin-top:12px;padding-top:12px;border-top:1px dashed #fde68a;font-size:0.9rem;color:#78350f;">' . wp_kses_post(wpautop(wptexturize($ctx['instr_custom']))) . '</div>';
                }
                return self::card($body, '#fffbeb', '#fde68a');

            case 'email_notice':
                return self::card(
                    '<p style="margin:0;text-align:center;color:#166534;font-size:0.9rem;">' . esc_html__('Your order confirmation has been sent to your email.', 'walletup') . '</p>',
                    '#f0fdf4',
                    '#bbf7d0'
                );

            case 'view_orders':
                return '<div style="text-align:center;margin:4px 0 20px;">'
                    . '<a href="' . esc_url($ctx['orders_url']) . '" style="display:inline-block;padding:12px 30px;background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;text-decoration:none;border-radius:10px;font-weight:600;font-size:0.95rem;">'
                    . esc_html__('View your orders', 'walletup') . '</a></div>';

            case 'order_details':
                $items = is_array($ctx['items']) ? $ctx['items'] : [];
                $body = self::sectionHeading(__('Order details', 'walletup'));
                $body .= '<table style="width:100%;border-collapse:collapse;">';
                foreach ($items as $it) {
                    $body .= '<tr style="border-bottom:1px solid #f3f4f6;">'
                        . '<td style="padding:10px 0;color:#374151;font-size:0.95rem;">' . esc_html($it['name']) . ' <span style="color:#9ca3af;">&times; ' . esc_html($it['qty']) . '</span></td>'
                        . '<td style="padding:10px 0;text-align:right;color:#374151;font-weight:500;font-size:0.95rem;">' . wp_kses_post($it['total']) . '</td>'
                        . '</tr>';
                }
                $body .= '</table>';
                $rows = (isset($ctx['totals']) && is_array($ctx['totals']) && !empty($ctx['totals'])) ? $ctx['totals'] : [];
                if (empty($rows)) {
                    $rows = [
                        ['label' => __('Subtotal', 'walletup'), 'value' => $ctx['subtotal']],
                        ['label' => __('Total', 'walletup'), 'value' => $ctx['total']],
                    ];
                }
                $lastIndex = count($rows) - 1;
                $body .= '<div style="margin-top:14px;padding-top:14px;border-top:1px solid #e5e7eb;">';
                foreach ($rows as $idx => $row) {
                    $isTotal = $idx === $lastIndex;
                    $body .= '<div style="display:flex;justify-content:space-between;padding:' . ($isTotal ? '9px 0;margin-top:6px;border-top:1px solid #e5e7eb;' : '5px 0;') . 'font-size:0.95rem;">'
                        . '<span style="color:' . ($isTotal ? '#111827;font-weight:700;' : '#6b7280;') . '">' . wp_kses_post($row['label']) . '</span>'
                        . '<span style="color:' . ($isTotal ? '#7c3aed;font-weight:700;' : '#374151;') . '">' . wp_kses_post($row['value']) . '</span></div>';
                }
                $body .= '</div>';
                return self::card($body, '#f9fafb', '#e5e7eb');

            case 'merchant_contact':
                if (empty($ctx['merchant_name']) && empty($ctx['merchant_email']) && empty($ctx['merchant_phone'])) {
                    return '';
                }
                $body = self::sectionHeading(__('Merchant contact', 'walletup'), '#4c1d95');
                $body .= '<div style="color:#4b5563;line-height:1.6;font-size:0.95rem;">';
                if (!empty($ctx['merchant_name'])) {
                    $body .= '<p style="margin:0 0 4px;"><strong>' . esc_html($ctx['merchant_name']) . '</strong></p>';
                }
                if (!empty($ctx['merchant_email'])) {
                    $body .= '<p style="margin:0 0 4px;"><a href="mailto:' . esc_attr($ctx['merchant_email']) . '" style="color:#7c3aed;text-decoration:none;">' . esc_html($ctx['merchant_email']) . '</a></p>';
                }
                if (!empty($ctx['merchant_phone'])) {
                    $body .= '<p style="margin:0;"><a href="tel:' . esc_attr($ctx['merchant_phone']) . '" style="color:#7c3aed;text-decoration:none;">' . esc_html($ctx['merchant_phone']) . '</a></p>';
                }
                $body .= '</div>';
                return self::card($body, '#faf5ff', '#e9d5ff');

            case 'billing_address':
                if (empty($ctx['billing_html'])) {
                    return '';
                }
                $body = self::sectionHeading(__('Billing address', 'walletup'));
                $body .= '<p style="margin:0;color:#4b5563;line-height:1.6;font-size:0.95rem;">' . wp_kses_post($ctx['billing_html']) . '</p>';
                return self::card($body, '#f9fafb', '#e5e7eb');
        }

        return '';
    }
}
