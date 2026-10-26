<?php
namespace WalletUpPro\Payments;

use WalletUpPro\Core\Database\DatabaseManager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * PayTo (Australia) — the newer real-time account-to-account rail on the NPP. The payer
 * authorises a payment from their own bank app to a PayID (email/phone/ABN) or BSB+account.
 * No clickable payment URL; runs through WalletUp's manual-confirm P2P flow (awaiting_confirm).
 * The target is the `payto_holder` setting.
 */
class PayTo extends BankTransferMethod
{
    public function __construct(DatabaseManager $db)
    {
        parent::__construct('payto', $db);
    }

    public function getTitle(): string
    {
        return __('PayTo', 'walletup');
    }

    public function getDescription(): string
    {
        return __('Pay directly from your Australian bank account via PayTo (PayID or BSB).', 'walletup');
    }

}
