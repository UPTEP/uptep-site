const fs=require('node:fs'),assert=require('node:assert/strict'),puppeteer=require('/root/maudits-lighthouse/node_modules/puppeteer-core');
(async()=>{const browser=await puppeteer.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox']});try{
const accounts=JSON.parse(fs.readFileSync('/root/uptep-chaine-tests.json'));
for(const [index,account] of accounts.entries()){
 const ctx=await browser.createBrowserContext(),p=await ctx.newPage(),errors=[];p.on('pageerror',e=>errors.push(e.message));
 await p.goto('https://uptep.top/stats.php',{waitUntil:'networkidle0'});assert.ok(p.url().includes('/login.php'));
 await p.type('#username',account.username);await p.type('#password',account.password);await Promise.all([p.waitForNavigation({waitUntil:'networkidle0'}),p.click('.primary-button')]);
 await p.click('nav a[href="/stats.php"]');await p.waitForSelector('.stats-form');
 assert.equal(await p.$$eval('.stats-field input',es=>es.length),41);
 assert.ok(await p.$$eval('.stats-field input',es=>es.every(e=>e.value==='')));
 if(index===0){
  await p.type('#stat-buildings-couveuse','12');await p.type('#stat-research-armes','0');await p.type('#stat-army-jsn','9 007 199 254 740 993');await p.type('#stat-workers-total','150000');await p.type('#stat-resources-nourriture','250000');
  assert.equal(await p.$eval('.stats-form',f=>f.checkValidity()),true);
  await Promise.all([p.waitForNavigation({waitUntil:'networkidle0'}),p.click('.stats-toolbar button')]);assert.ok(await p.$('.admin-success'));
  assert.equal(await p.$eval('#stat-army-jsn',e=>e.value),'9007199254740993');assert.equal(await p.$eval('#stat-research-armes',e=>e.value),'0');assert.equal(await p.$eval('#stat-research-acide',e=>e.value),'');
  await p.reload({waitUntil:'networkidle0'});assert.equal(await p.$eval('#stat-buildings-couveuse',e=>e.value),'12');
 }else{
  await p.goto('https://uptep.top/stats.php?user_id='+accounts[0].id,{waitUntil:'networkidle0'});assert.ok(await p.$$eval('.stats-field input',es=>es.every(e=>e.value==='')));
 }
 for(const width of [390,1024,1440]){await p.setViewport({width,height:1000});assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,'Overflow '+width);}
 if(index===0)await p.screenshot({path:'/root/uptep-site/previews/stats-desktop.png',fullPage:true});
 assert.deepEqual(errors,[]);await ctx.close();
}
console.log('OK : connexion requise, 41 champs, enregistrement/relecture, zéro et vide distincts, grands nombres, isolation et affichage 390/1024/1440 px.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
