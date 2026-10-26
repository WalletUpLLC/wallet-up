<?php
namespace WalletUpPro\Payments;

use WalletUpPro\Core\Database\DatabaseManager;
use Exception;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
class Venmo extends PaymentMethod
{
    private const VENMO_BASE_URL = 'https://venmo.com/';
    private const VALIDATION_PATTERN = '/^@?[a-zA-Z0-9_-]{1,20}$/';

    public function __construct(DatabaseManager $db)
    {
        parent::__construct('venmo', $db);
    }
    public function getTitle(): string
    {
        return __('Venmo', 'walletup');
    }

    public function getDescription(): string
    {
        return __('Send and receive money with friends.', 'walletup');
    }
    /**
     * Generate payment URL for Venmo
     * 
     * @param string $amount Payment amount
     * @param string $note Optional payment note
     * @return string Generated payment URL
     * @throws Exception If validation fails
     */
    protected function generatePaymentUrl(string $amount, string $note = ''): string
    {

        $holder = $this->getHolder();
        if (empty($holder)) {
            throw new Exception(esc_html('Venmo account holder not configured'));
        }

        $username = $this->validateUsername($holder);

        $params = [
            'txn' => 'pay',
            'amount' => $amount,
        ];
        if ($note !== '') {
            $params['note'] = $note;
        }

        $url = self::VENMO_BASE_URL . urlencode($username);
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        return $url;
    }
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
                'note' => $atts['note'] ?? '',
                'method' => $this->methodId,
                'url' => $paymentUrl
            ]
        );
    }

    /**
     * Validate Venmo username
     * 
     * @param string $username Username to validate
     * @return string Cleaned and validated username
     * @throws Exception If username is invalid
     */
    private function validateUsername(string $username): string
    {
        $username = \WalletUpPro\Core\Config\PaymentMethodsConfig::normalizeHolder('venmo', $username);
        $username = ltrim($username, '@');

        if (!preg_match(self::VALIDATION_PATTERN, $username)) {
            throw new Exception(esc_html__( 'Enter your Venmo username, for example @yourname.', 'walletup' ));
        }

        return $username;
    }
}