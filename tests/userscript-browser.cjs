const fs=require('node:fs'),assert=require('node:assert/strict'),pp=require('/root/maudits-lighthouse/node_modules/puppeteer-core');
(async()=>{const b=await pp.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox']});try{
 const accounts=JSON.parse(fs.readFileSync('/root/uptep-chaine-tests.json'));const ctx=await b.createBrowserContext(),p=await ctx.newPage();
 async function login(page,a){await page.goto('https://uptep.top/login.php');await page.type('#username',a.username);await page.type('#password',a.password);await Promise.all([page.waitForNavigation(),page.click('.primary-button')]);}
 await login(p,accounts[0]);await p.goto('https://uptep.top/script.php');await Promise.all([p.waitForNavigation(),p.click('.account-settings button')]);
 const source=await p.evaluate(async()=>await(await fetch('/uptep.user.js')).text());assert.ok(source.startsWith('// ==UserScript=='));assert.ok(!source.includes('__UPTEP_TOKEN__'));
 const gameData=JSON.parse(fs.readFileSync('/root/uptep-script-test-player.json'));
 const game=await ctx.newPage();await game.setRequestInterception(true);game.on('request',r=>r.respond({status:200,contentType:'text/html',body:'<!doctype html><html><body><div id="pseudo"></div><div id="tag_alliance">UPTEP</div><div id="menuBoite"><div id="boiteComptePlus"><div class="titre_colonne_cliquable">Compte +</div><div class="contenu_boite_compte_plus">Contenu</div></div></div></body></html>'}));
 await game.exposeFunction('transport',async opts=>{const r=await fetch(opts.url,{method:opts.method,headers:opts.headers,body:opts.data});return {status:r.status,responseText:await r.text(),finalUrl:r.url};});
 for(const api of ['legacy','modern']){
  await game.goto('https://s5.fourmizzz.fr/test-uptep');await game.evaluate(({pseudo,api})=>{document.getElementById('pseudo').textContent=pseudo;window.timers=[];window.setInterval=(fn,ms)=>{timers.push({fn,ms});return timers.length};const xhr=opts=>transport(opts).then(opts.onload).catch(opts.onerror);if(api==='legacy')window.GM_xmlhttpRequest=xhr;else window.GM={xmlHttpRequest:xhr};},{pseudo:gameData.pseudo,api});
  await game.addScriptTag({content:source});await game.waitForSelector('#uptep-plus-badge');assert.equal(await game.$eval('#uptep-plus-badge .titre_colonne_cliquable',e=>e.textContent),'UPTEP+');
  await game.evaluate(()=>{document.getElementById('tag_alliance').textContent='OTHER';timers.find(t=>t.ms===1000).fn();});assert.equal(await game.$('#uptep-plus-badge'),null);
  await game.evaluate(()=>{document.getElementById('tag_alliance').textContent='UPTEP';return timers.find(t=>t.ms===30000).fn();});await game.waitForSelector('#uptep-plus-badge');
 }
 const adminCtx=await b.createBrowserContext(),admin=await adminCtx.newPage();await login(admin,accounts[1]);await admin.goto('https://uptep.top/admin.php');
 const row=`tr[data-user-id="${accounts[0].id}"]`;
 await Promise.all([admin.waitForNavigation(),admin.click(`${row} button[aria-label^="Désactiver UPTEP+"]`)]);
 await game.evaluate(()=>timers.find(t=>t.ms===30000).fn());assert.equal(await game.$('#uptep-plus-badge'),null);
 await Promise.all([admin.waitForNavigation(),admin.click(`${row} button[aria-label^="Activer UPTEP+"]`)]);
 await game.evaluate(()=>timers.find(t=>t.ms===30000).fn());await game.waitForSelector('#uptep-plus-badge');
 // Simulate a new remote revision without changing the installed loader.
 await game.evaluate(()=>{window.transport=async opts=>({status:200,responseText:JSON.stringify({ok:true,revision:'new',code:'const a=context.document.createElement("span");a.id="uptep-plus-badge";a.textContent="UPTEP+ v2";context.document.body.append(a);return ()=>a.remove();'})});});
 await game.evaluate(()=>timers.find(t=>t.ms===30000).fn());assert.equal(await game.$eval('#uptep-plus-badge',e=>e.textContent),'UPTEP+ v2');
 for(const width of [390,1024,1440]){await p.setViewport({width,height:900});assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,'Install page overflow '+width);await admin.setViewport({width,height:900});assert.equal(await admin.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,'Admin overflow '+width);}
 await ctx.close();await adminCtx.close();console.log('OK : installation personnelle, API réelle, badge avec API GM legacy/moderne simulées, alliance, désactivation/réactivation administrateur sans réinstallation et pages mobiles.');
}finally{await b.close();}})().catch(e=>{console.error(e.message);process.exitCode=1});
