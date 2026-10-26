function convoyAutoBadge(doc){const badge=doc.createElement('span');badge.textContent='Auto production';badge.title='Le demandeur produit lui-même ses ressources. L’envoi vers cette demande est désactivé.';badge.style.cssText='display:inline-block;vertical-align:middle;margin-left:7px;padding:2px 7px;border:1px solid #928443;border-radius:10px;background:#eee0a0;color:#5b4d19;font:11px/1.4 Verdana,sans-serif;';return badge;}
// Read semantic lines rather than layout classes: standard/C+ pages can use
// strong/span, nested markup, images or plain resource labels, and table rows.
// All projected deductions are display-only. The persisted remainder is untouched.
function convoyColonyCapacity(value){
 const number=v=>typeof v==='string'&&/^(0|[1-9][0-9]{0,18})$/.test(v)&&BigInt(v)<=9223372036854775807n?BigInt(v):null;
 if(typeof value==='string')return number(value);
 if(!value||typeof value!=='object'||!Array.isArray(value.gains))return null;
 let capacity=number(value.own);if(capacity===null)return null;
 const transfer=t=>{const terrain=number(t?.terrain);return terrain===null||!Number.isInteger(t?.percent)||t.percent<0||t.percent>100?null:terrain*BigInt(t.percent)/100n;};
 if(value.loss!==null&&value.loss!==undefined){const loss=transfer(value.loss);if(loss===null||loss>capacity)return null;capacity-=loss;}
 for(const t of value.gains){const gain=transfer(t);if(gain===null)return null;capacity+=gain;}
 return capacity;
}
function convoyProductionEstimate(d){
 const integer=v=>typeof v==='string'&&/^(0|[1-9][0-9]{0,18})$/.test(v)&&BigInt(v)<=9223372036854775807n?BigInt(v):null;
 const tdc=integer(d.production?.tdc),capacity=d.production?.colonies_included?convoyColonyCapacity(d.production.capacity_per_cycle):tdc,apples=integer(d.nbpomme_restant),wood=integer(d.nbmateriaux_restant);
 if(tdc===null||capacity===null||apples===null||wood===null)return {tdc,cycles:null,reason:'Données de capacité manquantes'};
 let harvested=0n;const projection=d.production?.projection;
 if(projection){
  if(projection.history_complete!==true||!Array.isArray(projection.segments))return {tdc,cycles:null,reason:'Historique de TdC incomplet depuis le point de départ'};
  for(const segment of projection.segments){const cycles=integer(segment.cycles),rate=convoyColonyCapacity(segment.capacity_per_cycle);if(cycles===null||rate===null)return {tdc,cycles:null,reason:'Historique de capacité illisible'};harvested+=cycles*rate;}
 }
 const remaining=apples+wood>harvested?apples+wood-harvested:0n;
 if(remaining>0n&&capacity===0n)return {tdc,cycles:null,reason:'Capacité de récolte nulle'};
 const cycles=remaining===0n?0n:(remaining+capacity-1n)/capacity;
 let finish_at=null,minutes=cycles*30n;
 if(projection&&cycles>0n){const anchor=Date.parse(projection.anchor_at),at=Date.parse(projection.calculated_at);const elapsed=integer(projection.elapsed_cycles);
  if(!Number.isFinite(anchor)||!Number.isFinite(at)||elapsed===null)return {tdc,cycles:null,reason:'Date de calcul indisponible'};
  const finish=anchor+Number(elapsed+cycles)*1800000;
  if(Number.isFinite(finish)&&Math.abs(finish)<=8640000000000000){finish_at=finish;minutes=BigInt(Math.max(0,Math.ceil((finish-at)/60000)));}
 }
 return {tdc,cycles,minutes,harvested,remaining,finish_at,capacity};
}
function convoyProductionMinutes(minutes){const days=minutes/1440n,hours=minutes/60n%24n,rest=minutes%60n;return [days?days.toLocaleString('fr-FR')+' j':'',hours?hours+' h':'',rest?rest+' min':''].filter(Boolean).join(' ')||'0 min';}
function convoyProductionDuration(cycles){return convoyProductionMinutes(cycles*30n);}
function readCommerceConvoys(doc,baseUrl,now) {
 const root=doc.getElementById('centre');if(!root)return [];
 const lines=[];let line={text:'',links:[]};
 const flush=()=>{if(line.text.trim())lines.push(line);line={text:'',links:[]};};
 function walk(node){
  if(node.nodeType===3){line.text+=node.nodeValue;return;}
  if(node.nodeType!==1||node.matches('script,style,nav,form,[data-uptep-convoy-ui]'))return;
  if(node.tagName==='BR'){flush();return;}
  const block=/^(DIV|P|TR|H[1-6]|LI)$/.test(node.tagName);if(block)flush();
  if(node.tagName==='A')line.links.push({node,offset:line.text.length});
  if(node.tagName==='IMG')line.text+=' '+(node.getAttribute('alt')||'')+' ';
  else {if(node.tagName==='TD')line.text+=' ';for(const child of node.childNodes)walk(child);}
  if(block)flush();
 }
 walk(root);flush();const rows=[],seen=new Set();
 for(const line of lines){
  // Keep string offsets while normalizing space characters (one for one).
  const text=line.text.replace(/[\u00a0\u202f]/g,' ');
  const pattern=/Vous allez livrer\s+(\d[\d ]*?)\s*(?:nourritures?)?\s+et\s+(\d[\d ]*?)\s*(?:mat[eé]riaux?)?\s+[àa]\s+(.+?)\s+dans\s+((?:\d+\s*[jHhms]\s*)+)/g;
  const matches=[...text.matchAll(pattern)];
  for(let index=0;index<matches.length;index++){
   const m=matches[index],end=matches[index+1]?.index??text.length;
   const links=line.links.filter(l=>l.offset>=m.index&&l.offset<end);
   const cancel=links.find(({node})=>{try{const u=new URL(node.getAttribute('href'),baseUrl);return u.origin===new URL(baseUrl).origin&&u.pathname.toLowerCase()==='/commerce.php'&&/^[1-9]\d{0,18}$/.test(u.searchParams.get('annuler')||'');}catch{return false;}});
   if(!cancel)continue; // No stable game ID: never guess and risk duplicate debits.
   const gameId=new URL(cancel.node.getAttribute('href'),baseUrl).searchParams.get('annuler');if(seen.has(gameId))continue;
   const recipient=links.find(({node})=>{try{const u=new URL(node.getAttribute('href'),baseUrl);return u.origin===new URL(baseUrl).origin&&u.pathname.toLowerCase()==='/membre.php'&&u.searchParams.has('Pseudo');}catch{return false;}});
   const target=m[3].trim();if(recipient&&new URL(recipient.node.getAttribute('href'),baseUrl).searchParams.get('Pseudo')!==target)continue;
   const apples=m[1].replace(/\s/g,''),wood=m[2].replace(/\s/g,'');
   if([apples,wood].some(n=>!/^\d{1,19}$/.test(n)||BigInt(n)>9223372036854775807n)||BigInt(apples)+BigInt(wood)===0n)continue;
   const duration=m[4].replace(/\s/g,'').toLowerCase().match(/^(?:(\d+)j)?(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/);if(!duration)continue;
   const seconds=[86400,3600,60,1].reduce((n,f,i)=>n+Number(duration[i+1]||0)*f,0);if(seconds<1||seconds>366*86400)continue;
   const signature=JSON.stringify([gameId,target,apples,wood]);let arrival;
   try{const cache=JSON.parse(cancel.node.dataset.uptepConvoyTime||'null');if(cache?.signature===signature)arrival=cache.arrival;}catch{}
   if(!arrival){arrival=new Date(now+seconds*1000).toISOString().replace(/\.\d{3}Z$/,'Z');cancel.node.dataset.uptepConvoyTime=JSON.stringify({signature,arrival});}
   rows.push({game_id:gameId,target,apples:BigInt(apples).toString(),wood:BigInt(wood).toString(),arrival,anchor:cancel.node});seen.add(gameId);
  }
 }
 return rows;
}
function convoyInputNumber(raw){
 const m=String(raw).trim().replace(/[\s\u00a0\u202f]/g,'').toLowerCase().match(/^(\d+)(?:[.,](\d+))?([kmg]?)$/);
 if(!m)throw Error('Quantité invalide : entier, 1,5k, 2m ou 3g.');const power={'':0,k:3,m:6,g:9}[m[3]],fraction=m[2]||'';
 if(/[^0]/.test(fraction.slice(power)))throw Error('Les quantités doivent être entières.');
 const value=BigInt(m[1]+fraction.slice(0,power).padEnd(power,'0'));if(value>9223372036854775807n)throw Error('Quantité trop grande.');return String(value);
}
function convoyGameValue(doc,name){
 const script=[...doc.scripts].map(n=>n.textContent).find(s=>/function\s+(?:max_convoi|maj_input_convoi)\s*\(/.test(s));if(!script)throw Error('Capacité de transport non reconnue. Aucun envoi.');
 const matches=[...script.matchAll(new RegExp('var\\s+'+name+'\\s*=\\s*(\\d+)\\s*;','g'))];if(!matches.length||matches.some(m=>m[1]!==matches[0][1]))throw Error('Données du jeu non reconnues : '+name+'. Aucun envoi.');return BigInt(matches[0][1]);
}
function convoyRequiredWorkers(apples,wood,level){
 const total=BigInt(apples)+BigInt(wood),capacity=20n+BigInt(level);
 return (total*2n+capacity-1n)/capacity;
}
// Largest convoy for a demand without uncovering the hunting field: workers beyond the TdC only
// (as the game's first "max" click), 10 resources each +5 % per aphid stable level, capped by the
// stock and the remaining demand. A short capacity is split in proportion to what is still asked;
// whatever one resource cannot use goes to the other.
function convoyMaxOrder({apples,wood,workers,terrain,level,needApples,needWood}){
 const min=(a,b)=>a<b?a:b,stockApples=BigInt(apples),stockWood=BigInt(wood),askApples=BigInt(needApples),askWood=BigInt(needWood);
 const free=BigInt(workers)-BigInt(terrain),capacity=free>0n?free*(20n+BigInt(level))/2n:0n;
 const capApples=min(askApples,stockApples),capWood=min(askWood,stockWood);
 if(askApples+askWood===0n)return {apples:0n,wood:0n,free,capacity,limit:'done'};
 if(capacity===0n)return {apples:0n,wood:0n,free,capacity,limit:'workers'};
 if(capApples+capWood<=capacity)return {apples:capApples,wood:capWood,free,capacity,limit:capApples===askApples&&capWood===askWood?'demand':'stock'};
 let sendApples=min(capApples,capacity*askApples/(askApples+askWood));
 const sendWood=min(capWood,capacity-sendApples);sendApples=min(capApples,capacity-sendWood);
 return {apples:sendApples,wood:sendWood,free,capacity,limit:'workers'};
}
function convoyNativeForm(doc,win,pseudo,order){
 if(doc.getElementById('pseudo')?.textContent.trim()!==pseudo||doc.getElementById('tag_alliance')?.textContent.trim()!=='UPTEP')throw Error('Le compte connecté au jeu a changé ou la session a expiré.');
 const form=doc.querySelector('[name="pseudo_convoi"]')?.closest('form');
 if(!form||form.method.toLowerCase()!=='post'||new URL(form.getAttribute('action')||'',win.location.href).pathname.toLowerCase()!=='/commerce.php'||new URL(form.getAttribute('action')||'',win.location.href).origin!==win.location.origin)throw Error('Formulaire de convoi non reconnu. Aucun envoi.');
 for(const key of ['t','nbNourriture','nbMateriaux','nbOuvriere'])if(!form.querySelector('[name="'+key+'"]'))throw Error('Formulaire de convoi incomplet. Aucun envoi.');
 if(!form.querySelector('[name=t]').value)throw Error('Jeton du jeu absent. Recharge la page.');
 const variable=name=>convoyGameValue(doc,name);
 const bonus=variable('bonus'),food=variable('max_nourriture'),wood=variable('max_materiaux'),workers=variable('max_ouvriere');
 const apples=BigInt(order.apples),materials=BigInt(order.wood),total=apples+materials,needed=convoyRequiredWorkers(apples,materials,bonus);
 if(total===0n||apples>food||materials>wood||needed>workers)throw Error('Ressources ou ouvrières insuffisantes dans le jeu.');
 const params=new URLSearchParams();for(const [key,value] of new win.FormData(form)){if(typeof value!=='string')throw Error('Formulaire inattendu.');params.append(key,value);}
 for(const [key,value] of Object.entries({pseudo_convoi:order.target,nbNourriture:order.apples,nbMateriaux:order.wood,nbOuvriere:String(needed),input_nbNourriture:order.apples,input_nbMateriaux:order.wood,input_nbOuvriere:String(needed),ratio_nourriture:String(Number(apples)/Number(total)),convoi:form.querySelector('[name=convoi]')?.value||'Lancer le convoi'}))params.set(key,value);
 return params;
}
function mountConvoys(context,auth,serverTime,travelData=null){
 const {document:doc,window:win}=context;if(win.location.pathname.toLowerCase()!=='/commerce.php'||!auth)return ()=>{};
 const root=doc.getElementById('centre');if(!root)return ()=>{};
 const key='uptep-convoys-s5:'+context.pseudo,pending=new Set(),controllers=new Set(),estimateRows=new Map();let projectionRefreshAt=0,projectionRefreshing=false,projectionGeneration=0;let stopped=false,busy=false,reloading=false,transportDoc=doc;
 const base=Date.parse(serverTime)||Date.now(),started=win.performance.now(),now=()=>base+win.performance.now()-started,iso=()=>new Date(now()).toISOString().replace(/\.\d{3}Z$/,'Z');
 const el=(tag,text)=>{const n=doc.createElement(tag);if(text!==undefined)n.textContent=text;return n;},fmt=n=>new Intl.NumberFormat('fr-FR').format(BigInt(n));
 const icon=kind=>{const image=el('img');image.src='/images/icone/'+({apples:'icone_pomme.png',wood:'icone_bois.png',workers:'icone_ouvriere.png'}[kind]);image.alt='';image.setAttribute('aria-hidden','true');image.width=kind==='workers'?25:20;image.style.cssText='height:auto;vertical-align:middle;margin-right:5px;';return image;};
 const box=el('section');box.id='uptep-convoys';box.dataset.uptepConvoyUi='1';box.style.cssText='box-sizing:border-box;margin:18px auto;padding:16px;max-width:900px;background:#d7c384;color:#000;border:1px solid #887749;border-radius:0;font:13px Verdana;';
 const buttonStyle=el('style');buttonStyle.textContent=`#uptep-convoys button:where(:not(.uptep-modal-close)){box-sizing:border-box;margin:3px 5px 3px 0!important;padding:4px 9px!important;border:1px solid #938355!important;border-radius:3px!important;background:#ffffff38!important;color:#302b18!important;font:11px/1.4 Verdana,sans-serif!important;box-shadow:none!important;cursor:pointer;transition:background-color .12s,border-color .12s}#uptep-convoys button:where(:not(.uptep-modal-close)):hover:not(:disabled){background:#ffffff65!important;border-color:#6c613f!important}#uptep-convoys button[data-uptep-primary="1"],#uptep-convoys button[role="tab"][aria-selected="true"],#uptep-convoys .uptep-demand-actions button:first-child{background:#525543!important;border-color:#525543!important;color:#fff3d3!important}#uptep-convoys button[data-uptep-primary="1"]:hover:not(:disabled),#uptep-convoys button[role="tab"][aria-selected="true"]:hover:not(:disabled),#uptep-convoys .uptep-demand-actions button:first-child:hover:not(:disabled){background:#64694f!important}#uptep-convoys button[role="tab"]{margin:0!important;padding:6px 10px!important}#uptep-convoys button:disabled{opacity:.45;cursor:not-allowed}#uptep-convoys button:focus-visible{outline:2px solid #254e59;outline-offset:2px}`;buttonStyle.textContent+='#uptep-convoys select{box-sizing:border-box;appearance:none;-webkit-appearance:none;min-height:28px;max-width:100%;margin:4px 0 8px;padding:5px 30px 5px 10px;border:1px solid #938355;border-radius:3px;background-color:#e8dab0;background-image:linear-gradient(45deg,transparent 50%,#525543 50%),linear-gradient(135deg,#525543 50%,transparent 50%);background-position:right 13px center,right 9px center;background-size:4px 4px;background-repeat:no-repeat;color:#302b18;font:12px/1.4 Verdana,sans-serif;box-shadow:inset 0 1px 0 #ffffff38;cursor:pointer;transition:background-color .12s,border-color .12s}#uptep-convoys select:hover:not(:disabled){background-color:#f0e3be;border-color:#6c613f}#uptep-convoys select:focus-visible{outline:2px solid #254e59;outline-offset:2px}#uptep-convoys select:disabled{opacity:.45;cursor:not-allowed}#uptep-convoys select option{background:#eee2bb;color:#302b18}@media(forced-colors:active){#uptep-convoys select{appearance:auto;background-image:none}}';box.append(buttonStyle);
 const title=el('h2','Demandes de convois UPTEP'),status=el('p'),refresh=el('button','Actualiser les demandes'),recovery=el('div'),list=el('div');refresh.type='button';status.setAttribute('role','status');
 box.append(title,el('p','Choisis les quantités puis clique sur Envoyer : le convoi part dans le jeu et sa déclaration UPTEP suit automatiquement. Les ouvrières au travail peuvent être utilisées, comme dans le formulaire du jeu.'),refresh,status,recovery,list);
 const tabs=el('div'),waiting=el('div'),createPanel=el('div'),tabButtons={},panels={},tabLoads={};tabs.setAttribute('role','tablist');tabs.setAttribute('aria-label','Demandes de convois');tabs.style.cssText='display:flex;flex-wrap:wrap;gap:5px;margin:10px 0;border-bottom:1px solid #887749;padding-bottom:7px;';
 let activeTab='current',pendingVersion=0,management=null;
 function addTab(id,label,panel,onSelect=null){
  const button=el('button',label);button.type='button';button.id='uptep-convoys-tab-'+id;button.setAttribute('role','tab');button.setAttribute('aria-controls','uptep-convoys-panel-'+id);button.style.cssText='padding:8px 12px;border:1px solid #887749;cursor:pointer;';
  tabButtons[id]=button;panels[id]=panel;tabLoads[id]=onSelect;panel.id='uptep-convoys-panel-'+id;panel.setAttribute('role','tabpanel');panel.setAttribute('aria-labelledby',button.id);panel.hidden=id!==activeTab;button.setAttribute('aria-selected',String(id===activeTab));button.tabIndex=id===activeTab?0:-1;button.addEventListener('click',()=>showTab(id));
  button.addEventListener('keydown',event=>{if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;event.preventDefault();const ids=Object.keys(panels).filter(key=>!tabButtons[key].hidden),index=ids.indexOf(id),next=event.key==='Home'?0:event.key==='End'?ids.length-1:(index+(event.key==='ArrowRight'?1:ids.length-1))%ids.length;showTab(ids[next]);tabButtons[ids[next]].focus();});tabs.append(button);if(panel!==list)box.append(panel);
  return {setVisible(visible){button.hidden=!visible;if(!visible){panel.hidden=true;if(activeTab===id)showTab('current',true);}}};
 }
 list.before(tabs);addTab('current','Demandes en cours',list);addTab('pending','En attente de validation',waiting,loadPending);addTab('create','Faire une demande',createPanel);
 function showTab(id,force=false){if((busy&&!force)||!panels[id]||tabButtons[id].hidden)return;activeTab=id;for(const key of Object.keys(panels)){panels[key].hidden=key!==id;tabButtons[key].setAttribute('aria-selected',String(key===id));tabButtons[key].tabIndex=key===id?0:-1;tabButtons[key].style.background=key===id?'#525543':'#d7c384';tabButtons[key].style.color=key===id?'#d3d9b8':'#302b18';}refresh.hidden=id==='create';if(tabLoads[id])Promise.resolve(tabLoads[id]()).catch(e=>{if(!stopped)status.textContent=e.message;});}
 async function loadPending(){const version=++pendingVersion;waiting.textContent='Chargement…';const data=await api({action:'convoy-pending'});if(stopped||version!==pendingVersion)return;management?.acceptPermissions(data.permissions);waiting.replaceChildren();for(const d of data.demands){const row=el('article');row.style.cssText='padding:10px 0;border-bottom:1px solid #b39b61;';const heading=el('h3',`#${d.id} · ${d.target||d.pseudo_demandeur} · ${d.objet||'Demande'}${d.niveau?' niveau '+d.niveau:''}`);heading.style.cssText='margin:0 0 6px;font-size:15px;line-height:1.35;';if(d.auto_production)heading.append(convoyAutoBadge(doc));row.append(heading);const amounts=el('p');amounts.style.cssText='margin:0;font-size:13px;';amounts.append(icon('apples'),doc.createTextNode(fmt(d.nbpomme_restant)+' pommes · '),icon('wood'),doc.createTextNode(fmt(d.nbmateriaux_restant)+' matériaux'));const meta=el('div');meta.style.cssText='display:flex;flex-wrap:wrap;align-items:center;gap:5px 14px;margin-bottom:5px;';const badge=el('span','À valider');badge.title='En attente de validation — envoi indisponible.';badge.style.cssText='padding:3px 8px;border:1px solid #887749;border-radius:10px;background:#eee0ad;font-size:11px;line-height:1.3;';meta.append(amounts,badge);const created=new Date(d.date_demande);if(Number.isFinite(created.getTime())){const stamp=el('time','Demandée le '+created.toLocaleDateString('fr-FR',{timeZone:'Europe/Paris'})+' à '+created.toLocaleTimeString('fr-FR',{timeZone:'Europe/Paris',hour:'2-digit',minute:'2-digit'})+' (Paris)');stamp.dateTime=created.toISOString();stamp.className='uptep-pending-created';stamp.style.cssText='font-size:11px;color:#655a37;';meta.append(stamp);}row.append(meta);management?.decorate(row,d);waiting.append(row);}if(!data.demands.length)waiting.append(el('p','Aucune demande en attente de validation.'));}
 if(typeof mountConvoyManagement==='function')management=mountConvoyManagement(context,{api,exclusive,addTab,status,box,tabs,refreshCurrent:async()=>{await load();if(activeTab==='pending')await loadPending();}});
 const requestForm=el('form'),requestFields={},requestNotice=el('p');requestForm.id='uptep-convoy-request-form';requestNotice.setAttribute('role','status');
 createPanel.append(el('p','Crée une demande pour '+context.pseudo+'. Elle apparaîtra en attente de validation.'),requestForm,requestNotice);
 for(const [name,label,type,value] of [['apples','Pommes','text','0'],['wood','Matériaux','text','0'],['object','Construction ou recherche','text',''],['level','Niveau','number','1'],['wanted_date','Date souhaitée (facultative)','date',''],['auto_production','Auto production','checkbox','']]){
  const wrap=el('label',label+' '),input=el('input');input.name=name;input.type=type;input.defaultValue=value;input.value=value;input.required=!['wanted_date','auto_production'].includes(name);wrap.style.cssText='display:block;margin:9px 0;';input.style.cssText='display:block;box-sizing:border-box;width:100%;max-width:340px;padding:5px;margin-top:4px;';
  if(name==='apples'||name==='wood'){input.inputMode='decimal';wrap.prepend(icon(name));}if(type==='checkbox'){input.style.cssText='width:auto;margin:0 6px 0 0;vertical-align:middle;accent-color:#525543;';wrap.style.cssText='display:flex;align-items:center;gap:6px;margin:9px 0;';}if(name==='object')input.maxLength=160;if(name==='level'){input.min='1';input.max='999';}wrap.append(input);requestForm.append(wrap);requestFields[name]=input;
 }
 const createButton=el('button','Créer la demande');createButton.type='submit';createButton.dataset.uptepPrimary='1';requestForm.append(createButton);
 const requestKey=key+':new-demand';
 function restoreRequest(){const raw=win.localStorage.getItem(requestKey);if(!raw)return null;const job=JSON.parse(raw);if(!job||job.action!=='convoy-create'||!job.request_key)throw Error('Demande à vérifier sur UPTEP avant une nouvelle création.');for(const [name,input] of Object.entries(requestFields)){if(input.type==='checkbox'){input.checked=job[name]===true;input.disabled=true;input.dataset.unavailable='1';}else input.value=job[name]??'';input.readOnly=true;}createButton.textContent='Réessayer la création';requestNotice.textContent='Une création attend sa confirmation. Réessaie : la même demande ne sera pas créée deux fois.';return job;}
 try{restoreRequest();}catch(e){requestNotice.textContent=e.message;}
 requestForm.addEventListener('submit',event=>{event.preventDefault();exclusive(async()=>{
  let job=restoreRequest();if(!job){job={action:'convoy-create',request_key:win.crypto.randomUUID(),confirmed:true,apples:convoyInputNumber(requestFields.apples.value),wood:convoyInputNumber(requestFields.wood.value),object:requestFields.object.value.trim(),level:requestFields.level.value,wanted_date:requestFields.wanted_date.value||null,auto_production:requestFields.auto_production.checked};if(BigInt(job.apples)+BigInt(job.wood)===0n)throw Error('Renseigne au moins une quantité positive.');if(!job.object||!/^[1-9][0-9]{0,2}$/.test(job.level))throw Error('Renseigne la construction ou recherche et un niveau de 1 à 999.');win.localStorage.setItem(requestKey,JSON.stringify(job));}
  requestNotice.textContent='Création de la demande…';
  try{const result=await api(job);win.localStorage.removeItem(requestKey);requestForm.reset();for(const input of Object.values(requestFields)){input.readOnly=false;delete input.dataset.unavailable;input.disabled=false;}createButton.textContent='Créer la demande';requestNotice.textContent='✓ Demande #'+result.demand_id+' créée, en attente de validation.';await loadPending();}
  catch(e){if(e.status===400||e.status===422){win.localStorage.removeItem(requestKey);for(const input of Object.values(requestFields)){input.readOnly=false;delete input.dataset.unavailable;input.disabled=false;}createButton.textContent='Créer la demande';}else restoreRequest();requestNotice.textContent=e.message;throw e;}
 });});
 showTab('current');
 const native=doc.querySelector('[name=pseudo_convoi]')?.closest('table.simulateur');if(native){
  const theme=win.getComputedStyle(native);
  if(theme.backgroundColor!=='rgba(0, 0, 0, 0)'&&theme.backgroundColor!=='transparent')box.style.backgroundColor=theme.backgroundColor;
  box.style.color=theme.color;box.style.fontFamily=theme.fontFamily;native.before(box);
 }else root.append(box);
 function alive(){if(stopped)throw Error('Module rechargé.');if(doc.getElementById('pseudo')?.textContent.trim()!==context.pseudo||doc.getElementById('tag_alliance')?.textContent.trim()!=='UPTEP')throw Error('Le compte connecté a changé.');}
 function read(){const raw=win.localStorage.getItem(key);if(!raw)return null;let value;try{value=JSON.parse(raw);}catch{throw Error('Suivi local illisible. Vérifie tes convois avant toute nouvelle tentative.');}if(!value||!['sending','uncertain','game-sent','done'].includes(value.state)||!value.order)throw Error('Suivi local invalide.');return value;}
 function save(record){win.localStorage.setItem(key,JSON.stringify(record));}
 function lock(value){busy=value;box.setAttribute('aria-busy',String(value));for(const node of box.querySelectorAll('input,button'))node.disabled=value||node.dataset.unavailable==='1';}
 async function exclusive(fn){if(busy||reloading)return;if(!win.navigator.locks){status.textContent='Utilise la page HTTPS dans un navigateur prenant en charge le verrouillage des envois.';return;}lock(true);try{let failure;
   const run=async held=>{try{if(!held)throw Error('Un envoi est en cours dans un autre onglet.');alive();await fn();}catch(e){failure=e;}};
   let callback=run;
   // Firefox requires an exported callback and a page-owned Promise here.
   // Keep errors in the userscript realm; release the lock only after all work settles.
   if(typeof exportFunction==='function'&&win.wrappedJSObject){
    const pageWindow=win.wrappedJSObject;
    callback=exportFunction(held=>new pageWindow.Promise(exportFunction(resolve=>{run(held).then(()=>{resolve();});},pageWindow)),pageWindow);
   }
   await win.navigator.locks.request(key,{ifAvailable:true},callback);if(failure)throw failure;}catch(e){if(!stopped)status.textContent=e.message;}finally{if(!stopped&&!reloading){lock(false);showRecovery();}}}

 function api(payload){return new Promise((resolve,reject)=>{
  if(stopped)return reject(Error('Module rechargé.'));const frame=el('iframe');frame.hidden=true;frame.title='Convois UPTEP';frame.referrerPolicy='no-referrer';frame.src='https://uptep.top/portal.php?bridge=convoys';const requestId=win.crypto.randomUUID();let timer;
  const cancel=()=>finish(Error('Module rechargé.'));function finish(error,result){win.clearTimeout(timer);win.removeEventListener('message',receive);frame.remove();pending.delete(cancel);error?reject(error):resolve(result);}
  function receive(event){if(event.origin!=='https://uptep.top'||event.source!==frame.contentWindow)return;if(event.data?.type==='uptep-ready')frame.contentWindow.postMessage({type:'uptep-auth',token:auth.token,pseudo:context.pseudo,convoy:{...payload,requestId}},'https://uptep.top');if(event.data?.type==='uptep-convoy-result'&&event.data.requestId===requestId){if(!event.data.ok)return finish(Object.assign(Error(event.data.error||'UPTEP indisponible.'),{status:event.data.status||0}));
   // Firefox/Violentmonkey must resolve a value created in this userscript realm.
   // Parse before removing the frame; never hand an external object to Promise.resolve.
   let result;try{const serialized=typeof event.data.result_json==='string'?event.data.result_json:JSON.stringify(event.data.result);result=JSON.parse(serialized);}catch{return finish(Error('Réponse UPTEP illisible. Réessaie la même opération.'));}finish(null,result);
  }}
  pending.add(cancel);win.addEventListener('message',receive);timer=win.setTimeout(()=>finish(Error('UPTEP ne répond pas.')),20000);doc.body.append(frame);
 });}
 async function game(params){alive();const controller=new win.AbortController();controllers.add(controller);const timer=win.setTimeout(()=>controller.abort(),20000);try{const r=await win.fetch(new URL('/commerce.php',win.location.href).href,{method:params?'POST':'GET',credentials:'same-origin',cache:'no-store',...(params?{headers:{'Content-Type':'application/x-www-form-urlencoded'},body:params.toString()}:{}),signal:controller.signal});if(!r.ok||new URL(r.url).origin!==win.location.origin)throw Error('Réponse du jeu indisponible.');return await r.text();}finally{win.clearTimeout(timer);controllers.delete(controller);}}
 // Keep Documents out of async return values: Firefox probes their protected `then` property.
 function gameDocument(text){return new win.DOMParser().parseFromString(text,'text/html');}
 function findSent(html,record){return readCommerceConvoys(html,win.location.href,now()).filter(r=>!record.before.includes(r.game_id)&&r.target===record.order.target&&r.apples===record.order.apples&&r.wood===record.order.wood);}
 async function declare(record){
  if(record.state!=='game-sent')throw Error('Envoi du jeu non confirmé.');status.textContent='Convoi envoyé dans le jeu. Enregistrement sur UPTEP…';
  const {anchor,...sent}=record.sent;const result=await api({action:'convoy-declare',...sent,demand_id:record.order.demand_id,sent_at:record.at,confirmed:true});
  record.state='done';record.result=result;save(record);if(!stopped){status.textContent='✓ Convoi envoyé et déclaré. Actualisation de la page…';reloading=true;win.location.replace('/commerce.php');}
 }
 async function send(order){await exclusive(async()=>{
  const existing=read();if(existing&&existing.state!=='done')throw Error('Un envoi précédent doit être vérifié ou déclaré avant de continuer.');
  status.textContent='Vérification de la demande, des ressources et des ouvrières…';const readiness=await api({action:'convoy-check',...order});alive();
  if(readiness.auto_production)throw Error('Envoi désactivé : cette demande est en auto production.');
  const excess=readiness.overage;if(excess&&(BigInt(excess.pommes)>0n||BigInt(excess.materiaux)>0n)&&!win.confirm('Attention, le montant envoyé dépasse le restant demandé de '+fmt(excess.pommes)+' pommes et '+fmt(excess.materiaux)+' matériaux. Confirmer cet envoi ?')){status.textContent='Envoi annulé. Tu peux modifier les quantités.';return;}
  const before=gameDocument(await game());transportDoc=before;const params=convoyNativeForm(before,win,context.pseudo,order);alive();
  const record={state:'sending',at:iso(),order,before:readCommerceConvoys(before,win.location.href,now()).map(r=>r.game_id)};save(record);
  try{
   status.textContent='Envoi du convoi dans Fourmizzz…';const response=gameDocument(await game(params)),matches=findSent(response,record);
   if(matches.length!==1){record.state='uncertain';save(record);throw Error('Le jeu n’a pas confirmé un convoi unique. Vérifie l’envoi ci-dessous ; aucun renvoi automatique.');}
   const {anchor,...sent}=matches[0];record.sent=sent;record.state='game-sent';save(record);transportDoc=response;await declare(record);
  }catch(e){if(record.state==='sending'){record.state='uncertain';save(record);}throw Error(e.message+(record.state==='game-sent'?' Le convoi est déjà parti : utilise « Réessayer la déclaration UPTEP ».':''));}
 });}
 function showRecovery(){
  recovery.replaceChildren();let record;try{record=read();}catch(e){status.textContent=e.message;return;}if(!record||record.state==='done')return;
  recovery.append(el('p',`Envoi à suivre : ${record.order.target} · ${fmt(record.order.apples)} pommes / ${fmt(record.order.wood)} matériaux · demande #${record.order.demand_id}.`));
  const retry=el('button',record.state==='game-sent'?'Réessayer la déclaration UPTEP':'Vérifier si le convoi est parti');retry.type='button';retry.addEventListener('click',()=>exclusive(async()=>{
   record=read();if(!record||record.state==='done')return;
   if(record.state!=='game-sent'){const html=gameDocument(await game()),matches=findSent(html,record);if(matches.length!==1)throw Error('Impossible d’identifier un seul nouveau convoi. Vérifie les convois dans le jeu et sur UPTEP. Aucun nouvel envoi effectué.');const {anchor,...sent}=matches[0];record.sent=sent;record.state='game-sent';save(record);}
   await declare(record);
  }));recovery.append(retry);
  if(record.state!=='game-sent'){const reset=el('button','J’ai vérifié : aucun convoi n’est parti');reset.type='button';reset.addEventListener('click',()=>exclusive(async()=>{const latest=read();if(!latest||latest.state==='done'||latest.state==='game-sent')throw Error('L’état a changé, recharge la page.');if(win.confirm('Après vérification dans le jeu : confirmes-tu qu’aucun convoi n’est parti ? Cette action autorise un nouvel envoi.')){win.localStorage.removeItem(key);status.textContent='Tu peux préparer un nouvel envoi.';}}));recovery.append(doc.createTextNode(' '),reset);}
 }
 async function load(){const generation=++projectionGeneration;const result=await api({action:'convoy-demands'});if(stopped||generation!==projectionGeneration)return;management?.acceptPermissions(result.permissions);list.replaceChildren();estimateRows.clear();projectionRefreshAt=Date.now();for(const d of result.demands){
  const form=el('form'),heading=el('h3',`#${d.id} · ${d.target||d.pseudo_demandeur} · ${d.objet||'Demande'}${d.niveau?' niveau '+d.niveau:''}`);form.style.cssText='padding:10px 0;border-top:1px solid #b39b61;';heading.style.cssText='margin:0 0 6px;font-size:15px;line-height:1.35;';
  const metadata=el('div');metadata.style.cssText='display:flex;flex-wrap:wrap;align-items:center;gap:4px 20px;margin:0 0 7px;';const remaining=el('p','Restant : ');remaining.style.margin='0';remaining.append(icon('apples'),doc.createTextNode(fmt(d.nbpomme_restant)+' pommes · '),icon('wood'),doc.createTextNode(fmt(d.nbmateriaux_restant)+' matériaux'));metadata.append(remaining);if(d.auto_production)heading.append(convoyAutoBadge(doc));form.append(heading,metadata);
  const players=travelData?.players||[],origin=players.find(p=>String(p.player_id)===String(travelData?.self)),target=players.find(p=>String(p.player_id)===String(d.iddemandeur))||players.find(p=>p.pseudo===d.target);
  const travel=typeof chaineTravel==='function'?chaineTravel(origin,target,travelData?.attack_speed):{seconds:null,distance:null};
  const travelLine=el('p');travelLine.className='uptep-convoy-travel';travelLine.style.cssText='display:inline-block;margin:0;padding:3px 8px;border:1px solid #887749;border-radius:10px;background:#525543;color:#fff3d3;font-size:12px;line-height:1.3;';
  if(travel.seconds!==null){travelLine.textContent='Trajet : '+chaineTravelDuration(travel.seconds);travelLine.title='Vitesse d’attaque '+travelData.attack_speed+(travelData.research_synced_at?' · niveau relevé le '+new Date(travelData.research_synced_at).toLocaleString('fr-FR',{timeZone:'Europe/Paris'}):'')+' · calcul à partir des coordonnées connues.';}
  else travelLine.textContent=travel.distance===null?'Temps de trajet indisponible : coordonnées du joueur manquantes.':'Temps de trajet indisponible : synchronise ton laboratoire ou renseigne ta vitesse d’attaque dans Mes stats.';
  metadata.append(travelLine);
  const tdcBadge=el('span'),productionBadge=el('span');tdcBadge.className='uptep-convoy-tdc';productionBadge.className='uptep-convoy-production';
  for(const badge of [tdcBadge,productionBadge])badge.style.cssText='display:inline-block;padding:3px 8px;border:1px solid #a48f54;border-radius:10px;background:#ffffff25;font-size:11px;line-height:1.3;';
  const observed=value=>value?new Date(value+(String(value).includes('T')||/[+Z]/.test(value)?'':'Z')).toLocaleString('fr-FR',{timeZone:'Europe/Paris'}):'date inconnue';
  let sendButton=null,sendNotice=null,maxButton=null;
  function updateSending(updated){if(!sendButton)return;const unavailable=updated.self||!updated.target||updated.auto_production;for(const node of [sendButton,maxButton]){if(!node)continue;node.disabled=!!unavailable||busy;if(unavailable)node.dataset.unavailable='1';else delete node.dataset.unavailable;}sendButton.title=updated.auto_production?'Envoi désactivé : auto production.':'';sendNotice.textContent=updated.auto_production?'Envoi désactivé : auto production.':'';}
  function updateProduction(updated){
   updateSending(updated);
   const estimate=convoyProductionEstimate(updated),p=updated.production,projection=p?.projection;
   remaining.replaceChildren(doc.createTextNode('Restant : '),icon('apples'),doc.createTextNode(fmt(updated.nbpomme_restant)+' pommes · '),icon('wood'),doc.createTextNode(fmt(updated.nbmateriaux_restant)+' matériaux'));
   tdcBadge.textContent=estimate.tdc===null?'TdC : inconnu':'TdC : '+fmt(estimate.tdc)+' cm²';tdcBadge.title='Dernier TdC connu · relevé du '+observed(p?.tdc_at)+'.';
   productionBadge.textContent=estimate.cycles===null?'Production : estimation indisponible':estimate.cycles===0n?'Production théorique suffisante':'Estimation : '+estimate.cycles.toLocaleString('fr-FR')+' cycle'+(estimate.cycles===1n?'':'s')+' · ≈ '+convoyProductionMinutes(estimate.minutes)+' (théorique)';
   if(p?.capacity_per_cycle?.assumed_rates||projection?.assumed_rates)productionBadge.textContent+=' · étable inconnue';
   if(estimate.finish_at!==null&&estimate.finish_at!==undefined)productionBadge.textContent+=' · fin vers '+observed(new Date(estimate.finish_at).toISOString());
   const baseline=projection?(projection.anchor_kind==='adjustment'?'Depuis l’ajustement du ':'Depuis la validation du ')+observed(projection.anchor_at)+'. Calcul au '+observed(projection.calculated_at)+', dernier export TdC : '+observed(projection.last_export_at)+'. ':'';
   productionBadge.title=(estimate.reason?estimate.reason+'. ':'')+baseline+'1 cycle = 30 min. '+(projection?'Production théorique écoulée : '+fmt(estimate.harvested??0n)+' ressources. Les convois sont déjà comptés dans le restant déclaré. ':'')+'Aucune modification du restant réel ni clôture automatique. Chaque demande est estimée séparément avec toute la capacité de récolte et une répartition ajustable entre ressources. Hors champignonnière, consommation, dépenses et entrepôts. Le TdC est supposé constant entre deux exports. '+'Base : TdC entièrement exploité (1 ressource par cm² par cycle de 30 min). Colonies internes UPTEP uniquement : le colonisé cède 20 % + 1 % par niveau d’étable du colonisateur, qui reçoit la même quantité. '+(estimate.capacity!==undefined?'Récolte nette estimée : '+fmt(estimate.capacity)+' par cycle. ':'')+(p?.capacity_per_cycle?.assumed_rates||projection?.assumed_rates?'Étable inconnue sur certaines périodes : hypothèse de 20 %, estimation approximative.':'');
  }
  updateProduction(d);estimateRows.set(String(d.id),{form,update:updateProduction});metadata.append(tdcBadge,productionBadge);

  const controls=el('div');controls.style.cssText='display:flex;flex-wrap:wrap;align-items:center;gap:6px 12px;';form.append(controls);
  const inputs={};for(const [key,label] of [['apples','Pommes'],['wood','Matériaux']]){const wrap=el('label',label+' '),input=el('input');input.name=key;input.type='text';input.inputMode='decimal';input.value='0';input.required=true;input.style.cssText='box-sizing:border-box;width:110px;max-width:100%;margin:0;';wrap.style.cssText='display:inline-flex;align-items:center;gap:4px;';wrap.prepend(icon(key));wrap.append(input);controls.append(wrap);inputs[key]=input;}
  maxButton=el('button','Max');maxButton.type='button';maxButton.title='Remplit au maximum avec tes ouvrières libres (sans découvrir ton TdC), ton stock et le restant de la demande.';controls.append(maxButton);
  const button=el('button','Envoyer');button.type='submit';button.dataset.uptepPrimary='1';sendButton=button;sendNotice=el('small');updateSending(d);controls.append(button,sendNotice);management?.decorate(controls,d,{validation:false});if(d.self)controls.append(el('small',' Ta propre demande.'));if(!d.target)controls.append(el('small',' Joueur absent du dernier relevé S5.'));
  const workforce=el('p'),workerText=el('span');workforce.append(icon('workers'),workerText);workforce.className='uptep-convoy-workers';workforce.style.cssText='margin:6px 0 0;font-size:12px;line-height:1.4;';workforce.setAttribute('aria-live','polite');form.append(workforce);
  function updateWorkforce(){
   try{
    const level=convoyGameValue(transportDoc,'bonus'),needed=convoyRequiredWorkers(convoyInputNumber(inputs.apples.value||'0'),convoyInputNumber(inputs.wood.value||'0'),level);
    workerText.textContent='Ouvrières mobilisées : '+fmt(needed)+' · Étable à pucerons niveau '+String(level)+'.';
    try{const available=convoyGameValue(transportDoc,'max_ouvriere');if(needed>available)workerText.textContent+=' Ouvrières insuffisantes ('+fmt(available)+' présentes).';}catch{}
   }catch(e){workerText.textContent='Ouvrières mobilisées : calcul indisponible. Vérifie les quantités ou recharge la page du jeu.';}
  }
  const maxInfo=el('p');maxInfo.className='uptep-convoy-max';maxInfo.style.cssText='margin:4px 0 0;font-size:12px;line-height:1.4;';maxInfo.setAttribute('aria-live','polite');maxInfo.hidden=true;form.append(maxInfo);
  const showMax=text=>{maxInfo.textContent=text;maxInfo.hidden=!text;};
  for(const input of Object.values(inputs)){input.addEventListener('input',()=>{updateWorkforce();showMax('');});input.addEventListener('change',updateWorkforce);}updateWorkforce();
  maxButton.addEventListener('click',async()=>{
   if(busy)return;maxButton.disabled=true;showMax('Lecture de tes ressources et ouvrières dans le jeu…');
   try{
    // Fresh stocks and workers from the game; the page loaded earlier may be minutes old.
    try{transportDoc=gameDocument(await game());}catch{}
    const value=name=>convoyGameValue(transportDoc,name),stock={apples:value('max_nourriture'),wood:value('max_materiaux')};
    const r=convoyMaxOrder({...stock,workers:value('max_ouvriere'),terrain:value('terrain'),level:value('bonus'),needApples:d.nbpomme_restant,needWood:d.nbmateriaux_restant});
    inputs.apples.value=String(r.apples);inputs.wood.value=String(r.wood);updateWorkforce();
    showMax({done:'Rien à envoyer : la demande est déjà couverte.',
     demand:'Max : tout le restant de la demande, dans la limite de tes '+fmt(r.free)+' ouvrières libres.',
     stock:'Max : limité par ton stock ('+fmt(stock.apples)+' pommes · '+fmt(stock.wood)+' matériaux).',
     workers:r.free>0n?'Max : limité par tes '+fmt(r.free)+' ouvrières libres sans découvrir ton TdC → '+fmt(r.capacity)+' ressources au plus.':'Aucune ouvrière libre : toutes tes ouvrières exploitent ton TdC.'}[r.limit]);
   }catch(e){showMax(e.message);}
   finally{maxButton.disabled=busy||maxButton.dataset.unavailable==='1';}
  });
  form.addEventListener('submit',event=>{event.preventDefault();try{const order={demand_id:String(d.id),target:d.target,apples:convoyInputNumber(inputs.apples.value),wood:convoyInputNumber(inputs.wood.value)};if(BigInt(order.apples)+BigInt(order.wood)===0n)throw Error('Renseigne une quantité à envoyer.');send(order);}catch(e){status.textContent=e.message;}});list.append(form);
 }if(!result.demands.length)list.append(el('p','Aucune demande validée en cours.'));}
 // Refresh hourly without replacing forms, amounts being typed or the open editor.
 async function refreshProjections(){
  if(stopped||busy||projectionRefreshing||Date.now()-projectionRefreshAt<3600000)return;projectionRefreshing=true;const generation=projectionGeneration;
  try{const result=await api({action:'convoy-demands'});if(stopped||generation!==projectionGeneration)return;const available=new Set();for(const d of result.demands){available.add(String(d.id));estimateRows.get(String(d.id))?.update(d);}for(const [id,view] of estimateRows)if(!available.has(id)){view.form.remove();estimateRows.delete(id);}projectionRefreshAt=Date.now();}
  catch{if(!stopped)for(const view of estimateRows.values()){const badge=view.form.querySelector('.uptep-convoy-production');if(badge&&!badge.textContent.includes('à actualiser'))badge.textContent+=' · à actualiser';}}
  finally{projectionRefreshing=false;}
 }
 const projectionTimer=win.setInterval(refreshProjections,3600000);win.addEventListener('focus',refreshProjections);
 refresh.addEventListener('click',()=>exclusive(async()=>{await load();if(activeTab==='pending')await loadPending();else if(!['current','create'].includes(activeTab))await management?.refresh(activeTab);}));showRecovery();load().catch(e=>{if(!stopped)status.textContent=e.message;});
 return ()=>{stopped=true;win.clearInterval(projectionTimer);win.removeEventListener('focus',refreshProjections);management?.cleanup();for(const cancel of [...pending])cancel();for(const controller of controllers)controller.abort();box.remove();};
}
