const fs=require('node:fs'),assert=require('node:assert/strict'),{execFileSync}=require('node:child_process');
const puppeteer=require('/root/maudits-lighthouse/node_modules/puppeteer-core');
const [editor,reader]=JSON.parse(fs.readFileSync('/root/uptep-chaine-tests.json'));const origin='https://uptep.top';
(async()=>{const b=await puppeteer.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox']});const errors=[];
async function login(u){const ctx=await b.createBrowserContext(),p=await ctx.newPage();p.on('pageerror',e=>errors.push(e.message));p.on('console',m=>{if(m.text().includes('Content Security Policy'))errors.push(m.text());});await p.goto(origin+'/login.php',{waitUntil:'networkidle0'});await p.type('#username',u.username);await p.type('#password',u.password);await Promise.all([p.waitForNavigation({waitUntil:'networkidle0'}),p.click('.primary-button')]);await p.goto(origin+'/chaine.php',{waitUntil:'networkidle0'});return p;}
try{
 assert.equal((await fetch(origin+'/chaine.php',{redirect:'manual'})).status,303);
 const r=await login(reader);assert.ok(await r.$('.nav-active[href="/chaine.php"]'));assert.equal(await r.$$('.chaine-grade').then(es=>es.length),0);assert.ok(await r.$$eval('.chaine-table tbody tr',es=>es.length)>0);
 assert.ok((await r.$eval('.chaine-updated',e=>e.textContent)).includes('Dernier relevé'));
 const p=await login(editor);assert.ok(await p.$('.chaine-grade'));
 assert.deepEqual(await p.$$eval('.chaine-grade option',es=>es.slice(0,6).map(e=>e.textContent)),['Non attribué','Grenier','Passeur','Chasseur','A flooder','Ne pas toucher']);
 const state=await p.$$eval('.chaine-table tbody tr',rows=>rows.map(row=>({id:row.dataset.playerId,version:row.querySelector('[name=version]').value})).find(r=>r.version==='0'));
 assert.ok(state,'Expected unassigned member for unchanged-value save test');
 const forged=await r.evaluate(async id=>(await fetch('/chaine.php',{method:'POST',body:new URLSearchParams({csrf:document.querySelector('[name=csrf]').value,player_id:id,version:'0',grade:'Grenier',sort_order:'1'})})).status,state.id);assert.equal(forged,403);
 const csrf=await p.$eval('[name=csrf]',e=>e.value);
 const bad=await p.evaluate(async({id,csrf})=>(await fetch('/chaine.php',{method:'POST',body:new URLSearchParams({csrf,player_id:id,version:'0',grade:'invalide',sort_order:'1'})})).status,{id:state.id,csrf});assert.equal(bad,422);
 const badCsrf=await p.evaluate(async id=>(await fetch('/chaine.php',{method:'POST',body:new URLSearchParams({csrf:'invalid',player_id:id,version:'0',grade:'',sort_order:''})})).status,state.id);assert.equal(badCsrf,403);
 await Promise.all([p.waitForNavigation({waitUntil:'networkidle0'}),p.click(`#player-${state.id} button`)]);assert.ok(await p.$('[role=status]'));assert.equal(await p.$eval(`#player-${state.id} [name=version]`,e=>e.value),'1');
 console.log('OK: liste FZZZ, lecture pour tous les comptes, édition réservée à chaine, validation serveur/CSRF et enregistrement du formulaire.');
 await p.setViewport({width:1440,height:1000});await p.screenshot({path:'/root/uptep-site/previews/chaine-editor-desktop.png'});
 for(const page of [p,r])for(const width of [320,390,768,1440]){await page.setViewport({width,height:900});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,`overflow ${width}`);}
 await r.screenshot({path:'/root/uptep-site/previews/chaine-reader-desktop.png'});await p.setViewport({width:390,height:844});await p.screenshot({path:'/root/uptep-site/previews/chaine-mobile.png',fullPage:true});
 execFileSync('runuser',['-u','postgres','--','psql','-X','-d','uptep','-c',"UPDATE users SET is_chaine=false WHERE username='qa_chaine_editor'"],{stdio:'pipe'});
 await p.reload({waitUntil:'networkidle0'});assert.equal(await p.$$('.chaine-grade').then(es=>es.length),0);
 assert.deepEqual(errors,[]);console.log('OK: retrait immédiat du droit, affichage de 320 à 1440 px, aucune erreur JS/CSP.');
}finally{await b.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
