const fs=require('node:fs'),assert=require('node:assert/strict'),puppeteer=require('/root/maudits-lighthouse/node_modules/puppeteer-core');
const [editor,reader]=JSON.parse(fs.readFileSync('/root/uptep-chaine-tests.json'));const fixture=JSON.parse(fs.readFileSync('/root/uptep-focus-data.json'));
(async()=>{const b=await puppeteer.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox']});const errors=[];
async function login(u){const ctx=await b.createBrowserContext(),p=await ctx.newPage();p.on('pageerror',e=>errors.push(e.message));p.on('console',m=>{if(m.text().includes('Content Security Policy'))errors.push(m.text());});await p.goto('https://uptep.top/login.php',{waitUntil:'networkidle0'});await p.type('#username',u.username);await p.type('#password',u.password);await Promise.all([p.waitForNavigation({waitUntil:'networkidle0'}),p.click('.primary-button')]);return p;}
try{
assert.equal((await fetch('https://uptep.top/compte.php',{redirect:'manual'})).status,303);
const p=await login(editor),r=await login(reader);
await p.click('.account-link');await p.waitForSelector('#game-player-id');assert.equal(await p.$eval('#game-player-id',e=>e.value),'');
const wrongCsrf=await p.evaluate(async id=>(await fetch('/compte.php',{method:'POST',body:new URLSearchParams({csrf:'bad',game_player_id:id})})).status,String(fixture.player_id));assert.equal(wrongCsrf,403);
const wrongId=await p.evaluate(async()=>(await fetch('/compte.php',{method:'POST',body:new URLSearchParams({csrf:document.querySelector('[name=csrf]').value,game_player_id:'9223372036854775807'})})).status);assert.equal(wrongId,422);
await p.type('#game-player-id',String(fixture.player_id));
// A supplied user_id cannot redirect a personal account update to another user.
await p.evaluate(id=>{const hidden=document.createElement('input');hidden.type='hidden';hidden.name='user_id';hidden.value=id;document.querySelector('.account-settings form').append(hidden);},String(reader.id));
await Promise.all([p.waitForNavigation({waitUntil:'networkidle0'}),p.click('.account-settings button[type=submit]')]);assert.ok(await p.$('.linked-player'));assert.equal(await p.$eval('#game-player-id',e=>e.value),String(fixture.player_id));
await r.goto('https://uptep.top/compte.php',{waitUntil:'networkidle0'});assert.equal(await r.$eval('#game-player-id',e=>e.value),'');
await p.setViewport({width:1440,height:1000});await p.screenshot({path:'/root/uptep-site/previews/mon-compte-desktop.png'});
await p.goto('https://uptep.top/chaine.php',{waitUntil:'networkidle0'});
assert.equal(await p.$eval('tr.is-self',e=>e.dataset.playerId),String(fixture.player_id));
const targets=await p.$$eval('tr.is-target',es=>es.map(e=>Number(e.dataset.playerId)).sort((a,b)=>a-b));assert.deepEqual(targets,[...fixture.targets].sort((a,b)=>a-b));
await p.click('[data-sort=field]');await p.click('[data-sort=field]');assert.deepEqual(await p.$$eval('tr.is-target',es=>es.map(e=>Number(e.dataset.playerId)).sort((a,b)=>a-b)),targets);
await p.screenshot({path:'/root/uptep-site/previews/chaine-personnelle-desktop.png'});
for(const route of ['chaine.php','compte.php']){await p.goto('https://uptep.top/'+route,{waitUntil:'networkidle0'});for(const width of [320,390,768,1440]){await p.setViewport({width,height:900});assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,`${route} ${width}`);}}
await p.$eval('#game-player-id',e=>e.value='');await Promise.all([p.waitForNavigation({waitUntil:'networkidle0'}),p.click('.account-settings button[type=submit]')]);
await p.goto('https://uptep.top/chaine.php',{waitUntil:'networkidle0'});assert.equal(await p.$$eval('tr.is-self,tr.is-target',es=>es.length),0);assert.ok(await p.$('.chaine-personal-note a[href="/compte.php"]'));
assert.deepEqual(errors,[]);console.log('OK : compte privé, ID vérifié, CSRF, isolation entre comptes, enregistrement/retrait, surlignage et tri, 320–1440 px.');
}finally{await b.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
