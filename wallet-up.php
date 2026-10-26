<?php
/**
 * Plugin Name: Wallet Up
 * Description: Accept Cash App, Venmo, and PayPal payments on your WordPress site with QR codes and payment links.
 * Plugin URI: https://walletup.app/
 * Author: WalletUp.app
 * Version: 6.3.9
 * Requires at least: 5.8
 * Tested up to: 7.1
 * Requires PHP: 8.1
 * Author URI: https://walletup.app/
 * Text Domain: walletup
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * @package Wallet Up
 * @category Core
 */
if (!defined('ABSPATH')) { exit; }
if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error"><p><strong>Wallet Up</strong> requires PHP 8.1+. You have ' . esc_html(PHP_VERSION) . '.</p></div>';
    });
    return;
}
// Wallet Up Pro coexistence guard. The free and Pro builds declare the same
// WalletUpPro\ functions, classes, and constants. If Pro is already loaded in
// this request — most importantly when WordPress includes this file to fire the
// uninstall hook while deleting the free plugin with Pro active — loading the
// free build too would fatally redeclare them (Cannot redeclare ...). The
// WALLET_UP_IS_PRO_BUILD constant is true only in the Pro build, so finding it
// already true here means Pro loaded first: stand down and let Pro run.
if (defined('WALLET_UP_IS_PRO_BUILD') && WALLET_UP_IS_PRO_BUILD) {
    return;
}
require_once dirname(__FILE__) . '/wallet-up-start.php';
if (file_exists(WALLET_UP_PRO_PATH . 'src/Core/Admin/Notices/WalletUpProNoticeHandler.php')) {
    require_once WALLET_UP_PRO_PATH . 'src/Core/Admin/Notices/WalletUpProNoticeHandler.php';
}
