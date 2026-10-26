(function () {
    if (!window.walletUpTrack) {
        console.warn('[WalletUp] walletUpTrack config not found');
        return;
    }
    var tracked = {};
    document.addEventListener('click', function (e) {
        var el = e.target.closest('[data-wallet-up-method]');
        if (!el) {
            el = e.target.closest('.walletup-qr-link, .walletup-button-link, .walletup-payment-link');
        }
        if (!el) return;
        var method = el.getAttribute('data-wallet-up-method') || el.getAttribute('data-walletup-link') || 'unknown';
        var postId = el.getAttribute('data-wallet-up-post') || '0';
        var key = method + '_' + postId;
        if (tracked[key]) {
            return;
        }
        tracked[key] = true;
        var d = new FormData();
        d.append('action', 'wallet_up_track_click');
        d.append('nonce', window.walletUpTrack.nonce);
        d.append('method', method);
        d.append('post_id', postId);
        navigator.sendBeacon(window.walletUpTrack.ajaxUrl, d);
    }, true);
})();
