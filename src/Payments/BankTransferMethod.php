<?php

namespace WalletUpPro\Payments;

use WalletUpPro\Core\Config\PaymentMethodsConfig;
use Exception;

if (!defined('ABSPATH')) {
    exit;
}

abstract class BankTransferMethod extends PaymentMethod
{
    public function getMethodName(): string
    {
        return $this->getTitle();
    }

    protected function generateDisplay(array $atts, string $paymentUrl): string
    {
        return $this->displayHandler->generateDisplay(
            $atts['display_mode'],
            $paymentUrl,
            $this->methodId,
            $this->displaySettings,
            [
                'amount' => $atts['amount'],
                'note'   => $atts['note'] ?? '',
                'method' => $this->methodId,
                'url'    => $paymentUrl,
            ]
        );
    }

    public function hasNativeLink(): bool
    {
        return false;
    }

    protected function generatePaymentUrl(string $amount, string $note = ''): string
    {
        $this->validateHolder($this->getHolder());
        return \WalletUpPro\Core\Payments\HostedPayPage::url($this->methodId, $amount, $note);
    }

    public function qrPayload(string $amount = '', string $reference = ''): string
    {
        $hosted = \WalletUpPro\Core\Payments\HostedPayPage::url($this->methodId, $amount, $reference);
        return $hosted !== '' ? $hosted : trim($this->getHolder());
    }

    protected function validateHolder(string $holder): string
    {
        $holder = trim($holder);
        $pattern = PaymentMethodsConfig::getValidationPattern($this->methodId);
        if ($pattern && !preg_match($pattern, $holder)) {
            throw new Exception(esc_html(sprintf(
                /* translators: %s: payment method name, e.g. BPAY */
                __('The %s account details are not in a valid format.', 'walletup'),
                $this->getTitle()
            )));
        }
        return $holder;
    }
}
