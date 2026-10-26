(function () {
    if (!window.wupEvt) return;
    var done = {};
    document.addEventListener('click', function (e) {
        var el = e.target.closest('[data-wallet-up-method]');
        if (!el) {
            el = e.target.closest('.walletup-qr-link, .walletup-button-link, .walletup-payment-link');
        }
        if (!el) return;
        var m = el.getAttribute('data-wallet-up-method') || el.getAttribute('data-walletup-link') || 'unknown';
        var p = el.getAttribute('data-wallet-up-post') || '0';
        var k = m + '_' + p;
        if (done[k]) return;
        done[k] = true;
        var d = new FormData();
        d.append('action', 'wallet_up_payment_event');
        d.append('nonce', window.wupEvt.n);
        d.append('method', m);
        d.append('post_id', p);
        d.append('type', 'click');
        navigator.sendBeacon(window.wupEvt.u, d);
    }, true);
})();

(function () {
    function fallbackCopy(text) {
        var ok = false;
        try {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            ok = document.execCommand('copy');
            document.body.removeChild(ta);
        } catch (e) {
            ok = false;
        }
        return ok;
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.walletup-copy-address[data-walletup-copy]');
        if (!btn) return;
        e.preventDefault();

        var address = btn.getAttribute('data-walletup-copy') || '';
        if (!address) return;

        var done = btn.getAttribute('data-copied-label') || btn.textContent;
        var original = btn.textContent;
        var flip = function () {
            btn.textContent = done;
            setTimeout(function () { btn.textContent = original; }, 1600);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(address).then(flip, function () {
                if (fallbackCopy(address)) flip();
            });
        } else if (fallbackCopy(address)) {
            flip();
        }
    });
})();
