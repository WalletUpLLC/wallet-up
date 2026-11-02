/* Wallet Up 6.6.0: block-checkout entry for the free one-method gateway. No build step. */
(function () {
  if (!window.wc || !window.wc.wcBlocksRegistry || !window.wp || !window.wp.element) return;
  var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
  var el = window.wp.element.createElement;
  var getSetting = window.wc.wcSettings && window.wc.wcSettings.getSetting;
  var decode = (window.wp.htmlEntities && window.wp.htmlEntities.decodeEntities) || function (s) { return s; };
  var s = getSetting ? getSetting('wallet_up_p2p_data', {}) : {};
  var title = decode(s.title || 'Wallet Up');
  var label = el('span', { style: { display: 'flex', alignItems: 'center', gap: '8px' } },
    s.icon ? el('img', { src: s.icon, alt: '', style: { height: '20px' } }) : null,
    el('span', null, title));
  var content = el('div', null, decode(s.description || ''));
  registerPaymentMethod({
    name: 'wallet_up_p2p',
    label: label,
    content: content,
    edit: content,
    canMakePayment: function () { return true; },
    ariaLabel: title,
    supports: { features: s.supports || ['products'] }
  });
})();
