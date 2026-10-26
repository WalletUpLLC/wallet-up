(function ($) {
  if (!walletUpVerification ||
      !walletUpVerification.ajaxUrl || 
      !walletUpVerification.orderId || 
      !walletUpVerification.nonce) {
    console.error('Wallet Up payment verification: Missing required data.');
    return;
  }

  const { ajaxUrl, orderId, nonce, checkInterval, i18n } = walletUpVerification;

  let verificationTimeout;

  function verifyPayment() {
    $.ajax({
      type: 'POST',
      url: ajaxUrl,
      data: {
        action: 'wallet_up_verify_payment',
        order_id: orderId,
        nonce: nonce
      },
      success: function (response) {
        if (response.success) {
          handleVerificationSuccess(response.data);
        } else {
          handleVerificationError(response.data);
        }
      },
      error: function (xhr, status, error) {
        console.error('Payment verification AJAX error:', error);
      }
    });
  }

  function handleVerificationSuccess(data) {
    const { status, message, redirect_url } = data;

    updateVerificationMessage(message);

    switch (status) {
      case 'completed':
        handlePaymentComplete(redirect_url);
        break;
      case 'pending':
      case 'processing':
        scheduleNextVerification();
        break;
      case 'failed':
        handlePaymentFailure();
        break;
    }
  }

  function handleVerificationError(data) {
    console.error('Payment verification error:', data.message);
    updateVerificationMessage(i18n.failed, 'error');
  }

  function updateVerificationMessage(message, type = 'info') {
    const $message = $('.wallet-up-payment-verification-message');

    if ($message.length) {
      $message.attr('class', `wallet-up-payment-verification-message ${type}`).text(message);
    } else {
      const $newMessage = $(`<div class="wallet-up-payment-verification-message ${type}">${message}</div>`);
      $('.entry-content').prepend($newMessage);
    }
  }

  function handlePaymentComplete(redirectUrl) {
    updateVerificationMessage(i18n.confirmed, 'success');

    if (redirectUrl) {
      setTimeout(function () {
        window.location = redirectUrl;
      }, 2000);
    }
  }

  function scheduleNextVerification() {
    verificationTimeout = setTimeout(verifyPayment, checkInterval);
  }

  function handlePaymentFailure() {
    updateVerificationMessage(i18n.failed, 'error');
  }

  $(window).on('beforeunload', function () {
    clearTimeout(verificationTimeout);
  });

  verifyPayment();

})(jQuery);