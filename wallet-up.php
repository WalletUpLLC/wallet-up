<?php
/**
 * Plugin Name: Wallet Up
 * Description: The Wallet Up "Virtual Wallet" has it all: Easily Integrate Zelle, Facebook pay, Cash App, Venmo, PayPal with a generated QR Code anywhere on your Online Web Site. Get started now!
 * Plugin URI: https://walletup.app/wallet-up
 * Author: Wallet Up
 * Version: 3.2.3
 * Author URI: https://walletup.app/
 *
 * Text Domain: walletup
 *
 * @package Wallet Up
 * @category Core
 *
 * Wallet Up is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * Wallet Up is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 */


if ( !defined( 'ABSPATH' ) ) {
	exit;
}

include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
include_once( ABSPATH . 'wp-includes/pluggable.php' );
include_once( ABSPATH . 'wp-includes/option.php' );
add_filter( 'widget_text', 'shortcode_unautop' );
add_filter( 'widget_text', 'do_shortcode' );


/**
 * Currently plugin version.
 */
define( 'WALLET_UP_VERSION', '3.2.1' );


/** Plugin paths **/
define( 'WALLET_UP_BASE_DIR', plugin_dir_path( __FILE__ ) );
define( 'WALLET_UP_BASENAME', plugin_basename( __FILE__ ) );
define( 'WALLET_UP_BASE_URL', plugins_url( '/', __FILE__ ) );

/**
 * Define plugin admin access capability.
 */
if ( !defined( 'WALUP_ADMIN_CAP' ) )
	define( 'WALUP_ADMIN_CAP', 'manage_options' );

/**
 * Define plugin menu access capability
 */
if ( !defined( 'WALUP_MENU_ACCESS_CAP' ) )
	define( 'WALUP_MENU_ACCESS_CAP', 'manage_options' );

if(!defined('WALUP_ADMIN_MIN_CSS'))
  define('WALUP_ADMIN_MIN_CSS', WALLET_UP_BASE_URL . 'assets/css/walletup.min.css');

if(!defined('WALUP_ADMIN_TAB_CSS'))
  define('WALUP_ADMIN_TAB_CSS', WALLET_UP_BASE_URL . 'assets/css/walletup.tabs.css');

if(!defined('WALUP_FONTAWESOME_CSS'))
  define('WALUP_FONTAWESOME_CSS', WALLET_UP_BASE_URL . 'assets/css/walletup.fontawesome.css'. 'rel="stylesheet"');

if ( current_user_can( WALUP_ADMIN_CAP ) ) {
	add_action( 'activated_plugin', function ( $plugin ) {
		if ( $plugin == WALLET_UP_BASENAME ) {
			exit( wp_redirect( admin_url( 'admin.php?page=wallet-up', __FILE__ ) ) );
		}
	} );

	include_once WALLET_UP_BASE_DIR . 'core/dash/settings-link.php';

}

require_once WALLET_UP_BASE_DIR . 'core/enqueue-walletup.php';

?>
