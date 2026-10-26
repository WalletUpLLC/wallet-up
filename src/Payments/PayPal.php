<?php
namespace WalletUpPro\Payments;

use WalletUpPro\Core\Database\DatabaseManager;
use Exception;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
class PayPal extends PaymentMethod
{
    private const PAYPAL_BASE_URL = 'https://www.paypal.com/paypalme/';
    private const VALIDATION_PATTERN = '/^[a-zA-Z0-9._-]{1,}$/';

    public function __construct(DatabaseManager $db)
    {
        parent::__construct('paypal', $db);
    }
    public function getTitle(): string
    {
        return __('PayPal.me', 'walletup');
    }

    public function getDescription(): string
    {
        return __('Get paid with your PayPal.me link — secure and worldwide.', 'walletup');
    }
    /**
     * Generate payment URL for PayPal
     * 
     * @param string $amount Payment amount
     * @param string $note Optional payment note (not used in this case)
     * @return string Generated payment URL
     * @throws Exception If validation fails
     */
    protected function generatePaymentUrl(string $amount, string $note = ''): string
    {

        $holder = $this->getHolder();
        if (empty($holder)) {
            throw new Exception(esc_html('PayPal account holder not configured'));
        }

        $username = $this->validateUsername($holder);

        $url = self::PAYPAL_BASE_URL . urlencode($username);
        if (!empty($amount)) {
            $url .= '/' . urlencode($amount);
        }

        return $url;
    }
    public function getMethodName(): string
    {
        return $this->getTitle();
    }
    /**
     * Validate PayPal username
     * 
     * @param string $username Username to validate
     * @return string Cleaned and validated username
     * @throws Exception If username is invalid
     */
    private function validateUsername(string $username): string
    {
        $username = \WalletUpPro\Core\Config\PaymentMethodsConfig::normalizeHolder('paypal', $username);

        if (!preg_match(self::VALIDATION_PATTERN, $username)) {
            throw new Exception(esc_html__( 'Enter your PayPal.Me handle, the part after paypal.me/. A PayPal login email does not work in a PayPal.Me link.', 'walletup' ));
        }

        return $username;
    }

    protected function generateDisplay(array $atts, string $paymentUrl): string
    {
        $basicDisplay = $this->displayHandler->generateDisplay(
            $atts['display_mode'],
            $paymentUrl,
            $this->methodId,
            $this->displaySettings,
            [
                'amount' => $atts['amount'],
                'note' => $atts['note'] ?? '',
                'method' => $this->methodId,
                'url' => $paymentUrl
            ]
        );

        return sprintf(
            '<div class="walletup-container walletup-%s" data-wallet-up-method="%s">%s</div>',
            esc_attr($this->methodId),
            esc_attr($this->methodId),
            $basicDisplay
        );
    }
}