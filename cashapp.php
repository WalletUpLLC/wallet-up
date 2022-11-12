<?php

if ( !defined( 'ABSPATH' ) ) {
	exit;
}

add_shortcode( 'cashapp', 'wallet_up_front_end_cashapp' );

function wallet_up_front_end_cashapp( $atts ) {
	/*
	 * Here we rerieve the defined values
	 */
	$wallet_up_transfer = get_option( 'wallet_up_transfer_call' ); // Array of All Options

	if ( !isset( $wallet_up_transfer[ 'cashapp_holder' ] ) ) {
		$cashapp_holder = '';
	} else {
		$cashapp_holder = $wallet_up_transfer[ 'cashapp_holder' ];
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
		$csrc = esc_url( WALLET_UP_CHART_API ) . "https://cash.app" . "/" . esc_attr( wp_kses_post( $cashapp_holder ) ) . "/" . esc_attr( wp_kses_post( $amount ) );
		$walup_pay = '&nbsp;' . esc_attr( wp_kses_post( '$' . $amount ) );
		$url_cashapp_yes = ( '<p style="padding: 10px 10px 10px 0; max-width: 280px; text-align: center;' . $walup_pagebox . '">' . $walup_pay_yes . '<strong>' . $walup_pay . '</strong>' . ' with Cashapp' .
			'<a href="https://cash.app/' . esc_attr( wp_kses_post( $cashapp_holder ) ) . '/' . esc_attr( wp_kses_post( $amount ) ) . '" target="_blank" rel="noopener">' .
			'<img style="float: none!important; max-height:180px!important; max-width:180px!important;" alt="Cashapp" src="' . $csrc . '" >' . '</a></p>' );

		return $url_cashapp_yes;
		?>
		<script>
			function walup_cashapp_yes() {
				let text = "IMPORTANT! DO NOT CLOSE THIS WINDOWS: Confirm returning to complete the form";
				if ( confirm( text ) == true ) {
					return window.open( encodeURI( $url_cashapp_yes ) );
				} else {
					document.write( "Sorry! You canceled! <strong>Please wait 3s...</strong> or refresh to try again" );
					event.preventDefault();
					setTimeout( "location.reload(true);", 3000 );
				}
			}
		</script>
		<?php

	} elseif ( $scan == 'no' ) {
		$imgsrc = esc_url( WALLET_UP_BASE_URL . 'assets/lib/cashapp-walup.png' );
		$walup_pay = '&nbsp;' . esc_attr( wp_kses_post( '$' . $amount ) );
		$url_cashapp_no = ( '<p style="padding: 10px 10px 10px 0; max-width: 180px; text-align: center;' . $walup_pagebox . '">' . $walup_pay_no . '<strong>' . $walup_pay . '</strong>' . ' with Cashapp' .
			'<a href="https://cash.app/' . esc_attr( wp_kses_post( $cashapp_holder ) ) . '/' . esc_attr( wp_kses_post( $amount ) ) . '" target="_blank" rel="noopener">' .
			'<img style="float: none!important; max-height:180px!important; max-width:180px!important;" alt="Cashapp" src="' . $imgsrc . '" >' . '</a></p>');

		return $url_cashapp_no;
		?>
				<script>
					function walup_cashapp_no() {
						let text = "IMPORTANT! DO NOT CLOSE THIS WINDOWS: Confirm returning to complete the form";
						if ( confirm( text ) == true ) {
							return window.open( encodeURI( $url_cashapp_no ) );
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
