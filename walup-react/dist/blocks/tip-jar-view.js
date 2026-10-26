(function () {
  'use strict';

  function legacyBuildUrl(method, holder, amount) {
    holder = String(holder || '').trim();
    amount = String(amount).replace(/[^0-9.]/g, '');
    if (!holder || !amount) return '';
    switch (method) {
      case 'cashapp': {
        var tag = holder.replace(/^\$/, '');
        return 'https://cash.app/$' + encodeURIComponent(tag) + '/' + amount;
      }
      case 'paypal': {
        var user = holder.replace(/^@/, '');
        return 'https://paypal.me/' + encodeURIComponent(user) + '/' + amount;
      }
      case 'venmo': {
        var vuser = holder.replace(/^@/, '');
        return 'https://venmo.com/' + encodeURIComponent(vuser) + '?txn=pay&amount=' + amount;
      }
      default:
        return '';
    }
  }

  function resolveUrl(btn, amount) {
    var cleanAmt = String(amount).replace(/[^0-9.]/g, '');
    if (!cleanAmt) return '';
    var template = btn.getAttribute('data-url-template');
    if (template && template.indexOf('__WUP_AMT__') !== -1) {
      return template.replace(/__WUP_AMT__/g, encodeURIComponent(cleanAmt));
    }
    var method = btn.getAttribute('data-method');
    var holder = btn.getAttribute('data-holder');
    return legacyBuildUrl(method, holder, cleanAmt);
  }

  function flashInvalid(input) {
    input.focus();
    input.classList.add('walletup-tipjar-input-invalid');
    setTimeout(function () {
      input.classList.remove('walletup-tipjar-input-invalid');
    }, 1500);
  }

  function showCustomUnsupportedNotice(wrapper) {
    if (!wrapper) return;
    var existing = wrapper.querySelector('.walletup-tipjar-unsupported-notice');
    if (existing) return;
    var notice = document.createElement('div');
    notice.className = 'walletup-tipjar-unsupported-notice';
    notice.setAttribute('role', 'alert');
    notice.textContent = (window.walletUpTipJarStrings && window.walletUpTipJarStrings.customNotSupported)
      || 'Custom amounts are not supported for this payment method: please use one of the preset buttons above.';
    wrapper.appendChild(notice);
    setTimeout(function () { if (notice.parentNode) notice.parentNode.removeChild(notice); }, 6000);
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.walletup-tipjar-custom-btn');
    if (!btn) return;
    e.preventDefault();
    var wrapper = btn.closest('.walletup-tipjar-custom');
    var input = wrapper ? wrapper.querySelector('.walletup-tipjar-custom-input') : null;
    if (!input) return;
    var amount = parseFloat(input.value);
    if (!amount || amount <= 0) {
      flashInvalid(input);
      return;
    }
    var url = resolveUrl(btn, amount);
    if (url) {
      window.open(url, '_blank', 'noopener,noreferrer');
    } else {
      showCustomUnsupportedNotice(wrapper);
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter') return;
    if (!e.target.classList || !e.target.classList.contains('walletup-tipjar-custom-input')) return;
    e.preventDefault();
    var btn = e.target.parentElement && e.target.parentElement.querySelector('.walletup-tipjar-custom-btn');
    if (btn) btn.click();
  });
})();
