<?php
namespace WalletUpPro\Payments;

use WalletUpPro\Core\Database\DatabaseManager;

if (!defined('ABSPATH')) {
    exit;
}

class Interac extends BankTransferMethod
{
    public function __construct(DatabaseManager $db)
    {
        parent::__construct('interac', $db);
    }

    public function getTitle(): string
    {
        return __('Interac e-Transfer', 'walletup');
    }

    public function getDescription(): string
    {
        return __('Send money from any Canadian bank account using an email or phone number.', 'walletup');
    }

}
