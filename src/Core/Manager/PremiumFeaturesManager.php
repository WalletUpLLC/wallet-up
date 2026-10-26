<?php
namespace WalletUpPro\Core\Manager;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class PremiumFeaturesManager
{
    private static $instance = null;
    private $premium_status = false;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->checkPremiumStatus();

        add_action('admin_init', array($this, 'checkPremiumStatus'));

        $fs = function_exists('WalletUpPro\\wallet_up_fs') ? \WalletUpPro\wallet_up_fs() : null;
        if (is_object($fs)) {
            $fs->add_action('after_license_activation', array($this, 'refreshPremiumStatus'));
            $fs->add_action('after_license_change', array($this, 'refreshPremiumStatus'));
        }
    }

    public function checkPremiumStatus(): bool
    {
        $this->premium_status = \WalletUpPro\wallet_up_premium_active();

        return $this->premium_status;
    }

    public function refreshPremiumStatus(): void
    {
        $this->checkPremiumStatus();
    }

    public function addScriptData(array $data): array
    {
        $data['isPremium'] = $this->premium_status;

        $data['selfServeUrl'] = 'https://walletup.app/activate';

        return $data;
    }
}