const assert = require('node:assert/strict');
const fs = require('node:fs');
const puppeteer = require('/root/maudits-lighthouse/node_modules/puppeteer-core');
const [admin,member,pending]=JSON.parse(fs.readFileSync('/root/uptep-admin-tests.json'));
const origin='https://uptep.top';
(async()=>{
 const b=await puppeteer.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox']});
 const errors=[];
 async function login(account){
   const context=await b.createBrowserContext();const p=await context.newPage();
   p.on('pageerror',e=>errors.push(e.message));
   p.on('console',m=>{if(m.text().includes('Content Security Policy'))errors.push(m.text());});
   await p.goto(origin+'/login.php',{waitUntil:'networkidle0'});
   await p.type('#username',account.username);await p.type('#password',account.password);
   await Promise.all([p.waitForNavigation({waitUntil:'networkidle0'}),p.click('.primary-button')]);
   assert.equal(p.url(),origin+'/');return p;
 }
 async function save(p,id){await Promise.all([p.waitForNavigation({waitUntil:'networkidle0'}),p.click(`#user-${id} button`)]);}
 try {
   const anon=await fetch(origin+'/admin.php',{redirect:'manual'});assert.equal(anon.status,303);
   const a=await login(admin);await a.setViewport({width:1440,height:1000});
   assert.equal(await a.$$eval('.top-navigation a',es=>es.length),2);
   await a.goto(origin+'/admin.php',{waitUntil:'networkidle0'});
   const m=await login(member);
   assert.equal(await m.$$eval('.top-navigation a',es=>es.length),1);
   assert.equal((await m.goto(origin+'/admin.php',{waitUntil:'networkidle0'})).status(),403);
   const memberPost=await m.evaluate(async id=>{
     const csrf=document.querySelector('[name=csrf]').value;
     return (await fetch('/admin.php',{method:'POST',body:new URLSearchParams({csrf,user_id:id,version:1,admin:1,approved:1})})).status;
   },String(member.id));assert.equal(memberPost,403);
   const badCsrf=await a.evaluate(async id=>(await fetch('/admin.php',{method:'POST',body:new URLSearchParams({csrf:'invalid',user_id:id,version:1,admin:1,approved:1})})).status,String(member.id));assert.equal(badCsrf,403);
   for(const key of ['chef','chaine'])await a.click(`input[form="user-${member.id}"][name="${key}"]`);
   await save(a,member.id);
   assert.ok(await a.$('[role=status]'));
   for(const key of ['chef','chaine','approved'])assert.equal(await a.$eval(`input[form="user-${member.id}"][name="${key}"]`,e=>e.checked),true);
   assert.equal(await a.$eval(`input[form="user-${member.id}"][name="admin"]`,e=>e.checked),false);
   console.log('OK: accès réservé, POST non-admin/CSRF bloqués, cases indépendantes enregistrées.');
   // Revocation takes effect on an already logged-in member.
   await a.click(`input[form="user-${member.id}"][name="approved"]`);await save(a,member.id);
   await m.goto(origin+'/',{waitUntil:'networkidle0'});assert.ok(m.url().endsWith('/login.php'));
   // Approve an account and grant multiple rights through the actual form.
   for(const key of ['admin','chef','chaine','approved'])await a.click(`input[form="user-${pending.id}"][name="${key}"]`);
   await save(a,pending.id);
   const newAdmin=await login(pending);
   assert.equal((await newAdmin.goto(origin+'/admin.php',{waitUntil:'networkidle0'})).status(),200);
   // Existing admin sessions lose access immediately when their Admin right is removed.
   await a.click(`input[form="user-${pending.id}"][name="admin"]`);await save(a,pending.id);
   assert.equal((await newAdmin.goto(origin+'/admin.php',{waitUntil:'networkidle0'})).status(),403);
   console.log('OK: validation autorise la connexion ; retrait de validation ou du droit Admin effectif sur les sessions existantes.');
   await a.screenshot({path:'/root/uptep-site/previews/administration-desktop.png'});
   await a.setViewport({width:390,height:844});
   assert.equal(await a.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
   assert.equal(await a.$eval('.users-scroll',e=>e.scrollWidth>e.clientWidth),true);
   await a.screenshot({path:'/root/uptep-site/previews/administration-mobile.png',fullPage:true});
   assert.deepEqual(errors,[]);
   console.log('OK: affichage ordinateur/mobile et aucune erreur JavaScript/CSP.');
 }finally{await b.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
