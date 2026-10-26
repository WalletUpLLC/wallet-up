<?php
// CashApp.php
namespace WalletUpPro\Payments;

use WalletUpPro\Core\Database\DatabaseManager;
use Exception;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
class CashApp extends PaymentMethod
{
    private const CASHAPP_BASE_URL = 'https://cash.app/';
    private const VALIDATION_PATTERN = '/^\$?[a-zA-Z0-9]{1,20}$/';

    public function __construct(DatabaseManager $db)
    {
        parent::__construct('cashapp', $db);
    }
    public function getTitle(): string
    {
        return __('Cash App', 'walletup');
    }

    public function getDescription(): string
    {
        return __('Pay instantly with your Cash App balance.', 'walletup');
    }
    /**
     * Generate payment URL for Cash App
     * 
     * @param string $amount Payment amount
     * @param string $note Optional payment note (not used here)
     * @return string Generated payment URL
     * @throws Exception If validation fails
     */
    protected function generatePaymentUrl(string $amount, string $note = ''): string
    {

        $holder = $this->getHolder();
        if (empty($holder)) {
            throw new Exception(esc_html('Cash App account holder not configured'));
        }

        $cashtag = $this->formatCashtag($holder);
        $url = self::CASHAPP_BASE_URL . urlencode($cashtag);

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
     * Format and validate Cash App cashtag
     * 
     * @param string $cashtag Cashtag to format
     * @return string Formatted cashtag
     * @throws Exception If cashtag is invalid
     */
    private function formatCashtag(string $cashtag): string
    {
        $cashtag = \WalletUpPro\Core\Config\PaymentMethodsConfig::normalizeHolder('cashapp', $cashtag);
        $cashtag = ltrim($cashtag, '$');

        if (!preg_match(self::VALIDATION_PATTERN, '$' . $cashtag)) {
            throw new Exception(esc_html__( 'Enter your Cash App $Cashtag, for example $yourname.', 'walletup' ));
        }

        return '$' . $cashtag;
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
                'url' => $paymentUrl,
                'cashtag' => $this->formatCashtag($this->getHolder())
            ]
        );

        $additionalContent = sprintf(
            '<div class="walletup-disclaimer">%s</div>',
            esc_html__('Scan with Cash App to pay instantly', 'walletup')
        );

        return sprintf(
            '<div class="walletup-container walletup-%s" data-wallet-up-method="%s">%s%s</div>',
            esc_attr($this->methodId),
            esc_attr($this->methodId),
            $basicDisplay,
            $additionalContent
        );
    }
}