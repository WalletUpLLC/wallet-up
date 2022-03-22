<?php

if ( !defined( 'ABSPATH' ) ) {
	exit;
}

add_shortcode( 'venmo', 'wallet_up_front_end_venmo' );

function wallet_up_front_end_venmo( $atts ) {
	/*
	 * Here we rerieve the defined values
	 */
	$wallet_up_transfer = get_option( 'wallet_up_transfer_call' ); // Array of All Options

	if ( !isset( $wallet_up_transfer[ 'venmo_holder' ] ) ) {
		$venmo_holder = '';
	} else {
		$venmo_holder = $wallet_up_transfer[ 'venmo_holder' ];
	}
	if ( !isset( $wallet_up_transfer[ 'walup_front_box_display' ] ) ) {
		$walup_pagebox = 'display: inline-block;';
	} else {
		$walup_pagebox = '';
	}
	if ( !isset( $wallet_up_transfer[ 'walup_front_box_text' ] ) || ( $wallet_up_transfer[ 'walup_front_box_text' ] == '' ) ) {
		$walup_pay_yes = 'Please <strong>Scan or Click</strong><br>to Pay';
	}
	if ( !isset( $wallet_up_transfer[ 'walup_front_box_text' ] ) || ( $wallet_up_transfer[ 'walup_front_box_text' ] == '' ) ) {
		$walup_pay_no = 'Please <strong>Click</strong><br>to Pay';
	} else {
		$walup_pay_yes = $wallet_up_transfer[ 'walup_front_box_text' ] . '<br>';
	}

	extract( shortcode_atts( array(
		'scan' => 'yes',
		'amount' => '',
		'note' => '',
	), $atts ) );

	if ( $scan == 'yes' ) {
		$vsrc = esc_url( WALLET_UP_CHART_API ) . "https://venmo.com" . "/" . esc_attr( wp_kses_post( $venmo_holder ) ) . urlencode( esc_attr( wp_kses_post( "?txn=pay&amount=" . $amount . '&note=' . $note ) ) );
		$walup_pay = '&nbsp;' . esc_attr( wp_kses_post( '$' . $amount ) );
		$url_venmo_yes = ( '<p style="padding: 10px 10px 10px 0; max-width: 180px; text-align: center;' . $walup_pagebox . '">' . $walup_pay_yes . '<strong>' . $walup_pay . '</strong>' . ' with Venmo' .
			'<a href="https://venmo.com/' . esc_attr( wp_kses_post( $venmo_holder ) ) . '?txn=pay&amount=' . esc_attr( wp_kses_post( $amount ) ) . '&note=' . esc_attr( wp_kses_post( $note ) ) . '"target="_blank">' .
			'<img style="float: none!important; max-height:180px!important; max-width:180px!important;" alt="Venmo" src=' . $vsrc . '">' . '</a></p>' );

		return $url_venmo_yes;
		?>
		<script>
			function walup_venmo_yes() {
				let text = "IMPORTANT! DO NOT CLOSE THIS WINDOWS: Confirm returning to complete the form";
				if ( confirm( text ) == true ) {
					return window.open( encodeURI( $url_venmo_yes ) );
				} else {
					document.write( "Sorry! You canceled! <strong>Please wait 3s...</strong> or refresh to try again" );
					event.preventDefault();
					setTimeout( "location.reload(true);", 3000 );
				}
			}
		</script>
		<?php

	} elseif ( $scan == 'no' ) {
		$imgsrc = esc_url( WALLET_UP_BASE_URL . 'assets/lib/venmo-walup.png' );
		$walup_pay = '&nbsp;' . esc_attr( wp_kses_post( '$' . $amount ) );
		$url_venmo_no = ( '<p style="padding: 10px 10px 10px 0; max-width: 180px; text-align: center;' . $walup_pagebox . '">' . $walup_pay_no . '<strong>' . $walup_pay . '</strong>' . ' with Venmo' .
			'<a href="https://venmo.com/' . esc_attr( wp_kses_post( $venmo_holder ) ) . '?txn=pay&amount=' . esc_attr( wp_kses_post( $amount ) ) . '&note=' . esc_attr( wp_kses_post( $note ) ) . '" target="_blank">' .
			'<img style="float: none!important; max-height:180px!important; max-width:180px!important;" alt="Venmo" src=' . $imgsrc . '">' . '</a></p>' );

		return $url_venmo_no;
		?>
			<script>
				function walup_venmo_no() {
					let text = "IMPORTANT! DO NOT CLOSE THIS WINDOWS: Confirm returning to complete the form";
					if ( confirm( text ) == true ) {
						return window.open( encodeURI( $url_venmo_no ) );
					} else {
						document.write( "Sorry! You canceled! <strong>Please wait 3s...</strong> or refresh to try again" );
						event.preventDefault();
						setTimeout( "location.reload(true);", 3000 );
					}
				}
			</script>
		<?php
	}
}
include_once WALLET_UP_BASE_DIR . 'vendor/google/chart.php';
