const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const puppeteer=require(process.env.PUPPETEER_MODULE||'/root/maudits-lighthouse/node_modules/puppeteer-core');
const root=path.resolve(__dirname,'..'),source=['chaine','multiflood'].map(n=>fs.readFileSync(root+'/userscript/src/'+n+'.js','utf8')).join('\n');
const rows=[['1','Self','10721'],['2','Khan','8711'],['3','Morel','20000'],['4','Holiday','8000'],['5','Low','100']];
const data={self:'1',attack_speed:5,players:rows.map(([id,pseudo],i)=>({player_id:id,pseudo,x:i*10,y:0,grade:'Passeur',sort_order:5-i,on_holiday:i===3}))};
const members=()=>'<table id="members"><tr><th></th><th>Rang</th><th>Pseudo</th><th colspan="2">Terrain</th><th>Technologie</th><th>Fourmilière</th><th colspan="2">État</th></tr>'+rows.map(([id,pseudo,tdc],i)=>`<tr data-id="${id}"><td>${i+1}</td><td>Rang</td><td><a href="Membre.php?Pseudo=${pseudo}">${pseudo}</a></td><td>${tdc}</td><td></td><td>20</td><td>40</td><td><img alt="${pseudo==='Holiday'?'En vacances':'Actif'}"></td><td></td></tr>`).join('')+'</table>';
const html=body=>`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>#formulaireFlood button{width:180px}body{margin:0;background:#3f4835;font:14px Verdana}#centre{box-sizing:border-box;background:#ccb36f;margin:0 240px;padding:45px 10px;min-height:900px}#menu{background:#4f5940;color:white;padding:10px;position:relative}#pseudo,#tag_alliance{display:none}@media(max-width:900px){#centre{margin:0;padding:10px}}</style></head><body><div id="pseudo">Self</div><div id="tag_alliance">UPTEP</div><main id="centre"><nav id="menu">Fourmilière　Alliance　Communauté　Compte +</nav>${body}</main></body></html>`;
let army='50000',slots=10,sent=[],reject=false,partial=false,delay=false,termAllowed=true;
const native=()=>html('<form id="formulaireFlood">'+Object.entries({joueur0:'Self',joueur0ID:'1',joueur0TDC:rows[0][2],armee:army,joueur0attaquesDispo:slots,joueur0VA:5,t:'fixture-only-not-a-real-token'}).map(([id,value])=>`<input id="${id}" value="${value}">`).join('')+'</form>');
(async()=>{
 const browser=await puppeteer.launch({executablePath:process.env.CHROMIUM||'/usr/bin/chromium',headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
 try{
  const page=await browser.newPage();await page.setViewport({width:1600,height:1000});const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.setRequestInterception(true);
  page.on('request',async r=>{
   const url=new URL(r.url());let body='',type='text/html',status=200;
   if(url.pathname==='/simulateurFlood.php')body=native();
   else if(url.pathname==='/appelAjaxFlood.php'&&url.searchParams.has('codeFlood')){
    const values=url.searchParams.get('codeFlood').split('|').filter(Boolean);sent.push(values);
    assert.equal(url.searchParams.get('t'),'fixture-only-not-a-real-token');
    if(delay)await new Promise(resolve=>setTimeout(resolve,350));
    if(reject){status=503;body='error';}else if(!partial){for(let i=1;i<values.length;i+=2)army=String(BigInt(army)-BigInt(values[i]));slots-=values.length/2;}
   }else if(url.pathname==='/appelAjaxFlood.php'){
    type='application/json';body=JSON.stringify(rows.filter(p=>p[1]===url.searchParams.get('term')).map(([id,value,terrain])=>({id,value,terrain,type:termAllowed?'type1':'type2'})));
   }else body=html(members());
   await r.respond({status,contentType:type+'; charset=utf-8',body});
  });
  const mount=async(chain=false)=>{await page.addScriptTag({content:source});await page.evaluate(({data,chain})=>{window.cleanupChain=chain?mountChaine({document,window,pseudo:'Self'},data):()=>{};window.cleanupMF=mountMultiflood({document,window,pseudo:'Self'},data);}, {data,chain});};
  await page.goto('https://s5.fourmizzz.fr/alliance.php?Membres');await mount(true);
  assert.equal((await page.$$('.uptep-mf-select')).length,5);assert.equal((await page.$$('.uptep-mf-select:not(:disabled)')).length,2);
  await page.click('[data-id="2"] .uptep-mf-select');await page.click('[data-uptep-sort=terrain]');
  await page.waitForFunction(()=>document.querySelector('[data-id="2"] .uptep-mf-select')?.checked);
  assert.equal((await page.$$('.uptep-mf-cell')).length,12);
  await page.click('[data-id="3"] .uptep-mf-select');
  await Promise.all([page.waitForNavigation(),page.click('.uptep-mf-tools button')]);await mount();
  await page.waitForFunction(()=>!document.querySelector('#uptep-multiflood fieldset').disabled);
  assert.equal(await page.evaluate(()=>JSON.parse(sessionStorage.getItem('uptep:multiflood:Self:draft')).targets.length),2);
  assert.equal((await page.$$('#resultatFlood section')).length,2);
  assert.equal(await page.$eval('#joueur0TDC',n=>n.value),'10721');
  assert.ok(await page.$eval('#formulaireFlood button',n=>n.getBoundingClientRect().width<50),'Native form CSS does not stretch remove buttons');
  assert.equal((await page.$$('#resultatFlood input:checked')).length,7);
  await page.click('#uptep-multiflood button[aria-expanded]');
  assert.equal(await page.$eval('#joueur0max',n=>n.closest('tr').hidden),false);
  await page.$eval('#joueur0max',n=>{n.value='150';n.dispatchEvent(new Event('input',{bubbles:true}));});
  assert.match(await page.$eval('.uptep-mf-summary',n=>n.textContent),/150 fourmis/);
  await page.$eval('#joueur0max',n=>{n.value='';n.dispatchEvent(new Event('input',{bubbles:true}));});
  await page.click('#uptep-multiflood button[aria-expanded]');
  await page.click('[data-attack="2:0"]');
  assert.equal(await page.$eval('[data-attack="2:1"]',n=>n.closest('tr').cells[1].textContent),'10\u202f721','Unchecked attack no longer changes later terrain');
  await page.screenshot({path:'/tmp/uptep-multiflood-desktop.png',fullPage:true});
  await page.setViewport({width:390,height:844});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);await page.screenshot({path:'/tmp/uptep-multiflood-mobile.png',fullPage:true});
  await page.setViewport({width:1600,height:1000});
  // A data refresh remount preserves the draft and checkbox choices.
  await page.evaluate(()=>cleanupMF());await mount();await page.waitForFunction(()=>!document.querySelector('#uptep-multiflood fieldset').disabled);
  assert.equal(await page.$eval('[data-attack="2:0"]',n=>n.checked),false);
  assert(!await page.evaluate(()=>sessionStorage.getItem('uptep:multiflood:Self:draft').includes('fixture-only')),'No native token saved');
  const launch=()=>page.$eval('#uptep-multiflood>button',n=>n.click());
  // Changed real availability requires reviewing the new calculation.
  army='10000';await launch();await page.waitForFunction(()=>document.querySelector('.uptep-mf-status').textContent.includes('ont changé'));assert.equal(sent.length,0);
  termAllowed=false;await launch();await page.waitForFunction(()=>document.querySelector('.uptep-mf-status').textContent.includes('ne permet pas'));assert.equal(sent.length,0);termAllowed=true;
  delay=true;await launch();await launch();await page.waitForFunction(()=>document.querySelector('.uptep-mf-status').textContent.includes('enregistrée'));
  assert.equal(sent.length,1,'Double click sends one batch');assert.equal(sent[0][0],'2','Nearest target first');
  await page.evaluate(()=>cleanupMF());await mount();assert.equal(await page.$eval('#uptep-multiflood>button',n=>n.disabled),true,'Remount never resubmits');assert.equal(sent.length,1);
  // The next preparation explicitly resets submission state, reads new data, and
  // permanently blocks automatic retries if a send result is uncertain.
  await page.evaluate(()=>sessionStorage.setItem('uptep:multiflood:Self:submission','null'));
  army='50000';slots=10;partial=true;await page.evaluate(()=>cleanupMF());await mount();await page.waitForFunction(()=>!document.querySelector('#uptep-multiflood fieldset').disabled);await launch();
  await page.waitForFunction(()=>document.querySelector('.uptep-mf-status').textContent.includes('Aucun renvoi automatique'));
  assert.equal(await page.$eval('#uptep-multiflood>button',n=>n.disabled),true);assert.equal(await page.evaluate(()=>JSON.parse(sessionStorage.getItem('uptep:multiflood:Self:submission')).state),'uncertain');
  partial=false;reject=true;
  await page.evaluate(()=>{cleanupMF();sessionStorage.setItem('uptep:multiflood:Self:submission','null');});await mount();await page.waitForFunction(()=>!document.querySelector('#uptep-multiflood fieldset').disabled);await launch();
  await page.waitForFunction(()=>document.querySelector('.uptep-mf-status').textContent.includes('Aucun renvoi automatique'));
  assert.equal(await page.evaluate(()=>JSON.parse(sessionStorage.getItem('uptep:multiflood:Self:submission')).state),'uncertain','HTTP errors never permit automatic retry');reject=false;
  await page.evaluate(()=>cleanupMF());assert.equal((await page.$$('#uptep-multiflood')).length,0);assert.equal(await page.$eval('#members',n=>n.style.display),'','Cleanup restores native page');
  // Direct per-player entry uses exactly the same preparation page.
  await page.goto('https://s5.fourmizzz.fr/alliance.php?Membres');await mount(true);
  await Promise.all([page.waitForNavigation(),page.click('[data-id="2"] .uptep-mf-launch')]);
  assert.equal(new URL(page.url()).searchParams.get('uptepMultiflood'),'1');assert.equal(await page.evaluate(()=>JSON.parse(sessionStorage.getItem('uptep:multiflood:Self:draft')).targets.length),1);
  await page.goto('https://s5.fourmizzz.fr/alliance.php?Membres');
  const runtime=fs.readFileSync(root+'/app/script-runtime.min.js','utf8');
  await page.evaluate(({runtime,data})=>{
   const run=new Function('context','const uptepPreferences={traffic:false,clock:false,chaine:true,laboratory:false};const uptepChaineData='+JSON.stringify(data)+';'+runtime);
   window.runtimeStop=run({document,window,pseudo:'Self'});
  },{runtime,data});
  assert.equal((await page.$$('.uptep-mf-select')).length,5,'Compiled runtime mounts member controls');
  await page.evaluate(()=>runtimeStop());assert.equal((await page.$$('.uptep-mf-cell')).length,0,'Compiled cleanup removes controls');
  assert.deepEqual(errors,[]);console.log('OK: member selection, chain sorting, direct/multi entry, live fields, deselection, persisted draft, mobile, fresh preflight, native permissions, one-shot batch send, server verification, uncertain result lock and cleanup.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
