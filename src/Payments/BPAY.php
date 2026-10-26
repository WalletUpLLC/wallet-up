<?php
namespace WalletUpPro\Payments;

use WalletUpPro\Core\Database\DatabaseManager;

if (!defined('ABSPATH')) {
    exit;
}

class BPAY extends BankTransferMethod
{
    public function __construct(DatabaseManager $db)
    {
        parent::__construct('bpay', $db);
    }

    public function qrPayload(string $amount = '', string $reference = ''): string
    {
        $hosted = \WalletUpPro\Core\Payments\HostedPayPage::url('bpay', $amount, $reference);
        if ($hosted !== '') {
            return $hosted;
        }

        $biller = substr(preg_replace('/\D/', '', $this->getHolder()), 0, 10);
        $crn    = substr(preg_replace('/\D/', '', $reference), 0, 20);
        if ($biller === '' || $crn === '') {
            return '';
        }

        $parts = ['1=' . $biller, '2=' . $crn];
        if ($amount !== '' && (float) $amount > 0) {
            $parts[] = '3=' . number_format((float) $amount, 2, '.', '');
        }
        $parts[] = '4=' . gmdate('dmY', (int) current_time('timestamp'));

        return 'bpay.com/p ' . implode(' ', $parts);
    }

    public function getTitle(): string
    {
        return __('BPAY', 'walletup');
    }

    public function getDescription(): string
    {
        return __('Pay from any Australian bank account using a BPAY Biller Code and reference.', 'walletup');
    }

}
