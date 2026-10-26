<?php
namespace WalletUpPro\Templates;

if (!defined('ABSPATH')) {
    exit;
}

class PaymentDisplayTemplate
{
    private $qrService;
    private $errorHandler;
    private $displayHandler;

    public function __construct()
    {
        $this->qrService = \WalletUpPro\Services\QRCodeService::getInstance();
        $this->errorHandler = \WalletUpPro\Core\Error\ErrorHandler::getInstance();
        $this->displayHandler = \WalletUpPro\Core\Display\DisplayHandler::getInstance();
    }

    public function render($methodId, $amount, $settings = [])
    {
        try {
            $method = \WalletUpPro\Core\PaymentRegistry::getInstance()->getMethod($methodId);
            if (!$method) {
                throw new \Exception(esc_html('Invalid payment method'));
            }

            $paymentUrl = $method->getPaymentUrl($amount);

            $display = $this->displayHandler->generateDisplay(
                $settings['display_mode'] ?? 'qr',
                $paymentUrl,
                $methodId,
                $settings,
                [
                    'amount' => $amount,
                    'method' => $methodId,
                    'url' => $paymentUrl
                ]
            );

            $display .= $this->displayHandler->generateTrackingScript([
                'method' => $methodId,
                'amount' => $amount,
                'display_mode' => $settings['display_mode'] ?? 'qr'
            ]);

            return $display;

        } catch (\Exception $e) {
            $this->errorHandler->log('Payment display template error: ' . $e->getMessage());
            return sprintf(
                '<div class="walletup-error"><p>%s</p></div>',
                esc_html__('Error generating payment display.', 'walletup')
            );
        }
    }

    /**
     * Display payment method details
     * 
     * @param array $methodDetails The payment method details
     * @return string Returns HTML for payment method display
     */
    function get_wallet_up_method_display($methodDetails)
    {
        if (empty($methodDetails) || !isset($methodDetails['name'])) {
            return '';
        }

        $html = '<div class="wallet-up-method-display">';

        if (!empty($methodDetails['icon'])) {
            $html .= sprintf(
                '<img src="%s" alt="%s" class="wallet-up-method-icon" style="height: 24px; width: auto; margin-right: 8px; vertical-align: middle;">',
                esc_url($methodDetails['icon']),
                esc_attr($methodDetails['name'])
            );
        }

        $html .= sprintf(
            '<span class="wallet-up-method-name">%s</span>',
            esc_html($methodDetails['name'])
        );

        if (!empty($methodDetails['description'])) {
            $html .= sprintf(
                '<span class="wallet-up-method-description" style="display: block; font-size: 0.875em; color: #666; margin-top: 4px;">%s</span>',
                esc_html($methodDetails['description'])
            );
        }

        $html .= '</div>';

        return $html;
    }

}