// Usage: node prove.js <label> [withWoo]
// Walks the first run as a user: Plugins -> Activate -> whatever appears -> wizard -> dashboard -> checks.
const { chromium } = require('playwright'); const fs=require('fs');
const BASE='http://127.0.0.1:8080'; const label=process.argv[2]||'run'; const withWoo=process.argv[3]==='woo';
const OUT=__dirname+'/proof-'+label; fs.mkdirSync(OUT,{recursive:true});
const R={label,withWoo,checks:{},notes:[]}; let n=0;
const shot=async(p,name,full)=>{n++; const f=`${OUT}/${String(n).padStart(2,'0')}-${name}.png`; await p.screenshot({path:f,fullPage:!!full}); console.log('SHOT',f);};
const heads=async p=>(await p.locator('h1,h2').allTextContents()).map(s=>s.trim()).filter(Boolean).slice(0,4).join(' | ');
const notices=async p=>(await p.locator('.notice, .error, .updated').allInnerTexts()).map(s=>s.replace(/\s+/g,' ').trim()).filter(Boolean).filter(s=>!/development version of WooCommerce|WooCommerce Blocks development|secure connection/.test(s));
(async()=>{
  const browser=await chromium.launch(); const ctx=await browser.newContext({viewport:{width:1440,height:900}}); const page=await ctx.newPage();
  const errs=[]; page.on('console',m=>{ if(m.type()==='error' && !/ERR_TUNNEL|403|500/.test(m.text())) errs.push(m.text().slice(0,160)); });
  await page.goto(`${BASE}/wp-login.php`); await page.fill('#user_login','admin'); await page.fill('#user_pass','password'); await page.click('#wp-submit'); await page.waitForLoadState('networkidle');
  if(withWoo){ await page.goto(`${BASE}/wp-admin/plugins.php`,{waitUntil:'networkidle'}); const a=page.locator('tr[data-slug="woocommerce"] a[href*="action=activate"]'); if(await a.count()){ await a.first().click(); try{await page.waitForLoadState('networkidle',{timeout:60000});}catch(e){} }
    await page.goto(`${BASE}/wp-admin/index.php`,{waitUntil:'networkidle'});
    await page.evaluate(async()=>{const nonce=window.wpApiSettings&&window.wpApiSettings.nonce;const h={'Content-Type':'application/json','X-WP-Nonce':nonce};await fetch('/wp-json/wc-admin/onboarding/profile',{method:'POST',headers:h,credentials:'same-origin',body:JSON.stringify({skipped:true,completed:true})});await fetch('/wp-json/wc-admin/options',{method:'POST',headers:h,credentials:'same-origin',body:JSON.stringify({woocommerce_task_list_hidden:'yes',woocommerce_coming_soon:'no',woocommerce_default_country:'US:CA',woocommerce_currency:'USD'})});}); }
  // 1. activate
  await page.goto(`${BASE}/wp-admin/plugins.php`,{waitUntil:'networkidle'}); await shot(page,'plugins-before');
  const t0=Date.now(); await page.locator('tr[data-slug="wallet-up"] a[href*="action=activate"]').first().click(); try{await page.waitForLoadState('networkidle',{timeout:60000});}catch(e){} await page.waitForTimeout(2000);
  R.checks.activate_ms=Date.now()-t0; R.checks.landing_url=page.url(); R.checks.landing_headings=await heads(page);
  R.checks.freemius_optin_shown=await page.locator('#fs_connect').count()>0;
  R.checks.landed_on_setup=/wallet-up-onboarding/.test(page.url());
  await shot(page,'after-activate',true);
  // 2. notices on other admin screens
  for(const [u,k] of [['/wp-admin/index.php','wp_dashboard'],['/wp-admin/plugins.php','plugins'],['/wp-admin/edit.php','posts']]){ await page.goto(BASE+u,{waitUntil:'networkidle'}); R.checks['notices_'+k]=await notices(page); }
  await page.goto(`${BASE}/wp-admin/index.php`,{waitUntil:'networkidle'}); await shot(page,'wp-dashboard');
  R.checks.plugins_row_actions=(await page.goto(`${BASE}/wp-admin/plugins.php`,{waitUntil:'networkidle'}), await page.locator('tr[data-slug="wallet-up"] .row-actions').innerText()).replace(/\s+/g,' ');
  // 3. wizard
  await page.goto(`${BASE}/wp-admin/admin.php?page=wallet-up-onboarding`,{waitUntil:'networkidle'}); await page.waitForTimeout(1500);
  await shot(page,'wizard-1-welcome',true);
  await page.locator('button:has-text("Start setup")').click(); await page.waitForTimeout(1200); await shot(page,'wizard-2-industry',true);
  await page.getByText('Retail / E-commerce',{exact:false}).first().click(); await page.waitForTimeout(1800);
  const cont=page.locator('button:has-text("Continue")'); if(await cont.count()&&await cont.first().isEnabled()){ await cont.first().click(); await page.waitForTimeout(1500); }
  R.checks.step3_heading=await heads(page);
  const selected=await page.locator('#wallet-up-admin-root button:has(svg.lucide-check)').allInnerTexts().catch(()=>[]);
  R.checks.step3_preselected_methods=selected.map(s=>s.trim());
  R.checks.step3_required_username_fields=await page.locator('#wallet-up-admin-root label:has-text("username")').count();
  const rootText=await page.locator('#wallet-up-admin-root').innerText(); R.checks.step3_woo_copy=(rootText.match(/[^\n]*(WooCommerce|WPForms)[^\n]*/g)||[]).slice(0,6);
  await shot(page,'wizard-3-details',true);
  const f=async(sel,v)=>{const l=page.locator(sel).first(); if(await l.count()&&!(await l.inputValue())) await l.fill(v);};
  await f('input[placeholder*="Anna"]','Test Shop'); await f('input[placeholder*="example.com"]','admin@example.com');
  for(const ph of ['yourname','username','you@','paypal']){ const l=page.locator(`#wallet-up-admin-root input[placeholder*="${ph}"]`); const c=await l.count(); for(let i=0;i<c;i++){ if(!(await l.nth(i).inputValue())) await l.nth(i).fill('TestShop'); } }
  await f('input[placeholder*="50"]','25'); await page.waitForTimeout(800);
  const c2=page.locator('button:has-text("Continue")').first(); R.checks.step3_continue_enabled_after_one_username=await c2.isEnabled(); if(await c2.isEnabled()){ await c2.click(); await page.waitForTimeout(2500); }
  R.checks.step4_heading=await heads(page); await shot(page,'wizard-4-ready',true);
  R.checks.wizard_total_ms=Date.now()-t0;
  // 4. dashboard
  await page.goto(`${BASE}/wp-admin/admin.php?page=wallet-up`,{waitUntil:'networkidle'}); await page.waitForTimeout(2500); await shot(page,'dashboard',true);
  const nav=await page.locator('#wallet-up-admin-root nav button, #wallet-up-admin-root nav a').allInnerTexts(); R.checks.nav_order=nav.map(s=>s.replace(/\s+/g,' ').trim()).filter(Boolean);
  const dashText=await page.locator('#wallet-up-admin-root').innerText();
  R.checks.upsell_surfaces={explore_pro_nav:/Explore Pro/.test(dashText), sidebar_unlock_card:/Unlock all 20 payment methods/.test(dashText), bottom_banner:/Unlock the full Wallet Up experience/.test(dashText), pro_badges:(dashText.match(/\bPRO\b/g)||[]).length};
  const perf=await page.evaluate(()=>({requests:performance.getEntriesByType('resource').length, kb:Math.round(performance.getEntriesByType('resource').reduce((a,e)=>a+(e.transferSize||e.encodedBodySize||0),0)/1024), media_loaded:!!(window.wp&&window.wp.media), wp_components_css:!!document.querySelector('link[id^="wp-components"]'), block_editor_js:!!document.querySelector('script[src*="block-editor"]')})); R.checks.admin_page_weight=perf;
  // 5. locked page + settings
  await page.locator('#wallet-up-admin-root :is(a,button):has-text("Donations")').first().click(); await page.waitForTimeout(1800); await shot(page,'donations-locked',true);
  const lockedText=await page.locator('#wallet-up-admin-root').innerText(); R.checks.locked_page_mock_numbers=/\$4,280|Sarah M\.|47/.test(lockedText); R.checks.locked_page_has_explainer=/Donations & Campaigns/.test(lockedText);
  await page.locator('#wallet-up-admin-root :is(a,button):has-text("Settings")').first().click(); await page.waitForTimeout(2500); await shot(page,'settings',true);
  const setText=await page.locator('#wallet-up-admin-root').innerText(); R.checks.settings_sections=(setText.match(/^(About Wallet Up|Branding|Setup assistant|Display|Powered by[^\n]*)$/gm)||[]); R.checks.settings_has_powered_by_control=/Powered by/.test(setText); R.checks.settings_has_rerun_setup=/Run setup again|setup assistant/i.test(setText);
  // 6. public page via REST
  await page.goto(`${BASE}/wp-admin/index.php`,{waitUntil:'networkidle'});
  const link=await page.evaluate(async(base)=>{const nonce=window.wpApiSettings&&window.wpApiSettings.nonce;const r=await fetch(base+'/?rest_route=/wp/v2/pages',{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':nonce},credentials:'same-origin',body:JSON.stringify({title:'Pay me',status:'publish',content:'[wallet_up method="paypal" amount="25"]'})});const j=await r.json();return j.link;},BASE);
  const v=await browser.newContext({viewport:{width:390,height:844},isMobile:true}); const vp=await v.newPage(); await vp.goto(link,{waitUntil:'networkidle'}); await vp.waitForTimeout(1200);
  const ext=await vp.evaluate(()=>performance.getEntriesByType('resource').filter(e=>!e.name.startsWith('http://127.0.0.1')).map(e=>new URL(e.name).host)); R.checks.visitor_external_hosts=[...new Set(ext)]; R.checks.visitor_loads_stripe=ext.some(h=>/stripe/.test(h));
  const vb=await vp.textContent('body'); R.checks.visitor_powered_by=/Powered by/.test(vb); R.checks.visitor_card_rendered=/Scan or Click to pay/.test(vb); n++; await vp.screenshot({path:`${OUT}/${String(n).padStart(2,'0')}-visitor-mobile.png`,fullPage:true});
  R.console_errors=errs.slice(0,10);
  fs.writeFileSync(OUT+'/results.json',JSON.stringify(R,null,2)); console.log(JSON.stringify(R,null,2));
  await browser.close();
})().catch(e=>{console.error('FATAL',e.message); R.fatal=e.stack; fs.writeFileSync(OUT+'/results.json',JSON.stringify(R,null,2)); process.exit(1);});
