// Read only native upgrade costs, never forecasts or cumulative prices added by other scripts.
function readConstructionDemand(row){
 const title=row.querySelector('.desciption_amelioration h2')?.textContent.trim();
 const level=row.querySelector('.niveau_amelioration')?.textContent.trim().match(/^niveau\s+(\d+)$/i);
 const cost=[...row.children].find(node=>node.classList.contains('cout_amelioration'));
 if(!title||title.length>160||!level||Number(level[1])>=999||!cost)throw Error('Coût ou niveau de l’amélioration non reconnu.');
 function amount(selector){
  const nodes=cost.querySelectorAll(selector);if(!nodes.length)return '0';
  if(nodes.length!==1)throw Error('Coût ambigu : aucune demande créée.');
  const value=nodes[0].textContent.replace(/[\s\u00a0\u202f]/g,'');
  if(!/^\d{1,19}$/.test(value)||BigInt(value)>9223372036854775807n)throw Error('Coût non reconnu : aucune demande créée.');
  return BigInt(value).toString();
 }
 const apples=amount('.nourriture, .nourritures, .pomme, .pommes'),wood=amount('.materiaux');
 if(apples==='0'&&wood==='0')throw Error('Aucun coût en ressources reconnu.');
 return {object:title,level:String(Number(level[1])+1),apples,wood};
}
// Launch dates come from PostgreSQL ("2026-11-11 10:43:57+00"): normalised so every browser reads them.
function uptepLaunchTime(raw){const ms=Date.parse(String(raw||'').replace(' ','T').replace(/([+-]\d{2})$/,'$1:00'));return Number.isFinite(ms)?ms:null;}
function uptepLaunchDate(raw){const ms=uptepLaunchTime(raw);return ms===null?'—':new Date(ms).toLocaleString('fr-FR',{timeZone:'Europe/Paris',day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});}
// This demand's launch, and the requester's previous one of the same kind ("Construction précédente :
// Couveuse niv. 16 · en cours jusqu’au 07/11 08:36"): shown while running, or always on a detail view.
function uptepLaunchLines(doc,d,detail){
 const kind=d.type_construction==='recherche'?'Recherche':'Construction',lines=[],now=Date.now();
 const line=text=>{const p=doc.createElement('p');p.textContent=text;p.style.cssText='margin:0 0 6px;font-size:12px;color:#5a4a1e;';lines.push(p);};
 if(d.lancement_construction)line(`🏗 ${kind} lancée le ${uptepLaunchDate(d.lancement_construction)} · ${uptepLaunchTime(d.fin_construction)>now?'fin prévue le':'terminée le'} ${uptepLaunchDate(d.fin_construction)}`);
 const prev=d.lancement_precedent;
 if(prev){const running=uptepLaunchTime(prev.fin_construction)>now;if(running||detail)line(`⏳ ${kind} précédente : ${prev.objet||'#'+prev.id}${prev.niveau?' niv. '+prev.niveau:''} · ${running?'en cours jusqu’au':'terminée le'} ${uptepLaunchDate(prev.fin_construction)}`);}
 return lines;
}
function mountConstructionDemands(context,auth){
 const {document:doc,window:win}=context;
 const page=win.location.pathname.toLowerCase(),laboratory=page==='/laboratoire.php';
 if(!auth||(!laboratory&&page!=='/construction.php'))return ()=>{};
 const root=doc.querySelector('#centre');if(!root)return ()=>{};
 const pending=new Set(),tools=[],entries=[];let stopped=false,busy=false,dialog=null,openVersion=0;
 const el=(tag,text)=>{const n=doc.createElement(tag);if(text!==undefined)n.textContent=text;return n;};
 const style=el('style');style.textContent=`.uptep-building-demand button,.uptep-building-modal button{box-sizing:border-box;padding:5px 9px;border:1px solid #938355;border-radius:3px;background:#ffffff38;color:#302b18;font:11px/1.4 Verdana,sans-serif;cursor:pointer}.uptep-building-demand button:hover,.uptep-building-modal button:hover{background:#ffffff65}.uptep-building-modal button[type=submit]{background:#525543;border-color:#525543;color:#fff3d3}.uptep-building-demand button:disabled,.uptep-building-modal button:disabled{opacity:.5;cursor:default}.uptep-building-demand button:focus-visible,.uptep-building-modal :focus-visible{outline:2px solid #254e59;outline-offset:2px}.uptep-building-modal{box-sizing:border-box;width:min(440px,calc(100vw - 24px));max-height:calc(100dvh - 24px);overflow:auto;padding:18px;border:1px solid #887749;border-radius:6px;background:#e2d29e;color:#302b18;font:13px/1.5 Verdana,sans-serif}.uptep-building-modal::backdrop{background:#171b15a6}.uptep-building-modal h2{margin:0 0 8px;font-size:17px}.uptep-building-modal p{margin:8px 0}.uptep-building-modal input[type=checkbox]{accent-color:#525543}.uptep-building-modal .uptep-building-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:16px;flex-wrap:wrap}`;
 root.append(style);
 // Delivered demands whose launch is not confirmed yet: one click once the work is started in the game.
 const served=Date.parse(typeof uptepTrafficServerTime==='undefined'?'':uptepTrafficServerTime),loadedAt=win.performance.now();
 const serverNow=()=>(Number.isFinite(served)?served:Date.now())+(win.performance.now()-loadedAt);
 // The page counts the running work down with reste(seconds,"<id>"), as for hunts; used only when unambiguous.
 const countdowns=[];for(const script of root.querySelectorAll('script'))for(const m of script.textContent.matchAll(/reste\(\s*(\d+)\s*,\s*["']([^"']+)["']\s*\)/g))if(!m[2].startsWith('chasse_'))countdowns.push(Number(m[1]));
 const countdown=countdowns.length===1?countdowns[0]:null,countdownEnd=countdown===null?null:serverNow()+countdown*1000;
 const isoTime=ms=>new Date(Math.round(ms/1000)*1000).toISOString().replace(/\.\d{3}Z$/,'Z');
 const span=total=>[[86400,'j'],[3600,'h'],[60,'m'],[1,'s']].reduce((text,[factor,unit])=>{const n=Math.floor(total/factor);total%=factor;return n?text+n+unit+' ':text;},'').trim();
 const banner=el('div');banner.className='uptep-building-demand';banner.style.cssText='margin:0 0 12px;padding:8px 10px;border:1px solid #938355;border-radius:4px;background:#f1e6bf;color:#302b18;font:12px/1.5 Verdana,sans-serif;text-align:left;';
 async function loadLaunches(){
  let result;try{result=await request({action:'convoy-list',scope:'mine',state:'terminee'});}catch{return;}
  if(stopped)return;const kind=laboratory?'recherche':'construction';
  // The server decides which demands await a launch: delivered since the feature, within 30 days.
  const todo=(result.demands||[]).filter(d=>d.lancement_attendu&&d.type_construction===kind).slice(0,3);
  if(!todo.length)return;banner.replaceChildren();root.prepend(banner);
  for(const d of todo){
   const name=`#${d.id} · ${d.objet||'Ressources'}${d.niveau?' niv. '+d.niveau:''}`;
   const item=el('div'),label=el('span',`Ta demande ${name} est complète. `),open=el('button',laboratory?'Confirmer le lancement de la recherche':'Confirmer le lancement de la construction');
   open.type='button';item.style.cssText='padding:3px 0;';item.append(label,open);banner.append(item);
   open.addEventListener('click',()=>{
    open.remove();
    const form=el('form'),remaining=el('input'),submit=el('button','Confirmer'),status=el('span');
    form.style.cssText='display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-top:4px;';remaining.required=true;remaining.placeholder='Ex. 12h26m';remaining.style.cssText='width:120px;font:12px Verdana,sans-serif;';
    if(countdown!==null)remaining.value=span(countdown);submit.type='submit';status.setAttribute('role','status');
    form.append(el('span','Temps restant affiché dans le jeu :'),remaining,submit,status);item.append(form);remaining.focus();
    form.addEventListener('submit',async event=>{
     event.preventDefault();const seconds=parseHuntDuration(remaining.value);
     if(!seconds){status.textContent='Durée non reconnue (ex. 12h26m).';return;}
     submit.disabled=true;status.textContent='Enregistrement…';const now=serverNow();
     // The untouched countdown keeps its exact end; a typed value counts from now.
     const end=countdownEnd!==null&&remaining.value===span(countdown)?countdownEnd:now+seconds*1000;
     try{const out=await request({action:'convoy-manage',demand_id:d.id,operation:'lancement',request_key:win.crypto.randomUUID(),confirmed:true,launched_at:isoTime(Math.min(now,end-1000)),ends_at:isoTime(end)});
      form.remove();label.textContent=`✓ ${name} : lancement confirmé, fin prévue le ${uptepLaunchDate(out.demande?.fin_construction)}. Visible sur ta prochaine demande.`;}
     catch(e){submit.disabled=false;status.textContent=e.message;}
    });
   });
  }
 }
 loadLaunches();
 function alive(){if(stopped)throw Error('Module rechargé.');if(doc.getElementById('pseudo')?.textContent.trim()!==context.pseudo||doc.getElementById('tag_alliance')?.textContent.trim()!=='UPTEP')throw Error('Le compte connecté a changé.');}
 function request(payload){return new Promise((resolve,reject)=>{
  try{alive();}catch(e){reject(e);return;}
  const frame=el('iframe');frame.hidden=true;frame.title='Création de demande UPTEP';frame.referrerPolicy='no-referrer';frame.src='https://uptep.top/portal.php?bridge=convoys';const requestId=win.crypto.randomUUID();let timer;
  function finish(error,result){win.clearTimeout(timer);win.removeEventListener('message',receive);frame.remove();pending.delete(cancel);error?reject(error):resolve(result);}
  const cancel=()=>finish(Error('Module rechargé.'));
  function receive(event){
   if(event.origin!=='https://uptep.top'||event.source!==frame.contentWindow)return;
   try{alive();}catch(e){finish(e);return;}
   if(event.data?.type==='uptep-ready')frame.contentWindow.postMessage({type:'uptep-auth',token:auth.token,pseudo:context.pseudo,convoy:{...payload,requestId}},'https://uptep.top');
   if(event.data?.type==='uptep-convoy-result'&&event.data.requestId===requestId){
    if(!event.data.ok)return finish(Object.assign(Error(event.data.error||'UPTEP indisponible.'),{status:event.data.status||0}));
    let result;try{result=JSON.parse(typeof event.data.result_json==='string'?event.data.result_json:JSON.stringify(event.data.result));}catch{return finish(Error('Réponse UPTEP illisible. Réessaie la même demande.'));}
    finish(null,result);
   }
  }
  pending.add(cancel);win.addEventListener('message',receive);timer=win.setTimeout(()=>finish(Error('UPTEP ne répond pas. Réessaie la même demande.')),20000);doc.body.append(frame);
 });}
 async function exclusive(key,fn){
  if(!win.navigator.locks)throw Error('Ouvre cette page en HTTPS dans un navigateur prenant en charge le verrouillage des demandes.');
  let failure;const run=async held=>{try{if(!held)throw Error('Cette demande est déjà traitée dans un autre onglet.');alive();await fn();}catch(e){failure=e;}};let callback=run;
  if(typeof exportFunction==='function'&&win.wrappedJSObject){const pageWindow=win.wrappedJSObject;callback=exportFunction(held=>new pageWindow.Promise(exportFunction(resolve=>{run(held).then(()=>{resolve();});},pageWindow)),pageWindow);}
  await win.navigator.locks.request(key,{ifAvailable:true},callback);if(failure)throw failure;
 }
 const keyFor=offer=>(laboratory?'uptep-research-demand:s5:':'uptep-building-demand:s5:')+encodeURIComponent(context.pseudo)+':'+encodeURIComponent(offer.object)+':'+offer.level;
 function read(key){const raw=win.localStorage.getItem(key);if(!raw)return null;const saved=JSON.parse(raw);if(!saved||!saved.payload||saved.payload.action!=='convoy-create'||!saved.payload.request_key||!['pending','done'].includes(saved.state))throw Error('Vérifie cette demande dans Commerce avant toute nouvelle création.');return saved;}
 // Only confirmed creations may be discarded after checking the server. Pending
 // creations retain their idempotency key until their outcome is known.
 async function current(key){
  const saved=read(key);if(saved?.state!=='done')return saved;
  let demand;
  try{const result=await request({action:'convoy-detail',demand_id:saved.demand_id});demand=result.demand;if(!demand||String(demand.id)!==saved.demand_id||typeof demand.annulee!=='boolean'||typeof demand.fini!=='boolean')throw Error('État de la demande illisible.');}
  catch(e){if(e.status!==404)throw e;demand={annulee:true};}
  alive();const latest=read(key);if(latest?.state!=='done'||latest.demand_id!==saved.demand_id)return latest;
  if(demand.annulee||demand.fini){win.localStorage.removeItem(key);return null;}
  return {...latest,demand_state:demand.etat};
 }
 function display(entry,saved){entry.button.textContent=saved?.state==='done'?'Voir ma demande':saved?'Reprendre ma demande':'Faire une demande de ressource';entry.notice.textContent=saved?.state==='done'?'Demande #'+saved.demand_id+' · '+(saved.demand_state==='en_cours'?'En cours':'À valider'):'';}
 async function sync(entry){
  if(stopped||busy||entry.checking)return;entry.checking=true;entry.button.disabled=true;
  try{const saved=await current(entry.key);if(!stopped){display(entry,saved);if(!saved&&dialog?.dataset.demandKey===entry.key&&dialog.dataset.demandId)close();}}catch(e){if(!stopped){entry.button.textContent='Vérifier ma demande';entry.notice.textContent='État non vérifié : '+e.message;}}finally{entry.checking=false;if(!stopped)entry.button.disabled=false;}
 }
 function refresh(){for(const entry of entries)sync(entry);}
 function close(){if(dialog){dialog.close();dialog.remove();dialog=null;}}
 async function open(row,button,notice){
  if(busy||stopped)return;close();const turn=++openVersion;let offer,key,saved;button.disabled=true;
  try{alive();offer=readConstructionDemand(row);key=keyFor(offer);saved=await current(key);if(stopped||turn!==openVersion)return;display({button,notice},saved);}catch(e){if(!stopped)notice.textContent='État non vérifié : '+e.message;return;}finally{if(!stopped)button.disabled=false;}
  const modal=el('dialog');modal.className='uptep-building-modal';modal.setAttribute('aria-labelledby','uptep-building-demand-title');dialog=modal;modal.dataset.demandKey=key;if(saved?.state==='done')modal.dataset.demandId=saved.demand_id;
  const heading=el('h2','Faire une demande de ressource');heading.id='uptep-building-demand-title';modal.append(heading);
  const shown=saved?.payload||offer;modal.append(el('p',shown.object+' · niveau '+shown.level));
  const amounts=el('p');
  for(const [name,label,icon] of [['apples','nourriture','icone_pomme.png'],['wood','matériaux','icone_bois.png']]){const image=el('img');image.src='/images/icone/'+icon;image.alt='';image.width=18;image.style.cssText='vertical-align:middle;margin-right:4px';amounts.append(image,doc.createTextNode(BigInt(shown[name]).toLocaleString('fr-FR')+' '+label+(name==='apples'?' · ':'')));}modal.append(amounts);
  modal.append(el('p','Coût total de cette amélioration. La demande sera créée pour '+context.pseudo+', en attente de validation.'));
  const form=el('form'),label=el('label'),auto=el('input');auto.type='checkbox';auto.name='auto_production';auto.checked=saved?.payload.auto_production===true;auto.disabled=!!saved;label.append(auto,doc.createTextNode(' Auto production'));form.append(label);
  const status=el('p');status.setAttribute('role','status');form.append(status);
  const actions=el('div');actions.className='uptep-building-actions';const cancel=el('button','Annuler'),submit=el('button',saved?.state==='done'?'Demande déjà créée':saved?'Réessayer la création':'Confirmer la demande');cancel.type='button';submit.type='submit';submit.disabled=saved?.state==='done';actions.append(cancel,submit);form.append(actions);modal.append(form);
  function completed(record){status.textContent='✓ Demande #'+record.demand_id+' · '+(record.demand_state==='en_cours'?'En cours.':'En attente de validation.');notice.textContent='Demande #'+record.demand_id+' créée';button.textContent='Voir ma demande';submit.textContent='Demande créée';submit.disabled=true;auto.disabled=true;cancel.textContent='Fermer';const link=el('a','Consulter mes demandes');link.href='/commerce.php';status.append(doc.createTextNode(' '),link);}
  if(saved?.state==='done')completed(saved);else if(saved)status.textContent='La confirmation de cette création est en attente. Réessayer reprend la même demande, sans doublon.';
  cancel.addEventListener('click',close);modal.addEventListener('close',()=>{if(dialog===modal)dialog=null;modal.remove();button.focus();});modal.addEventListener('cancel',event=>{if(busy)event.preventDefault();});modal.addEventListener('click',event=>{if(event.target===modal&&!busy){const r=modal.getBoundingClientRect();if(event.clientX<r.left||event.clientX>r.right||event.clientY<r.top||event.clientY>r.bottom)close();}});
  form.addEventListener('submit',async event=>{
   event.preventDefault();if(busy||submit.disabled)return;busy=true;submit.disabled=true;cancel.disabled=true;auto.disabled=true;status.textContent='Création de la demande…';
   try{await exclusive(key,async()=>{
    let record=await current(key);if(record?.state==='done'){completed(record);close();return;}
    if(!record){const current=readConstructionDemand(row);if(JSON.stringify(current)!==JSON.stringify(offer))throw Error('Le coût a changé. Ferme puis rouvre la confirmation.');record={state:'pending',payload:{action:'convoy-create',request_key:win.crypto.randomUUID(),confirmed:true,...current,wanted_date:null,auto_production:auto.checked}};win.localStorage.setItem(key,JSON.stringify(record));}
    saved=record;
    try{const result=await request(record.payload);alive();if(!/^[1-9]\d*$/.test(String(result.demand_id)))throw Error('Confirmation de demande illisible. Réessaie la même demande.');const done={...record,state:'done',demand_id:String(result.demand_id)};win.localStorage.setItem(key,JSON.stringify(done));completed(done);saved=done;close();}
    catch(e){if(e.status===400||e.status===422){win.localStorage.removeItem(key);saved=null;}throw e;}
   });}catch(e){if(!stopped){status.textContent=e.message;submit.textContent=saved?'Réessayer la création':'Confirmer la demande';}}
   finally{busy=false;if(!stopped){cancel.disabled=false;submit.disabled=saved?.state==='done';auto.disabled=!!saved;}}
  });
  doc.body.append(modal);modal.showModal();
 }
 for(const row of root.querySelectorAll('tr.ligneAmelioration')){
  let offer;try{offer=readConstructionDemand(row);}catch{continue;}
  const cost=[...row.children].find(node=>node.classList.contains('cout_amelioration'));const tool=el('div');tool.className='uptep-building-demand';tool.style.cssText='display:block;margin-top:7px;';
  const button=el('button','Faire une demande de ressource'),notice=el('small');button.type='button';button.style.maxWidth='190px';notice.style.cssText='display:block;margin-top:3px;font:11px Verdana;';notice.setAttribute('role','status');
  const entry={key:keyFor(offer),button,notice,checking:false};entries.push(entry);
  try{const saved=read(entry.key);if(saved?.state==='done'){button.textContent='Vérification de la demande…';button.disabled=true;}else display(entry,saved);}catch(e){notice.textContent=e.message;}
  button.addEventListener('click',()=>open(row,button,notice));tool.append(button,notice);cost.append(tool);tools.push(tool);

 }
 refresh();const timer=win.setInterval(refresh,30000);win.addEventListener('focus',refresh);
 return ()=>{stopped=true;openVersion++;win.clearInterval(timer);win.removeEventListener('focus',refresh);for(const cancel of [...pending])cancel();close();for(const tool of tools)tool.remove();style.remove();};
}
