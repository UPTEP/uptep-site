const {chromium}=require(process.env.UPTEP_PLAYWRIGHT_MODULE||'playwright');const fs=require('fs'),path=require('path'),assert=require('assert/strict');
const root=path.resolve(__dirname,'..'),parser=fs.readFileSync(path.resolve(root,'../discord-bot/src/flood-parser.js'),'utf8').replace(/^import .*;\n/,'').replace('export function parseFloods','function parseFloods').replaceAll('UserError','Error');
const code='const parseArmyFloodReport=(()=>{'+parser+';return parseFloods;})();\n'+fs.readFileSync(root+'/userscript/src/floods.js','utf8');
const fixture=fs.readFileSync(root+'/tests/army-floods.html','utf8');
(async()=>{const browser=await chromium.launch({headless:true,args:['--no-sandbox']});try{
 for(const mode of ['new','existing','ambiguous','lost','check-failure','warning','double-click','wrong-account']){
  const ctx=await browser.newContext();let checks=0,writes=0,saved=false,failCheck=mode==='check-failure';const errors=[];
  await ctx.route('**/*',async route=>{const req=route.request(),url=new URL(req.url());
   if(url.hostname==='s5.fourmizzz.fr'){assert.equal(req.method(),'GET','never POST to game');return route.fulfill({contentType:'text/html',body:'<div id="pseudo">Sender</div><div id="tag_alliance">UPTEP</div><div id="centre">'+(mode.startsWith('warning')?fixture.replaceAll(' (Terrain de chasse)',''):fixture).replaceAll('<script language="JavaScript">','<script type="application/json">')+'</div>'});}
   if(url.pathname==='/portal.php')return route.fulfill({contentType:'text/html',body:fs.readFileSync(root+'/public/portal.php','utf8').replace(/<\?php[\s\S]*?\?>/g,'')});
   if(url.pathname==='/assets/portal.js')return route.fulfill({contentType:'application/javascript',body:fs.readFileSync(root+'/public/assets/portal.js','utf8')});
   if(url.pathname==='/portal-api.php'){
    const b=req.postDataJSON();assert.equal(req.headers().authorization,'Bearer '+'a'.repeat(64));assert.equal(b.pseudo,'Sender');
    if(b.action==='flood-check'){checks++;if(failCheck){failCheck=false;return route.fulfill({status:503,json:{ok:false,error:'Indisponible'}});}return route.fulfill({json:{ok:true,floods:b.floods.map(f=>({game_id:f.game_id,state:saved?'declared':mode==='existing'?'existing':mode==='ambiguous'?'ambiguous':'new',id:'42'}))}});}
    assert.equal(b.action,'flood-declare');assert.equal(b.confirmed,true);assert.equal(b.floods[0].game_id,'33359');assert.equal(b.floods[0].pseudo,'SSovietsky');assert.match(b.floods[0].troops,/2 204/);writes++;saved=true;
    if(mode==='lost')return route.fulfill({status:503,json:{ok:false,error:'Réponse perdue'}});
    return route.fulfill({json:{ok:true,state:'declared',id:'42'}});
   }
   return route.fulfill({body:''});
  });
  const page=await ctx.newPage();page.on('pageerror',e=>errors.push(e.message));page.on('dialog',d=>{errors.push('Unexpected popup');d.dismiss();});await page.goto('https://s5.fourmizzz.fr/Armee.php');
  await page.evaluate(code=>{(0,eval)(code);window.cleanup=mountFloodDeclarations({document,window,pseudo:'Sender'},{token:'a'.repeat(64)},'2026-10-16T13:13:22Z');},code);
  const widgets=page.locator('[data-uptep-flood-ui]');assert.equal(await widgets.count(),2);
  if(['existing','ambiguous'].includes(mode)){await widgets.first().getByRole('status').filter({hasText:mode==='existing'?'Déjà déclaré':'À vérifier'}).waitFor();assert.equal(await widgets.first().getByRole('button').isVisible(),false);assert.equal(writes,0);}
  else{
   if(mode==='check-failure'){await widgets.first().getByRole('button',{name:'Vérifier / réessayer'}).click();await widgets.first().getByRole('button',{name:'Déclarer le flood'}).waitFor();assert.equal(writes,0);}
   await widgets.first().getByRole('button',{name:'Déclarer le flood'}).waitFor();
   if(mode==='wrong-account')await page.locator('#pseudo').evaluate(n=>n.textContent='Other');
   if(mode==='double-click')await widgets.first().getByRole('button').evaluate(b=>{b.click();b.click();});else await widgets.first().getByRole('button').click();
   if(mode==='lost'){await widgets.first().getByRole('button',{name:'Réessayer',exact:true}).waitFor();await widgets.first().getByRole('button').click();}
   if(mode==='warning-reject'){await widgets.first().getByRole('button').waitFor({state:'visible'});assert.equal(writes,0);}
   else if(mode==='wrong-account'){await widgets.first().getByRole('status').filter({hasText:'compte connecté'}).waitFor();assert.equal(writes,0);}
   else{await widgets.first().getByRole('status').filter({hasText:'Déjà déclaré'}).waitFor();assert.equal(writes,1);}
  }
  assert.deepEqual(errors,[]);await page.evaluate(()=>cleanup());assert.equal(await widgets.count(),0);await ctx.close();console.log('Flood UI',mode,'OK');
 }
 const ctx=await browser.newContext(),page=await ctx.newPage();await page.goto('about:blank');await page.evaluate(code=>(0,eval)(code),code);
 await page.evaluate(fixture=>{
  const parse=html=>readArmyFloods(new DOMParser().parseFromString(html,'text/html'),Date.parse('2026-10-16T13:13:22Z'));
  for(const html of [fixture,fixture.replaceAll('span','strong'),fixture.replace(/<small>|<\/small>|<em>|<\/em>/g,'')]){const r=parse(html);if(r.length!==2||r.some(e=>e.error)||r[0].flood.troops===r[1].flood.troops)throw Error('HTML variants');}
  for(const loc of ['Terrain de chasse','Dôme (12)','Loge Impériale (3)',null]){
   const h='<div>Vous allez attaquer <a href="Membre.php?Pseudo=Hardware">Hardware</a>(UPTEP) '+(loc?'('+loc+') ':'')+'dans <span id="attaque_10">1H2m3s</span><br></div>';const r=parse(h);if(r.length!==1||r[0].error)throw Error('Location '+loc);
  }
  if(parse(fixture.replaceAll('Vous allez attaquer','Vous allez être attaqué par')).length)throw Error('Incoming parsed');
  if(!parse(fixture.replaceAll('id="attaque_', 'id="unknown_')).every(e=>e.error))throw Error('No stable ID');
 },fixture);await ctx.close();console.log('Army HTML variants OK');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1});
