const {chromium}=require(process.env.UPTEP_PLAYWRIGHT_MODULE||'playwright');const fs=require('fs'),assert=require('assert/strict');
const root=require('path').resolve(__dirname,'..'),fixture=fs.readFileSync(root+'/tests/fixtures/commerce.html','utf8'),moduleCode=fs.readFileSync(root+'/userscript/src/chaine.js','utf8')+'\n'+fs.readFileSync(process.env.UPTEP_CONVOYS_SOURCE||root+'/userscript/src/convoys.js','utf8');
(async()=>{const browser=await chromium.launch({headless:true,args:['--no-sandbox']});try{
for(const mode of (process.env.UPTEP_CONVOY_TEST_MODES?process.env.UPTEP_CONVOY_TEST_MODES.split(','):['success','refused','api-failure','lost-response','wrong-account','no-stock','double-click','storage-failure','ambiguous','compiled','etable','tabs','create-retry','create-validation','auto-cancel','auto-send','auto-late','auto-retry','overage-cancel','overage-send','overage-retry','auto-overage','protected-document','protected-after-send','protected-bridge','protected-bridge-retry','protected-bridge-legacy','projection','colonies','colonies-unknown'])){
 const ctx=await browser.newContext();let demandCalls=0;let warnings=0,posts=0,declares=0,gameSent=false,failDeclare=['api-failure','auto-retry','overage-retry','protected-bridge-retry'].includes(mode),createCalls=[],failCreate=mode==='create-retry',invalidCreate=mode==='create-validation',lost=mode==='lost-response',posted;
 const colony={own:'80',loss:{terrain:'80',percent:20},gains:[{terrain:'100',percent:20}],assumed_rates:mode==='colonies-unknown'};
 const line='<br><span class="gras">- Vous allez livrer 10 <img alt="nourritures"> et 20 <img alt="materiaux"> à <a href="Membre.php?Pseudo=Target">Target</a> dans 1H 0m 0s</span> - <a href="commerce.php?annuler=9001&t=new-token">Annuler</a><br>';
 const html=()=>fixture.replaceAll('fixture-token','new-token').replace('<div class="Bas">',(gameSent?line:'')+'<div class="Bas">');
 await ctx.route('**/*',async route=>{const req=route.request(),u=new URL(req.url());
  if(u.hostname==='s5.fourmizzz.fr'){
   if(req.method()==='POST'){posts++;posted=new URLSearchParams(req.postData());if(mode!=='refused')gameSent=true;if(lost){lost=false;return route.abort();}}
   let body=html().replace(/<script type="text\/javascript">/g,'<script type="application/json">');if(mode==='etable')body=body.replaceAll('var bonus = 0;','var bonus = 20;');if(mode==='ambiguous'&&gameSent)body=body.replace('<div class="Bas">',line.replace('9001','9002')+'<div class="Bas">');if(mode==='wrong-account'&&req.resourceType()!=='document')body=body.replace('id="pseudo">Sender','id="pseudo">Other');if(mode==='no-stock')body=body.replace(/max_materiaux = 477986/g,'max_materiaux = 0');
   return route.fulfill({status:200,contentType:'text/html; charset=utf-8',body});
  }
  if(u.pathname==='/portal.php')return route.fulfill({status:200,contentType:'text/html; charset=utf-8',body:fs.readFileSync(root+'/public/portal.php','utf8').replace(/<\?php[\s\S]*?\?>/g,'')});
  if(u.pathname==='/assets/portal.js')return route.fulfill({status:200,contentType:'application/javascript',body:fs.readFileSync(root+'/public/assets/portal.js','utf8')});
  if(u.pathname==='/portal-api.php'){
   const data=req.postDataJSON();assert.equal(req.headers().authorization,'Bearer '+'a'.repeat(64));assert.equal(data.pseudo,'Sender');
   if(data.action==='convoy-demands'){demandCalls++;return route.fulfill({json:{ok:true,demands:[{id:'7',target:'Target',nbpomme_restant:'100',nbmateriaux_restant:'100',objet:'Dôme',niveau:10,production:{tdc:'80',capacity_per_cycle:'0',workers_known:true,tdc_at:'2026-10-18 10:00:00',workers_at:'2026-10-18T10:00:00Z',...(mode==='projection'?{projection:{anchor_at:'2026-10-18T10:00:00Z',anchor_kind:'validation',calculated_at:demandCalls>1?'2026-10-18T12:15:00Z':'2026-10-18T11:15:00Z',elapsed_cycles:demandCalls>1?'4':'2',history_complete:true,segments:[{cycles:demandCalls>1?'4':'2',capacity_per_cycle:demandCalls>1?'40':'30'}]}}:{}),...(mode.startsWith('colonies')?{colonies_included:true,capacity_per_cycle:colony,projection:{anchor_at:'2026-10-18T10:00:00Z',anchor_kind:'validation',calculated_at:'2026-10-18T10:45:00Z',elapsed_cycles:'1',history_complete:true,assumed_rates:mode==='colonies-unknown',segments:[{cycles:'1',capacity_per_cycle:colony}]}}:{})},auto_production:mode.startsWith('auto-')&&mode!=='auto-late'&&(mode!=='auto-retry'||gameSent)}]}});}
   if(data.action==='convoy-pending')return route.fulfill({json:{ok:true,demands:[{id:'88',target:'Sender',nbpomme_restant:'1000',nbmateriaux_restant:'2000',objet:'Étable',niveau:12}]}});
   if(data.action==='convoy-create'){createCalls.push(data);if(failCreate)return route.fulfill({status:503,json:{ok:false,error:'Coupure test'}});if(invalidCreate)return route.fulfill({status:422,json:{ok:false,error:'Date invalide'}});return route.fulfill({json:{ok:true,demand_id:'88',state:'attente'}});}
   if(data.action==='convoy-check')return route.fulfill({json:{ok:true,ready:true,overage:mode.includes('overage')?{pommes:'1',materiaux:'2000000000000'}:{pommes:'0',materiaux:'0'},auto_production:mode.startsWith('auto-')&&mode!=='auto-retry'}});
   if(data.action==='convoy-declare'){declares++;assert(gameSent);assert.equal(data.game_id,'9001');assert.equal(data.demand_id,'7');assert.equal(data.apples,'10');assert.equal(data.wood,'20');assert.equal(data.confirmed,true);if(failDeclare)return route.fulfill({status:503,json:{ok:false,error:'Test indisponible'}});return route.fulfill({json:{ok:true,declared:true,demand_id:'7',convoy_id:'42'}});}
   throw Error('Unexpected action '+data.action);
  }
  return route.fulfill({status:200,contentType:u.pathname.endsWith('.js')?'application/javascript':'text/css',body:''});
 });
 const page=await ctx.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));page.on('dialog',d=>{warnings++;assert.equal(posts,0,'all warnings must precede game send');assert.match(d.message(),/auto production|dépasse le restant/);if(d.message().includes('dépasse')){assert.match(d.message(),/1 pommes/);assert.match(d.message().replace(/[\s\u202f]/g,''),/2000000000000matériaux/);}return ['auto-cancel','overage-cancel'].includes(mode)?d.dismiss():d.accept();});await page.goto('https://s5.fourmizzz.fr/commerce.php');if(mode==='projection')await page.clock.install();
 if(mode.startsWith('protected-')){page.setDefaultTimeout(7000);await page.evaluate(mode=>{
  const parse=DOMParser.prototype.parseFromString;DOMParser.prototype.parseFromString=function(...args){const doc=parse.apply(this,args);if(mode==='protected-document'||mode==='protected-after-send'&&args[0].includes('annuler=9001'))Object.defineProperty(doc,'then',{get(){throw new DOMException('Permission denied to access property "then"','SecurityError');}});return doc;};
  window.addEventListener('message',event=>{if(window.protectBridge&&event.origin==='https://uptep.top'&&event.data?.type==='uptep-convoy-result'&&event.data.ok){if(mode==='protected-bridge-legacy')delete event.data.result_json;Object.defineProperty(event.data.result,'then',{get(){throw new DOMException('Permission denied to access property "then"','SecurityError');}});}},true);
 },mode);}
 async function mount(){await page.evaluate(code=>{window.cleanupConvoys?.();(0,eval)(code+'\nwindow.cleanupConvoys=mountConvoys({document,window,pseudo:"Sender"},{token:"'+ 'a'.repeat(64)+'"},new Date().toISOString(),{self:"2",attack_speed:10,players:[{player_id:"2",pseudo:"Sender",x:100,y:100},{player_id:"1",pseudo:"Target",x:103,y:104}]});');},moduleCode);await page.waitForSelector('#uptep-convoys-panel-current form');}
 await mount();assert.match(await page.locator('.uptep-convoy-tdc').innerText(),/TdC : 80 cm²/);assert.match(await page.locator('.uptep-convoy-production').innerText(),(mode==='projection'||mode.startsWith('colonies'))?/2 cycles · ≈ 45 min/:/3 cycles · ≈ 1 h 30 min/);if(mode==='compiled'){const bundle=fs.readFileSync(root+'/userscript/dist/script-runtime.min.js','utf8');await page.evaluate(code=>{window.cleanupConvoys();window.cleanupConvoys=new Function('context','const uptepPreferences={economy:false,clock:false,chaine:false,laboratory:false,traffic:false,multiflood:false};const uptepPortalAuth={token:"'+'a'.repeat(64)+'"};const uptepTrafficServerTime=new Date().toISOString();'+code)({document,window,pseudo:'Sender'});},bundle);await page.waitForSelector('#uptep-convoys-panel-current form');}assert.equal(posts,0);assert.equal(declares,0);if(mode!=='compiled'){assert.match(await page.locator('.uptep-convoy-travel').innerText(),/Trajet :/);assert.equal(await page.locator('#uptep-convoys').evaluate(e=>getComputedStyle(e).backgroundColor),'rgb(215, 195, 132)');}

 if(mode.startsWith('colonies')){
  const badge=page.locator('.uptep-convoy-production');assert.match(await badge.getAttribute('title'),/Colonies internes UPTEP uniquement/);assert.match(await badge.getAttribute('title'),/Récolte nette estimée : 84 par cycle/);assert.equal((await badge.innerText()).includes('étable inconnue'),mode==='colonies-unknown');assert.equal(posts,0);assert.equal(declares,0);await ctx.close();console.log('Browser:',mode,'debit/credit projection OK');continue;
 }

 if(mode==='projection'){
  assert.match(await page.locator('.uptep-convoy-production').getAttribute('title'),/Depuis la validation/);await page.locator('#uptep-convoys-panel-current [name=apples]').fill('17');await page.locator('#uptep-convoys-panel-current [name=wood]').fill('29');
  await page.clock.fastForward(3600000);await page.waitForFunction(()=>document.querySelector('.uptep-convoy-production')?.textContent.includes('1 cycle · ≈ 15 min'));
  assert.equal(await page.locator('#uptep-convoys-panel-current [name=apples]').inputValue(),'17');assert.equal(await page.locator('#uptep-convoys-panel-current [name=wood]').inputValue(),'29');assert.match(await page.locator('#uptep-convoys-panel-current').innerText(),/100 pommes/);assert.equal(posts,0);assert.equal(declares,0);assert(demandCalls>=2);await ctx.close();console.log('Browser: validation projection and hourly refresh preserve typed inputs and real balance OK');continue;
 }

 if(['tabs','create-retry','create-validation'].includes(mode)){
  assert.equal(await page.getByRole('tab').count(),3);
  await page.getByRole('tab',{name:'En attente de validation'}).click();
  await page.locator('#uptep-convoys-panel-pending article').waitFor();
  assert.equal(await page.locator('#uptep-convoys-panel-pending button').count(),0);
  assert.equal(await page.locator('#uptep-convoys-panel-current').isVisible(),false);
  await page.getByRole('tab',{name:'Faire une demande'}).click();
  const form=page.locator('#uptep-convoy-request-form');
  await form.locator('[name=apples]').fill('1k');await form.locator('[name=wood]').fill('2k');
  await form.locator('[name=object]').fill('Étable');await form.locator('[name=level]').fill('12');await form.locator('[name=auto_production]').check();
  await page.getByRole('button',{name:'Créer la demande',exact:true}).click();
  if(mode==='create-retry'){
   await page.getByRole('button',{name:'Réessayer la création'}).waitFor();
   failCreate=false;await page.reload();await mount();await page.getByRole('tab',{name:'Faire une demande'}).click();
   assert.equal(await form.locator('[name=object]').inputValue(),'Étable');
   assert(await form.locator('[name=object]').evaluate(e=>e.readOnly));assert(await form.locator('[name=auto_production]').isChecked());
   await page.getByRole('button',{name:'Réessayer la création'}).click();
  }
  if(mode==='create-validation'){
   await page.getByRole('status').filter({hasText:'Date invalide'}).first().waitFor();
   assert.equal(await form.locator('[name=object]').evaluate(e=>e.readOnly),false);
   invalidCreate=false;await form.locator('[name=object]').fill('Dôme');await page.getByRole('button',{name:'Créer la demande',exact:true}).click();
  }
  await page.getByRole('status').filter({hasText:'✓ Demande #88 créée'}).waitFor();
  assert.equal(posts,0);assert.equal(declares,0);assert.equal(createCalls[0].apples,'1000');assert.equal(createCalls[0].wood,'2000');assert.equal(createCalls[0].auto_production,true);
  if(mode==='create-retry')assert.deepEqual(createCalls[0],createCalls[1]);
  if(mode==='create-validation')assert.notEqual(createCalls[0].request_key,createCalls[1].request_key);
  assert.equal(await form.locator('[name=apples]').inputValue(),'0');
  if(mode==='auto-cancel'){await page.getByRole('status').filter({hasText:'Envoi annulé'}).first().waitFor();assert.equal(posts,0);assert.equal(declares,0);}
 if(mode.startsWith('auto-'))assert.equal(warnings,1,'one warning before send, never during declaration recovery');
 assert.deepEqual(errors,[]);console.log('Browser:',mode,'OK');await ctx.close();continue;
 }
 // Every saved plain-text fixture is checked separately below. This HTML variant
 // verifies icons, C+ autocomplete markup and outgoing/incoming coexistence.
 const parsed=await page.evaluate(()=>readCommerceConvoys(document,location.href,Date.now()).map(({anchor,...r})=>r));assert.equal(parsed.length,1);assert.equal(parsed[0].game_id,'8102');assert.equal(parsed[0].wood,'1');
 await page.fill('#uptep-convoys-panel-current [name=apples]','1k');await page.fill('#uptep-convoys-panel-current [name=wood]','500');assert.match(await page.locator('.uptep-convoy-workers').innerText(),new RegExp('Ouvrières mobilisées : '+(mode==='etable'?'75':'150')+' · Étable à pucerons niveau '+(mode==='etable'?'20':'0')));
 if(mode.includes('protected-bridge'))await page.evaluate(()=>{window.protectBridge=true;});
 await page.fill('#uptep-convoys-panel-current [name=apples]','10');await page.fill('#uptep-convoys-panel-current [name=wood]','20');
 if(mode==='storage-failure')await page.evaluate(()=>{Storage.prototype.setItem=function(){throw Error('Stockage indisponible');};});
 if(mode.startsWith('auto-')&&mode!=='auto-retry'){
  const send=page.getByRole('button',{name:'Envoyer',exact:true});
  if(mode==='auto-late'){assert.equal(await send.isDisabled(),false);await send.click();await page.getByRole('status').filter({hasText:'Envoi désactivé'}).first().waitFor();}
  else {assert.equal(await send.isDisabled(),true);assert.match(await send.getAttribute('title'),/auto production/);await page.getByRole('button',{name:'Actualiser les demandes',exact:true}).click();assert.equal(await send.isDisabled(),true);}
  assert.equal(posts,0);assert.equal(declares,0);assert.equal(warnings,0);assert.deepEqual(errors,[]);console.log('Browser:',mode,'blocked OK');await ctx.close();continue;
 }
 if(mode==='double-click')await page.evaluate(()=>{const form=document.querySelector('#uptep-convoys-panel-current form');form.requestSubmit();form.requestSubmit();});else await page.getByRole('button',{name:'Envoyer',exact:true}).click();
 if(process.env.UPTEP_CONVOYS_SOURCE&&mode.startsWith('protected-')){await page.getByRole('status').filter({hasText:'Permission denied to access property "then"'}).first().waitFor();assert.equal(posts,mode==='protected-after-send'?1:0);assert.equal(declares,0);if(posts)assert.equal(await page.evaluate(()=>JSON.parse(localStorage.getItem('uptep-convoys-s5:Sender')).state),'uncertain');console.log('Before fix: protected document reproduces Permission denied to access property "then"; no automatic second send.');await ctx.close();continue;}
 if(['success','double-click','compiled','etable','tabs','create-retry','create-validation','auto-send','auto-late','overage-send','auto-overage','protected-document','protected-after-send','protected-bridge','protected-bridge-legacy'].includes(mode)){await page.waitForFunction(()=>!document.getElementById('uptep-convoys')&&!!document.querySelector('a[href*="annuler=9001"]'));assert.equal(await page.evaluate(()=>JSON.parse(localStorage.getItem('uptep-convoys-s5:Sender')).state),'done');assert.equal(posts,1);assert.equal(declares,1);assert.equal(posted.get('nbOuvriere'),mode==='etable'?'2':'3');assert.equal(posted.get('pseudo_convoi'),'Target');assert.equal(posted.get('t'),'new-token');}
 if(mode==='refused'||mode==='ambiguous'){await page.getByRole('button',{name:'Vérifier si le convoi est parti'}).waitFor();assert.equal(posts,1);assert.equal(declares,0);await page.getByRole('button',{name:'Vérifier si le convoi est parti'}).click();await page.getByRole('status').filter({hasText:'Impossible d’identifier'}).waitFor();assert.equal(posts,1);}
 if(['api-failure','auto-retry','overage-retry','protected-bridge-retry'].includes(mode)){await page.getByRole('button',{name:'Réessayer la déclaration UPTEP'}).waitFor();assert.equal(posts,1);failDeclare=false;await page.reload();await mount();await page.getByRole('button',{name:'Réessayer la déclaration UPTEP'}).click();await page.waitForFunction(()=>!document.getElementById('uptep-convoys')&&!!document.querySelector('a[href*="annuler=9001"]'));assert.equal(await page.evaluate(()=>JSON.parse(localStorage.getItem('uptep-convoys-s5:Sender')).state),'done');assert.equal(posts,1);assert.equal(declares,2);}
 if(mode==='lost-response'){await page.getByRole('button',{name:'Vérifier si le convoi est parti'}).waitFor();assert.equal(posts,1);await page.reload();await mount();await page.getByRole('button',{name:'Vérifier si le convoi est parti'}).click();await page.waitForFunction(()=>!document.getElementById('uptep-convoys')&&!!document.querySelector('a[href*="annuler=9001"]'));assert.equal(await page.evaluate(()=>JSON.parse(localStorage.getItem('uptep-convoys-s5:Sender')).state),'done');assert.equal(posts,1);assert.equal(declares,1);}
 if(mode==='storage-failure'){await page.getByRole('status').filter({hasText:'Stockage indisponible'}).waitFor();assert.equal(posts,0);assert.equal(declares,0);}
 if(mode==='wrong-account'||mode==='no-stock'){await page.getByRole('status').filter({hasText:mode==='wrong-account'?'compte connecté':'insuffisantes'}).waitFor();assert.equal(posts,0);assert.equal(declares,0);}
 if(['auto-cancel','overage-cancel'].includes(mode)){await page.getByRole('status').filter({hasText:'Envoi annulé'}).first().waitFor();assert.equal(posts,0);assert.equal(declares,0);assert.equal(await page.locator('#uptep-convoys-panel-current [name=wood]').inputValue(),'20');}
 if(mode.includes('overage'))assert.equal(warnings,mode==='auto-overage'?2:1,'warnings are not repeated during recovery');
 assert.deepEqual(errors,[]);console.log('Browser:',mode,'OK');await ctx.close();
}
// Structural variants and all stored report strings, including compact C+ copies.
const ctx=await browser.newContext();const page=await ctx.newPage();await page.goto('about:blank');await page.evaluate(code=>(0,eval)(code),moduleCode);
const reports=[...new Set([...fs.readFileSync(require('path').resolve(root,'../discord-bot/test/bot.test.js'),'utf8').matchAll(/'([^'\n]*Vous allez livrer[^'\n]*)'/g),...fs.readFileSync(require('path').resolve(root,'../discord-bot/test/convoy-batch.test.js'),'utf8').matchAll(/'([^'\n]*Vous allez livrer[^'\n]*)'/g)].map(m=>m[1]))];
const result=await page.evaluate(({fixture,reports})=>{
 const parse=html=>readCommerceConvoys(new DOMParser().parseFromString(html,'text/html'),'https://s5.fourmizzz.fr/commerce.php',Date.now());
 for(const html of [fixture,fixture.replace(/<strong>/g,'<span>').replace(/<\/strong>/g,'</span>'),fixture.replace(/<img[^>]+alt="nourritures"[^>]*>/g,' nourritures ').replace(/<img[^>]+alt="materiaux"[^>]*>/g,' matériaux '),fixture.replace(/class="ui-autocomplete-input"/g,'')]){if(parse(html).length!==1)throw Error('HTML variant');}
 for(const tag of ['div','p','tr']){const row='<'+tag+'>Vous allez livrer 40 000 nourritures et 0 matériaux à Justixon dans 6H 55m 57s - Retour le 11 oct. à 03h33 - <a href="commerce.php?annuler=12">Annuler</a></'+tag+'>';if(parse('<div id="centre">'+(tag==='tr'?'<table>'+row+'</table>':row)+'</div>').length!==1)throw Error('block '+tag);}
 if(parse('<div id="centre">Vous allez recevoir 0 et 100 de Target dans 1h <a href="commerce.php?annuler=1">Annuler</a></div>').length)throw Error('incoming');
 if(parse('<div id="centre">Vous allez livrer 0 et 100 à Target dans 1h</div>').length)throw Error('missing ID');
 if(convoyRequiredWorkers('10','0',0)!==1n||convoyRequiredWorkers('11','0',0)!==2n||convoyRequiredWorkers('31','0',20)!==2n||convoyRequiredWorkers('0','0',20)!==0n)throw Error('workers rounding');
 if(convoyInputNumber('1,5k')!=='1500')throw Error('quantity');let checked=0;
 for(const raw of reports){if(/\\|\$|bientôt|Vous allez livrer et|100 matériaux et 200 nourritures|0 et 0/.test(raw))continue;const clean=raw.replaceAll('**','');if(!/Vous allez livrer \d[\d ]*(?: nourritures?)?\s+et\s+\d/.test(clean))continue;
 const node=document.createElement('div');node.id='centre';node.textContent=clean;const a=document.createElement('a');a.href='https://s5.fourmizzz.fr/commerce.php?annuler=9';a.textContent='Annuler';node.append(a);const out=parse(node.outerHTML);if(out.length!==1)throw Error('stored copy: '+raw);checked++;}
 return checked;
},{fixture,reports});console.log('HTML variants and',result,'saved copy/paste cases OK');await ctx.close();
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1});
