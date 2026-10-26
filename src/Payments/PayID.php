<?php
namespace WalletUpPro\Payments;

use WalletUpPro\Core\Database\DatabaseManager;

if (!defined('ABSPATH')) {
    exit;
}

class PayID extends BankTransferMethod
{
    public function __construct(DatabaseManager $db)
    {
        parent::__construct('payid', $db);
    }

    public function getTitle(): string
    {
        return __('PayID', 'walletup');
    }

    public function getDescription(): string
    {
        return __('Pay instantly to an email, phone, or ABN via PayID and Osko.', 'walletup');
    }

}
