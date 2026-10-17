// Read semantic lines rather than layout classes: standard/C+ pages can use
// strong/span, nested markup, images or plain resource labels, and table rows.
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
 const key='uptep-convoys-s5:'+context.pseudo,pending=new Set(),controllers=new Set();let stopped=false,busy=false,reloading=false,transportDoc=doc;
 const base=Date.parse(serverTime)||Date.now(),started=win.performance.now(),now=()=>base+win.performance.now()-started,iso=()=>new Date(now()).toISOString().replace(/\.\d{3}Z$/,'Z');
 const el=(tag,text)=>{const n=doc.createElement(tag);if(text!==undefined)n.textContent=text;return n;},fmt=n=>new Intl.NumberFormat('fr-FR').format(BigInt(n));
 const icon=kind=>{const image=el('img');image.src='/images/icone/'+({apples:'icone_pomme.png',wood:'icone_bois.png',workers:'icone_ouvriere.png'}[kind]);image.alt='';image.setAttribute('aria-hidden','true');image.width=kind==='workers'?25:20;image.style.cssText='height:auto;vertical-align:middle;margin-right:5px;';return image;};
 const box=el('section');box.id='uptep-convoys';box.dataset.uptepConvoyUi='1';box.style.cssText='box-sizing:border-box;margin:18px auto;padding:16px;max-width:900px;background:#d7c384;color:#000;border:1px solid #887749;border-radius:0;font:13px Verdana;';
 const title=el('h2','Demandes de convois UPTEP'),status=el('p'),refresh=el('button','Actualiser les demandes'),recovery=el('div'),list=el('div');refresh.type='button';status.setAttribute('role','status');
 box.append(title,el('p','Choisis les quantités puis clique sur Envoyer : le convoi part dans le jeu et sa déclaration UPTEP suit automatiquement. Les ouvrières au travail peuvent être utilisées, comme dans le formulaire du jeu.'),refresh,status,recovery,list);
 const tabs=el('div'),waiting=el('div'),createPanel=el('div'),tabButtons={};tabs.setAttribute('role','tablist');tabs.setAttribute('aria-label','Demandes de convois');tabs.style.cssText='display:flex;flex-wrap:wrap;gap:5px;margin:14px 0;border-bottom:1px solid #887749;padding-bottom:7px;';
 const panels={current:list,pending:waiting,create:createPanel};let activeTab='current',pendingVersion=0;
 for(const [id,label] of [['current','Demandes en cours'],['pending','En attente de validation'],['create','Faire une demande']]){
  const button=el('button',label);button.type='button';button.id='uptep-convoys-tab-'+id;button.setAttribute('role','tab');button.setAttribute('aria-controls','uptep-convoys-panel-'+id);button.style.cssText='padding:8px 12px;border:1px solid #887749;cursor:pointer;';
  tabButtons[id]=button;const panel=panels[id];panel.id='uptep-convoys-panel-'+id;panel.setAttribute('role','tabpanel');panel.setAttribute('aria-labelledby',button.id);button.addEventListener('click',()=>showTab(id));
  button.addEventListener('keydown',event=>{if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;event.preventDefault();const ids=Object.keys(panels),index=ids.indexOf(id),next=event.key==='Home'?0:event.key==='End'?2:(index+(event.key==='ArrowRight'?1:2))%3;showTab(ids[next]);tabButtons[ids[next]].focus();});tabs.append(button);
 }
 list.before(tabs);box.append(waiting,createPanel);
 function showTab(id){if(busy)return;activeTab=id;for(const key of Object.keys(panels)){panels[key].hidden=key!==id;tabButtons[key].setAttribute('aria-selected',String(key===id));tabButtons[key].tabIndex=key===id?0:-1;tabButtons[key].style.background=key===id?'#525543':'#d7c384';tabButtons[key].style.color=key===id?'#d3d9b8':'#302b18';}refresh.hidden=id==='create';if(id==='pending')loadPending().catch(e=>{if(!stopped)status.textContent=e.message;});}
 async function loadPending(){const version=++pendingVersion;waiting.textContent='Chargement…';const data=await api({action:'convoy-pending'});if(stopped||version!==pendingVersion)return;waiting.replaceChildren();for(const d of data.demands){const row=el('article');row.style.cssText='padding:12px 0;border-bottom:1px solid #b39b61;';row.append(el('h3',`#${d.id} · ${d.target||d.pseudo_demandeur} · ${d.objet||'Demande'}${d.niveau?' niveau '+d.niveau:''}`));const amounts=el('p');amounts.append(icon('apples'),doc.createTextNode(fmt(d.nbpomme_restant)+' pommes · '),icon('wood'),doc.createTextNode(fmt(d.nbmateriaux_restant)+' matériaux'));row.append(amounts,el('p','En attente de validation — envoi indisponible.'));waiting.append(row);}if(!data.demands.length)waiting.append(el('p','Aucune demande en attente de validation.'));}
 const requestForm=el('form'),requestFields={},requestNotice=el('p');requestForm.id='uptep-convoy-request-form';requestNotice.setAttribute('role','status');
 createPanel.append(el('p','Crée une demande pour '+context.pseudo+'. Elle apparaîtra en attente de validation.'),requestForm,requestNotice);
 for(const [name,label,type,value] of [['apples','Pommes','text','0'],['wood','Matériaux','text','0'],['object','Construction ou recherche','text',''],['level','Niveau','number','1'],['wanted_date','Date souhaitée (facultative)','date','']]){
  const wrap=el('label',label+' '),input=el('input');input.name=name;input.type=type;input.defaultValue=value;input.value=value;input.required=name!=='wanted_date';wrap.style.cssText='display:block;margin:9px 0;';input.style.cssText='display:block;box-sizing:border-box;width:100%;max-width:340px;padding:5px;margin-top:4px;';
  if(name==='apples'||name==='wood'){input.inputMode='decimal';wrap.prepend(icon(name));}if(name==='object')input.maxLength=160;if(name==='level'){input.min='1';input.max='999';}wrap.append(input);requestForm.append(wrap);requestFields[name]=input;
 }
 const createButton=el('button','Créer la demande');createButton.type='submit';requestForm.append(createButton);
 const requestKey=key+':new-demand';
 function restoreRequest(){const raw=win.localStorage.getItem(requestKey);if(!raw)return null;const job=JSON.parse(raw);if(!job||job.action!=='convoy-create'||!job.request_key)throw Error('Demande à vérifier sur UPTEP avant une nouvelle création.');for(const [name,input] of Object.entries(requestFields)){input.value=job[name]??'';input.readOnly=true;}createButton.textContent='Réessayer la création';requestNotice.textContent='Une création attend sa confirmation. Réessaie : la même demande ne sera pas créée deux fois.';return job;}
 try{restoreRequest();}catch(e){requestNotice.textContent=e.message;}
 requestForm.addEventListener('submit',event=>{event.preventDefault();exclusive(async()=>{
  let job=restoreRequest();if(!job){job={action:'convoy-create',request_key:win.crypto.randomUUID(),confirmed:true,apples:convoyInputNumber(requestFields.apples.value),wood:convoyInputNumber(requestFields.wood.value),object:requestFields.object.value.trim(),level:requestFields.level.value,wanted_date:requestFields.wanted_date.value||null};if(BigInt(job.apples)+BigInt(job.wood)===0n)throw Error('Renseigne au moins une quantité positive.');if(!job.object||!/^[1-9][0-9]{0,2}$/.test(job.level))throw Error('Renseigne la construction ou recherche et un niveau de 1 à 999.');win.localStorage.setItem(requestKey,JSON.stringify(job));}
  requestNotice.textContent='Création de la demande…';
  try{const result=await api(job);win.localStorage.removeItem(requestKey);requestForm.reset();for(const input of Object.values(requestFields))input.readOnly=false;createButton.textContent='Créer la demande';requestNotice.textContent='✓ Demande #'+result.demand_id+' créée, en attente de validation.';await loadPending();}
  catch(e){if(e.status===400||e.status===422){win.localStorage.removeItem(requestKey);for(const input of Object.values(requestFields))input.readOnly=false;createButton.textContent='Créer la demande';}else restoreRequest();requestNotice.textContent=e.message;throw e;}
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
 function lock(value){busy=value;for(const node of box.querySelectorAll('input,button'))node.disabled=value||node.dataset.unavailable==='1';}
 async function exclusive(fn){if(busy||reloading)return;if(!win.navigator.locks){status.textContent='Utilise la page HTTPS dans un navigateur prenant en charge le verrouillage des envois.';return;}lock(true);try{await win.navigator.locks.request(key,{ifAvailable:true},async held=>{if(!held)throw Error('Un envoi est en cours dans un autre onglet.');alive();await fn();});}catch(e){if(!stopped)status.textContent=e.message;}finally{if(!stopped&&!reloading){lock(false);showRecovery();}}}
 function api(payload){return new Promise((resolve,reject)=>{
  if(stopped)return reject(Error('Module rechargé.'));const frame=el('iframe');frame.hidden=true;frame.title='Convois UPTEP';frame.referrerPolicy='no-referrer';frame.src='https://uptep.top/portal.php?bridge=convoys';const requestId=win.crypto.randomUUID();let timer;
  const cancel=()=>finish(Error('Module rechargé.'));function finish(error,result){win.clearTimeout(timer);win.removeEventListener('message',receive);frame.remove();pending.delete(cancel);error?reject(error):resolve(result);}
  function receive(event){if(event.origin!=='https://uptep.top'||event.source!==frame.contentWindow)return;if(event.data?.type==='uptep-ready')frame.contentWindow.postMessage({type:'uptep-auth',token:auth.token,pseudo:context.pseudo,convoy:{...payload,requestId}},'https://uptep.top');if(event.data?.type==='uptep-convoy-result'&&event.data.requestId===requestId)finish(event.data.ok?null:Object.assign(Error(event.data.error||'UPTEP indisponible.'),{status:event.data.status||0}),event.data.result);}
  pending.add(cancel);win.addEventListener('message',receive);timer=win.setTimeout(()=>finish(Error('UPTEP ne répond pas.')),20000);doc.body.append(frame);
 });}
 async function game(params){alive();const controller=new win.AbortController();controllers.add(controller);const timer=win.setTimeout(()=>controller.abort(),20000);try{const r=await win.fetch('/commerce.php',{method:params?'POST':'GET',credentials:'same-origin',cache:'no-store',...(params?{headers:{'Content-Type':'application/x-www-form-urlencoded'},body:params.toString()}:{}),signal:controller.signal});if(!r.ok||new URL(r.url).origin!==win.location.origin)throw Error('Réponse du jeu indisponible.');return new win.DOMParser().parseFromString(await r.text(),'text/html');}finally{win.clearTimeout(timer);controllers.delete(controller);}}
 function findSent(html,record){return readCommerceConvoys(html,win.location.href,now()).filter(r=>!record.before.includes(r.game_id)&&r.target===record.order.target&&r.apples===record.order.apples&&r.wood===record.order.wood);}
 async function declare(record){
  if(record.state!=='game-sent')throw Error('Envoi du jeu non confirmé.');status.textContent='Convoi envoyé dans le jeu. Enregistrement sur UPTEP…';
  const {anchor,...sent}=record.sent;const result=await api({action:'convoy-declare',...sent,demand_id:record.order.demand_id,sent_at:record.at,confirmed:true});
  record.state='done';record.result=result;save(record);if(!stopped){status.textContent='✓ Convoi envoyé et déclaré. Actualisation de la page…';reloading=true;win.location.replace('/commerce.php');}
 }
 async function send(order){await exclusive(async()=>{
  const existing=read();if(existing&&existing.state!=='done')throw Error('Un envoi précédent doit être vérifié ou déclaré avant de continuer.');
  status.textContent='Vérification de la demande, des ressources et des ouvrières…';await api({action:'convoy-check',...order});alive();
  const before=await game();transportDoc=before;const params=convoyNativeForm(before,win,context.pseudo,order);alive();
  const record={state:'sending',at:iso(),order,before:readCommerceConvoys(before,win.location.href,now()).map(r=>r.game_id)};save(record);
  try{
   status.textContent='Envoi du convoi dans Fourmizzz…';const response=await game(params),matches=findSent(response,record);
   if(matches.length!==1){record.state='uncertain';save(record);throw Error('Le jeu n’a pas confirmé un convoi unique. Vérifie l’envoi ci-dessous ; aucun renvoi automatique.');}
   const {anchor,...sent}=matches[0];record.sent=sent;record.state='game-sent';save(record);transportDoc=response;await declare(record);
  }catch(e){if(record.state==='sending'){record.state='uncertain';save(record);}throw Error(e.message+(record.state==='game-sent'?' Le convoi est déjà parti : utilise « Réessayer la déclaration UPTEP ».':''));}
 });}
 function showRecovery(){
  recovery.replaceChildren();let record;try{record=read();}catch(e){status.textContent=e.message;return;}if(!record||record.state==='done')return;
  recovery.append(el('p',`Envoi à suivre : ${record.order.target} · ${fmt(record.order.apples)} pommes / ${fmt(record.order.wood)} matériaux · demande #${record.order.demand_id}.`));
  const retry=el('button',record.state==='game-sent'?'Réessayer la déclaration UPTEP':'Vérifier si le convoi est parti');retry.type='button';retry.addEventListener('click',()=>exclusive(async()=>{
   record=read();if(!record||record.state==='done')return;
   if(record.state!=='game-sent'){const html=await game(),matches=findSent(html,record);if(matches.length!==1)throw Error('Impossible d’identifier un seul nouveau convoi. Vérifie les convois dans le jeu et sur UPTEP. Aucun nouvel envoi effectué.');const {anchor,...sent}=matches[0];record.sent=sent;record.state='game-sent';save(record);}
   await declare(record);
  }));recovery.append(retry);
  if(record.state!=='game-sent'){const reset=el('button','J’ai vérifié : aucun convoi n’est parti');reset.type='button';reset.addEventListener('click',()=>exclusive(async()=>{const latest=read();if(!latest||latest.state==='done'||latest.state==='game-sent')throw Error('L’état a changé, recharge la page.');if(win.confirm('Après vérification dans le jeu : confirmes-tu qu’aucun convoi n’est parti ? Cette action autorise un nouvel envoi.')){win.localStorage.removeItem(key);status.textContent='Tu peux préparer un nouvel envoi.';}}));recovery.append(doc.createTextNode(' '),reset);}
 }
 async function load(){const result=await api({action:'convoy-demands'});if(stopped)return;list.replaceChildren();for(const d of result.demands){
  const form=el('form'),heading=el('h3',`#${d.id} · ${d.target||d.pseudo_demandeur} · ${d.objet||'Demande'}${d.niveau?' niveau '+d.niveau:''}`);form.style.cssText='padding:12px 0;border-top:1px solid #b39b61;';
  const remaining=el('p','Restant : ');remaining.append(icon('apples'),doc.createTextNode(fmt(d.nbpomme_restant)+' pommes · '),icon('wood'),doc.createTextNode(fmt(d.nbmateriaux_restant)+' matériaux'));form.append(heading,remaining);
  const players=travelData?.players||[],origin=players.find(p=>String(p.player_id)===String(travelData?.self)),target=players.find(p=>String(p.player_id)===String(d.iddemandeur))||players.find(p=>p.pseudo===d.target);
  const travel=typeof chaineTravel==='function'?chaineTravel(origin,target,travelData?.attack_speed):{seconds:null,distance:null};
  const travelLine=el('p');travelLine.className='uptep-convoy-travel';
  if(travel.seconds!==null){travelLine.textContent='Trajet aller : '+chaineTravelDuration(travel.seconds);travelLine.title='Vitesse d’attaque '+travelData.attack_speed+(travelData.research_synced_at?' · niveau relevé le '+new Date(travelData.research_synced_at).toLocaleString('fr-FR',{timeZone:'Europe/Paris'}):'')+' · calcul à partir des coordonnées connues.';}
  else travelLine.textContent=travel.distance===null?'Temps de trajet indisponible : coordonnées du joueur manquantes.':'Temps de trajet indisponible : synchronise ton laboratoire ou renseigne ta vitesse d’attaque dans Mes stats.';
  form.append(travelLine);
  const inputs={};for(const [key,label] of [['apples','Pommes'],['wood','Matériaux']]){const wrap=el('label',label+' '),input=el('input');input.name=key;input.type='text';input.inputMode='decimal';input.value='0';input.required=true;input.style.cssText='width:130px;max-width:100%;margin:4px 12px 4px 0;';wrap.prepend(icon(key));wrap.append(input);form.append(wrap);inputs[key]=input;}
  const button=el('button','Envoyer');button.type='submit';button.disabled=d.self||!d.target||busy;if(d.self||!d.target)button.dataset.unavailable='1';form.append(button);if(d.self)form.append(el('small',' Ta propre demande.'));if(!d.target)form.append(el('small',' Joueur absent du dernier relevé S5.'));
  const workforce=el('p'),workerText=el('span');workforce.append(icon('workers'),workerText);workforce.className='uptep-convoy-workers';workforce.setAttribute('aria-live','polite');form.append(workforce);
  function updateWorkforce(){
   try{
    const level=convoyGameValue(transportDoc,'bonus'),needed=convoyRequiredWorkers(convoyInputNumber(inputs.apples.value||'0'),convoyInputNumber(inputs.wood.value||'0'),level);
    workerText.textContent='Ouvrières mobilisées : '+fmt(needed)+' · Étable à pucerons niveau '+String(level)+'.';
    try{const available=convoyGameValue(transportDoc,'max_ouvriere');if(needed>available)workerText.textContent+=' Ouvrières insuffisantes ('+fmt(available)+' présentes).';}catch{}
   }catch(e){workerText.textContent='Ouvrières mobilisées : calcul indisponible. Vérifie les quantités ou recharge la page du jeu.';}
  }
  for(const input of Object.values(inputs)){input.addEventListener('input',updateWorkforce);input.addEventListener('change',updateWorkforce);}updateWorkforce();
  form.addEventListener('submit',event=>{event.preventDefault();try{const order={demand_id:String(d.id),target:d.target,apples:convoyInputNumber(inputs.apples.value),wood:convoyInputNumber(inputs.wood.value)};if(BigInt(order.apples)+BigInt(order.wood)===0n)throw Error('Renseigne une quantité à envoyer.');send(order);}catch(e){status.textContent=e.message;}});list.append(form);
 }if(!result.demands.length)list.append(el('p','Aucune demande validée en cours.'));}
 refresh.addEventListener('click',()=>exclusive(activeTab==='pending'?loadPending:load));showRecovery();load().catch(e=>{if(!stopped)status.textContent=e.message;});
 return ()=>{stopped=true;for(const cancel of [...pending])cancel();for(const controller of controllers)controller.abort();box.remove();};
}
