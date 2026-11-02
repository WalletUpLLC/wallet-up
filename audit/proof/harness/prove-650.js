// 6.5.0 proof. Usage: node prove-650.js <label> [geo]
// Fresh install expected. With "geo", the site is first set to Europe/Berlin to exercise the country hint.
const { chromium } = require('playwright'); const fs=require('fs');
const BASE='http://127.0.0.1:8080'; const label=process.argv[2]||'650'; const geo=process.argv[3]==='geo';
const OUT=__dirname+'/proof-'+label; fs.mkdirSync(OUT,{recursive:true});
const R={label,geo,checks:{}}; let n=0;
const shot=async(p,name,full)=>{n++; const f=`${OUT}/${String(n).padStart(2,'0')}-${name}.png`; await p.screenshot({path:f,fullPage:!!full}); console.log('SHOT',f);};
const heads=async p=>(await p.locator('h1,h2').allTextContents()).map(s=>s.trim()).filter(Boolean).slice(0,4).join(' | ');
let NONCE=''; const rest=async(p,path,init)=>p.evaluate(async([path,init,n0])=>{let nonce=(window.wpApiSettings&&window.wpApiSettings.nonce)||n0; if(!nonce){ nonce=await (await fetch('/wp-admin/admin-ajax.php?action=rest-nonce',{credentials:'same-origin'})).text(); }const h=Object.assign({'Content-Type':'application/json','X-WP-Nonce':nonce},(init&&init.headers)||{});const r=await fetch('/?rest_route='+path,Object.assign({credentials:'same-origin'},init||{},{headers:h}));const t=await r.text();try{const j=JSON.parse(t); if(j&&j.code){ j.status=r.status; } return j;}catch(e){return {status:r.status,text:t.slice(0,200)};}},[path,init||{},NONCE]);
(async()=>{
  const browser=await chromium.launch(); const ctx=await browser.newContext({viewport:{width:1440,height:900}}); const page=await ctx.newPage(); page.on('dialog',d=>d.accept());
  const errs=[]; page.on('console',m=>{ if(m.type()==='error' && !/ERR_TUNNEL|403|500/.test(m.text())) errs.push(m.text().slice(0,160)); });
  await page.goto(`${BASE}/wp-login.php`); await page.fill('#user_login','admin'); await page.fill('#user_pass','password'); await page.click('#wp-submit'); await page.waitForLoadState('networkidle');
  if(geo){ await page.goto(`${BASE}/wp-admin/index.php`,{waitUntil:'networkidle'}); R.checks.set_timezone=await rest(page,'/wp/v2/settings',{method:'POST',body:JSON.stringify({timezone:'Europe/Berlin'})}); R.checks.set_timezone=R.checks.set_timezone.timezone; }
  await page.goto(`${BASE}/wp-admin/plugins.php`,{waitUntil:'networkidle'});
  let act=page.locator('tr[data-slug="wallet-up"] a[href*="action=activate"]').first();
  if(!(await act.count())){ await page.waitForTimeout(3000); await page.reload({waitUntil:'networkidle'}); act=page.locator('tr[data-slug="wallet-up"] a[href*="action=activate"]').first(); }
  await act.click({timeout:60000}); try{await page.waitForLoadState('networkidle',{timeout:60000});}catch(e){} await page.waitForTimeout(2500);
  R.checks.landing=page.url(); await shot(page,'welcome',true);
  const geoLine=page.locator('#wup-geo-line'); R.checks.geo_line_shown=await geoLine.count()>0; R.checks.geo_line_text=R.checks.geo_line_shown?(await geoLine.innerText()).replace(/\s+/g,' '):'';
  R.checks.geo_hint_option=await rest(page,'/wup-test/option/wallet_up_geo_hint'); R.checks.geo_hint_option=R.checks.geo_hint_option.value;
  R.checks.currency_option=(await rest(page,'/wup-test/option/wallet_up_settings')).value;
  // wizard
  await page.locator('button:has-text("Start setup")').click(); await page.waitForTimeout(1200);
  await page.getByText('Retail / E-commerce',{exact:false}).first().click(); await page.waitForTimeout(1800);
  const cont=page.locator('button:has-text("Continue")'); if(await cont.count()&&await cont.first().isEnabled()){ await cont.first().click(); await page.waitForTimeout(1500); }
  const zelleBtn=page.locator('#wallet-up-admin-root button:has-text("Zelle")').first(); R.checks.zelle_button_enabled=await zelleBtn.count()?!(await zelleBtn.isDisabled()):null;
  R.checks.preselected=(await page.locator('#wallet-up-admin-root button:has(svg.lucide-check)').allInnerTexts()).map(s=>s.trim()).filter(s=>!/Change/.test(s));
  if(R.checks.zelle_button_enabled){ await zelleBtn.click(); await page.waitForTimeout(800); }
  R.checks.zelle_hint_shown=await page.locator('#wup-zelle-hint').count()>0;
  await shot(page,'step3',true);
  const f=async(sel,v)=>{const l=page.locator(sel).first(); if(await l.count()&&!(await l.inputValue())) await l.fill(v);};
  await f('input[placeholder*="Anna"]','Test Shop'); await f('input[placeholder*="example.com"]:not([placeholder*="555"])','admin@example.com');
  const inputs=page.locator('#wallet-up-admin-root input[type="text"], #wallet-up-admin-root input:not([type])'); const c=await inputs.count();
  for(let i=0;i<c;i++){ const el=inputs.nth(i); const ph=(await el.getAttribute('placeholder'))||''; if(await el.inputValue()) continue; if(/555/.test(ph)) await el.fill('pay@testshop.com'); else if(/yourname|username|paypal/i.test(ph)) await el.fill('TestShop'); }
  await f('input[placeholder*="50"]','25'); await page.waitForTimeout(800);
  const c2=page.locator('button:has-text("Continue")').first(); if(await c2.isEnabled()){ await c2.click(); await page.waitForTimeout(2500); }
  R.checks.step4=await heads(page);
  const notify=page.locator('#wup-notify'); R.checks.notify_block_shown=await notify.count()>0; await shot(page,'step4-notify',true);
  if(R.checks.notify_block_shown){ await notify.locator('button:has-text("Turn on notifications")').click(); await page.waitForTimeout(2000); R.checks.notify_email_option=(await rest(page,'/wup-test/option/wallet_up_notify_email')).value; R.checks.notify_text=(await notify.innerText()).replace(/\s+/g,' '); }
  // accounts saved
  R.checks.accounts=(await rest(page,'/wup-test/option/wallet_up_transfer_call')).value; R.checks.enabled=(await rest(page,'/wup-test/option/wallet_up_enabled_methods')).value;
  // Payments page: Zelle row + upload
  await page.goto(`${BASE}/wp-admin/admin.php?page=wallet-up`,{waitUntil:'networkidle'}); await page.waitForTimeout(2500);
  await page.locator('#wallet-up-admin-root :is(a,button):has-text("Payments")').first().click(); await page.waitForTimeout(2500);
  R.checks.zelle_upload_control=await page.locator('#wup-zelle-qr input[type=file]').count()>0;
  const zelleInput=page.locator('#wallet-up-admin-root input[placeholder*="Available in Pro"]'); R.checks.zelle_row_locked_inputs=await zelleInput.count();
  // make a tiny PNG and upload
  const png=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAIAAAAlC+aJAAAAMElEQVR4nO3OMQEAAAgDoGn/zlpDN2QgkADmS0dPT09PT09PT09PT09PT09PT09Pb1z8AWZcAXJa5+YnAAAAAElFTkSuQmCC','base64'); fs.writeFileSync(OUT+'/qr.png',png);
  if(R.checks.zelle_upload_control){ await page.locator('#wup-zelle-qr input[type=file]').setInputFiles(OUT+'/qr.png'); await page.waitForTimeout(3000); R.checks.custom_qr_option=(await rest(page,'/wup-test/option/wallet_up_custom_qr_images')).value; R.checks.zelle_upload_status=(await page.locator('#wup-zelle-qr').innerText()).replace(/\s+/g,' ').slice(0,200); }
  await shot(page,'payments-zelle',true);
  // Settings: where-you-are card
  await page.locator('#wallet-up-admin-root :is(a,button):has-text("Settings")').first().click(); await page.waitForTimeout(2000);
  R.checks.settings_cards=(await page.locator('#wup-free-settings span.font-semibold').allInnerTexts());
  await shot(page,'settings',true);
  // never-silent: cashapp saved but off; venmo unconfigured; zelle on
  await page.goto(`${BASE}/wp-admin/index.php`,{waitUntil:'networkidle'});
  const acc=Object.assign({},R.checks.accounts||{},{cashapp_holder:'TestShopCash'}); await rest(page,'/wup-test/set-option',{method:'POST',body:JSON.stringify({name:'wallet_up_transfer_call',value:acc})});
  const en=Object.assign({},R.checks.enabled||{}); en.cashapp=false; delete en.venmo; await rest(page,'/wup-test/set-option',{method:'POST',body:JSON.stringify({name:'wallet_up_enabled_methods',value:en})});
  const pg=await rest(page,'/wp/v2/pages',{method:'POST',body:JSON.stringify({title:'Silence test',status:'publish',content:'<p>Zelle:</p>[wallet_up method="zelle" amount="20"]<p>Cash App (saved, off):</p>[wallet_up method="cashapp" amount="5"]<p>Venmo (no username):</p>[wallet_up method="venmo" amount="5"]<p>PayPal:</p>[wallet_up method="paypal" amount="25"]'})});
  R.checks.page=pg.link;
  await page.goto(pg.link,{waitUntil:'networkidle'}); await page.waitForTimeout(1000); await shot(page,'admin-view-page',true);
  const adminText=(await page.textContent('body')).replace(/\s+/g,' '); R.checks.admin_sees_turn_on=/Turn on Cash App/.test(adminText); R.checks.admin_sees_venmo_notice=/Venmo has no username saved/.test(adminText); R.checks.zelle_card=/pay@testshop\.com/.test(adminText); R.checks.amounts=(adminText.match(/[$€£][0-9]+\.[0-9]{2}|[0-9]+\.[0-9]{2} ?(EUR|USD)/g)||[]).slice(0,6);
  const paypalHref=await page.locator('a[href*="paypal.com/paypalme"]').first().getAttribute('href').catch(()=>null); R.checks.paypal_link=paypalHref;
  const v=await browser.newContext(); const vp=await v.newPage(); await vp.goto(pg.link,{waitUntil:'networkidle'}); const html=await vp.content(); R.checks.visitor_comments=(html.match(/<!-- wallet-up: [^>]+-->/g)||[]); R.checks.visitor_sees_notice_box=/walletup-shortcode-notice/.test(html); R.checks.visitor_sees_zelle=/pay@testshop\.com/.test(html); await v.close();
  const turn=page.locator('form button:has-text("Turn on Cash App")').first(); if(await turn.count()){ await turn.click(); await page.waitForLoadState('networkidle'); await page.waitForTimeout(800); const t2=(await page.textContent('body')).replace(/\s+/g,' '); R.checks.after_turn_on_cashapp_renders=/cash\.app\/\$?TestShopCash|Scan or Click to pay/.test(t2)&&!/Turn on Cash App/.test(t2); R.checks.after_turn_on_url=page.url(); }
  // Zelle without an uploaded bank QR: the pay-to card must carry the handle and a copy button
  await page.goto(`${BASE}/wp-admin/index.php`,{waitUntil:'networkidle'}); await rest(page,'/wup-test/set-option',{method:'POST',body:JSON.stringify({name:'wallet_up_custom_qr_images',value:{}})});
  { const v2=await browser.newContext(); const p2=await v2.newPage(); await p2.goto(pg.link,{waitUntil:'networkidle'}); const h2=await p2.content(); R.checks.zelle_no_qr_payto_card=/walletup-payto-instructions/.test(h2)&&/pay@testshop\.com/.test(h2); R.checks.zelle_no_qr_has_img=/<img[^>]+alt="Zelle/.test(h2); n++; await p2.screenshot({path:`${OUT}/${String(n).padStart(2,'0')}-visitor-zelle-no-qr.png`,fullPage:true}); await v2.close(); }
  // geo: I'm in the US button
  if(geo){ await page.goto(`${BASE}/wp-admin/admin.php?page=wallet-up-onboarding`,{waitUntil:'networkidle'}); await page.waitForTimeout(1500); }
  // deactivation dialog timeline
  await page.goto(`${BASE}/wp-admin/plugins.php`,{waitUntil:'networkidle'}); await page.locator('tr[data-slug="wallet-up"] a[href*="action=deactivate"]').first().click(); await page.waitForTimeout(2500);
  R.checks.timeline_note=(await page.locator('.wup-timeline').first().innerText().catch(()=>'')).trim(); await shot(page,'deactivate-timeline');
  const other=page.locator('.fs-modal-deactivation-feedback label:has-text("Other")').first(); if(await other.count()){ await other.click(); await page.waitForTimeout(500); const ta=page.locator('.fs-modal-deactivation-feedback textarea, .fs-modal-deactivation-feedback input[type=text]').first(); if(await ta.count()) await ta.fill('just testing'); const submit=page.locator('.fs-modal-deactivation-feedback .button-deactivate').first(); const [req]=await Promise.all([page.waitForRequest(r=>/admin-ajax\.php/.test(r.url())&&/fs_submit_uninstall_reason/.test(r.postData()||''),{timeout:15000}).catch(()=>null), submit.click()]); R.checks.fs_submit_request=!!req; await page.waitForTimeout(2500); }
  const fsAccounts=(await (async()=>{await page.goto(`${BASE}/wp-admin/index.php`,{waitUntil:'networkidle'}); return rest(page,'/wup-test/option/fs_accounts');})()).value; const dump=JSON.stringify(fsAccounts||{}); const m=dump.match(/just testing[^"]*/); R.checks.stored_reason_info=m?m[0]:null; R.checks.stored_reason_has_timeline=!!(m&&/\[6\.\d+\.\d+ /.test(m[0]));
  R.console_errors=errs.slice(0,10); fs.writeFileSync(OUT+'/results.json',JSON.stringify(R,null,2)); console.log(JSON.stringify(R,null,2)); await browser.close();
})().catch(e=>{console.error('FATAL',e.message); R.fatal=e.stack; fs.writeFileSync(OUT+'/results.json',JSON.stringify(R,null,2)); process.exit(1);});
