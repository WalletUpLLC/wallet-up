/* Wallet Up 6.5.0+: free-plan additions rendered beside the React admin app.
 * - 6.7.0: Setup check card on Dashboard and Payments.
 * - Welcome step: one line saying which payment apps work in the site's country.
 * - Step 3 and Payments: Zelle explainer and bank-QR upload.
 * - Step 4: "tell me when someone pays" opt-in, offered after the first QR exists. */
(function () {
  if (!window.wupFreeUi) return;
  var cfg = window.wupFreeUi, t = cfg.i18n || {};

  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; }
  function fmt(s, v) { return String(s || '').replace('%s', v); }
  function post(action, data) {
    var fd = new FormData(); fd.append('action', action); fd.append('nonce', cfg.nonce);
    Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); });
  }
  function names(ids) { var n = (ids || []).map(function (id) { return (cfg.methodNames && cfg.methodNames[id]) || id; }); return n.length > 1 ? n.slice(0, -1).join(', ') + ' and ' + n[n.length - 1] : n.join(''); }
  function root() { return document.getElementById('wallet-up-admin-root'); }
  /* Anchors match the English, Spanish and French strings shipped in the compiled app (6.7.0). */
  var RX = {
    welcome: /^(Welcome to Wallet Up|Bienvenue (dans|sur) Wallet Up|Te damos la bienvenida a Wallet Up|Bienvenid[oa] a Wallet Up)$/,
    ready: /(ready to accept payments|prêt à recevoir des paiements|Ya puedes recibir pagos|list[oa] para (aceptar|recibir) pagos)/i,
    skip: /(Just go to dashboard|Ir al panel|Aller au tableau de bord)/,
    dashboard: /^(Dashboard|Panel|Tableau de bord)$/,
    checks: /^(Dashboard|Panel|Tableau de bord|Payments|Pagos|Paiements|Payment Methods)$/,
    zelleLabel: /^(Your|Tu|Votre)[^\n]*Zelle/i
  };
  function findText(sel, re) { var r = root(); if (!r) return null; return Array.prototype.find.call(r.querySelectorAll(sel), function (e) { return re.test(e.textContent.trim()); }) || null; }

  /* ---- Welcome step: geography line ---- */
  function mountGeo() {
    if (document.getElementById('wup-geo-line')) return;
    var g = cfg.geo || {};
    /* A confident US detection (owner, WooCommerce or timezone) needs no line. A default en_US install
     * with no timezone is "US by locale" only, so it gets the neutral line with a country chooser. */
    var confident = g.isUS && g.source && g.source !== 'locale' && g.source !== 'unknown';
    if (confident) return;
    var h = findText('h1,h2', RX.welcome); if (!h) return;
    var abroad = !!g.country && !g.isUS;
    var box = el('div', 'mt-4 p-3 rounded-lg border border-amber-200 bg-amber-50 text-sm text-amber-900'); box.id = 'wup-geo-line';
    if (abroad) {
      box.appendChild(el('strong', '', fmt(t.geoLine, g.countryName || g.country) + ' '));
      box.appendChild(el('span', '', (g.doesNotWork && g.doesNotWork.length ? fmt(t.geoUsOnly, names(g.doesNotWork)) + ' ' : '') + (t.geoWorks || '') + ' ' + (t.geoPro || '')));
    } else {
      box.appendChild(el('span', '', t.geoUnknown || ''));
    }
    function setGeo(geo) { cfg.geo = geo; if (window.walletUpData) { window.walletUpData.geoHint = geo; } }
    var actions = el('div', 'mt-2 flex flex-wrap gap-3 items-center');
    if (abroad) {
      var us = el('button', 'text-sm font-medium text-purple-700 underline', t.imInUs || "I'm in the US"); us.type = 'button';
      us.addEventListener('click', function () { us.disabled = true; post('wallet_up_set_country', { country: 'US' }).then(function (j) { if (j && j.success) { setGeo(j.data.geo); box.remove(); } else { us.disabled = false; } }); });
      actions.appendChild(us);
    }
    var pick = el('label', 'text-sm text-amber-900'); pick.appendChild(document.createTextNode((t.geoChoose || 'Choose your country') + ' '));
    var sel = el('select', 'border border-amber-300 rounded px-2 py-1 text-sm bg-white'); var blank = el('option', '', '—'); blank.value = ''; sel.appendChild(blank);
    Object.keys(cfg.countries || {}).forEach(function (cc) { var o = el('option', '', cfg.countries[cc]); o.value = cc; if (g.country === cc && g.source === 'owner') o.selected = true; sel.appendChild(o); });
    sel.addEventListener('change', function () { if (!sel.value) return; sel.disabled = true; post('wallet_up_set_country', { country: sel.value }).then(function (j) { sel.disabled = false; if (j && j.success) { setGeo(j.data.geo); box.remove(); mountGeo(); var line = document.getElementById('wup-geo-line'); if (line) line.insertAdjacentElement('afterbegin', el('div', 'text-xs text-green-800 mb-1', fmt(t.geoSaved, j.data.geo.countryName || j.data.geo.country))); } }); });
    pick.appendChild(sel); actions.appendChild(pick); box.appendChild(actions);
    var sub = h.nextElementSibling; (sub && sub.tagName === 'P' ? sub : h).insertAdjacentElement('afterend', box);
  }

  /* ---- Zelle explainer under the Zelle username field (wizard) ---- */
  function mountZelleHint() {
    if (document.getElementById('wup-zelle-hint')) return;
    var label = findText('label', RX.zelleLabel); if (!label) return;
    var input = label.parentElement && label.parentElement.querySelector('input');
    if (input) { input.placeholder = 'you@example.com or 555-123-4567'; }
    var p = el('p', 'text-xs text-gray-500 mt-1', t.zelleHow || ''); p.id = 'wup-zelle-hint';
    (input || label).insertAdjacentElement('afterend', p);
  }

  /* ---- Payments page: Zelle bank-QR upload ---- */
  function mountZelleUpload() {
    if (document.getElementById('wup-zelle-qr')) return;
    /* The Payments list renders the method name in an h3; the span form was a closed dropdown option. */
    var r0 = root(); var rowLabel = r0 && Array.prototype.find.call(r0.querySelectorAll('h3,span'), function (e) { return /^Zelle$/.test(e.textContent.trim()) && e.getClientRects().length > 0; }); if (!rowLabel) return;
    var row = rowLabel.closest('div.flex, div[class*="items-center"]'); if (!row) return;
    var wrap = el('div', 'mt-2 ml-14 text-sm text-gray-600'); wrap.id = 'wup-zelle-qr';
    var status = el('div', 'text-xs text-gray-500 mb-2');
    var preview = el('img'); preview.style.maxWidth = '120px'; preview.style.display = 'none'; preview.className = 'rounded border border-gray-200 mb-2';
    var input = el('input'); input.type = 'file'; input.accept = 'image/png,image/jpeg'; input.className = 'text-xs';
    var remove = el('button', 'ml-2 text-xs text-red-600 underline', t.zelleRemove || 'Remove'); remove.type = 'button';
    function render() {
      if (cfg.zelleQrUrl) { preview.src = cfg.zelleQrUrl; preview.style.display = 'block'; status.textContent = t.zelleUploaded || ''; remove.style.display = ''; }
      else { preview.style.display = 'none'; status.textContent = t.zelleNone || ''; remove.style.display = 'none'; }
    }
    input.addEventListener('change', function () {
      var f = input.files && input.files[0]; if (!f) return;
      var fr = new FileReader();
      fr.onload = function () {
        post('wallet_up_upload_zelle_qr', { image: fr.result }).then(function (j) {
          if (j && j.success && j.data && j.data.url) { return post('wallet_up_save_custom_qr', { method: 'zelle', url: j.data.url }).then(function (k) { if (k && k.success) { cfg.zelleQrUrl = j.data.url; render(); } else { status.textContent = t.error; } }); }
          status.textContent = (j && j.data && j.data.message) || t.error;
        }).catch(function () { status.textContent = t.error; });
      };
      fr.readAsDataURL(f);
    });
    remove.addEventListener('click', function () { post('wallet_up_save_custom_qr', { method: 'zelle', url: '' }).then(function (k) { if (k && k.success) { cfg.zelleQrUrl = ''; render(); } }); });
    wrap.appendChild(el('p', 'text-xs text-gray-500 mb-2', t.zelleHow || ''));
    wrap.appendChild(status); wrap.appendChild(preview);
    var line = el('label', 'text-xs text-gray-700'); line.appendChild(document.createTextNode((t.zelleUpload || 'Upload') + ' ')); line.appendChild(input); line.appendChild(remove);
    wrap.appendChild(line); render();
    row.insertAdjacentElement('afterend', wrap);
  }

  /* ---- Step 4: notifications opt-in ---- */
  function mountNotify() {
    if (document.getElementById('wup-notify')) return;
    var h = findText('h1,h2', RX.ready); if (!h) return;
    var card = h.closest('div'); if (!card) return;
    var box = el('div', 'mt-6 p-4 rounded-xl border border-purple-100 bg-purple-50'); box.id = 'wup-notify';
    if (cfg.notifyEmail) { box.appendChild(el('p', 'text-sm text-gray-700', fmt(t.notifyOn, cfg.notifyEmail))); }
    else {
      box.appendChild(el('p', 'font-semibold text-gray-900', t.notifyTitle || ''));
      box.appendChild(el('p', 'text-sm text-gray-600 mt-1', t.notifyBody || ''));
      var form = el('div', 'mt-3 flex flex-wrap gap-2 items-center');
      var email = el('input', 'border border-gray-300 rounded-lg px-3 py-2 text-sm'); email.type = 'email'; email.value = cfg.adminEmail || ''; email.placeholder = 'you@example.com';
      var on = el('button', 'bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg px-4 py-2 text-sm', t.notifyButton || ''); on.type = 'button';
      var later = el('button', 'text-sm text-gray-500 underline', t.notifyLater || ''); later.type = 'button';
      var msg = el('span', 'text-xs text-gray-500');
      var shareWrap = el('label', 'flex items-start gap-2 text-xs text-gray-600 w-full'); var share = el('input'); share.type = 'checkbox'; share.id = 'wup-notify-share'; share.className = 'mt-0.5';
      shareWrap.appendChild(share); shareWrap.appendChild(document.createTextNode(t.shareLabel || ''));
      box.appendChild(el('p', 'text-xs text-gray-500 mt-1', t.notifyHelp || ''));
      on.addEventListener('click', function () { on.disabled = true; post('wallet_up_notify_optin', { email: email.value, share: share.checked ? '1' : '0' }).then(function (j) { if (j && j.success) { cfg.notifyEmail = j.data.email; form.innerHTML = ''; form.appendChild(el('p', 'text-sm text-gray-700', fmt(t.notifyOn, j.data.email))); } else { msg.textContent = (j && j.data && j.data.message) || t.error; on.disabled = false; } }).catch(function () { msg.textContent = t.error; on.disabled = false; }); });
      later.addEventListener('click', function () { box.remove(); window.sessionStorage && sessionStorage.setItem('wupNotifyLater', '1'); });
      if (window.sessionStorage && sessionStorage.getItem('wupNotifyLater')) return;
      form.appendChild(email); form.appendChild(on); form.appendChild(later); form.appendChild(msg); box.appendChild(form); box.appendChild(shareWrap);
    }
    var anchor = findText('button', RX.skip); (anchor && anchor.parentElement ? anchor.parentElement : card).insertAdjacentElement('beforebegin', box);
  }

  /* ---- Dashboard: "This week" card answers "did anyone pay me?" ---- */
  function stat(n, label) { var d = el('div', 'flex-1 min-w-[100px]'); d.appendChild(el('div', 'text-2xl font-bold text-gray-900', String(n))); d.appendChild(el('div', 'text-xs text-gray-500', label)); return d; }
  function mountWeek() {
    if (cfg.isPremium || !cfg.counts || document.getElementById('wup-this-week')) return;
    var h = findText('h1', RX.dashboard); if (!h) return;
    var container = h.closest('.space-y-6'); if (!container) return;
    var c = cfg.counts, w = c.week || {};
    var card = el('div', 'rounded-lg border bg-card text-card-foreground shadow-sm overflow-hidden'); card.id = 'wup-this-week';
    var head = el('div', 'w-full flex items-center justify-between p-4'); head.appendChild(el('span', 'font-semibold text-gray-900 text-base', t.weekTitle || 'This week')); card.appendChild(head);
    var body = el('div', 'px-4 pb-4 border-t border-gray-100 pt-4 space-y-3 text-sm text-gray-600'); card.appendChild(body);
    var row = el('div', 'flex flex-wrap gap-4'); row.appendChild(stat(w.impressions || 0, t.views || 'views')); row.appendChild(stat(w.clicks || 0, t.taps || 'taps')); row.appendChild(stat(w.confirmations || 0, t.confirmed || 'confirmed payments')); body.appendChild(row);
    if (c.recent && c.recent.length) {
      body.appendChild(el('div', 'text-xs font-semibold uppercase tracking-wide text-gray-500 pt-2', t.recentTitle || 'Latest confirmations'));
      var ul = el('ul', 'space-y-1');
      c.recent.forEach(function (r) { var li = el('li', 'flex items-center justify-between gap-2'); li.appendChild(el('span', '', (r.name || '—') + ' · ' + r.symbol + r.amount + ' · ' + r.method + ' · ' + r.when)); if (r.order) { var a = el('a', 'text-purple-600 hover:underline text-xs', t.viewOrder || 'View order'); a.href = (cfg.adminUrl || '/wp-admin/') + 'post.php?post=' + r.order + '&action=edit'; li.appendChild(a); } ul.appendChild(li); });
      body.appendChild(ul);
    } else if (!(w.impressions || w.clicks || w.confirmations)) {
      body.appendChild(el('p', 'pt-2', t.noActivity || ''));
      var ol = el('ol', 'list-decimal ml-5 space-y-1'); [t.tip1, t.tip2, t.tip3].forEach(function (x) { if (x) ol.appendChild(el('li', '', x)); }); body.appendChild(ol);
    }
    if (!c.notify) { var p = el('p', 'text-xs text-gray-500 pt-2'); var a2 = el('a', 'text-purple-600 hover:underline', t.notifyOff || ''); a2.href = '#'; a2.addEventListener('click', function (e) { e.preventDefault(); var em = window.prompt('Email', cfg.adminEmail || ''); if (!em) return; post('wallet_up_notify_optin', { email: em }).then(function (j) { if (j && j.success) { cfg.counts.notify = j.data.email; p.textContent = fmt(t.notifyOn, j.data.email); } }); }); p.appendChild(a2); body.appendChild(p); }
    var first = container.children[1] || null; container.insertBefore(card, first ? first.nextSibling : null);
  }

  /* ---- 6.7.0: Setup check card on Dashboard and Payments ---- */
  function checkRows(items) {
    var ul = el('ul', 'space-y-2');
    items.forEach(function (i) {
      var li = el('li', 'flex items-start gap-2 text-sm');
      var mark = el('span', 'font-bold w-4 shrink-0', i.ok === true ? '\u2713' : (i.ok === false ? '\u2717' : '\u2013'));
      mark.style.color = i.ok === true ? '#1e8449' : (i.ok === false ? '#b32d2e' : '#8c8f94');
      var body = el('div', ''); body.appendChild(el('div', 'font-medium text-gray-900', i.label));
      var d = el('div', 'text-xs text-gray-500'); d.textContent = i.detail;
      if (i.ok === false && i.url) { var a = el('a', 'ml-1 text-purple-600 hover:underline', t.checkFix || 'Fix'); a.href = i.url; d.appendChild(a); }
      body.appendChild(d); li.appendChild(mark); li.appendChild(body); ul.appendChild(li);
    });
    return ul;
  }
  function mountChecks() {
    if (!cfg.checks || document.getElementById('wup-self-check')) return;
    var h = findText('h1', RX.checks); if (!h) return;
    var container = h.closest('.space-y-6') || h.parentElement; if (!container) return;
    var r = cfg.checks;
    var card = el('div', 'rounded-lg border bg-card text-card-foreground shadow-sm overflow-hidden'); card.id = 'wup-self-check';
    var head = el('div', 'w-full flex items-center justify-between p-4');
    var title = el('span', 'font-semibold text-gray-900 text-base', t.checkTitle || 'Setup check');
    var summary = el('span', 'text-sm ' + (r.failed ? 'text-red-700 font-medium' : 'text-gray-500'), (t.checkPassed || '%1$d of %2$d passed').replace('%1$d', r.passed).replace('%2$d', r.total));
    head.appendChild(title); head.appendChild(summary); card.appendChild(head);
    var body = el('div', 'px-4 pb-4 border-t border-gray-100 pt-4'); body.style.display = r.failed ? 'block' : 'none';
    body.appendChild(checkRows(r.items)); card.appendChild(body);
    var foot = el('div', 'px-4 pb-4 flex gap-4 text-xs');
    var toggle = el('button', 'text-purple-600 hover:underline', r.failed ? (t.checkHide || 'Hide details') : (t.checkShow || 'Show details')); toggle.type = 'button';
    toggle.addEventListener('click', function () { var open = body.style.display !== 'none'; body.style.display = open ? 'none' : 'block'; toggle.textContent = open ? (t.checkShow || 'Show details') : (t.checkHide || 'Hide details'); });
    var rerun = el('button', 'text-gray-500 hover:underline', t.checkRerun || 'Run again'); rerun.type = 'button';
    rerun.addEventListener('click', function () { rerun.disabled = true; post('wallet_up_self_check', {}).then(function (j) { if (j && j.success) { cfg.checks = j.data; card.remove(); mountChecks(); var c2 = document.getElementById('wup-self-check'); if (c2) { var b2 = c2.querySelector('.border-t'); if (b2) b2.style.display = 'block'; } } rerun.disabled = false; }).catch(function () { rerun.disabled = false; }); });
    foot.appendChild(toggle); foot.appendChild(rerun); card.appendChild(foot);
    var week = document.getElementById('wup-this-week');
    if (week && week.parentElement === container) { week.insertAdjacentElement('afterend', card); }
    else { var first = container.children[1] || null; container.insertBefore(card, first ? first.nextSibling : null); }
    if (window.location.hash === '#wup-self-check') { body.style.display = 'block'; card.scrollIntoView(); }
  }

  var pending = null;
  function tick() { pending = null; try { mountGeo(); mountZelleHint(); mountZelleUpload(); mountNotify(); mountWeek(); mountChecks(); } catch (e) { /* never break the admin */ } }
  function schedule() { if (pending) return; pending = setTimeout(tick, 150); }
  document.addEventListener('DOMContentLoaded', function () { schedule(); var r = root(); if (r && window.MutationObserver) new MutationObserver(schedule).observe(r, { childList: true, subtree: true }); });
})();
