const { chromium } = require('playwright');
(async()=>{
  const BASE='http://127.0.0.1:8080'; const browser=await chromium.launch(); const ctx=await browser.newContext(); const page=await ctx.newPage();
  await page.goto(`${BASE}/wp-login.php`); await page.fill('#user_login','admin'); await page.fill('#user_pass','password'); await page.click('#wp-submit'); await page.waitForLoadState('load');
  await page.goto(`${BASE}/wp-admin/plugin-install.php?tab=upload`,{waitUntil:'load'});
  await page.setInputFiles('#pluginzip', process.argv[2]);
  await page.click('#install-plugin-submit'); await page.waitForLoadState('load',{timeout:120000}); await page.waitForTimeout(1500);
  const txt=(await page.textContent('body')).replace(/\s+/g,' ');
  console.log('RESULT', (txt.match(/(Plugin installed successfully|Unpacking the package|Installing the plugin|error|Destination folder already exists)[^.]{0,80}/gi)||[]).slice(0,4).join(' | '));
  await page.screenshot({path:'/tmp/claude-0/upload-result.png'});
  await browser.close();
})();
