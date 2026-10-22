// Text parser is bundled directly from discord-bot/src/flood-parser.js.
function readArmyFloods(doc,at){
 const root=doc.getElementById('centre')||doc.body;let text='';const timers=[],links=[];
 function walk(n){
  if(n.nodeType===3){text+=n.nodeValue;return;}
  if(n.nodeType!==1||n.matches('script,style,noscript,form,[data-uptep-flood-ui],#uptep-army-sync'))return;
  if(n.tagName==='BR'){text+='\n';return;}
  const start=text.length;for(const child of n.childNodes)walk(child);
  if(/^attaque_[1-9][0-9]*$/.test(n.id))timers.push({node:n,start,end:text.length,game_id:n.id.slice(8)});
  if(n.tagName==='A'){try{const u=new URL(n.getAttribute('href'),doc.location?.href||'https://s5.fourmizzz.fr/Armee.php');if(u.origin==='https://s5.fourmizzz.fr'&&u.pathname==='/Membre.php'&&u.searchParams.has('Pseudo'))links.push({node:n,start,pseudo:u.searchParams.get('Pseudo')});}catch{}}
  if(/^(DIV|P|TR|H[1-6]|CENTER|SMALL)$/.test(n.tagName))text+='\n';
 }
 walk(root);const starts=[...text.matchAll(/Vous allez attaquer\s+/gi)],out=[];
 for(let i=0;i<starts.length;i++){
  const start=starts[i].index,end=starts[i+1]?.index??text.length,found=timers.filter(t=>t.start>=start&&t.end<=end);
  if(found.length!==1){const target=links.find(l=>l.start>=start&&l.start<end);if(target)out.push({node:target.node,error:'Identifiant unique de l’attaque introuvable.'});continue;}const timer=found[0],block=text.slice(start,end).trim();
  try{const parsed=parseArmyFloodReport(block,new Date(at)),f=parsed.floods[0],targets=links.filter(l=>l.start>=start&&l.start<timer.start);
   if(parsed.floods.length!==1||targets.length!==1||targets[0].pseudo.normalize('NFC')!==f.pseudo.normalize('NFC'))throw Error('Cible non reconnue.');
   out.push({node:timer.node,game_id:timer.game_id,...parsed,floods:undefined,flood:f});
  }catch(e){out.push({node:timer.node,game_id:timer.game_id,error:e.message});}
 }
 return out;
}
// Flood actions through the UPTEP portal (hidden iframe, token sent by postMessage), one iframe per call.
// Shared by the Armée page declarations and the Multiflood tool.
function uptepFloodBridge(context,auth){
 const {document:doc,window:win}=context;const jobs=new Set();
 function call(payload){return new Promise((resolve,reject)=>{
  const frame=doc.createElement('iframe');frame.hidden=true;frame.src='https://uptep.top/portal.php?bridge=convoys';frame.title='Déclarations des floods UPTEP';frame.referrerPolicy='no-referrer';const requestId=win.crypto.randomUUID();let timer;
  const cancel=()=>finish(Error('Module rechargé.'));function finish(error,result){win.clearTimeout(timer);win.removeEventListener('message',receive);frame.remove();jobs.delete(cancel);error?reject(error):resolve(result);}
  function receive(event){if(event.origin!=='https://uptep.top'||event.source!==frame.contentWindow)return;if(event.data?.type==='uptep-ready')frame.contentWindow.postMessage({type:'uptep-auth',token:auth.token,pseudo:context.pseudo,convoy:{...payload,requestId}},'https://uptep.top');if(event.data?.type==='uptep-convoy-result'&&event.data.requestId===requestId)finish(event.data.ok?null:Error(event.data.error||'UPTEP indisponible.'),event.data.result);}
  jobs.add(cancel);win.addEventListener('message',receive);timer=win.setTimeout(()=>finish(Error('UPTEP ne répond pas. Réessaie la vérification.')),20000);doc.body.append(frame);
 });}
 return {call,cancelAll(){for(const cancel of [...jobs])cancel();}};
}
function mountFloodDeclarations(context,auth,serverTime){
 const {document:doc,window:win}=context;if(win.location.pathname.toLowerCase()!=='/armee.php'||!auth)return ()=>{};
 let stopped=false;const nodes=[],at=Date.parse(serverTime)||Date.now();
 const entries=readArmyFloods(doc,at);if(!entries.length)return ()=>{};
 const bridge=uptepFloodBridge(context,auth);const api=bridge.call;
 const good=[];
 for(const e of entries){
  const wrap=doc.createElement('span'),badge=doc.createElement('span'),button=doc.createElement('button');wrap.dataset.uptepFloodUi='1';if(e.game_id)wrap.dataset.uptepFloodGame=e.game_id;wrap.style.cssText='display:inline-flex;align-items:center;gap:6px;margin-left:8px;font:11px Verdana;';badge.setAttribute('role','status');badge.style.cssText='padding:3px 5px;background:#e9dfba;border:1px solid #887749;color:#302b18;';button.type='button';button.style.cssText='background:#525543;color:#d3d9b8;border:1px solid #887749;padding:4px 7px;cursor:pointer;';wrap.append(badge,button);e.node.after(wrap);nodes.push(wrap);e.wrap=wrap;e.badge=badge;e.button=button;
  if(e.error){badge.textContent='À vérifier : relevé non reconnu';badge.title=e.error;button.hidden=true;continue;}
  e.payload={captured_at:e.captured_at,floods:[{game_id:e.game_id,...e.flood}]};good.push(e);
  badge.title=e.warnings.join('\n');badge.textContent='Vérification UPTEP…';button.textContent='Déclarer';button.disabled=true;
  e.state='checking';
  button.addEventListener('click',async()=>{
   if(e.busy||stopped)return;const verificationOnly=e.state==='checking';e.busy=true;button.disabled=true;
   try{
    if(doc.getElementById('pseudo')?.textContent.trim()!==context.pseudo||doc.getElementById('tag_alliance')?.textContent.trim()!=='UPTEP')throw Error('Le compte connecté a changé. Recharge la page.');
    const check=await api({action:'flood-check',...e.payload});const state=check.floods[0];render(e,state);
    if(state.state!=='new'||verificationOnly)return;
    badge.textContent='Déclaration…';const result=await api({action:'flood-declare',...e.payload,confirmed:true});render(e,result);
   }catch(error){if(!stopped){badge.textContent=error.message;button.textContent='Réessayer';button.hidden=false;e.state='error';}}
   finally{e.busy=false;if(!stopped)button.disabled=false;}
  });
 }
 function render(e,r){if(stopped)return;e.state=r.state;e.button.disabled=false;e.button.hidden=r.state!=='new';
  if(r.state==='declared'||r.state==='existing'){e.badge.textContent='✓ Déjà déclaré · #'+r.id;e.badge.style.background='#d3d9b8';}
  else if(r.state==='ambiguous'){e.badge.textContent='À vérifier : plusieurs floods correspondent';}
  else{e.badge.textContent=e.warnings.length?'Non déclaré · relevé à vérifier':'Non déclaré';e.button.textContent='Déclarer le flood';}
 }
 async function checkAll(){for(let i=0;i<good.length&&!stopped;i+=20){const group=good.slice(i,i+20);try{const result=await api({action:'flood-check',captured_at:group[0].captured_at,floods:group.map(e=>e.payload.floods[0])});for(const e of group){const r=result.floods.find(r=>r.game_id===e.game_id);if(!r)throw Error('Réponse UPTEP incomplète.');render(e,r);}}catch(error){for(const e of group)if(!stopped){e.badge.textContent='Vérification indisponible';e.badge.title=error.message;e.button.disabled=false;e.button.textContent='Vérifier / réessayer';}}}}
 // The Optizzz extension hides the game's attack lines (and the badges placed after them) and shows its
 // own rows instead. Move each badge into the matching Optizzz row: same target and place, arrival within
 // 90 seconds. Rows are matched again whenever Optizzz redraws them; without Optizzz nothing moves.
 const placeKey=v=>/^terrain/i.test(v||'')?'T':/^d/i.test(v||'')?'D':/^loge/i.test(v||'')?'L':'';
 const normTroops=v=>String(v||'').normalize('NFC').toLowerCase().replace(/[\s\u00a0\u202f.]+/g,'');
 const rowSeconds=text=>{let s=0,found=false;for(const m of String(text||'').matchAll(/(\d+)\s*([jhms])/gi)){s+=Number(m[1])*{j:86400,h:3600,m:60,s:1}[m[2].toLowerCase()];found=true;}return found?s:null;};
 function placeInOptizzz(){
  const rows=[...doc.querySelectorAll('.optizzz-on-way-row')];if(!rows.length)return;
  const now=Date.now(),used=new Set();
  const info=rows.map(row=>{let pseudo=null;const link=row.querySelector('.optizzz-on-way-to a[href*="Membre.php"]');try{pseudo=new URL(link.getAttribute('href'),'https://s5.fourmizzz.fr/').searchParams.get('Pseudo');}catch{}
   const seconds=rowSeconds(row.querySelector('.optizzz-on-way-left')?.textContent);
   const troops=normTroops((row.querySelector('.optizzz-attacks-troops')?.getAttribute('title')||'').replace(/^\s*Troupes en attaques?\s*:\s*/i,''));
   return {troops,pseudo:pseudo?.normalize('NFC')??null,place:placeKey(row.querySelector('.optizzz-attacks-place')?.textContent.trim()),arrival:seconds===null?null:now+seconds*1000,cell:row.querySelector('.optizzz-on-way-actions')||row};});
  for(const e of entries){
   if(!e.flood||!e.wrap)continue;const target=Date.parse(e.flood.arrival_at),place=placeKey(e.flood.location),troops=normTroops(e.flood.troops);let best=null;
   // Identical attacks (same target and time) are told apart by their troops when both sides show them.
   for(const r of info){if(used.has(r)||r.arrival===null||r.pseudo!==e.flood.pseudo.normalize('NFC')||(r.place&&r.place!==place))continue;const gap=Math.abs(r.arrival-target);if(gap>90000)continue;
    const score=(troops&&r.troops&&troops!==r.troops?1:0)*1e9+gap;if(!best||score<best.score)best={r,score};}
   if(!best)continue;used.add(best.r);if(e.wrap.parentNode!==best.r.cell){e.wrap.style.marginLeft='6px';best.r.cell.append(e.wrap);}
  }
 }
 let observer=null;
 if(typeof win.MutationObserver==='function'){
  let queued=false;
  observer=new win.MutationObserver(records=>{
   if(queued||stopped)return;
   // Ignore the moves of our own badges, otherwise each placement would trigger the next one.
   if(records.every(r=>[...r.addedNodes,...r.removedNodes].every(n=>n.nodeType===1&&n.dataset?.uptepFloodUi)))return;
   queued=true;win.setTimeout(()=>{queued=false;if(!stopped)placeInOptizzz();},50);
  });
  observer.observe(doc.getElementById('centre')||doc.body,{childList:true,subtree:true});
 }
 placeInOptizzz();
 checkAll();return ()=>{stopped=true;observer?.disconnect();bridge.cancelAll();for(const node of nodes)node.remove();};
}
