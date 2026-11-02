// 6.7.0 proof. Usage: node prove-670.js <label>   (fresh install expected)
const { chromium } = require('playwright'); const fs=require('fs');
const BASE='http://127.0.0.1:8080'; const label=process.argv[2]||'670';
const OUT=__dirname+'/proof-'+label; fs.mkdirSync(OUT,{recursive:true});
const DEBUG='/tmp/claude-0/-home-user-wallet-up/fd5e39c7-41c4-5b51-8c0a-c79cf509220e/scratchpad/wp/core/wp-content/debug.log';
const R={label,checks:{}}; let n=0;
const shot=async(p,name,full)=>{n++; const f=`${OUT}/${String(n).padStart(2,'0')}-${name}.png`; await p.screenshot({path:f,fullPage:!!full}); console.log('SHOT',f);};
const rest=async(p,path,init)=>p.evaluate(async([path,init])=>{let nonce=(window.wpApiSettings&&window.wpApiSettings.nonce)||''; if(!nonce){ nonce=await (await fetch('/wp-admin/admin-ajax.php?action=rest-nonce',{credentials:'same-origin'})).text(); }const h=Object.assign({'Content-Type':'application/json','X-WP-Nonce':nonce},(init&&init.headers)||{});const r=await fetch('/?rest_route='+path,Object.assign({credentials:'same-origin'},init||{},{headers:h}));const t=await r.text();try{const j=JSON.parse(t); if(j&&j.code){ j.status=r.status; } return j;}catch(e){return {status:r.status,text:t.slice(0,200)};}},[path,init||{}]);
const opt=async(p,name)=>(await rest(p,'/wup-test/option/'+name)).value;
const setOpt=async(p,name,value)=>rest(p,'/wup-test/set-option',{method:'POST',body:JSON.stringify({name,value})});
const UA='Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
(async()=>{
  const browser=await chromium.launch(); const ctx=await browser.newContext({viewport:{width:1440,height:900},userAgent:UA}); const page=await ctx.newPage(); page.on('dialog',d=>d.accept());
  const errs=[]; page.on('console',m=>{ if(m.type()==='error' && !/ERR_TUNNEL|403|500/.test(m.text())) errs.push(m.text().slice(0,160)); });
  await page.goto(`${BASE}/wp-login.php`); await page.fill('#user_login','admin'); await page.fill('#user_pass','password'); await page.click('#wp-submit'); await page.waitForLoadState('networkidle');
  await page.goto(`${BASE}/wp-admin/plugins.php`,{waitUntil:'networkidle'});
  let act=page.locator('tr[data-slug="wallet-up"] a[href*="action=activate"]').first();
  if(!(await act.count())){ await page.waitForTimeout(3000); await page.reload({waitUntil:'networkidle'}); act=page.locator('tr[data-slug="wallet-up"] a[href*="action=activate"]').first(); }
  await act.click({timeout:60000}); try{await page.waitForLoadState('networkidle',{timeout:60000});}catch(e){} await page.waitForTimeout(3500);
  R.checks.landing=page.url();
  R.checks.onboarding_mounted_fallback_hidden=await page.evaluate(()=>({mounted:!!document.querySelector('#wallet-up-admin-root').firstElementChild, fallback:getComputedStyle(document.getElementById('wallet-up-onboarding-fallback')).display, formInPage:!!document.querySelector('#wallet-up-onboarding-fallback form input[name=method_holder]')}));
  await shot(page,'onboarding-normal',true);
  R.checks.mount_failed_after_normal=await opt(page,'wallet_up_admin_mount_failed_at');

  // front-end cost (visitor, plugin active, nothing configured)
  const vis=await browser.newContext({userAgent:UA}); const fp=await (await vis.request.get(`${BASE}/?wup_footprint=1&list=1`)).json(); await vis.close(); R.checks.footprint_after={plugin_files:fp.plugin_files,plugin_kb:fp.plugin_kb,peak_mb:fp.peak_mb,freemius_loaded:fp.freemius_loaded,top:fp.files.slice(0,8)};
  const fpl=await (await ctx.request.get(`${BASE}/?wup_footprint=1`)).json(); R.checks.footprint_logged_in_owner={plugin_files:fpl.plugin_files,plugin_kb:fpl.plugin_kb,freemius_loaded:fpl.freemius_loaded};
  R.checks.footprint_before_measured_on_6_6_0={plugin_files:96,plugin_kb:2436,freemius_loaded:true};

  // blocked bundle: the React app never mounts
  const state=await ctx.storageState(); const b=await browser.newContext({viewport:{width:1440,height:900},storageState:state,userAgent:UA}); await b.route(/walup-react\/dist\/assets\/.*\.js/,r=>r.abort()); const bp=await b.newPage(); bp.on('dialog',d=>d.accept());
  await bp.goto(`${BASE}/wp-admin/admin.php?page=wallet-up-onboarding`,{waitUntil:'load'}); await bp.waitForTimeout(3800);
  R.checks.blocked_onboarding=await bp.evaluate(()=>({mounted:!!document.querySelector('#wallet-up-admin-root').firstElementChild, fallback:getComputedStyle(document.getElementById('wallet-up-onboarding-fallback')).display, text:(document.getElementById('wallet-up-onboarding-fallback').innerText||'').replace(/\s+/g,' ').slice(0,140)}));
  await shot(bp,'blocked-onboarding-fallback',true);
  R.checks.mount_failed_recorded=await opt(page,'wallet_up_admin_mount_failed_at'); R.checks.mount_failed_screen=await opt(page,'wallet_up_admin_mount_failed_on');
  await bp.goto(`${BASE}/wp-admin/admin.php?page=wallet-up`,{waitUntil:'load'}); await bp.waitForTimeout(3800);
  R.checks.blocked_dashboard_unconfigured=await bp.evaluate(()=>({fallback:getComputedStyle(document.getElementById('wallet-up-dashboard-fallback')).display, text:(document.getElementById('wallet-up-dashboard-fallback').innerText||'').replace(/\s+/g,' ').slice(0,300), rows:document.querySelectorAll('#wup-self-check-table tbody tr').length}));
  await shot(bp,'blocked-dashboard-unconfigured',true);
  // critical notice on a Wallet Up screen while the failure is recorded
  R.checks.critical_notice=(await bp.locator('#wup-critical-check').innerText().catch(()=>'')).replace(/\s+/g,' ');
  // simple form end to end (still blocked)
  await bp.goto(`${BASE}/wp-admin/admin.php?page=wallet-up-onboarding`,{waitUntil:'load'}); await bp.waitForTimeout(3200);
  const fbForm=bp.locator('#wallet-up-onboarding-fallback form');
  await fbForm.locator('input[name=holder_owner]').fill('Test Shop'); await fbForm.locator('input[name=holder_email]').fill('admin@example.com'); await fbForm.locator('select[name=primary_method]').selectOption('paypal'); await fbForm.locator('input[name=method_holder]').fill('TestShop');
  await fbForm.locator('button[type=submit]').click(); await bp.waitForLoadState('load'); await bp.waitForTimeout(3800);
  R.checks.after_simple_form_url=bp.url();
  R.checks.blocked_dashboard_configured=await bp.evaluate(()=>{const f=document.getElementById('wallet-up-dashboard-fallback'); return {fallback:getComputedStyle(f).display, hasQr:!!f.querySelector('img[src^="data:image/"]'), link:(f.querySelector('a[href*="paypal"]')||{}).href||'', saved:/Setup saved/.test(f.innerText), pageLink:(f.querySelector('a[href*="/?page_id="], a[href*="payment"]')||{}).href||'', text:f.innerText.replace(/\s+/g,' ').slice(0,260)};});
  await shot(bp,'blocked-dashboard-configured',true);
  R.checks.simple_form_saved={accounts:await opt(page,'wallet_up_transfer_call'),enabled:await opt(page,'wallet_up_enabled_methods'),completed:await opt(page,'wallet_up_onboarding_completed'),page_id:await opt(page,'wallet_up_payment_page_id')};
  const pid=R.checks.simple_form_saved.page_id; const pg=pid?await rest(page,'/wp/v2/pages/'+pid):null; R.checks.simple_form_page={status:pg&&pg.status,link:pg&&pg.link,title:pg&&pg.title&&pg.title.rendered};
  await b.close();

  // JavaScript off: noscript content
  const nj=await browser.newContext({viewport:{width:1440,height:900},storageState:state,javaScriptEnabled:false,userAgent:UA}); const np=await nj.newPage(); await np.goto(`${BASE}/wp-admin/admin.php?page=wallet-up`,{waitUntil:'load'}); await np.waitForTimeout(800);
  const nt=(await np.textContent('body')).replace(/\s+/g,' '); R.checks.nojs_dashboard={card:/Your PayPal\.me code and link/.test(nt), checks:/Setup check: \d of 9 passed/.test(nt), visibleQr:await np.locator('noscript img[src^="data:image/"], img[src^="data:image/"]').count()}; await shot(np,'nojs-dashboard',true); await nj.close();

  // normal admin again: the failure clears, the checks card shows on Dashboard and Payments
  await page.goto(`${BASE}/wp-admin/admin.php?page=wallet-up`,{waitUntil:'load'}); await page.waitForTimeout(3500);
  R.checks.mount_failed_cleared_after_ok=await opt(page,'wallet_up_admin_mount_failed_at');
  R.checks.dashboard_card=await page.evaluate(()=>{const c=document.getElementById('wup-self-check'); if(!c) return null; const b=c.querySelector('.border-t'); return {summary:c.querySelector('.p-4 span:last-child').textContent, detailsOpen:b&&getComputedStyle(b).display!=='none', rows:c.querySelectorAll('li').length, labels:[...c.querySelectorAll('li .font-medium')].map(e=>e.textContent), marks:[...c.querySelectorAll('li > span')].map(e=>e.textContent)};});
  await page.locator('#wup-self-check button:has-text("Show details"), #wup-self-check button:has-text("Hide details")').first().click().catch(()=>{}); await page.waitForTimeout(300);
  await shot(page,'dashboard-check-card',true);
  await page.locator('#wallet-up-admin-root :is(a,button):has-text("Payments")').first().click(); await page.waitForTimeout(2500);
  R.checks.payments_card=await page.evaluate(()=>{const c=document.getElementById('wup-self-check'); const h=document.querySelector('#wallet-up-admin-root h1'); return {h1:h&&h.textContent, card:!!c};});
  await shot(page,'payments-check-card',true);
  // plugins row meta
  await page.goto(`${BASE}/wp-admin/plugins.php`,{waitUntil:'load'});
  R.checks.plugins_row=(await page.locator('tr[data-slug="wallet-up"] .plugin-version-author-uri').innerText()).replace(/\s+/g,' '); R.checks.plugins_row_optin_link=await page.locator('tr[data-slug="wallet-up"] a:has-text("Opt In")').count()>0;
  await shot(page,'plugins-row');
  // critical notice via recorded failure, then cleared by a successful mount
  await setOpt(page,'wallet_up_admin_mount_failed_at',Math.floor(Date.now()/1000)-3600);
  await page.goto(`${BASE}/wp-admin/admin.php?page=wallet-up`,{waitUntil:'load'}); await page.waitForTimeout(3500);
  R.checks.critical_notice_normal=(await page.locator('#wup-critical-check').innerText().catch(()=>'')).replace(/\s+/g,' '); R.checks.critical_notice_visible=await page.evaluate(()=>{const n=document.getElementById('wup-critical-check'); if(!n) return false; const r=n.getBoundingClientRect(); return getComputedStyle(n).display!=='none'&&r.height>10&&r.width>100;}); await shot(page,'critical-notice');
  R.checks.critical_cleared_by_mount=await opt(page,'wallet_up_admin_mount_failed_at');

  // visitor: payment page renders, scan redirect works, live-section shortcode lazy-loads
  const v=await browser.newContext({userAgent:UA}); const vp=await v.newPage();
  if(pg&&pg.link){ await vp.goto(pg.link,{waitUntil:'load'}); const vh=await vp.content(); R.checks.visitor_page={paypal:/paypal\.com\/paypalme\/TestShop/.test(vh), qr:/<img[^>]+(data:image|wallet-up)/.test(vh)||/walletup-qr/.test(vh)}; await shot(vp,'visitor-payment-page',true); await vp.goto(pg.link,{waitUntil:'load'}); }
  const b64=Buffer.from('https://example.com/paid').toString('base64').replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');
  const scan=await v.request.get(`${BASE}/?wup_scan=paypal&r=${b64}`,{maxRedirects:0}); R.checks.scan_redirect={status:scan.status(),location:scan.headers()['location']||''};
  const live=await rest(page,'/wp/v2/pages',{method:'POST',body:JSON.stringify({title:'Live section',status:'publish',content:'[walletup_payment_section]'})});
  await vp.goto(live.link,{waitUntil:'load'}); const lh=await vp.content(); R.checks.live_section_renders=/paypalme\/TestShop/.test(lh);
  await v.close();
  // ---- audit fixes (6.7.0) ----
  { // owner views are not counted; legacy shortcode honours the off switch; Zelle without a bank QR draws no QR; tap beacon needs no nonce
    await page.goto(`${BASE}/wp-admin/index.php`,{waitUntil:'load'});
    const before=(await opt(page,'wallet_up_free_counts'))||{days:{}};
    const acc=Object.assign({},await opt(page,'wallet_up_transfer_call'),{cashapp_holder:'TestShopCash',zelle_holder:'pay@testshop.com'}); await setOpt(page,'wallet_up_transfer_call',acc);
    const en=Object.assign({},await opt(page,'wallet_up_enabled_methods'),{cashapp:false,zelle:true}); await setOpt(page,'wallet_up_enabled_methods',en);
    const ap=await rest(page,'/wp/v2/pages',{method:'POST',body:JSON.stringify({title:'Audit page',status:'publish',content:'<p>legacy off:</p>[cashapp amount="5"]<p>zelle:</p>[wallet_up method="zelle" amount="20"]'})});
    await page.goto(ap.link,{waitUntil:'load'}); await page.waitForTimeout(800); await page.goto(ap.link,{waitUntil:'load'}); await page.waitForTimeout(800);
    await page.goto(`${BASE}/wp-admin/index.php`,{waitUntil:'load'});
    const afterOwner=(await opt(page,'wallet_up_free_counts'))||{days:{}};
    const sum=o=>Object.values(o.days||{}).reduce((a,d)=>a+(d.i||0),0);
    R.checks.owner_views_not_counted={before:sum(before),afterOwner:sum(afterOwner),ok:sum(before)===sum(afterOwner)};
    const v2=await browser.newContext({userAgent:UA}); const p2=await v2.newPage(); await p2.goto(ap.link,{waitUntil:'networkidle'}); const h=await p2.content();
    R.checks.legacy_shortcode_off={comment:/<!-- wallet-up: cashapp is switched off -->/.test(h), noLink:!/cash\.app\/\$?TestShopCash/.test(h)};
    const zelleSection=h.split('zelle:')[1]||''; R.checks.zelle_no_generic_qr={paytoCard:/walletup-payto-instructions/.test(zelleSection)&&/pay@testshop\.com/.test(zelleSection), dataUriImgs:(zelleSection.match(/<img[^>]+src="data:image/g)||[]).length, qrWrapper:/walletup-qr-code|class="walletup-qr"/.test(zelleSection)};
    await shot(p2,'audit-legacy-off-and-zelle',true);
    const beacon=await v2.request.post(`${BASE}/wp-admin/admin-ajax.php`,{form:{action:'wallet_up_payment_event',type:'click',method:'zelle',post_id:String(ap.id)}}); R.checks.tap_beacon_without_nonce={status:beacon.status(),body:(await beacon.text()).slice(0,80)};
    const bad=await v2.request.post(`${BASE}/wp-admin/admin-ajax.php`,{form:{action:'wallet_up_payment_event',type:'click',method:'<script>',post_id:'1'}}); R.checks.tap_beacon_bad_method_status=bad.status();
    await v2.close();
    await page.goto(`${BASE}/wp-admin/index.php`,{waitUntil:'load'}); const afterVisitor=(await opt(page,'wallet_up_free_counts'))||{days:{}}; R.checks.visitor_counted={views:sum(afterVisitor)-sum(afterOwner), taps:Object.values(afterVisitor.days||{}).reduce((a,d)=>a+(d.c||0),0)};
  }
  { // "Create page" twice reuses the page; Settings has the Notifications card; share checkbox is off by default on step 4
    await page.goto(`${BASE}/wp-admin/admin.php?page=wallet-up`,{waitUntil:'load'}); await page.waitForTimeout(2500);
    const gen=async()=>page.evaluate(async()=>{const n=await (await fetch('/wp-admin/admin-ajax.php?action=rest-nonce',{credentials:'same-origin'})).text(); const fd=new FormData(); fd.append('action','wallet_up_generate_payment_page'); fd.append('nonce',(window.walletUpData&&window.walletUpData.nonce)||(window.wupFreeUi&&window.wupFreeUi.nonce)||''); fd.append('methodId','paypal'); const r=await fetch('/wp-admin/admin-ajax.php',{method:'POST',credentials:'same-origin',body:fd}); return r.json();});
    const g1=await gen(), g2=await gen(); R.checks.create_page_reuses={first:g1&&g1.data&&g1.data.pageId, second:g2&&g2.data&&g2.data.pageId, same:!!(g1&&g2&&g1.data&&g2.data&&g1.data.pageId===g2.data.pageId)};
    const pages=await rest(page,'/wp/v2/pages?per_page=50&status=publish'); R.checks.published_pay_pages=(Array.isArray(pages)?pages:[]).filter(p=>/Pay with/.test(p.title.rendered)).length;
    await page.locator('#wallet-up-admin-root :is(a,button):has-text("Settings")').first().click(); await page.waitForTimeout(2000);
    R.checks.settings_cards=await page.locator('#wup-free-settings span.font-semibold').allInnerTexts();
    const offBtn=page.locator('#wup-free-settings button:has-text("Turn off")'); R.checks.notify_off_visible=await offBtn.count()>0&&await offBtn.first().isVisible();
    await shot(page,'audit-settings-notifications',true);
  }
  // uninstall-survey tag now counts page views
  await page.goto(`${BASE}/wp-admin/plugins.php`,{waitUntil:'load'}); await page.locator('tr[data-slug="wallet-up"] a[href*="action=deactivate"]').first().click(); await page.waitForTimeout(2500);
  R.checks.timeline_note=(await page.locator('.wup-timeline').first().innerText().catch(()=>'')).trim(); R.checks.timeline_pv_positive=/\b[1-9]\d* page views?\b/.test(R.checks.timeline_note); await shot(page,'deactivate-timeline');
  R.checks.free_counts=await (async()=>{await page.goto(`${BASE}/wp-admin/index.php`,{waitUntil:'load'}); const c=await opt(page,'wallet_up_free_counts'); return c&&c.days;})();
  // debug log: fatals / warnings from the plugin
  const log=fs.existsSync(DEBUG)?fs.readFileSync(DEBUG,'utf8'):''; R.checks.debug_log_plugin_lines=log.split('\n').filter(l=>/wallet-up\//.test(l)&&!/wordpress\.org/.test(l)).slice(0,10); R.checks.debug_log_fatals=(log.match(/PHP Fatal/g)||[]).length;
  R.console_errors=errs.slice(0,10); fs.writeFileSync(OUT+'/results.json',JSON.stringify(R,null,2)); console.log(JSON.stringify(R,null,2)); await browser.close();
})().catch(e=>{console.error('FATAL',e.message); R.fatal=e.stack; fs.writeFileSync(OUT+'/results.json',JSON.stringify(R,null,2)); process.exit(1);});
