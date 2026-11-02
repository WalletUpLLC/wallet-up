/* Wallet Up 6.4.1: Branding and Setup cards on the Settings screen.
 * The admin app renders Settings as one "About" card; this script adds two
 * native cards beside it and keeps them in place as the app re-renders. */
(function () {
  if (!window.wupFreeSettings) return;
  var cfg = window.wupFreeSettings, t = cfg.i18n || {};
  var ID = 'wup-free-settings';

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text !== undefined) e.textContent = text;
    return e;
  }
  function card(title, badge) {
    var c = el('div', 'rounded-lg border bg-card text-card-foreground shadow-sm overflow-hidden');
    var head = el('div', 'w-full flex items-center justify-between p-4');
    var left = el('div', 'flex items-center gap-3 min-w-0');
    var ttl = el('span', 'font-semibold text-gray-900 text-base block truncate', title);
    left.appendChild(ttl);
    if (badge) left.appendChild(el('span', 'ml-2 text-[10px] px-1.5 py-0 rounded border text-purple-600 border-purple-200', badge));
    head.appendChild(left);
    c.appendChild(head);
    var body = el('div', 'px-4 pb-4 border-t border-gray-100 pt-4 space-y-3 text-sm text-gray-600');
    c.appendChild(body);
    c._body = body;
    return c;
  }
  function post(action, data) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('nonce', cfg.nonce);
    Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); });
  }

  function brandingCard() {
    var c = card(t.branding || 'Branding', cfg.isPremium ? '' : (t.pro || 'PRO'));
    var row = el('label', 'flex items-start gap-3 cursor-pointer');
    var box = el('input'); box.type = 'checkbox'; box.className = 'mt-1';
    box.checked = !cfg.hideBranding; box.disabled = !cfg.isPremium;
    var txt = el('div');
    txt.appendChild(el('div', 'font-medium text-gray-900', t.brandingLabel || 'Show "Powered by WalletUp" under payment cards'));
    txt.appendChild(el('div', 'text-xs text-gray-500 mt-0.5', t.brandingHelp || ''));
    row.appendChild(box); row.appendChild(txt); c._body.appendChild(row);
    var status = el('div', 'text-xs text-gray-500');
    if (!cfg.isPremium) {
      var p = el('p', 'text-xs text-gray-600');
      p.appendChild(document.createTextNode((t.brandingPro || '') + ' '));
      var a = el('a', 'text-purple-600 hover:underline font-medium', t.upgrade || 'See Pro');
      a.href = cfg.upgradeUrl || '#'; a.target = '_blank'; a.rel = 'noopener';
      p.appendChild(a); c._body.appendChild(p);
    } else {
      box.addEventListener('change', function () {
        status.textContent = '…';
        post('wallet_up_save_branding', { hide: box.checked ? '0' : '1' }).then(function (j) {
          status.textContent = j && j.success ? (t.saved || 'Saved.') : (t.error || 'Could not save.');
          if (j && j.success) cfg.hideBranding = !box.checked;
        }).catch(function () { status.textContent = t.error || 'Could not save.'; });
      });
      c._body.appendChild(status);
    }
    return c;
  }

  function setupCard() {
    var c = card(t.setup || 'Setup assistant');
    c._body.appendChild(el('p', '', t.setupHelp || ''));
    var btn = el('button', 'inline-flex items-center justify-center bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg px-4 py-2 text-sm', t.rerun || 'Run setup again');
    btn.type = 'button';
    btn.addEventListener('click', function () {
      if (!window.confirm(t.rerunConfirm || 'Open the setup assistant again?')) return;
      btn.disabled = true;
      post('wallet_up_rerun_setup').then(function (j) {
        if (j && j.success && j.data && j.data.redirect) { window.location.href = j.data.redirect; }
        else { btn.disabled = false; window.alert(t.error || 'Could not save.'); }
      }).catch(function () { btn.disabled = false; });
    });
    c._body.appendChild(btn);
    return c;
  }

  function mount() {
    var root = document.getElementById('wallet-up-admin-root');
    if (!root || document.getElementById(ID)) return;
    var h1 = Array.prototype.find.call(root.querySelectorAll('h1'), function (h) { return h.textContent.trim() === 'Settings'; });
    if (!h1) return;
    var container = h1.closest('.space-y-6');
    if (!container) return;
    var wrap = el('div', 'space-y-6'); wrap.id = ID;
    wrap.appendChild(brandingCard());
    wrap.appendChild(setupCard());
    container.appendChild(wrap);
  }

  var pending = null;
  function schedule() { if (pending) return; pending = setTimeout(function () { pending = null; mount(); }, 120); }
  document.addEventListener('DOMContentLoaded', function () {
    schedule();
    var root = document.getElementById('wallet-up-admin-root');
    if (root && window.MutationObserver) new MutationObserver(schedule).observe(root, { childList: true, subtree: true });
  });
})();
