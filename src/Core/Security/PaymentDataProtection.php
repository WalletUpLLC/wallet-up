<?php
namespace WalletUpPro\Core\Security;

use WalletUpPro\Core\Error\ErrorHandler;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
class PaymentDataProtection
{
    private $encryptor;
    private $errorHandler;

    public function __construct(DataEncryption $encryptor, ErrorHandler $errorHandler)
    {
        $this->encryptor = $encryptor;
        $this->errorHandler = $errorHandler;
    }

    public function protectPaymentData(array $paymentData): array
    {
        try {
            $protected = [];
            foreach ($paymentData as $key => $value) {
                if ($this->isDataSensitive($key)) {
                    $protected[$key] = $this->encryptor->encrypt($value);
                } else {
                    $protected[$key] = $value;
                }
            }
            return $protected;
        } catch (\Exception $e) {
            $this->errorHandler->log('Payment data protection failed: ' . $e->getMessage());
            throw $e;
        }
    }

    public function unprotectPaymentData(array $protectedData): array
    {
        try {
            $unprotected = [];
            foreach ($protectedData as $key => $value) {
                if ($this->isDataSensitive($key)) {
                    $unprotected[$key] = $this->encryptor->decrypt($value);
                } else {
                    $unprotected[$key] = $value;
                }
            }
            return $unprotected;
        } catch (\Exception $e) {
            $this->errorHandler->log('Payment data unprotection failed: ' . $e->getMessage());
            throw $e;
        }
    }

    private function isDataSensitive(string $key): bool
    {
        $sensitiveFields = [
            'account_number',
            'routing_number',
            'secret_key',
            'access_token',
            'api_key',
            'auth_code'
        ];

        return in_array(strtolower($key), $sensitiveFields);
    }
}