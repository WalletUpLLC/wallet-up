<?php
if ( !defined( 'ABSPATH' ) ) {
	exit;
}

$this->wallet_up_transfer = get_option( 'wallet_up_transfer_call' );
settings_errors();

?>
<div>
	<img src="<?php echo WALLET_UP_BASE_URL; ?>assets/lib/walup-logo.png" margin-top="50px" alt="Wallet Up Logo"/>
	<h6> ALL IN ONE SIMPLIFIED</h6>
	<br>
</div>
<div>
	<p>Easily Integrate Zelle, Facebook pay, Cash App, Venmo, PayPal with a generated QR Code anywhere on your Online Web Site</p>
	<br>
</div>
<div class="tabset">
	<!-- Tab 1 -->
	<input type="radio" name="tabset" id="tab1" aria-controls="settings" checked>
	<label for="tab1">Account Settings</label>
	<!-- Tab 2 -->
	<input type="radio" name="tabset" id="tab2" aria-controls="shortcodes">
	<label for="tab2">How to use the Shortcodes</label>

	<div class="tab-panels">

		<section id="settings" class="tab-panel">
			<form method="post" action="options.php">
				<?php
				settings_fields( 'wallet_up_transfer_settings' );
				do_settings_sections( 'wallet-up-admin' );
				submit_button();
				?>
			</form>
		</section>
		<section id="shortcodes" class="tab-panel">
			<h3>How to use the Shortcodes</h3>

			<div class="card-footer">
				<h6 class="card-title">Single Shortcode for FaceBook Pay - Activates Scanning by Default</h6>
				<h6 class="card-subtitle mb-2 text-muted">[fbpay]</h6>
			</div>

			<div class="card-footer">
				<h6 class="card-title">Single Shortcode for Paypal - Activates Scanning by Default</h6>
				<h6 class="card-subtitle mb-2 text-muted">[paypal]</h6>
			</div>

			<div class="card-footer">
				<h6 class="card-title">Single Shortcode for Cashapp - Activates Scanning by Default</h6>
				<h6 class="card-subtitle mb-2 text-muted">[cashapp]</h6>
			</div>

			<div class="card-footer">
				<h6 class="card-title">Single Shortcode for Venmo - Activates Scanning by Default</h6>
				<h6 class="card-subtitle mb-2 text-muted">[venmo]</h6>
			</div>

			<div class="card-footer">
				<h6 class="card-title">Single Shortcode for Zelle - Activates Scanning by Default</h6>
				<h6 class="card-subtitle mb-2 text-muted">[zelle]</h6>
			</div>

			<div class="card-footer">
				<h6 class="card-title">You can assign a FaceBook Pay preset amount to be respectively scanned or clicked</h6>
				<h6 class="card-subtitle mb-2 text-muted">[fbpay amount="60" scan="yes"]</h6>
				<h6 class="card-subtitle mb-2 text-muted"> or [fbpay amount="60" scan="no"]</h6>
			</div>

			<div class="card-footer">
				<h6 class="card-title">You can assign a Paypal preset amount to be respectively scanned or clicked</h6>
				<h6 class="card-subtitle mb-2 text-muted">[paypal amount="60" scan="yes"]</h6>
				<h6 class="card-subtitle mb-2 text-muted"> or [paypal amount="60" scan="no"]</h6>
			</div>

			<div class="card-footer">
				<h6 class="card-title">You can assign a Cashapp preset amount to be respectively scanned or clicked</h6>
				<h6 class="card-subtitle mb-2 text-muted">[cashapp amount="60" scan="yes"]</h6>
				<h6 class="card-subtitle mb-2 text-muted"> or [cashapp amount="60" scan="no"]</h6>
			</div>

			<div class="card-footer">
				<h6 class="card-title">You can assign a Venmo preset amount to be respectively scanned or clicked, with a note <strong>(Only for Venmo)</strong></h6>
				<h6 class="card-subtitle mb-2 text-muted">[venmo amount="60" scan="yes" note="Wallet Up Demo"]</h6>
			</div>

			<div class="card-footer">
				<h6 class="card-title"><br>IMPORTANT NOTICE</br>You need to specify a descriptive notice next to your Zelle shortcode that <br>includes your zelle account to be paid to. The Qr code can be scanned or clicked</h6>
				<h6 class="card-subtitle mb-2 text-muted">[zelle amount="15" scan="yes"]</h6>
				<h6 class="card-subtitle mb-2 text-muted"> or [zelle amount="15" scan="no"]</h6>
			</div>
		</section>
	</div>
	<div><button onclick="WALUP_NOTICE()">IMPORTANT NOTICE: CLICK HERE</button>

		<p id="walup_app_notice"></p>


		<script>
			function WALUP_NOTICE() {
				document.getElementById( "walup_app_notice" ).innerHTML = "It is recommended that you refer a link for an app store (iPhone or iPad) <br>or a link for play store (Android) to your users with the option to install <br>on their phones a bar code scanner in case it is needed.<br>More features are coming soon.";
			}
		</script>
	</div>
</div>
