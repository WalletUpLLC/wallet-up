// 6.6.0 proof: WooCommerce active, free gateway end to end on the classic checkout, retention loop, published page.
const { chromium } = require('playwright'); const fs=require('fs');
const BASE='http://127.0.0.1:8080'; const label=process.argv[2]||'660';
const OUT=__dirname+'/proof-'+label; fs.mkdirSync(OUT,{recursive:true}); const MAIL='/tmp/claude-0/-home-user-wallet-up/fd5e39c7-41c4-5b51-8c0a-c79cf509220e/scratchpad/wp/core/wp-content/mail.log';
const R={label,checks:{}}; let n=0;
const shot=async(p,name,full)=>{n++; const f=`${OUT}/${String(n).padStart(2,'0')}-${name}.png`; await p.screenshot({path:f,fullPage:!!full}); console.log('SHOT',f);};
const heads=async p=>(await p.locator('h1,h2').allTextContents()).map(s=>s.trim()).filter(Boolean).slice(0,4).join(' | ');
const rest=async(p,path,init)=>p.evaluate(async([path,init])=>{let nonce=(window.wpApiSettings&&window.wpApiSettings.nonce)||await (await fetch('/wp-admin/admin-ajax.php?action=rest-nonce',{credentials:'same-origin'})).text();const h=Object.assign({'Content-Type':'application/json','X-WP-Nonce':nonce},(init&&init.headers)||{});const r=await fetch('/?rest_route='+path,Object.assign({credentials:'same-origin'},init||{},{headers:h}));const t=await r.text();try{const j=JSON.parse(t); if(j&&j.code) j.status=r.status; return j;}catch(e){return {status:r.status,text:t.slice(0,200)};}},[path,init||{}]);
const mails=()=>{try{return fs.readFileSync(MAIL,'utf8').trim().split('\n').filter(Boolean).map(l=>JSON.parse(l));}catch(e){return [];}};
(async()=>{
  try{fs.unlinkSync(MAIL);}catch(e){}
  const browser=await chromium.launch(); const ctx=await browser.newContext({viewport:{width:1440,height:900}}); const page=await ctx.newPage(); page.on('dialog',d=>d.accept());
  const errs=[]; page.on('console',m=>{ if(m.type()==='error' && !/ERR_TUNNEL|403|500|404/.test(m.text())) errs.push(m.text().slice(0,160)); });
  await page.goto(`${BASE}/wp-login.php`); await page.fill('#user_login','admin'); await page.fill('#user_pass','password'); await page.click('#wp-submit'); await page.waitForLoadState('networkidle');
  // WooCommerce first
  await page.goto(`${BASE}/wp-admin/plugins.php`,{waitUntil:'networkidle'}); const wa=page.locator('tr[data-slug="woocommerce"] a[href*="action=activate"]'); if(await wa.count()){ await wa.first().click(); try{await page.waitForLoadState('networkidle',{timeout:60000});}catch(e){} }
  await page.goto(`${BASE}/wp-admin/index.php`,{waitUntil:'networkidle'});
  await rest(page,'/wc-admin/onboarding/profile',{method:'POST',body:JSON.stringify({skipped:true,completed:true})});
  await rest(page,'/wc-admin/options',{method:'POST',body:JSON.stringify({woocommerce_task_list_hidden:'yes',woocommerce_coming_soon:'no',woocommerce_default_country:'US:CA',woocommerce_currency:'USD',woocommerce_store_address:'1 Main St',woocommerce_store_city:'San Jose',woocommerce_store_postcode:'95110',woocommerce_enable_guest_checkout:'yes',woocommerce_calc_taxes:'no'})});
  // classic checkout page + product
  const ck=await rest(page,'/wp/v2/pages',{method:'POST',body:JSON.stringify({title:'Checkout classic',status:'publish',content:'[woocommerce_checkout]'})});
  await rest(page,'/wup-test/set-option',{method:'POST',body:JSON.stringify({name:'woocommerce_checkout_page_id',value:ck.id})});
  const prod=await rest(page,'/wc/v3/products',{method:'POST',body:JSON.stringify({name:'Test mug',type:'simple',regular_price:'12.50',virtual:true,status:'publish'})}); R.checks.product_id=prod.id;
  // activate Wallet Up, wizard with checkout box ticked
  await page.goto(`${BASE}/wp-admin/plugins.php`,{waitUntil:'networkidle'}); await page.locator('tr[data-slug="wallet-up"] a[href*="action=activate"]').first().click({timeout:60000}); try{await page.waitForLoadState('networkidle',{timeout:60000});}catch(e){} await page.waitForTimeout(2500);
  await page.locator('button:has-text("Start setup")').click(); await page.waitForTimeout(1200); await page.getByText('Retail / E-commerce',{exact:false}).first().click(); await page.waitForTimeout(1800);
  const cont=page.locator('button:has-text("Continue")'); if(await cont.count()&&await cont.first().isEnabled()){ await cont.first().click(); await page.waitForTimeout(1500); }
  const f=async(sel,v)=>{const l=page.locator(sel).first(); if(await l.count()&&!(await l.inputValue())) await l.fill(v);};
  await f('input[placeholder*="Anna"]','Test Shop'); await f('input[placeholder*="example.com"]:not([placeholder*="555"])','admin@example.com');
  const inputs=page.locator('#wallet-up-admin-root input[type="text"], #wallet-up-admin-root input:not([type])'); const c=await inputs.count(); for(let i=0;i<c;i++){ const el=inputs.nth(i); const ph=(await el.getAttribute('placeholder'))||''; if(await el.inputValue()) continue; if(/yourname|username|paypal/i.test(ph)) await el.fill('TestShop'); }
  await f('input[placeholder*="50"]','25'); await page.waitForTimeout(600);
  const box=page.locator('#wallet-up-admin-root input[type=checkbox]').first(); R.checks.checkout_checkbox_present=await box.count()>0;
  const badge=await page.locator('#wallet-up-admin-root label:has(input[type=checkbox]) span:has-text("Pro")').count(); R.checks.checkout_checkbox_pro_badge=badge>0;
  if(await box.count()){ await box.check(); await page.waitForTimeout(400); }
  R.checks.step3_note=(await page.locator('#wallet-up-admin-root').innerText()).match(/One payment method at your WooCommerce checkout[^\n]*/)?.[0]||null;
  await shot(page,'step3-checkout-box',true);
  const c2=page.locator('button:has-text("Continue")').first(); if(await c2.isEnabled()){ await c2.click(); await page.waitForTimeout(2500); }
  R.checks.step4=await heads(page);
  // publish-page flow
  const create=page.locator('button:has-text("Create page with this block")'); if(await create.count()){ await create.click(); try{await page.waitForURL(u=>!/wp-admin/.test(u.toString()),{timeout:30000});}catch(e){} await page.waitForTimeout(1200); R.checks.after_create_url=page.url(); R.checks.after_create_is_frontend=!/wp-admin/.test(page.url()); await shot(page,'after-create-page',true); }
  await page.goto(`${BASE}/wp-admin/index.php`,{waitUntil:'networkidle'});
  R.checks.page_id_option=(await rest(page,'/wup-test/option/wallet_up_payment_page_id')).value; const pst=R.checks.page_id_option?(await rest(page,'/wp/v2/pages/'+R.checks.page_id_option)):null; R.checks.page_status=pst&&pst.status;
  R.checks.p2p_settings=(await rest(page,'/wup-test/option/woocommerce_wallet_up_p2p_settings')).value;
  let gws=await rest(page,'/wc/v3/payment_gateways'); R.checks.gateways_after_wizard=Array.isArray(gws)?gws.map(g=>g.id+':'+(g.enabled?'on':'off')):gws;
  R.checks.wizard_enabled_p2p=!!(R.checks.p2p_settings&&R.checks.p2p_settings.enabled==='yes');
  if(!R.checks.wizard_enabled_p2p){ // the WooCommerce → Settings → Payments path, as the notice's "Turn it on" button leads to
    R.checks.rest_enable=await rest(page,'/wc/v3/payment_gateways/wallet_up_p2p',{method:'PUT',body:JSON.stringify({enabled:true,settings:{method:'paypal'}})});
    R.checks.rest_enable={enabled:R.checks.rest_enable.enabled, method:R.checks.rest_enable.settings&&R.checks.rest_enable.settings.method&&R.checks.rest_enable.settings.method.value};
    gws=await rest(page,'/wc/v3/payment_gateways'); R.checks.gateways=Array.isArray(gws)?gws.map(g=>g.id+':'+(g.enabled?'on':'off')):gws; }
  // notifications on (so emails fire)
  await rest(page,'/wup-test/set-option',{method:'POST',body:JSON.stringify({name:'wallet_up_notify_email',value:'owner@example.com'})});
  // checkout as a guest
  const v=await browser.newContext({viewport:{width:1280,height:900},userAgent:'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36'}); const vp=await v.newPage(); vp.on('dialog',d=>d.accept());
  await vp.goto(`${BASE}/?add-to-cart=${prod.id}`,{waitUntil:'networkidle'}); await vp.goto(ck.link,{waitUntil:'networkidle'}); await vp.waitForTimeout(1500);
  R.checks.checkout_has_gateway=await vp.locator('#payment_method_wallet_up_p2p').count()>0; R.checks.checkout_gateway_label=(await vp.locator('label[for="payment_method_wallet_up_p2p"]').innerText().catch(()=>'')).trim();
  await shot(vp,'classic-checkout',true);
  const fill=async(id,v)=>{const l=vp.locator('#'+id); if(await l.count()) await l.fill(v);};
  await fill('billing_first_name','Ada'); await fill('billing_last_name','Lovelace'); await fill('billing_address_1','1 Main St'); await fill('billing_city','San Jose'); await fill('billing_postcode','95110'); await fill('billing_phone','4085551234'); await fill('billing_email','ada@example.com');
  const country=vp.locator('#billing_country'); if(await country.count()) await country.selectOption('US').catch(()=>{}); const state=vp.locator('#billing_state'); if(await state.count()) await state.selectOption('CA').catch(()=>{});
  if(R.checks.checkout_has_gateway){ await vp.check('#payment_method_wallet_up_p2p'); }
  const terms=vp.locator('#terms'); if(await terms.count()) await terms.check().catch(()=>{});
  await Promise.all([vp.waitForNavigation({timeout:60000}).catch(()=>{}), vp.locator('#place_order').click()]); await vp.waitForLoadState('networkidle'); await vp.waitForTimeout(1500);
  R.checks.after_place_order_url=vp.url(); const ty=await vp.content(); R.checks.thankyou_has_qr=/walletup-qr|Scan or Click to pay|walletup-payto|data:image\/png;base64/.test(ty); R.checks.thankyou_has_confirm_form=/walletup_confirm_payment_sent/.test(ty); R.checks.thankyou_error=(ty.match(/woocommerce-error[^<]*<[^>]*>([^<]{0,160})/)||[])[1]||null;
  await shot(vp,'thank-you',true);
  const orders=await rest(page,'/wc/v3/orders&per_page=1&orderby=id&order=desc'); const order=Array.isArray(orders)&&orders[0]; R.checks.order=order?{id:order.id,status:order.status,method:order.payment_method,title:order.payment_method_title,total:order.total,notes:(order.meta_data||[]).filter(m=>/_wallet_up/.test(m.key)).map(m=>m.key+'='+m.value)}:{raw:JSON.stringify(orders).slice(0,200)};
  // visitor confirms on the thank-you page
  R.checks.confirm_form_ids=await vp.evaluate(()=>[...document.querySelectorAll('[id^="walletup-confirm-"]')].map(e=>e.id+':'+e.tagName+':'+(getComputedStyle(e).display)));
  const tgl=vp.locator('[id^="walletup-confirm-"][id$="-toggle"]').first(); if(await tgl.count()){ await tgl.click({force:true}).catch(()=>{}); await vp.waitForTimeout(500); }
  await vp.evaluate(()=>{const f=[...document.querySelectorAll('[id^="walletup-confirm-"]')].find(e=>/^walletup-confirm-\d+$/.test(e.id)); if(f) f.style.display='block';});
  const ref=vp.locator('[id^="walletup-confirm-"][id$="-ref"]').first(); if(await ref.count()) await ref.fill('TX-123',{force:true}).catch(()=>{});
  const btn=vp.locator('[id^="walletup-confirm-"][id$="-btn"]').first();
  if(await btn.count()){ const [cr]=await Promise.all([vp.waitForResponse(r=>/admin-ajax\.php/.test(r.url())&&r.request().postData()&&/walletup_confirm_payment_sent/.test(r.request().postData()),{timeout:15000}).catch(()=>null), btn.click({force:true})]); R.checks.confirm_response=cr?await cr.text().then(t=>t.slice(0,200)).catch(()=>null):null; await vp.waitForTimeout(1500); R.checks.confirm_message=(await vp.locator('[id^="walletup-confirm-"][id$="-msg"]').first().innerText().catch(()=>'')).trim(); }
  await shot(vp,'thank-you-confirmed',true);
  // tap beacon on the published payment page
  const payUrl=R.checks.page_id_option?(await rest(page,'/wp/v2/pages/'+R.checks.page_id_option)).link:null; R.checks.pay_page=payUrl;
  if(payUrl){ await vp.goto(payUrl,{waitUntil:'networkidle'}); await vp.waitForTimeout(800); const link=vp.locator('a[data-wallet-up-method], a.walletup-qr-link, a.walletup-button-link, a.walletup-payment-link').first(); R.checks.pay_page_has_link=await link.count()>0; if(await link.count()){ await vp.evaluate(()=>{document.querySelectorAll('a[data-wallet-up-method],a.walletup-qr-link,a.walletup-button-link,a.walletup-payment-link').forEach(a=>a.setAttribute('target','_blank'));}); const [req]=await Promise.all([vp.waitForRequest(r=>/admin-ajax\.php/.test(r.url())&&/wallet_up_payment_event/.test(r.postData()||''),{timeout:8000}).catch(()=>null), vp.evaluate(()=>{const a=document.querySelector('a[data-wallet-up-method],a.walletup-qr-link,a.walletup-button-link,a.walletup-payment-link'); a.addEventListener('click',e=>e.preventDefault(),{once:true}); a.dispatchEvent(new MouseEvent('click',{bubbles:true,cancelable:true}));})]); R.checks.tap_beacon_sent=!!req; await vp.waitForTimeout(1500); } }
  await v.close();
  { const nonce=((ty||'').match(/fd\.append\("nonce","([^"]+)"\)/)||[])[1]||''; const oid=(R.checks.after_place_order_url.match(/order-received=(\d+)/)||[])[1]||'0'; const v3=await browser.newContext({userAgent:'Mozilla/5.0 (X11; Linux x86_64) Chrome/130.0'}); const vp3=v3;
    const r1=await vp3.request.post(`${BASE}/wp-admin/admin-ajax.php`,{form:{action:'walletup_confirm_payment_sent',nonce,payment_method:'paypal',amount:'12.50',customer_name:'Mallory',wc_order_id:oid,order_key:'wc_order_wrong',post_id:'0'}}); R.checks.confirm_wrong_order_key={status:r1.status(),body:(await r1.text()).slice(0,120)};
    const r2=await vp3.request.post(`${BASE}/wp-admin/admin-ajax.php`,{form:{action:'walletup_confirm_payment_sent',nonce,payment_method:'PayPal (fee due at evil.tld)',amount:'1',customer_name:'Mallory',wc_order_id:'0',post_id:'0'}}); R.checks.confirm_bad_method={status:r2.status(),body:(await r2.text()).slice(0,120)};
    await page.goto(`${BASE}/wp-admin/admin.php?page=wallet-up`,{waitUntil:'load'}); await page.waitForTimeout(2500); const st=await page.evaluate(async()=>{const fd=new FormData(); fd.append('action','wallet_up_onboarding_get_state'); fd.append('nonce',(window.walletUpData&&window.walletUpData.nonce)||(window.wupFreeUi&&window.wupFreeUi.nonce)||''); const r=await fetch('/wp-admin/admin-ajax.php',{method:'POST',credentials:'same-origin',body:fd}); return r.json();}).catch(()=>null); R.checks.wizard_state_wc=st&&st.data&&st.data.wc; await v3.close();
  }
  await page.goto(`${BASE}/wp-admin/index.php`,{waitUntil:'networkidle'}); R.checks.counts=(await rest(page,'/wup-test/option/wallet_up_free_counts')).value;
  // digest: backdate activation 8 days and run the daily hook
  await rest(page,'/wup-test/set-option',{method:'POST',body:JSON.stringify({name:'wallet_up_activated_at',value:Math.floor(Date.now()/1000)-8*86400})});
  R.checks.cron_ran=await rest(page,'/wup-test/cron/wallet_up_free_daily',{method:'POST'});
  const ms=mails(); R.checks.email_instruction_dupes=(function(){const ms=mails(); const m=ms.find(x=>/order has been received/.test(x.subject||'')); if(!m) return null; const body=(m.message||m.body||''); return (body.match(/using the code and link|Pay with/g)||[]).length;})(); R.checks.mails=ms.map(m=>m.subject); R.checks.mail_to=[...new Set(ms.map(m=>m.to))];
  // dashboard card + review prompt + banner
  await page.goto(`${BASE}/wp-admin/admin.php?page=wallet-up`,{waitUntil:'networkidle'}); await page.waitForTimeout(3000); await shot(page,'dashboard-this-week',true);
  R.checks.this_week_card=(await page.locator('#wup-this-week').innerText().catch(()=>'')).replace(/\s+/g,' ').slice(0,300); R.checks.setup_progress_hidden=!(await page.locator('text=Setup Progress').count());
  R.checks.review_prompt_shown=await page.locator('[data-walletup-review-prompt]').count()>0;
  await page.goto(`${BASE}/wp-admin/index.php`,{waitUntil:'networkidle'}); R.checks.wp_dashboard_notices=(await page.locator('.notice').allInnerTexts()).map(s=>s.replace(/\s+/g,' ').slice(0,100)).filter(s=>/Wallet Up/.test(s));
  // WooCommerce home notice copy
  await page.goto(`${BASE}/wp-admin/admin.php?page=wc-admin`,{waitUntil:'networkidle'}).catch(()=>{}); await page.waitForTimeout(1200); R.checks.wc_home_notice=(await page.locator('[data-wup-integration-notice]').innerText().catch(()=>'')).replace(/\s+/g,' ').slice(0,200);
  R.console_errors=errs.slice(0,10); fs.writeFileSync(OUT+'/results.json',JSON.stringify(R,null,2)); console.log(JSON.stringify(R,null,2)); await browser.close();
})().catch(e=>{console.error('FATAL',e.message); R.fatal=e.stack; fs.writeFileSync(OUT+'/results.json',JSON.stringify(R,null,2)); process.exit(1);});
