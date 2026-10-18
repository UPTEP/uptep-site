const {chromium}=require(process.env.UPTEP_PLAYWRIGHT_MODULE||'playwright'),fs=require('fs'),assert=require('assert/strict'),path=require('path');
const laboratory=process.env.UPTEP_DEMAND_PAGE==='laboratory',gamePage=laboratory?'laboratoire':'construction',count=laboratory?10:13,expected={name:laboratory?'Technique de ponte':'Champignonnière',level:laboratory?'11':'16',wood:laboratory?'122880':'915837',apples:laboratory?'122880':'0'};
const root=path.resolve(__dirname,'..'),source=fs.readFileSync(root+'/userscript/src/construction-demands.js','utf8');
(async()=>{const browser=await chromium.launch({headless:true,args:['--no-sandbox']});try{
for(const mode of ['native','optizzz','cancel','retry','reload-retry','double-click','food','malformed','identity','compiled','disabled','cancelled','finished','state-offline']){
 const ctx=await browser.newContext({viewport:mode==='optizzz'?{width:390,height:844}:{width:1100,height:850}});let remoteState='attente',detailOffline=false;let calls=[],fail=['retry','reload-retry'].includes(mode);
 const fixture=fs.readFileSync(root+'/tests/fixtures/'+(laboratory?'laboratory':'construction')+'-'+(mode==='optizzz'?'optizzz':'native')+'.html','utf8');
 await ctx.route('**/*',async route=>{const url=new URL(route.request().url());
  if(url.hostname==='s5.fourmizzz.fr')return route.fulfill({contentType:'text/html; charset=utf-8',body:'<!doctype html><meta charset="utf-8"><span id="pseudo">Sender</span><span id="tag_alliance">UPTEP</span><div id="centre">'+fixture+'</div>'});
  if(url.pathname==='/portal.php')return route.fulfill({contentType:'text/html',body:fs.readFileSync(root+'/public/portal.php','utf8').replace(/<\?php[\s\S]*?\?>/g,'')});
  if(url.pathname==='/assets/portal.js')return route.fulfill({contentType:'application/javascript',body:fs.readFileSync(root+'/public/assets/portal.js','utf8')});
  if(url.pathname==='/portal-api.php'){const payload=route.request().postDataJSON();if(payload.action==='convoy-detail')return detailOffline?route.fulfill({status:503,json:{ok:false,error:'État indisponible'}}):route.fulfill({json:{ok:true,demand:{id:'999',annulee:remoteState==='annulee',fini:remoteState==='terminee',etat:remoteState}}});calls.push(payload);assert.equal(payload.action,'convoy-create');assert.equal(payload.pseudo,'Sender');return fail?route.fulfill({status:503,json:{ok:false,error:'Coupure de test'}}):route.fulfill({json:{ok:true,demand_id:'999',state:'attente'}});}
  return route.fulfill({body:''});
 });
 const page=await ctx.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));await page.goto('https://s5.fourmizzz.fr/'+gamePage+'.php');
 if(mode==='food'&&!laboratory)await page.evaluate(()=>document.querySelector('.cout_amelioration table tbody').insertAdjacentHTML('beforeend','<tr><td class="nourriture">12 345</td></tr>'));
 if(mode==='malformed')await page.evaluate(()=>{document.querySelector('.materiaux').textContent='915 ???';document.querySelectorAll('.niveau_amelioration')[1].textContent='niveau 8 → 9 en cours';});
 async function mount(){if(['compiled','disabled'].includes(mode)){await page.evaluate(({code,disabled})=>{window.qaCleanup=new Function('context','const uptepPreferences={convoys:'+!disabled+',clock:false,chaine:false,laboratory:false,traffic:false,multiflood:false,economy:false,playerProfile:false,floodDeclarations:false};const uptepPortalAuth={token:"'+'a'.repeat(64)+'"};'+code)({document,window,pseudo:'Sender'});},{code:fs.readFileSync(root+'/app/script-runtime.min.js','utf8'),disabled:mode==='disabled'});}else await page.evaluate(code=>{window.qaCleanup=new Function('context','auth',code+'\nreturn mountConstructionDemands(context,auth);')({document,window,pseudo:'Sender'},{token:'a'.repeat(64)});},source);}
 await mount();const buttons=page.locator('.uptep-building-demand button');assert.equal(await buttons.count(),mode==='disabled'?0:mode==='malformed'?count-2:count);
 if(['disabled','malformed'].includes(mode)){assert.equal(calls.length,0);await ctx.close();console.log(mode+' OK');continue;}
 const first=buttons.first();await first.click();let modal=page.locator('dialog.uptep-building-modal');await modal.waitFor({state:'visible'});assert.match(await modal.innerText(),new RegExp(expected.name+' · niveau '+expected.level));assert.match((await modal.innerText()).replace(/[\s\u202f]/g,''),new RegExp(expected.wood+'matériaux'));
 if(mode==='optizzz')assert(await modal.evaluate(e=>e.getBoundingClientRect().width<=innerWidth));
 if(mode==='cancel'){await modal.getByRole('button',{name:'Annuler',exact:true}).click();assert.equal(calls.length,0);assert.equal(await page.locator('dialog').count(),0);await ctx.close();console.log(mode+' OK');continue;}
 await modal.locator('[name=auto_production]').check();if(mode==='identity')await page.evaluate(()=>document.getElementById('pseudo').textContent='Other');
 const submit=modal.getByRole('button',{name:'Confirmer la demande'});if(mode==='double-click')await submit.dblclick();else await submit.click();
 if(mode==='identity'){await modal.getByRole('status').filter({hasText:'Le compte connecté a changé.'}).waitFor();assert.equal(calls.length,0);await ctx.close();console.log(mode+' OK');continue;}
 if(fail){await modal.getByRole('button',{name:'Réessayer la création'}).waitFor();assert.equal(calls.length,1);assert(await modal.locator('[name=auto_production]').isDisabled());fail=false;
  if(mode==='reload-retry'){await page.reload();await mount();await page.locator('.uptep-building-demand button').first().click();modal=page.locator('dialog.uptep-building-modal');}
  await modal.getByRole('button',{name:'Réessayer la création'}).click();
 }
 await page.locator('.uptep-building-demand small').filter({hasText:'Demande #999 créée'}).waitFor();assert.equal(await page.locator('dialog.uptep-building-modal').count(),0);assert.equal(calls.length,['retry','reload-retry'].includes(mode)?2:1);
 const payload=calls[0];assert.equal(payload.apples,mode==='food'&&!laboratory?'12345':expected.apples);assert.equal(payload.wood,expected.wood);assert.equal(payload.level,expected.level);assert.equal(payload.object,expected.name);assert.equal(payload.auto_production,true);assert.equal(payload.confirmed,true);if(calls.length===2)assert.deepEqual(calls[0],calls[1]);
 await page.locator('.uptep-building-demand button').first().click();assert(await page.locator('dialog button[type=submit]').isDisabled());assert.equal(calls.length,['retry','reload-retry'].includes(mode)?2:1);
 if(['cancelled','finished','state-offline'].includes(mode)){
  if(mode==='state-offline')detailOffline=true;else remoteState=mode==='cancelled'?'annulee':'terminee';
  await page.evaluate(()=>window.dispatchEvent(new Event('focus')));
  if(mode==='state-offline'){await page.locator('.uptep-building-demand small').filter({hasText:'État non vérifié'}).waitFor();assert.equal(calls.length,1);assert.equal(await page.evaluate(()=>Object.values(localStorage).filter(v=>v.includes('"state":"done"')).length),1);}
  else {await page.locator('dialog.uptep-building-modal').waitFor({state:'detached'});assert.equal(await page.locator('.uptep-building-demand small').filter({hasText:'999'}).count(),0);await page.locator('.uptep-building-demand button').first().click();await page.locator('dialog button[type=submit]').click();await page.locator('dialog.uptep-building-modal').waitFor({state:'detached'});assert.equal(calls.length,2);assert.notEqual(calls[0].request_key,calls[1].request_key);}
 }

 await page.evaluate(()=>window.qaCleanup());assert.equal(await page.locator('.uptep-building-demand,dialog.uptep-building-modal').count(),0);assert.deepEqual(errors,[]);await ctx.close();console.log(mode+' OK');
}
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1});
