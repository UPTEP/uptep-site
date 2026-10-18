const {chromium}=require(process.env.UPTEP_PLAYWRIGHT_MODULE||'playwright');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..');
const code=['chaine.js','convoy-management.js','convoys.js'].map(n=>fs.readFileSync(root+'/userscript/src/'+n,'utf8')).join('\n');
const fixture=fs.readFileSync(root+'/tests/fixtures/commerce.html','utf8').replace(/<script type="text\/javascript">/g,'<script type="application/json">');
(async()=>{const browser=await chromium.launch({headless:true,args:['--no-sandbox']});try{
 for(const mode of ['member','chain','lost-response','stale','revoked','storage-failure','current-member','current-chain','current-other']){
  const ctx=await browser.newContext();let chain=!['member','current-member','current-other'].includes(mode),listCalls=[],writes=[],commits=0,gamePosts=0,failed=false;const receipts=new Map(),errors=[];
  const d={id:'7',serveur:'s5',iddemandeur:['member','current-member'].includes(mode)?'2':'1',pseudo_demandeur:['member','current-member'].includes(mode)?'Sender':'Target',objet:'Dôme',niveau:12,date_demande:'2026-10-18T10:05:00Z',date_souhaitee:'2026-12-01',nbpomme_original:'1000',nbmateriaux_original:'2000',nbpomme_restant:'1000',nbmateriaux_restant:'2000',auto_production:mode==='member',valide:mode.startsWith('current-'),fini:false,annulee:false,fin_manuelle:false,version_modification:'a'.repeat(64),ajustements:[]};
  const permissions=()=>({is_chaine:chain,player_id:'2'});
  await ctx.route('**/*',async route=>{const req=route.request(),url=new URL(req.url());
   if(url.hostname==='s5.fourmizzz.fr'){if(req.method()==='POST')gamePosts++;return route.fulfill({contentType:'text/html',body:fixture});}
   if(url.pathname==='/portal.php')return route.fulfill({contentType:'text/html',body:fs.readFileSync(root+'/public/portal.php','utf8').replace(/<\?php[\s\S]*?\?>/g,'')});
   if(url.pathname==='/assets/portal.js')return route.fulfill({contentType:'application/javascript',body:fs.readFileSync(root+'/public/assets/portal.js','utf8')});
   if(url.pathname==='/portal-api.php'){
    const b=req.postDataJSON();assert.equal(req.headers().authorization,'Bearer '+'a'.repeat(64));assert.equal(b.pseudo,'Sender');
    const reply=json=>route.fulfill({json:{ok:true,...json}});
    if(b.action==='convoy-demands')return reply({demands:mode.startsWith('current-')?[{...d,target:d.pseudo_demandeur,self:d.iddemandeur==='2'}]:[],permissions:permissions()});
    if(b.action==='convoy-pending')return reply({demands:d.valide?[]:[d],permissions:permissions()});
    if(b.action==='convoy-list'){listCalls.push(b);return reply({demands:[d],players:[{player_id:'2',pseudo:'Sender'},{player_id:'1',pseudo:'Target'}],next:b.after==='0'?'7':null,permissions:permissions()});}
    if(b.action==='convoy-detail')return reply({demand:d,convoys:[{id:'42',idenvoyeur:'2',pseudo_envoyeur:'Sender',nbpomme:'10',nbmateriaux:'20',date_convoi:'2026-10-17T10:00:00Z',date_arrivee:'2026-10-17T11:00:00Z',annule:false}],next:null,permissions:permissions()});
    assert.equal(b.action,'convoy-manage');writes.push(b);assert.equal(b.confirmed,true);assert.match(b.request_key,/^[a-f0-9-]{36}$/);
    if(mode==='revoked'){chain=false;return route.fulfill({status:403,json:{ok:false,error:'Le droit chaîne est nécessaire.'}});}
    if(mode==='stale')return route.fulfill({status:409,json:{ok:false,error:'Le restant a changé. Rouvre le formulaire.'}});
    if(!receipts.has(b.request_key)){
     commits++;receipts.set(b.request_key,b);
     if(b.operation==='validation'){assert.equal(b.version,d.version_modification);d.valide=true;}
     if(b.operation==='modification'){assert.equal(b.version,d.version_modification);d.auto_production=b.auto_production;d.objet=b.object;d.nbpomme_original=b.apples;d.nbmateriaux_original=b.wood;d.nbpomme_restant=b.apples;d.nbmateriaux_restant=b.wood;}
     if(b.operation==='ajustement'){assert.equal(b.expected_apples,d.nbpomme_restant);assert.equal(b.expected_wood,d.nbmateriaux_restant);d.nbpomme_restant=b.apples;d.nbmateriaux_restant=b.wood;}
     if(b.operation==='suspension')d.valide=false;
     if(b.operation==='terminaison'){d.fini=true;d.fin_manuelle=true;}
     if(b.operation==='annulation')d.annulee=true;
    }
    if(mode==='lost-response'&&!failed){failed=true;return route.fulfill({status:503,json:{ok:false,error:'Réponse perdue'}});}
    return reply({demande:d});
   }
   return route.fulfill({body:''});
  });
  const page=await ctx.newPage();page.on('pageerror',e=>errors.push(e.message));await page.goto('https://s5.fourmizzz.fr/commerce.php');
  const mount=()=>page.evaluate(code=>{window.cleanup?.();(0,eval)(code+'\nwindow.cleanup=mountConvoys({document,window,pseudo:"Sender"},{token:"'+ 'a'.repeat(64)+'"},new Date().toISOString());');},code);
  await mount();const box=page.locator('#uptep-convoys'),editor=box.getByRole('region',{name:'Gestion de la demande'});
  if(mode.startsWith('current-')){
   const current=box.locator('#uptep-convoys-panel-current');await current.getByRole('button',{name:'Consulter / Gérer',exact:true}).click();assert.equal(await current.getByRole('button',{name:'Valider',exact:true}).count(),0);await editor.getByText('Demandé',{exact:true}).waitFor();
   if(mode==='current-other')assert.equal(await editor.getByRole('button',{name:'Modifier',exact:true}).count(),0);
   else {await editor.getByRole('button',{name:'Modifier',exact:true}).click();await editor.getByLabel('Pommes encore nécessaires',{exact:true}).fill('50');await editor.getByRole('button',{name:'Vérifier les modifications'}).click();await editor.getByRole('button',{name:'Confirmer',exact:true}).click();await page.waitForFunction(()=>!localStorage.getItem('uptep-convoys-s5:Sender:management')&&document.querySelector('#uptep-convoys')?.getAttribute('aria-busy')!=='true');assert.equal(writes.at(-1).operation,'ajustement');assert.match(await current.innerText(),/50 pommes/);assert.equal(await box.getByRole('tab',{name:'Demandes en cours',exact:true}).getAttribute('aria-selected'),'true');}
   assert.equal(gamePosts,0);assert.deepEqual(errors,[]);await page.evaluate(()=>window.cleanup());await ctx.close();console.log('Management browser '+mode+' OK');continue;
  }
  await box.getByRole('tab',{name:'Mes demandes',exact:true}).click();await box.getByRole('button',{name:'Consulter',exact:true}).first().waitFor();
  assert.equal(await box.getByRole('tab',{name:'Déclarer un convoi',exact:true}).count(),0);
  assert.equal(await box.getByRole('tab',{name:'Gestion chaîne',exact:true}).count(),0);
  if(mode==='member'){
   assert.equal(await box.getByRole('button',{name:'Valider',exact:true}).count(),0);
   await box.getByRole('button',{name:'Modifier',exact:true}).first().click();assert(await editor.getByRole('checkbox',{name:'Auto production',exact:true}).isChecked());await editor.getByRole('checkbox',{name:'Auto production',exact:true}).uncheck();await editor.getByLabel('Pommes demandées',{exact:true}).fill('1,5k');await editor.getByLabel('Construction ou recherche',{exact:true}).fill('Loge');await editor.getByRole('button',{name:'Vérifier les modifications'}).click();assert.equal(writes.length,0);await editor.getByRole('button',{name:'Confirmer',exact:true}).click();await page.waitForFunction(()=>!localStorage.getItem('uptep-convoys-s5:Sender:management')&&document.querySelector('#uptep-convoys')?.getAttribute('aria-busy')!=='true');assert.equal(writes[0].auto_production,false);assert.equal(writes[0].apples,'1500');assert.equal(writes[0].object,'Loge');
   await box.getByRole('button',{name:'Consulter',exact:true}).first().click();await editor.getByRole('button',{name:'Annuler la déclaration',exact:true}).click();await editor.getByRole('button',{name:'Confirmer',exact:true}).click();await page.waitForFunction(()=>!localStorage.getItem('uptep-convoys-s5:Sender:management')&&document.querySelector('#uptep-convoys')?.getAttribute('aria-busy')!=='true');assert.equal(writes.at(-1).operation,'annulation_convoi');assert.equal(writes.at(-1).convoy_id,'42');
  }else{
   await box.getByRole('tab',{name:'Consulter / Historique',exact:true}).click();await box.getByRole('button',{name:'Valider',exact:true}).first().waitFor();
   if(mode==='chain'){const history=box.locator('#uptep-convoys-panel-history');await history.getByRole('combobox',{name:'Joueur de l’alliance'}).selectOption('1');await page.waitForFunction(()=>document.querySelector('#uptep-convoys-panel-history button:last-child')?.disabled===false);await history.getByRole('combobox',{name:'État des demandes'}).selectOption('attente');await history.getByRole('button',{name:'Voir plus de demandes',exact:true}).click();await history.getByRole('button',{name:'Voir plus de demandes',exact:true}).waitFor({state:'hidden'});assert(listCalls.some(b=>b.player_id==='1'&&b.state==='attente'&&b.after==='7'));}
   await box.getByRole('button',{name:'Valider',exact:true}).first().click();assert.equal(writes.length,0);
   if(mode==='storage-failure')await page.evaluate(()=>{Storage.prototype.setItem=function(){throw Error('Stockage indisponible');};});
   await editor.getByRole('button',{name:'Confirmer',exact:true}).click();
   if(mode==='lost-response'){
    await box.getByText('Réponse perdue',{exact:true}).first().waitFor();assert.equal(commits,1);await mount();await box.getByRole('button',{name:'Réessayer la même opération'}).click();await page.waitForFunction(()=>!localStorage.getItem('uptep-convoys-s5:Sender:management')&&document.querySelector('#uptep-convoys')?.getAttribute('aria-busy')!=='true');assert.equal(writes.length,2);assert.equal(writes[0].request_key,writes[1].request_key);assert.equal(commits,1);
   }else if(mode==='stale'||mode==='revoked'){
    await box.getByText(mode==='stale'?'Le restant a changé. Rouvre le formulaire.':'Le droit chaîne est nécessaire.',{exact:true}).first().waitFor();assert.equal(commits,0);assert.equal(await page.evaluate(()=>localStorage.getItem('uptep-convoys-s5:Sender:management')),null);
   }else if(mode==='storage-failure'){await box.getByText('Stockage indisponible',{exact:true}).first().waitFor();assert.equal(writes.length,0);}
   else{
    await page.waitForFunction(()=>!localStorage.getItem('uptep-convoys-s5:Sender:management')&&document.querySelector('#uptep-convoys')?.getAttribute('aria-busy')!=='true');await box.getByRole('button',{name:'Modifier',exact:true}).first().click();await editor.getByLabel('Pommes encore nécessaires',{exact:true}).fill('50');await editor.getByRole('button',{name:'Vérifier les modifications'}).click();await editor.getByRole('button',{name:'Confirmer',exact:true}).click();await page.waitForFunction(()=>!localStorage.getItem('uptep-convoys-s5:Sender:management')&&document.querySelector('#uptep-convoys')?.getAttribute('aria-busy')!=='true');assert.equal(writes.at(-1).operation,'ajustement');assert.equal(writes.at(-1).expected_apples,'1000');
    await box.getByRole('button',{name:'Suspendre',exact:true}).first().click();await editor.getByRole('button',{name:'Confirmer',exact:true}).click();await page.waitForFunction(()=>!localStorage.getItem('uptep-convoys-s5:Sender:management')&&document.querySelector('#uptep-convoys')?.getAttribute('aria-busy')!=='true');assert.equal(writes.at(-1).operation,'suspension');assert.equal(d.nbpomme_restant,'50');
    await box.getByRole('tab',{name:'En attente de validation',exact:true}).click();await box.getByRole('button',{name:'Consulter / Gérer',exact:true}).waitFor();
    const pending=box.locator('#uptep-convoys-panel-pending');assert.match(await pending.locator('time.uptep-pending-created').innerText(),/18\/10\/2026 à 12:05 \(Paris\)/);assert(await pending.locator('article').first().evaluate(n=>n.getBoundingClientRect().height)<150);
    await box.getByRole('button',{name:'Consulter / Gérer',exact:true}).click();const modal=box.getByRole('dialog');await modal.getByText('Demandé',{exact:true}).waitFor();assert.equal(await modal.evaluate(n=>n.matches(':modal')),true);assert.equal(await modal.locator('.uptep-detail-card').count(),2);
    await page.screenshot({path:'/tmp/uptep-convoy-modal-desktop.png',fullPage:false});await page.keyboard.press('Escape');assert.equal(await modal.isVisible(),false);assert.equal(await box.getByRole('button',{name:'Consulter / Gérer',exact:true}).evaluate(n=>n===document.activeElement),true);
    await box.getByRole('button',{name:'Consulter / Gérer',exact:true}).click();await modal.getByText('Demandé',{exact:true}).waitFor();await modal.getByRole('button',{name:'Fermer la fenêtre',exact:true}).click();assert.equal(await modal.isVisible(),false);
    await box.getByRole('button',{name:'Consulter / Gérer',exact:true}).click();await modal.getByText('Demandé',{exact:true}).waitFor();await page.mouse.click(1,1);assert.equal(await modal.isVisible(),false);
    await page.setViewportSize({width:390,height:844});await box.getByRole('button',{name:'Consulter / Gérer',exact:true}).click();await modal.getByText('Demandé',{exact:true}).waitFor();assert.equal(await modal.evaluate(n=>n.scrollWidth>n.clientWidth),false);await page.screenshot({path:'/tmp/uptep-convoy-modal-mobile.png',fullPage:false});await page.keyboard.press('Escape');
    await pending.screenshot({path:'/tmp/uptep-convoy-pending-compact.png'});
   }
  }
  assert.equal(gamePosts,0,'management never sends game resources');assert.deepEqual(errors,[]);await page.evaluate(()=>window.cleanup());assert.equal(await box.count(),0);await ctx.close();console.log('Management browser '+mode+' OK');
 }
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
