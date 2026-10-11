const puppeteer=require('/root/maudits-lighthouse/node_modules/puppeteer-core');const fs=require('fs'),assert=require('node:assert/strict');
(async()=>{const browser=await puppeteer.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox']});try{
 const page=await browser.newPage();const errors=[],origins=new Set();page.on('pageerror',e=>errors.push(e.message));page.on('request',r=>{if(r.url().startsWith('http'))origins.add(new URL(r.url()).origin);});
 await page.setViewport({width:1440,height:1000});const response=await page.goto('https://api.uptep.top/');assert.equal(response.status(),200);assert.equal(page.url(),'https://api.uptep.top/docs');
 await page.waitForSelector('.opblock',{timeout:20000});assert.equal(await page.$$eval('.opblock',a=>a.length),6);
 assert.equal(await page.evaluate(()=>ui.specSelectors.specJson().get('openapi')),'3.0.3');
 const secret=fs.readFileSync('/root/uptep-bot-test-secret.txt','utf8').trim();await page.evaluate(token=>ui.preauthorizeApiKey('BotBearer',token),secret);
 const block=await page.$('.opblock-get');await page.click('.opblock-get .opblock-summary-control');await page.waitForSelector('.try-out__btn');await page.click('.try-out__btn');await page.waitForSelector('.execute');
 const apiResponse=page.waitForResponse(r=>r.url()==='https://uptep.top/api/bot/v1/health'&&r.request().method()==='GET');await page.click('.execute');const api=await apiResponse;assert.equal(api.status(),200);assert.equal((await api.json()).status,'healthy');
 assert.equal(api.headers()['access-control-allow-origin'],'https://api.uptep.top');
 await page.reload();await page.waitForSelector('.opblock');assert.equal(await page.evaluate(()=>ui.authSelectors.authorized().size),0);
 await page.screenshot({path:'/root/uptep-site/previews/api-docs-desktop.png',fullPage:true});
 await page.setViewport({width:390,height:844});await page.screenshot({path:'/root/uptep-site/previews/api-docs-mobile.png',fullPage:true});
 assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Mobile overflow');assert.deepEqual(errors,[]);assert.deepEqual([...origins].sort(),['https://api.uptep.top','https://uptep.top']);
 console.log('Docs OK: six endpoints, valid rendered spec, authenticated health via Swagger/CORS, secret cleared on reload, self-hosted assets, mobile.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
