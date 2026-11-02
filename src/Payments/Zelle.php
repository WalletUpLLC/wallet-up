<?php
namespace WalletUpPro\Payments;

use WalletUpPro\Core\Database\DatabaseManager;
use WalletUpPro\Core\Config\PaymentMethodsConfig;
use Exception;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Zelle on the free plan (6.5.0).
 *
 * Zelle has no public pay link and bank apps only scan Zelle's own codes, so this
 * method never fabricates a QR. It shows the merchant's uploaded bank QR when one
 * exists (wallet_up_custom_qr_images) and otherwise a plain "send with Zelle to"
 * card with the email or phone, the amount and a copy button.
 */
class Zelle extends PaymentMethod
{
    public function __construct(DatabaseManager $db)
    {
        parent::__construct('zelle', $db);
    }

    public function getTitle(): string
    {
        return __('Zelle', 'walletup');
    }

    public function getDescription(): string
    {
        return __('Send money from a US bank account using an email address or phone number.', 'walletup');
    }

    public function getMethodName(): string
    {
        return $this->getTitle();
    }

    public function hasNativeLink(): bool
    {
        return false;
    }

    /** No pay link exists for Zelle; an empty URL routes the display to the instruction card. */
    protected function generatePaymentUrl(string $amount, string $note = ''): string
    {
        $holder = trim($this->getHolder());
        if ($holder === '') {
            throw new Exception(esc_html__('Zelle email or phone is not configured.', 'walletup'));
        }
        $pattern = PaymentMethodsConfig::getValidationPattern('zelle');
        if ($pattern && !preg_match($pattern, $holder)) {
            throw new Exception(esc_html__('The Zelle email or phone number is not in a valid format.', 'walletup'));
        }
        return '';
    }

    /** Never encode a generic payload: a camera-app QR to an email is not something a bank app can pay. */
    public function qrPayload(string $amount = '', string $reference = ''): string
    {
        return '';
    }
}
