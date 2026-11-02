/* Wallet Up 6.5.0: free-plan additions rendered beside the React admin app.
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
  function findText(sel, re) { var r = root(); if (!r) return null; return Array.prototype.find.call(r.querySelectorAll(sel), function (e) { return re.test(e.textContent.trim()); }) || null; }

  /* ---- Welcome step: geography line ---- */
  function mountGeo() {
    if (document.getElementById('wup-geo-line')) return;
    var g = cfg.geo || {}; if (!g.country || g.isUS) return;
    var h = findText('h1,h2', /^Welcome to Wallet Up$/); if (!h) return;
    var box = el('div', 'mt-4 p-3 rounded-lg border border-amber-200 bg-amber-50 text-sm text-amber-900'); box.id = 'wup-geo-line';
    var strong = el('strong', '', fmt(t.geoLine, g.countryName || g.country) + ' ');
    var body = el('span', '', (g.doesNotWork && g.doesNotWork.length ? fmt(t.geoUsOnly, names(g.doesNotWork)) + ' ' : '') + (t.geoWorks || '') + ' ' + (t.geoPro || ''));
    box.appendChild(strong); box.appendChild(body);
    var actions = el('div', 'mt-2 flex gap-3');
    var us = el('button', 'text-sm font-medium text-purple-700 underline', t.imInUs || "I'm in the US"); us.type = 'button';
    us.addEventListener('click', function () { us.disabled = true; post('wallet_up_set_country', { country: 'US' }).then(function (j) { if (j && j.success) { cfg.geo = j.data.geo; box.remove(); } else { us.disabled = false; } }); });
    actions.appendChild(us); box.appendChild(actions);
    var sub = h.nextElementSibling; (sub && sub.tagName === 'P' ? sub : h).insertAdjacentElement('afterend', box);
  }

  /* ---- Zelle explainer under the Zelle username field (wizard) ---- */
  function mountZelleHint() {
    if (document.getElementById('wup-zelle-hint')) return;
    var label = findText('label', /^Your Zelle username/); if (!label) return;
    var input = label.parentElement && label.parentElement.querySelector('input');
    if (input) { input.placeholder = 'you@example.com or 555-123-4567'; }
    var p = el('p', 'text-xs text-gray-500 mt-1', t.zelleHow || ''); p.id = 'wup-zelle-hint';
    (input || label).insertAdjacentElement('afterend', p);
  }

  /* ---- Payments page: Zelle bank-QR upload ---- */
  function mountZelleUpload() {
    if (document.getElementById('wup-zelle-qr')) return;
    var rowLabel = findText('span', /^Zelle$/); if (!rowLabel) return;
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
    var h = findText('h1,h2', /ready to accept payments/i); if (!h) return;
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
      on.addEventListener('click', function () { on.disabled = true; post('wallet_up_notify_optin', { email: email.value }).then(function (j) { if (j && j.success) { cfg.notifyEmail = j.data.email; form.innerHTML = ''; form.appendChild(el('p', 'text-sm text-gray-700', fmt(t.notifyOn, j.data.email))); } else { msg.textContent = (j && j.data && j.data.message) || t.error; on.disabled = false; } }).catch(function () { msg.textContent = t.error; on.disabled = false; }); });
      later.addEventListener('click', function () { box.remove(); window.sessionStorage && sessionStorage.setItem('wupNotifyLater', '1'); });
      if (window.sessionStorage && sessionStorage.getItem('wupNotifyLater')) return;
      form.appendChild(email); form.appendChild(on); form.appendChild(later); form.appendChild(msg); box.appendChild(form);
    }
    var anchor = findText('button', /Just go to dashboard/); (anchor && anchor.parentElement ? anchor.parentElement : card).insertAdjacentElement('beforebegin', box);
  }

  var pending = null;
  function tick() { pending = null; try { mountGeo(); mountZelleHint(); mountZelleUpload(); mountNotify(); } catch (e) { /* never break the admin */ } }
  function schedule() { if (pending) return; pending = setTimeout(tick, 150); }
  document.addEventListener('DOMContentLoaded', function () { schedule(); var r = root(); if (r && window.MutationObserver) new MutationObserver(schedule).observe(r, { childList: true, subtree: true }); });
})();
