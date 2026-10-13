// Personal preparation stays in this game tab. No session token is persisted.
function multifloodNumber(value,optional=false){
 const s=String(value??'').replace(/[\s\u00a0\u202f]/g,'');
 if(optional&&s==='')return null;
 if(!/^\d+$/.test(s)||s.length>19||BigInt(s)>9223372036854775807n)throw Error('Saisis un nombre entier positif, sans décimales.');
 return BigInt(s);
}
function multifloodRange(a,b){return a>0n&&b>0n&&b*2n>=a&&b<=a*3n;}
function multifloodRounded(n,level){
 if(![1,2,3].includes(Number(level)))return n;
 const s=String(n),exponent=s.length-Number(level);if(exponent<0)return n;
 const step=10n**BigInt(exponent)*BigInt(Number(s[0])===1?10:Number(s[0])<5?25:50);
 return (n*10n/step)*step/10n;
}
function multifloodPlan(input){
 const min=(...v)=>v.reduce((a,b)=>a<b?a:b),positive=n=>n>0n?n:0n;
 let own=multifloodNumber(input.self.field),army=multifloodNumber(input.army),slots=Number(input.slots),gained=0n;
 if(!Number.isSafeInteger(slots)||slots<0||slots>1000)throw Error('Nombre d’attaques disponibles invalide.');
 const initial=own,globalMax=multifloodNumber(input.self.max,true),globalFinal=multifloodNumber(input.self.final,true);
 const excluded=new Set(input.excluded||[]),seen=new Set(),groups=[];
 const targets=[...input.targets].sort((a,b)=>(a.distance??Infinity)-(b.distance??Infinity));
 for(const target of targets){
  if(seen.has(String(target.id)))continue;seen.add(String(target.id));
  let field=multifloodNumber(target.field);const original=field;
  const group={target,rows:[],gain:0n,final:field,reason:''};groups.push(group);
  if(target.blocked){group.reason='Joueur indisponible.';continue;}
  if(!multifloodRange(own,field)){group.reason='Hors de portée avec le terrain prévu à cette étape.';continue;}
  const threshold=(own+field)*2n/3n,limit=positive(threshold-own)+(own+field-threshold)/5n;
  const max=multifloodNumber(target.max,true),final=multifloodNumber(target.final,true);
  let goal=min(limit,army);
  for(const bound of [max,final===null?null:positive(field-final),globalMax===null?null:positive(globalMax-gained),globalFinal===null?null:positive(globalFinal-own)])if(bound!==null)goal=min(goal,bound);
  goal=multifloodRounded(goal,target.rounding??4);
  // Count the full series first. Unchecking a row removes its effects, without
  // silently replacing it with an extra attack. Later rows use the new terrain.
  function next(a,b,left,remainingSlots){
   let amount=min(b/5n,left);
   const bridge=(2n*b-a)/3n;
   if(remainingSlots>1&&bridge>0n&&bridge<amount&&left>amount)amount=bridge;
   return amount;
  }
  let va=own,vb=field,left=goal,count=0;
  while(left>0n&&count<slots&&multifloodRange(va,vb)){
   const amount=next(va,vb,left,slots-count);if(amount<=0n)break;
   va+=amount;vb-=amount;left-=amount;count++;
  }
  for(let i=0;i<count;i++){
   if(!multifloodRange(own,field))break;
   const amount=next(own,field,goal-group.gain,count-i);if(amount<=0n)break;
   const key=target.id+':'+i,checked=!excluded.has(key);
   group.rows.push({key,id:String(target.id),pseudo:target.pseudo,amount,own,field,checked,percent:Number(amount*10000n/field)/100});
   if(checked){own+=amount;field-=amount;army-=amount;slots--;gained+=amount;group.gain+=amount;}
  }
  group.final=field;
  if(!group.rows.length)group.reason=slots===0?'Aucune attaque disponible.':army===0n?'Aucune fourmi disponible.':original===final?'Terrain final déjà atteint.':'Objectif atteint ou prise nulle.';
 }
 return {groups,initial,final:own,gain:gained,armyLeft:army,slotsLeft:slots,attacks:groups.flatMap(g=>g.rows).filter(r=>r.checked)};
}
function readMultifloodMembers(doc,data,base){
 const norm=s=>s.normalize('NFD').replace(/[\u0300-\u036f]/g,'').trim().toLowerCase();
 const column=(row,cell)=>{let i=0;for(const c of row.cells){if(c===cell)return i;i+=c.colSpan;}return -1;};
 const at=(row,index)=>{let i=0;for(const c of row.cells){if(i<=index&&index<i+c.colSpan)return c;i+=c.colSpan;}return null;};
 const saved=new Map(data.players.map(p=>[p.pseudo,p])),origin=data.players.find(p=>String(p.player_id)===String(data.self));
 for(const table of doc.querySelectorAll('#centre table')){
  const head=[...table.rows].find(r=>['pseudo','terrain'].every(label=>[...r.cells].some(c=>norm(c.textContent)===label)));if(!head)continue;
  const pcol=column(head,[...head.cells].find(c=>norm(c.textContent)==='pseudo')),tcol=column(head,[...head.cells].find(c=>norm(c.textContent)==='terrain'));
  const players=[];
  for(const row of table.rows){
   if(row===head)continue;const pc=at(row,pcol),tc=at(row,tcol);if(!pc||!tc)continue;
   let pseudo='';for(const link of pc.querySelectorAll('a[href]')){const url=new URL(link.getAttribute('href'),base);pseudo=url.searchParams.get('Pseudo')||url.searchParams.get('pseudo')||'';if(pseudo)break;}
   const player=saved.get(pseudo);if(!player)continue;
   let field;try{field=String(multifloodNumber(tc.textContent));}catch{continue;}
   const hints=norm([...row.querySelectorAll('img')].map(n=>(n.alt||'')+' '+(n.title||'')+' '+(n.getAttribute('src')||'')).join(' '));
   const blocked=/vacanc/.test(hints)||!!player.on_holiday;
   const travel=chaineTravel(origin,player,data.attack_speed);
   players.push({id:String(player.player_id),pseudo,field,blocked,x:player.x,y:player.y,distance:travel.distance,row});
  }
  const self=players.find(p=>p.id===String(data.self));if(self)return {table,head,players,self};
 }
 throw Error('Le tableau des membres ou ton terrain n’a pas pu être lu.');
}
function readMultifloodNative(doc,pseudo,id){
 const value=name=>doc.getElementById(name)?.value;
 if(value('joueur0')!==pseudo||value('joueur0ID')!==String(id))throw Error('Le compte du jeu a changé ou la session a expiré.');
 const army=String(multifloodNumber(value('armee'))),slots=Number(multifloodNumber(value('joueur0attaquesDispo'))),speed=Number(multifloodNumber(value('joueur0VA')));
 const token=value('t');if(!token||!Number.isSafeInteger(slots)||slots>1000||speed>999)throw Error('Disponibilités du jeu non reconnues.');
 return {army,slots,speed,token,field:String(multifloodNumber(value('joueur0TDC')))};
}
function mountMultiflood(context,data){
 const doc=context.document,win=context.window;
 if(!data||!Array.isArray(data.players)||win.location.pathname.toLowerCase()!=='/alliance.php')return ()=>{};
 const params=new URLSearchParams(win.location.search),isPage=params.get('uptepMultiflood')==='1',isMembers=params.has('Membres');
 if(!isPage&&!isMembers)return ()=>{};
 for(const [key,value] of params)if(/alliance|tag/i.test(key)&&value&&value!=='UPTEP')return ()=>{};
 const key='uptep:multiflood:'+encodeURIComponent(context.pseudo)+':',pageURL='/alliance.php?uptepMultiflood=1';
 const make=(tag,text,parent)=>{const n=doc.createElement(tag);if(text!==null)n.textContent=text;if(parent)parent.append(n);return n;};
 const button=(text,parent,click)=>{const b=make('button',text,parent);b.type='button';b.addEventListener('click',click);return b;};
 const fmt=n=>BigInt(n).toLocaleString('fr-FR');
 const read=name=>{try{return JSON.parse(win.sessionStorage.getItem(key+name));}catch{return null;}};
 const write=(name,value)=>{try{win.sessionStorage.setItem(key+name,JSON.stringify(value));}catch{throw Error('Le navigateur bloque la mémorisation de cette préparation.');}};
 const cleanPlayer=p=>({id:p.id,pseudo:p.pseudo,field:p.field,blocked:p.blocked,x:p.x,y:p.y,distance:p.distance});
 let disposed=false,timer=null;const controllers=new Set(),added=new Set(),hidden=new Map();
 const style=make('style',`#uptep-multiflood{font:12px/1.5 Verdana,sans-serif;color:#302b18;padding:15px 10px;text-align:left;max-width:100%;box-sizing:border-box}#uptep-multiflood *{box-sizing:border-box}#uptep-multiflood h2{font-size:21px;margin:8px 0}#uptep-multiflood h3{font-size:14px;margin:15px 0 8px}#uptep-multiflood button,.uptep-mf-tools button,.uptep-mf-cell button{cursor:pointer;width:auto;background:#4f5940;color:#fff4d3;border:1px solid #465037;border-radius:3px;padding:6px 10px;font:11px Verdana,sans-serif}#uptep-multiflood button:disabled,.uptep-mf-tools button:disabled,.uptep-mf-cell button:disabled{opacity:.5;cursor:default}#uptep-multiflood .uptep-mf-toolbar,.uptep-mf-tools{display:flex;align-items:center;flex-wrap:wrap;gap:10px;margin:10px 0}#uptep-multiflood fieldset{padding:0;margin:0;border:0;min-width:0}#uptep-multiflood .uptep-mf-scroll{overflow-x:auto;max-width:100%;margin:12px 0}#uptep-multiflood table{border-collapse:collapse;width:100%;font:12px Verdana,sans-serif}#uptep-multiflood th,#uptep-multiflood td{border:1px solid #a59259;padding:8px;text-align:center;white-space:nowrap}#uptep-multiflood th{background:#bda364;color:#302b18}#uptep-multiflood td{background:#e0d3a2}#uptep-multiflood input:not([type=checkbox]),#uptep-multiflood select{width:125px;max-width:100%;padding:5px;border:1px solid #a59259;border-radius:2px;background:#f3ebce;color:#302b18;font:12px Verdana,sans-serif;text-align:center}#uptep-multiflood input[readonly]{background:#d6c58e}#uptep-multiflood label{cursor:pointer}#uptep-multiflood .uptep-mf-options[hidden]{display:none}#uptep-multiflood .uptep-mf-summary{padding:12px;background:#d4ddba;border-left:4px solid #4f5940;margin:12px 0;white-space:pre-line}#uptep-multiflood .uptep-mf-note{font-size:11px;color:#655a36}#uptep-multiflood .uptep-mf-status{margin:10px 0;padding:10px;background:#e7d8a5;border-left:3px solid #91702a;white-space:pre-line}#uptep-multiflood .uptep-mf-status:empty{display:none}#uptep-multiflood a{color:#385848}.uptep-mf-cell{text-align:center!important;width:38px!important;white-space:nowrap;padding:2px 4px!important}.uptep-mf-cell input{cursor:pointer}.uptep-mf-cell button{padding:4px 6px}.uptep-mf-tools{font:12px Verdana,sans-serif}#uptep-multiflood .uptep-mf-unchecked td{opacity:.55}#uptep-multiflood :focus-visible,.uptep-mf-tools :focus-visible,.uptep-mf-cell :focus-visible{outline:2px solid #316276;outline-offset:2px}`,doc.head);
 let current=null;const savedSelection=read('selection'),selected=new Set(Array.isArray(savedSelection)?savedSelection:[]);
 const tools=isMembers?make('div',null):null;if(tools)tools.className='uptep-mf-tools';
 let selectButton,message;
 if(tools){
  selectButton=button('Multiflood la sélection',tools,()=>open([...selected]));
  button('Tout cocher à portée',tools,()=>{if(!current)return;for(const p of current.players)if(eligible(p,current.self))selected.add(p.id);saveSelection();updateMembers();});
  button('Tout décocher',tools,()=>{selected.clear();saveSelection();updateMembers();});message=make('span','',tools);
 }
 const eligible=(p,self)=>p.id!==self.id&&!p.blocked&&multifloodRange(BigInt(self.field),BigInt(p.field));
 function saveSelection(){try{write('selection',[...selected]);}catch(e){message.textContent=e.message;}}
 function open(ids){
  try{
   const members=readMultifloodMembers(doc,data,win.location.href),targets=members.players.filter(p=>ids.includes(p.id)&&eligible(p,members.self));
   if(!targets.length)throw Error('Sélectionne au moins un joueur à portée.');
   write('draft',{self:cleanPlayer(members.self),targets:targets.map(cleanPlayer),observed:Date.now(),excluded:[],options:false});
   // A previous uncertain submission must be reviewed on the preparation page.
   win.location.assign(pageURL);
  }catch(e){message.textContent=e.message;}
 }
 function updateMembers(){
  if(disposed)return;
  try{current=readMultifloodMembers(doc,data,win.location.href);}catch{tools?.remove();return;}
  const {table,head,self,players}=current;if(tools.nextSibling!==table)table.before(tools);
  for(const [name,label] of [['select','Choix'],['launch','Multiflood']])if(!head.querySelector('[data-mf-column="'+name+'"]')){const th=make('th',label,head);th.className='uptep-mf-cell sorter-false';th.dataset.mfColumn=name;th.scope='col';th.setAttribute('data-sorter','false');added.add(th);}
  for(const p of players){
   const enabled=eligible(p,self);if(!enabled)selected.delete(p.id);
   let select=p.row.querySelector('.uptep-mf-select');
   if(!select){const td=make('td',null,p.row);td.className='uptep-mf-cell';added.add(td);select=make('input',null,td);select.type='checkbox';select.className='uptep-mf-select';select.setAttribute('aria-label','Sélectionner '+p.pseudo);select.addEventListener('change',()=>{if(select.checked)selected.add(p.id);else selected.delete(p.id);saveSelection();updateMembers();});}
   select.disabled=!enabled;select.checked=selected.has(p.id);
   let launch=p.row.querySelector('.uptep-mf-launch');
   if(!launch){const td=make('td',null,p.row);td.className='uptep-mf-cell';added.add(td);launch=button('⚔',td,()=>open([p.id]));launch.className='uptep-mf-launch';launch.setAttribute('aria-label','Préparer un multiflood sur '+p.pseudo);}
   launch.disabled=!enabled;launch.title=enabled?'Préparer les attaques sur '+p.pseudo:p.id===self.id?'Ton compte':p.blocked?'Joueur en vacances':'Joueur hors de portée';
   // Keep the two columns together at the right, even after the chain refreshes.
   if(p.row.lastElementChild!==launch.parentNode||launch.parentNode.previousElementSibling!==select.parentNode)p.row.append(select.parentNode,launch.parentNode);
  }
  const headers=['select','launch'].map(n=>head.querySelector('[data-mf-column="'+n+'"]'));
  if(head.lastElementChild!==headers[1]||headers[1].previousElementSibling!==headers[0])head.append(...headers);
  const n=players.filter(p=>selected.has(p.id)).length;selectButton.disabled=n===0;selectButton.textContent='Multiflood la sélection ('+n+')';
 }
 let page=null,draft=null,native=null,plan=null,busy=false,submitted=read('submission'),status,fieldset,availability,results,send,refresh,options,form;
 function alive(){
  if(disposed)throw Error('Préparation interrompue.');
  if(doc.getElementById('pseudo')?.textContent.trim()!==context.pseudo||doc.getElementById('tag_alliance')?.textContent.trim()!=='UPTEP')throw Error('Le compte du jeu a changé ou la session a expiré.');
 }
 async function request(path){
  alive();const url=new URL(path,win.location.origin);if(url.origin!==win.location.origin)throw Error('Adresse du jeu invalide.');
  const controller=new win.AbortController();controllers.add(controller);const timeout=win.setTimeout(()=>controller.abort(),20000);
  try{const response=await win.fetch(url.href,{credentials:'same-origin',cache:'no-store',redirect:'error',signal:controller.signal});if(!response.ok)throw Error('Le jeu n’a pas répondu correctement ('+response.status+').');const text=await response.text();alive();return text;}
  finally{win.clearTimeout(timeout);controllers.delete(controller);}
 }
 const html=text=>new win.DOMParser().parseFromString(text,'text/html');
 async function getNative(){return readMultifloodNative(html(await request('/simulateurFlood.php')),context.pseudo,data.self);}
 function persist(){write('draft',draft);}
 function lock(value){busy=value;fieldset.disabled=value||!!submitted;refresh.disabled=value||!!submitted;send.disabled=value||!!submitted||!plan?.attacks.length||!native;}
 function showStatus(text){if(!disposed)status.textContent=text;}
 function inputCell(row,id,value,label,readOnly=false){const td=make('td',null,row),input=make('input',null,td);input.id=id;input.value=value??'';input.setAttribute('aria-label',label);input.readOnly=readOnly;if(!readOnly)input.inputMode='numeric';return input;}
 function renderForm(){
  form.replaceChildren();const scroll=make('div',null,form);scroll.className='uptep-mf-scroll';const table=make('table',null,scroll),head=make('tr',null,table);
  make('th','Attaquant',head);make('th','',head);const targetsHead=make('th','Cibles',head);targetsHead.colSpan=Math.max(1,draft.targets.length);
  const pseudo=make('tr',null,table);inputCell(pseudo,'joueur0',context.pseudo,'Attaquant',true);make('th','Pseudo',pseudo);
  for(let i=0;i<draft.targets.length;i++){const target=draft.targets[i],td=make('td',null,pseudo);make('strong',target.pseudo,td);const remove=button(' ×',td,()=>{draft.targets.splice(i,1);draft.excluded=[];persist();renderForm();calculate();});remove.title='Retirer '+target.pseudo;remove.setAttribute('aria-label','Retirer '+target.pseudo);}
  for(const [property,label,suffix] of [['field','Terrain actuel','TDC'],['final','Terrain final','final'],['max','Prise max','max'],['rounding','Arrondir','arrondi']]){
   const row=make('tr',null,table);if(property!=='field'){row.className='uptep-mf-options';row.hidden=!draft.options;}
   if(property==='rounding')make('td','—',row);else inputCell(row,'joueur0'+suffix,draft.self[property]??'',label+' · Moi');
   make('th',label,row);
   for(let i=0;i<draft.targets.length;i++){
    const p=draft.targets[i],id='joueur'+(i+1)+suffix;
    if(property==='rounding'){const td=make('td',null,row),select=make('select',null,td);select.id=id;select.setAttribute('aria-label','Arrondi · '+p.pseudo);for(const [v,l] of [[1,'Fort'],[2,'Normal'],[3,'Faible'],[4,'Aucun']]){const opt=make('option',l,select);opt.value=String(v);}select.value=String(p.rounding??4);}
    else inputCell(row,id,p[property]??'',label+' · '+p.pseudo);
   }
  }
  const travel=make('tr',null,table);make('td','—',travel);make('th','Distance / Trajet',travel);
  for(const target of draft.targets){const origin=data.players.find(p=>String(p.player_id)===String(data.self)),t=chaineTravel(origin,target,native?.speed??data.attack_speed);make('td',(t.distance===null?'—':Math.ceil(t.distance))+' / '+chaineTravelDuration(t.seconds),travel);}
  options.setAttribute('aria-expanded',String(!!draft.options));
 }
 function capture(){
  for(const [suffix,property] of [['TDC','field'],['final','final'],['max','max']])draft.self[property]=form.querySelector('#joueur0'+suffix).value;
  draft.targets.forEach((p,i)=>{for(const [suffix,property] of [['TDC','field'],['final','final'],['max','max'],['arrondi','rounding']])p[property]=form.querySelector('#joueur'+(i+1)+suffix).value;});
 }
 function calculate(){
  results.replaceChildren();plan=null;
  try{
   persist();
   if(!native){make('p','Lecture de l’armée et des attaques disponibles…',results);send.disabled=true;return;}
   plan=multifloodPlan({self:draft.self,targets:draft.targets,army:native.army,slots:native.slots,excluded:draft.excluded});
   availability.textContent=fmt(native.army)+' fourmis disponibles · '+native.slots+' attaques libres · VA '+native.speed;
   for(const group of plan.groups){
    const section=make('section',null,results);make('h3',group.target.pseudo,section);
    if(group.reason){make('p',group.reason,section);continue;}
    const scroll=make('div',null,section);scroll.className='uptep-mf-scroll';const table=make('table',null,scroll),head=make('tr',null,table);for(const label of ['Attaque','Mon terrain','Terrain de '+group.target.pseudo,'Prise'])make('th',label,head);
    group.rows.forEach((row,i)=>{const tr=make('tr',null,table);if(!row.checked)tr.className='uptep-mf-unchecked';const td=make('td',null,tr),label=make('label',null,td),check=make('input',null,label);check.type='checkbox';check.checked=row.checked;check.dataset.attack=row.key;label.append(doc.createTextNode(' Attaque '+(i+1)+' ('+row.percent.toLocaleString('fr-FR')+' %)'));check.addEventListener('change',()=>{const set=new Set(draft.excluded);if(check.checked)set.delete(row.key);else set.add(row.key);draft.excluded=[...set];calculate();});for(const val of [row.own,row.field,row.amount])make('td',fmt(val),tr);});
    const last=group.rows.filter(r=>r.checked).at(-1),total=make('tr',null,table);make('th','Résultat sélectionné',total);make('td',fmt(last?last.own+last.amount:group.rows[0].own),total);make('td',fmt(group.final),total);make('td','+'+fmt(group.gain),total);
   }
   const recap=make('div',`${plan.attacks.length} attaque(s) · ${fmt(plan.gain)} fourmis · +${fmt(plan.gain)} cm² estimés\nTon terrain final : ${fmt(plan.final)} cm²`,results);recap.className='uptep-mf-summary';
   send.disabled=busy||!!submitted||plan.attacks.length===0;
  }catch(e){plan=null;make('p',e.message,results);send.disabled=true;}
 }
 async function refreshData(){
  if(busy)return;lock(true);showStatus('Actualisation des terrains, de l’armée et des attaques libres…');
  try{
   const members=readMultifloodMembers(html(await request('/alliance.php?Membres')),data,win.location.href),live=await getNative();
   const map=new Map(members.players.map(p=>[p.id,p]));
   for(const p of draft.targets){const member=map.get(p.id);if(!member)throw Error(p.pseudo+' n’apparaît plus dans la liste des membres. Retire cette cible.');Object.assign(p,cleanPlayer(member));}
   Object.assign(draft.self,cleanPlayer(members.self));native=live;draft.excluded=[];draft.observed=Date.now();persist();renderForm();calculate();showStatus('Terrains relevés à '+new Date(draft.observed).toLocaleTimeString('fr-FR')+'.');
  }catch(e){native=null;showStatus(e.message);calculate();}finally{if(!disposed)lock(false);}
 }
 function fingerprint(p){return JSON.stringify(p.attacks.map(r=>[r.id,String(r.amount)]));}
 async function launch(){
  if(busy||submitted||!plan?.attacks.length)return;
  lock(true);showStatus('Vérification des terrains et des disponibilités avant l’envoi…');let attempted=false;
  try{
   capture();const previous=fingerprint(plan),live=await getNative();
   const members=readMultifloodMembers(html(await request('/alliance.php?Membres')),data,win.location.href),map=new Map(members.players.map(p=>[p.id,p]));
   let changed=String(multifloodNumber(draft.self.field))!==members.self.field||native.army!==live.army||native.slots!==live.slots;
   for(const target of draft.targets){const member=map.get(target.id);if(!member||member.blocked)throw Error(target.pseudo+' n’est plus disponible dans les membres.');if(String(multifloodNumber(target.field))!==member.field)changed=true;Object.assign(target,cleanPlayer(member));}
   Object.assign(draft.self,cleanPlayer(members.self));native=live;draft.observed=Date.now();renderForm();calculate();
   if(!plan?.attacks.length)throw Error('Aucune attaque possible avec les disponibilités actuelles.');
   if(changed||previous!==fingerprint(plan)){showStatus('Les terrains ou disponibilités ont changé. Le calcul a été actualisé : vérifie la sélection puis relance.');return;}
   for(const id of new Set(plan.attacks.map(r=>r.id))){
    const target=draft.targets.find(p=>p.id===id);if(!Number.isFinite(target.distance))throw Error('Coordonnées indisponibles pour '+target.pseudo+'. Actualise les données avant de lancer.');
    const choices=JSON.parse(await request('/appelAjaxFlood.php?'+new URLSearchParams({term:target.pseudo})));
    if(!Array.isArray(choices)||!choices.some(p=>String(p.id)===id&&p.value===target.pseudo&&['type1','type3'].includes(p.type)))throw Error('Le jeu ne permet pas actuellement d’attaquer '+target.pseudo+'.');
   }
   const latest=await getNative();
   if(latest.army!==native.army||latest.slots!==native.slots||latest.field!==native.field){native=latest;calculate();showStatus('Les disponibilités ont changé pendant la vérification. Vérifie la sélection puis relance.');return;}
   native=latest;
   const payload=new URLSearchParams({t:native.token,codeFlood:plan.attacks.map(r=>r.id+'|'+r.amount+'|').join('')});
   if(payload.toString().length>7000)throw Error('La série est trop longue. Sélectionne moins d’attaques à la fois.');
   submitted={state:'sending',at:Date.now(),count:plan.attacks.length,gain:String(plan.gain)};write('submission',submitted);attempted=true;
   showStatus('Envoi de '+plan.attacks.length+' attaque(s)…');
   let response=await request('/appelAjaxFlood.php?'+payload);
   if(response.includes('DELETION_CONFIRMATION_REQUIRED|')){
    // Preserve the game's explicit confirmation: never cancel account deletion silently.
    submitted=null;write('submission',null);attempted=false;
    const when=response.split('DELETION_CONFIRMATION_REQUIRED|')[1].trim().slice(0,150);
    if(!win.confirm('Cette action annulera la suppression de ton compte programmée '+when+'. Continuer ?')){showStatus('Envoi annulé.');return;}
    alive();payload.set('confirm_cancel_deletion','1');submitted={state:'sending',at:Date.now(),count:plan.attacks.length,gain:String(plan.gain)};write('submission',submitted);attempted=true;
    response=await request('/appelAjaxFlood.php?'+payload);
   }
   if(/DELETION_CONFIRMATION_REQUIRED|erreur|error|incorrect|insuffisan|impossible|refus|loginForm/i.test(response))throw Error('Le jeu n’a pas confirmé toute la série. Vérifie la page Armée avant une nouvelle préparation.');
   const after=await getNative();
   if(native.slots-after.slots!==plan.attacks.length||BigInt(native.army)-BigInt(after.army)!==plan.gain)throw Error('Réponse reçue, mais le résultat complet n’a pas pu être confirmé. Vérifie la page Armée.');
   submitted.state='done';write('submission',submitted);native=after;
   showStatus(plan.attacks.length+' attaque(s) enregistrée(s) par le jeu. Consulte la page Armée pour suivre les trajets.');
  }catch(e){
   if(attempted){submitted={...submitted,state:'uncertain'};try{write('submission',submitted);}catch{}showStatus(e.message+'\nAucun renvoi automatique : consulte la page Armée.');}
   else showStatus(e.message);
  }finally{if(!disposed){lock(false);fieldset.disabled=!!submitted;}}
 }
 if(isMembers){updateMembers();timer=win.setInterval(updateMembers,1000);}
 if(isPage){
  const centre=doc.getElementById('centre');if(!centre){style.remove();return ()=>{};}
  page=make('section',null);page.id='uptep-multiflood';page.setAttribute('aria-label','Multiflood UPTEP+');
  for(const child of centre.children){if(child.matches('script,style,link,nav,#menu,#menuBoite')||child.querySelector('#menu,#menuBoite'))continue;hidden.set(child,[child.style.getPropertyValue('display'),child.style.getPropertyPriority('display')]);child.style.setProperty('display','none','important');}
  centre.append(page);make('h2','Multiflood UPTEP+',page);
  const toolbar=make('div',null,page);toolbar.className='uptep-mf-toolbar';const back=make('a','← Membres',toolbar);back.href='/alliance.php?Membres';refresh=button('Actualiser les terrains',toolbar,refreshData);const armyLink=make('a','Voir mon armée',toolbar);armyLink.href='/Armee.php';armyLink.target='_blank';armyLink.rel='noopener';
  status=make('p','',page);status.className='uptep-mf-status';status.setAttribute('role','status');
  availability=make('p','',page);availability.className='uptep-mf-note';
  fieldset=make('fieldset',null,page);const actions=make('div',null,fieldset);actions.className='uptep-mf-toolbar';
  options=button('Options',actions,()=>{capture();draft.options=!draft.options;persist();renderForm();});options.setAttribute('aria-expanded','false');
  button('Recalculer',actions,()=>{capture();draft.excluded=[];calculate();});
  form=make('form',null,fieldset);form.id='formulaireFlood';form.addEventListener('submit',event=>{event.preventDefault();capture();calculate();});form.addEventListener('input',()=>{capture();draft.excluded=[];calculate();});form.addEventListener('change',()=>{capture();draft.excluded=[];calculate();});
  results=make('div',null,fieldset);results.id='resultatFlood';send=button('Lancer ces attaques',page,launch);send.disabled=true;
  make('p','Terrains relevés dans les membres. Estimation sans combat ni autres mouvements intervenant avant l’arrivée. Les cibles les plus proches sont traitées en premier.',page).className='uptep-mf-note';
  draft=read('draft');
  if(!draft||draft.self?.id!==String(data.self)||!Array.isArray(draft.targets)||!draft.targets.length){refresh.disabled=true;fieldset.hidden=true;showStatus('Sélectionne d’abord tes cibles depuis la page Membres.');}
  else{
   const seen=new Set();draft.targets=draft.targets.filter(p=>p&&typeof p.pseudo==='string'&&/^\d+$/.test(p.id)&&p.id!==String(data.self)&&!seen.has(p.id)&&seen.add(p.id));draft.excluded=Array.isArray(draft.excluded)?draft.excluded:[];
   renderForm();
   if(submitted){fieldset.disabled=true;refresh.disabled=true;showStatus(submitted.state==='done'?'Cette préparation a déjà été envoyée.':'Un envoi a été commencé. Vérifie la page Armée avant de préparer une autre série.');
    button('Nouvelle préparation',toolbar,()=>{if(submitted.state!=='done'&&!win.confirm('As-tu vérifié dans Armée les attaques déjà parties ? Une nouvelle préparation peut sinon les doubler.'))return;write('submission',null);submitted=null;fieldset.disabled=false;refresh.disabled=false;refreshData();});
   }else{
    lock(true);getNative().then(live=>{if(disposed)return;native=live;renderForm();calculate();showStatus('Terrains relevés à '+new Date(draft.observed).toLocaleTimeString('fr-FR')+'.');}).catch(e=>showStatus(e.message)).finally(()=>{if(!disposed)lock(false);});
   }
  }
 }
 return ()=>{disposed=true;win.clearInterval(timer);for(const controller of controllers)controller.abort();for(const n of added)n.remove();tools?.remove();page?.remove();style.remove();for(const [child,[display,priority]] of hidden){if(display)child.style.setProperty('display',display,priority);else child.style.removeProperty('display');}};
}
