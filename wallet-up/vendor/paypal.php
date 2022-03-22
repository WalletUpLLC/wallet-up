<?php

if ( !defined( 'ABSPATH' ) ) {
	exit;
}

add_shortcode( 'paypal', 'wallet_up_front_end_paypal' );

function wallet_up_front_end_paypal( $atts ) {
	/*
	 * Here we rerieve the defined values
	 */
	$wallet_up_transfer = get_option( 'wallet_up_transfer_call' ); // Array of All Options

	if ( !isset( $wallet_up_transfer[ 'paypal_holder' ] ) ) {
		$paypal_holder = '';
	} else {
		$paypal_holder = $wallet_up_transfer[ 'paypal_holder' ];
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
	), $atts ) );

	if ( $scan == 'yes' ) {
		$psrc = esc_url( WALLET_UP_CHART_API ) . "https://www.paypal.com/paypalme" . "/" . esc_attr( wp_kses_post( $paypal_holder ) ) . "/" . esc_attr( wp_kses_post( $amount ) );
		$walup_pay = '&nbsp;' . esc_attr( wp_kses_post( '$' . $amount ) );
		$url_paypal_yes = ( '<p style="padding: 10px 10px 10px 0; max-width: 280px; text-align: center;' . $walup_pagebox . '">' . $walup_pay_yes . '<strong>' . $walup_pay . '</strong>' . ' with Paypal' .
			'<a href= "https://www.paypal.com/paypalme/' . esc_attr( wp_kses_post( $paypal_holder ) ) . '/' . esc_attr( wp_kses_post( $amount ) ) . '" target="_blank">' .
			'<img style="float: none!important; max-height:180px!important; max-width:180px!important;" alt="Paypal" src="' . $psrc . '">' . '</a></p>' );

		return $url_paypal_yes;
		?>
		<script>
			function walup_paypal_yes() {
				let text = "IMPORTANT! DO NOT CLOSE THIS WINDOWS: Confirm returning to complete the form";
				if ( confirm( text ) == true ) {
					return window.open( encodeURI( $url_paypal_yes ) );
				} else {
					document.write( "Sorry! You canceled! <strong>Please wait 3s...</strong> or refresh to try again" );
					event.preventDefault();
					setTimeout( "location.reload(true);", 3000 );
				}
			}
		</script>
		<?php

	} elseif ( $scan == 'no' ) {
		$imgsrc = esc_url( WALLET_UP_BASE_URL . 'assets/lib/paypal-walup.png' );
		$walup_pay = '&nbsp;' . esc_attr( wp_kses_post( '$' . $amount ) );
		$url_paypal_no = ( '<p style="padding: 10px 10px 10px 0; max-width: 180px; text-align: center;' . $walup_pagebox . '">' . $walup_pay_no . '<strong>' . $walup_pay . '</strong>' . ' with Paypal' .
			'<a href="https://www.paypal.com/paypalme/' . esc_attr( wp_kses_post( $paypal_holder ) ) . '/' . esc_attr( wp_kses_post( $amount ) ) . '" target="_blank">' .
			'<img style="float: none!important; max-height:180px!important; max-width:180px!important;" alt="Paypal" src="' . $imgsrc . '">' . '</a></p>' );

		return $url_paypal_no;
		?>
			<script>
				function walup_paypal_no() {
					let text = "IMPORTANT! DO NOT CLOSE THIS WINDOWS: Confirm returning to complete the form";
					if ( confirm( text ) == true ) {
						return window.open( encodeURI( $url_paypal_no ) );
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
