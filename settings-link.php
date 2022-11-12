<?php

if ( !defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'plugin_action_links_' . WALLET_UP_BASENAME, 'wallet_up_settings_link' );
// Settings Button
function wallet_up_settings_link( $links_array ) {
	array_unshift( $links_array, '<a href="' . esc_url( admin_url( 'admin.php?page=wallet-up', __FILE__ ) ) . '">Settings</a>' );

	return $links_array;
}

?>