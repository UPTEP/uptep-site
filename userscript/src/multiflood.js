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
   group.rows.push({key,id:String(target.id),pseudo:target.pseudo,amount,capture:amount,own,field,checked,percent:Number(amount*10000n/field)/100});
   if(checked){own+=amount;field-=amount;army-=amount;slots--;gained+=amount;group.gain+=amount;}
  }
  group.final=field;
  if(!group.rows.length)group.reason=slots===0?'Aucune attaque disponible.':army===0n?'Aucune fourmi disponible.':original===final?'Terrain final déjà atteint.':'Objectif atteint ou prise nulle.';
 }
 return {groups,initial,final:own,gain:gained,sent:gained,armyLeft:army,slotsLeft:slots,attacks:groups.flatMap(g=>g.rows).filter(r=>r.checked)};
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
// The tutorial targets :59 at ARRIVAL, not :59 at departure.
function multifloodTiming(seconds,now){
 if(!Number.isSafeInteger(seconds)||seconds<0)return null;
 const clickSecond=59-seconds%60;
 if(!Number.isFinite(now))return {clickSecond};
 let clickAt=Math.floor(now/60000)*60000+clickSecond*1000;
 if(now>=clickAt+1000)clickAt+=60000;
 return {clickSecond,clickAt,arrivalAt:clickAt+seconds*1000,waitMs:Math.max(0,clickAt-now),inWindow:now>=clickAt&&now<clickAt+1000};
}
function multifloodDuration(value,estimate){
 if(!String(value??'').trim())return estimate;
 const match=String(value).trim().match(/^(\d{1,6}):([0-5]\d):([0-5]\d)$/);
 if(!match)throw Error('Durée attendue : HH:MM:SS (exemple : 15:09:08).');
 return Number(match[1])*3600+Number(match[2])*60+Number(match[3]);
}
function multifloodClock(win){
 try{
  const cache=JSON.parse(win.sessionStorage.getItem('uptep_s5_clock_cache_v1'));
  const age=win.performance.timeOrigin+win.performance.now()-cache.globalAtReference;
  if(cache.version!==1||!cache.model?.consistent||!Number.isFinite(age)||age<0||age>600000||Math.abs(Date.now()-cache.wallAtReference-age)>2000||!Number.isFinite(cache.serverAtReference)||!Number.isFinite(cache.model.uncertainty))return null;
  return {now:cache.serverAtReference+age,uncertainty:Math.ceil(cache.model.uncertainty+age*.0001)};
 }catch{return null;}
}
function multifloodTargetPlan(draft,native,target,records,arrivalAt){
 const max=(a,b)=>a>b?a:b,min=(a,b)=>a<b?a:b;
 const actual=multifloodNumber(draft.self.field),observed=draft.observedServer??draft.observed;
 const done=Object.values(records).filter(r=>r.state==='done');
 let forecast=0n,future=0n,alreadySent=0n;
 for(const record of done){
  const gain=multifloodNumber(record.gain);alreadySent+=gain;
  if(record.arrivalAt>observed){future+=gain;if(record.arrivalAt<=arrivalAt)forecast+=gain;}
 }
 let cap=multifloodNumber(draft.self.max,true),final=multifloodNumber(draft.self.final,true);
 if(cap!==null)cap=max(0n,cap-alreadySent);
 if(final!==null){const remaining=max(0n,final-actual-future);cap=cap===null?remaining:min(cap,remaining);}
 const result=multifloodPlan({self:{field:String(actual+forecast),max:cap===null?'':String(cap)},targets:[target],army:native.army,slots:native.slots,excluded:draft.excluded});
 if(target.useRemainingArmy&&result.attacks.length&&result.armyLeft>0n){
  const last=result.attacks[result.attacks.length-1],group=result.groups[0];
  last.amount+=result.armyLeft;result.sent+=result.armyLeft;result.armyLeft=0n;
  const capture=min(last.amount,last.field/5n),extra=capture-last.capture;
  last.capture=capture;last.percent=Number(capture*10000n/last.field)/100;
  result.gain+=extra;result.final+=extra;group.gain+=extra;group.final-=extra;
  for(const row of group.rows.slice(group.rows.indexOf(last)+1)){row.own+=extra;row.field-=extra;}
 }
 result.forecast=forecast;return result;
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
 const style=make('style',`#uptep-multiflood{font:12px/1.5 Verdana,sans-serif;color:#302b18;padding:15px 10px;text-align:left;max-width:100%;box-sizing:border-box}#uptep-multiflood *{box-sizing:border-box}#uptep-multiflood h2{font-size:21px;margin:8px 0}#uptep-multiflood h3{font-size:14px;margin:15px 0 8px}#uptep-multiflood button,.uptep-mf-tools button,.uptep-mf-cell button{cursor:pointer;width:auto;background:#4f5940;color:#fff4d3;border:1px solid #465037;border-radius:3px;padding:6px 10px;font:11px Verdana,sans-serif}#uptep-multiflood button:disabled,.uptep-mf-tools button:disabled,.uptep-mf-cell button:disabled{opacity:.5;cursor:default}#uptep-multiflood .uptep-mf-toolbar,.uptep-mf-tools{display:flex;align-items:center;flex-wrap:wrap;gap:10px;margin:10px 0}#uptep-multiflood fieldset{padding:0;margin:0;border:0;min-width:0}#uptep-multiflood .uptep-mf-scroll{overflow-x:auto;max-width:100%;margin:12px 0}#uptep-multiflood table{border-collapse:collapse;width:100%;font:12px Verdana,sans-serif}#uptep-multiflood th,#uptep-multiflood td{border:1px solid #a59259;padding:8px;text-align:center;white-space:nowrap}#uptep-multiflood th{background:#bda364;color:#302b18}#uptep-multiflood td{background:#e0d3a2}#uptep-multiflood input:not([type=checkbox]),#uptep-multiflood select{width:125px;max-width:100%;padding:5px;border:1px solid #a59259;border-radius:2px;background:#f3ebce;color:#302b18;font:12px Verdana,sans-serif;text-align:center}#uptep-multiflood input[readonly]{background:#d6c58e}#uptep-multiflood label{cursor:pointer}#uptep-multiflood .uptep-mf-options[hidden]{display:none}#uptep-multiflood .uptep-mf-summary{padding:12px;background:#d4ddba;border-left:4px solid #4f5940;margin:12px 0;white-space:pre-line}#uptep-multiflood .uptep-mf-note{font-size:11px;color:#655a36}#uptep-multiflood .uptep-mf-status{margin:10px 0;padding:10px;background:#e7d8a5;border-left:3px solid #91702a;white-space:pre-line}#uptep-multiflood .uptep-mf-status:empty{display:none}#uptep-multiflood a{color:#385848}.uptep-mf-cell{text-align:center!important;width:38px!important;white-space:nowrap;padding:2px 4px!important}.uptep-mf-cell input{cursor:pointer}.uptep-mf-cell button{padding:4px 6px}.uptep-mf-tools{font:12px Verdana,sans-serif}#uptep-multiflood .uptep-mf-timing{padding:10px;background:#e3d7ad;border-left:4px solid #907338;margin:10px 0 22px}#uptep-multiflood .uptep-mf-timing[data-ready="true"]{background:#d4e0b5;border-color:#3d713a}#uptep-multiflood .uptep-mf-countdown{font-variant-numeric:tabular-nums;margin:5px 0}#uptep-multiflood .uptep-mf-unchecked td{opacity:.55}#uptep-multiflood :focus-visible,.uptep-mf-tools :focus-visible,.uptep-mf-cell :focus-visible{outline:2px solid #316276;outline-offset:2px}`,doc.head);
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
   write('draft',{self:cleanPlayer(members.self),targets:targets.map(cleanPlayer),observed:Date.now(),observedServer:multifloodClock(win)?.now??Date.now(),excluded:[],options:false});
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
  const n=players.filter(p=>selected.has(p.id)).length;selectButton.disabled=n===0;const label='Multiflood la sélection ('+n+')';if(selectButton.textContent!==label)selectButton.textContent=label;
 }
 let page=null,draft=null,native=null,busy=false,prepared=null,status,fieldset,availability,results,refresh,options,form,reset,timingTimer;
 const previousSubmission=read('submission');
 let ledger=previousSubmission?.version===2?previousSubmission:{version:2,targets:{},legacy:previousSubmission||null};
 if(!ledger.targets||typeof ledger.targets!=='object'||Array.isArray(ledger.targets))ledger.targets={};
 const controls=new Map(),targetStatus=new Map();
 const unresolved=()=>!!ledger.legacy||Object.values(ledger.targets).some(r=>r.state!=='done');
 const saveLedger=()=>write('submission',ledger);
 const serverNow=()=>multifloodClock(win)?.now??Date.now();
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
 function lock(value){busy=value;fieldset.disabled=value||unresolved();refresh.disabled=value||unresolved();reset.disabled=value;updateTiming();}
 function showStatus(text,id){if(disposed)return;status.textContent=text;if(id){targetStatus.set(id,text);const feedback=controls.get(id)?.feedback;if(feedback)feedback.textContent=text;}}
 function travel(target){
  const origin=data.players.find(p=>String(p.player_id)===String(data.self));
  const estimated=chaineTravel(origin,target,native?.speed??data.attack_speed);
  return {...estimated,seconds:multifloodDuration(target.duration,estimated.seconds),exact:!!String(target.duration??'').trim()};
 }
 function inputCell(row,id,value,label,readOnly=false){const td=make('td',null,row),input=make('input',null,td);input.id=id;input.value=value??'';input.setAttribute('aria-label',label);input.readOnly=readOnly;if(!readOnly)input.inputMode='numeric';return input;}
 function renderForm(){
  form.replaceChildren();const scroll=make('div',null,form);scroll.className='uptep-mf-scroll';const table=make('table',null,scroll),head=make('tr',null,table);
  make('th','Attaquant',head);make('th','',head);const targetsHead=make('th','Cibles',head);targetsHead.colSpan=Math.max(1,draft.targets.length);
  const pseudo=make('tr',null,table);inputCell(pseudo,'joueur0',context.pseudo,'Attaquant',true);make('th','Pseudo',pseudo);
  for(let i=0;i<draft.targets.length;i++){const target=draft.targets[i],td=make('td',null,pseudo);make('strong',target.pseudo,td);const remove=button(' ×',td,()=>{capture();prepared=null;draft.targets.splice(i,1);draft.excluded=[];persist();renderForm();calculate();});remove.title='Retirer '+target.pseudo;remove.setAttribute('aria-label','Retirer '+target.pseudo);remove.disabled=!!ledger.targets[target.id];}
  for(const [property,label,suffix] of [['field','Terrain actuel','TDC'],['final','Terrain final','final'],['max','Prise max','max'],['rounding','Arrondir','arrondi']]){
   const row=make('tr',null,table);if(property!=='field'){row.className='uptep-mf-options';row.hidden=!draft.options;}
   if(property==='rounding')make('td','—',row);else inputCell(row,'joueur0'+suffix,draft.self[property]??'',label+' · Moi');
   make('th',label,row);
   for(let i=0;i<draft.targets.length;i++){
    const p=draft.targets[i],id='joueur'+(i+1)+suffix;let control;
    if(property==='rounding'){const td=make('td',null,row);control=make('select',null,td);control.id=id;control.setAttribute('aria-label','Arrondi · '+p.pseudo);for(const [v,l] of [[1,'Fort'],[2,'Normal'],[3,'Faible'],[4,'Aucun']]){const opt=make('option',l,control);opt.value=String(v);}control.value=String(p.rounding??4);}
    else control=inputCell(row,id,p[property]??'',label+' · '+p.pseudo);
    control.disabled=!!ledger.targets[p.id];
   }
  }
  const durations=make('tr',null,table);make('td','—',durations);make('th','Durée exacte du jeu',durations);
  draft.targets.forEach((p,i)=>{const input=inputCell(durations,'joueur'+(i+1)+'duration',p.duration||'','Durée exacte HH:MM:SS · '+p.pseudo);input.inputMode='text';input.placeholder='HH:MM:SS';input.title='Facultatif : recopie la durée du jeu pour remplacer l’estimation.';input.disabled=!!ledger.targets[p.id];});
  options.setAttribute('aria-expanded',String(!!draft.options));
 }
 function capture(){
  for(const [suffix,property] of [['TDC','field'],['final','final'],['max','max']])draft.self[property]=form.querySelector('#joueur0'+suffix).value;
  draft.targets.forEach((p,i)=>{for(const [suffix,property] of [['TDC','field'],['final','final'],['max','max'],['arrondi','rounding'],['duration','duration']])p[property]=form.querySelector('#joueur'+(i+1)+suffix).value;});
 }
 function targetPlan(target,at){
  const seconds=travel(target).seconds;
  const arrival=Number.isFinite(at)?at:seconds===null?Infinity:multifloodTiming(seconds,serverNow()).arrivalAt;
  return multifloodTargetPlan(draft,native,target,ledger.targets,arrival);
 }
 function calculate(){
  results.replaceChildren();controls.clear();
  try{
   persist();
   if(!native){make('p','Lecture de l’armée et des attaques disponibles…',results);return;}
   availability.textContent=fmt(native.army)+' fourmis disponibles · '+native.slots+' attaques libres · VA '+native.speed;
   for(const target of [...draft.targets].sort((a,b)=>(a.distance??Infinity)-(b.distance??Infinity))){
    const section=make('section',null,results);section.dataset.target=target.id;make('h3',target.pseudo,section);
    const record=ledger.targets[target.id];
    if(record){make('p',record.state==='done'?'✓ '+record.count+' attaque(s) envoyée(s) · '+fmt(record.sent??record.gain)+' fourmis':'Envoi à vérifier dans Armée. Aucun renvoi automatique.',section).className='uptep-mf-summary';continue;}
    const remainderLabel=make('label',null,section),remainder=make('input',null,remainderLabel);remainder.type='checkbox';remainder.checked=!!target.useRemainingArmy;remainder.dataset.remainingArmy=target.id;
    remainderLabel.append(doc.createTextNode(' Ajouter le reste de mon armée au dernier flood'));
    remainder.addEventListener('change',()=>{prepared=null;target.useRemainingArmy=remainder.checked;calculate();});
    if(target.useRemainingArmy)make('p','Toute l’armée disponible partira vers ce joueur. La prise du dernier flood reste limitée à 20 % et peut dépasser l’objectif saisi.',section).className='uptep-mf-note';
    const result=prepared?.id===target.id?prepared.plan:targetPlan(target),group=result.groups[0];
    if(group.reason)make('p',group.reason,section);
    else{
     const scroll=make('div',null,section);scroll.className='uptep-mf-scroll';const table=make('table',null,scroll),head=make('tr',null,table);for(const label of ['Attaque','Mon terrain','Terrain de '+target.pseudo,'Fourmis','Prise'])make('th',label,head);
     group.rows.forEach((row,i)=>{const tr=make('tr',null,table);if(!row.checked)tr.className='uptep-mf-unchecked';const td=make('td',null,tr),label=make('label',null,td),check=make('input',null,label);check.type='checkbox';check.checked=row.checked;check.dataset.attack=row.key;label.append(doc.createTextNode(' Attaque '+(i+1)+' ('+row.percent.toLocaleString('fr-FR')+' %)'));check.addEventListener('change',()=>{prepared=null;const set=new Set(draft.excluded);if(check.checked)set.delete(row.key);else set.add(row.key);draft.excluded=[...set];calculate();});for(const val of [row.own,row.field,row.amount,row.capture])make('td',fmt(val),tr);});
     const total=make('tr',null,table);make('th','Résultat pour cette cible',total);make('td',fmt(result.final),total);make('td',fmt(group.final),total);make('td',fmt(result.sent),total);make('td','+'+fmt(group.gain),total);
    }
    const timing=make('div',null,section);timing.className='uptep-mf-timing';
    const when=make('strong','',timing),clockLine=make('div','',timing);clockLine.className='uptep-mf-note';
    const countdown=make('div','',timing);countdown.className='uptep-mf-countdown';
    const actions=make('div',null,timing);actions.className='uptep-mf-toolbar';
    const prepare=button('1. Préparer '+target.pseudo,actions,()=>prepareTarget(target.id));prepare.dataset.prepare=target.id;
    const send=button('2. Envoyer les floods à '+target.pseudo,actions,()=>sendTarget(target.id));send.dataset.send=target.id;
    const feedback=make('div',targetStatus.get(target.id)||'Clique sur Préparer pour activer l’envoi.',timing);feedback.className='uptep-mf-feedback';feedback.setAttribute('role','status');
    controls.set(target.id,{target,result,when,clockLine,countdown,timing,prepare,send,feedback});
    if(result.forecast>0n)make('p','Terrain projeté avec les floods déjà envoyés : +'+fmt(result.forecast)+' cm² avant cette arrivée.',section).className='uptep-mf-note';
   }
   make('p','Estimations par joueur : chaque envoi consomme l’armée et les attaques disponibles. Les autres cibles sont recalculées après chaque départ.',results).className='uptep-mf-note';
   updateTiming();
  }catch(e){prepared=null;controls.clear();results.replaceChildren();make('p',e.message,results);}
 }
 const timeFormat=new Intl.DateTimeFormat('fr-FR',{timeZone:'Europe/Paris',hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23'});
 const arrivalFormat=new Intl.DateTimeFormat('fr-FR',{timeZone:'Europe/Paris',day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23'});
 function updateTiming(){
  if(disposed)return;
  const clock=multifloodClock(win),now=clock?.now??Date.now();
  if(prepared&&win.performance.now()-prepared.checkedAt>90000)prepared=null;
  for(const [id,c] of controls){
   let t=null;try{t=travel(c.target);}catch{}
   const hint=multifloodTiming(t?.seconds,clock?.now??NaN),ready=prepared?.id===id;
   c.when.textContent=hint?'Trajet '+(t.exact?'saisi':'estimé')+' '+chaineTravelDuration(t.seconds)+' · clic à :'+String(hint.clickSecond).padStart(2,'0')+' → arrivée visée à :59':'Trajet indisponible : renseigne la durée exacte du jeu.';
   c.clockLine.textContent=clock?'Heure S5 '+timeFormat.format(now)+' · précision estimée ±'+clock.uncertainty+' ms':'Compte à rebours indisponible : synchronise l’horloge S5 dans le menu UPTEP+.';
   c.countdown.textContent=hint?.clickAt?(hint.inWindow?'C’est la seconde du clic !':'Prochain clic à '+timeFormat.format(hint.clickAt)+' · dans '+Math.ceil(hint.waitMs/1000)+' s')+' · arrivée estimée le '+arrivalFormat.format(hint.arrivalAt):'';
   c.timing.dataset.ready=String(!!ready&&!!hint?.inWindow);
   c.prepare.disabled=busy||unresolved()||!native||!c.result.attacks.length;
   c.send.disabled=busy||unresolved()||!ready||!hint;
   c.prepare.textContent=ready?'✓ Prêt · '+Math.max(0,Math.ceil((90000-(win.performance.now()-prepared.checkedAt))/1000))+' s · revérifier':'1. Préparer '+c.target.pseudo;
   c.send.textContent='2. Envoyer à '+c.target.pseudo+(hint?' · clic :'+String(hint.clickSecond).padStart(2,'0'):'');
   const feedback=targetStatus.get(id)||(ready?'Prêt : clique sur Envoyer à la seconde indiquée.':'Clique sur Préparer pour activer l’envoi.');if(c.feedback.textContent!==feedback)c.feedback.textContent=feedback;
  }
 }
 async function updateSnapshot(){
  // The member table is read in the browser when selecting targets. For fresh
  // values, use the same data endpoints as the game's native flood simulator.
  const live=await getNative(),updates=[],allowed=new Map();
  for(const p of draft.targets){
   if(ledger.targets[p.id])continue;
   const choices=JSON.parse(await request('/appelAjaxFlood.php?'+new URLSearchParams({term:p.pseudo})));
   const player=Array.isArray(choices)?choices.find(choice=>String(choice.id)===p.id&&choice.value===p.pseudo):null;
   if(!player)throw Error(p.pseudo+' n’a pas été trouvé dans le simulateur du jeu. Actualise ou retire cette cible.');
   updates.push([p,{field:String(multifloodNumber(player.terrain))}]);allowed.set(p.id,['type1','type3'].includes(player.type));
  }
  for(const [target,values] of updates)Object.assign(target,values);
  draft.self.field=live.field;draft.observed=Date.now();draft.observedServer=serverNow();
  native=live;persist();return allowed;
 }
 async function refreshData(){
  if(busy||unresolved())return;prepared=null;lock(true);showStatus('Actualisation des terrains et des disponibilités…');
  try{await updateSnapshot();renderForm();calculate();showStatus('Terrains et disponibilités actualisés.');}
  catch(e){native=null;showStatus(e.message);calculate();}finally{if(!disposed)lock(false);}
 }
 function fingerprint(p){return JSON.stringify(p.attacks.map(r=>[r.id,String(r.amount)]));}
 async function prepareTarget(id){
  if(busy||unresolved()||ledger.targets[id])return;
  prepared=null;lock(true);showStatus('Vérification des floods pour '+draft.targets.find(p=>p.id===id).pseudo+'…',id);
  try{
   capture();const allowed=await updateSnapshot(),target=draft.targets.find(p=>p.id===id);
   if(!allowed.get(id))throw Error('Le jeu ne permet pas actuellement d’attaquer '+target.pseudo+'.');
   const seconds=travel(target).seconds;if(seconds===null)throw Error('Renseigne la durée du trajet pour '+target.pseudo+'.');
   // Read the token last, before waiting for the manual send click.
   native=await getNative();draft.self.field=native.field;draft.observed=Date.now();draft.observedServer=serverNow();const result=targetPlan(target);
   if(!result.attacks.length)throw Error('Aucune attaque sélectionnée et disponible pour '+target.pseudo+'.');
   const payload=new URLSearchParams({t:native.token,codeFlood:result.attacks.map(r=>r.id+'|'+r.amount+'|').join('')});
   if(payload.toString().length>7000)throw Error('Sélectionne moins d’attaques pour cette cible.');
   prepared={id,plan:result,payload,checkedAt:win.performance.now(),seconds,before:{...native}};
   targetStatus.delete(id);showStatus(target.pseudo+' : '+result.attacks.length+' attaque(s) prête(s). Vérifie les quantités puis clique sur Envoyer à la seconde indiquée.');
  }catch(e){showStatus(e.message,id);}
  finally{if(!disposed){renderForm();calculate();lock(false);}}
 }
 async function sendTarget(id){
  if(busy||unresolved()||ledger.targets[id]||prepared?.id!==id)return;
  if(win.performance.now()-prepared.checkedAt>90000){prepared=null;updateTiming();showStatus('Préparation expirée : revérifie cette cible avant l’envoi.');return;}
  // Everything before the network send below is local and synchronous.
  const batch=prepared,target=draft.targets.find(p=>p.id===id);let attempted=false;
  lock(true);
  try{
   alive();
   const at=serverNow(),result=targetPlan(target,at+batch.seconds*1000);
   if(fingerprint(result)!==fingerprint(batch.plan)){prepared=null;calculate();showStatus('L’ordre d’arrivée a changé le calcul. Prépare à nouveau cette cible.');return;}
   const record={state:'sending',at,arrivalAt:at+batch.seconds*1000,count:batch.plan.attacks.length,gain:String(batch.plan.gain),sent:String(batch.plan.sent),pseudo:target.pseudo};
   ledger.targets[id]=record;saveLedger();attempted=true;prepared=null;
   const responsePromise=request('/appelAjaxFlood.php?'+batch.payload);
   showStatus('Envoi des floods à '+target.pseudo+'…');
   const response=await responsePromise;
   if(response.includes('DELETION_CONFIRMATION_REQUIRED|')){
    // The game has not sent anything. Do not retry after a modal at a wrong second.
    delete ledger.targets[id];saveLedger();attempted=false;
    showStatus('Le jeu demande une confirmation liée à la suppression programmée du compte. Ouvre son simulateur de flood pour régler ce point, puis prépare à nouveau cet envoi.');return;
   }
   if(/erreur|error|incorrect|insuffisan|impossible|refus|loginForm/i.test(response))throw Error('Le jeu n’a pas confirmé toute la série.');
   const after=await getNative();
   if(batch.before.slots-after.slots!==batch.plan.attacks.length||BigInt(batch.before.army)-BigInt(after.army)!==batch.plan.sent)throw Error('Le résultat complet n’a pas pu être confirmé.');
   record.state='done';saveLedger();native=after;
   showStatus(target.pseudo+' : '+record.count+' attaque(s) enregistrée(s). Tu peux préparer le joueur suivant.');
  }catch(e){
   if(attempted){ledger.targets[id]={...ledger.targets[id],state:'uncertain'};try{saveLedger();}catch{}showStatus(e.message+'\nVérifie la page Armée. Aucun renvoi automatique.');}
   else showStatus(e.message);
  }finally{if(!disposed){renderForm();calculate();lock(false);}}
 }
 if(isMembers){updateMembers();timer=win.setInterval(updateMembers,1000);}
 if(isPage){
  const centre=doc.getElementById('centre');if(!centre){style.remove();return ()=>{};}
  page=make('section',null);page.id='uptep-multiflood';page.setAttribute('aria-label','Multiflood UPTEP+');
  for(const child of centre.children){if(child.matches('script,style,link,nav,#menu,#menuBoite')||child.querySelector('#menu,#menuBoite'))continue;hidden.set(child,[child.style.getPropertyValue('display'),child.style.getPropertyPriority('display')]);child.style.setProperty('display','none','important');}
  centre.append(page);make('h2','Multiflood UPTEP+',page);
  const toolbar=make('div',null,page);toolbar.className='uptep-mf-toolbar';const back=make('a','← Membres',toolbar);back.href='/alliance.php?Membres';refresh=button('Actualiser les terrains',toolbar,refreshData);const armyLink=make('a','Voir mon armée',toolbar);armyLink.href='/Armee.php';armyLink.target='_blank';armyLink.rel='noopener';
  reset=button('Nouvelle préparation',toolbar,()=>{if(unresolved()&&!win.confirm('As-tu vérifié dans Armée les attaques déjà parties ? Une nouvelle préparation peut sinon les doubler.'))return;ledger={version:2,targets:{}};saveLedger();prepared=null;refreshData();});
  status=make('p','',page);status.className='uptep-mf-status';status.setAttribute('role','status');
  availability=make('p','',page);availability.className='uptep-mf-note';
  make('p','Pour chaque joueur : prépare les floods, puis clique sur son bouton Envoyer à la seconde indiquée. Règle du tutoriel : 59 − secondes du trajet. Le délai réseau peut décaler l’arrivée.',page).className='uptep-mf-note';
  fieldset=make('fieldset',null,page);const actions=make('div',null,fieldset);actions.className='uptep-mf-toolbar';
  options=button('Options',actions,()=>{capture();draft.options=!draft.options;persist();renderForm();});options.setAttribute('aria-expanded','false');
  button('Recalculer',actions,()=>{capture();prepared=null;draft.excluded=[];calculate();});
  form=make('form',null,fieldset);form.id='formulaireFlood';form.addEventListener('submit',event=>{event.preventDefault();capture();prepared=null;calculate();});
  for(const type of ['input','change'])form.addEventListener(type,()=>{capture();prepared=null;draft.excluded=[];calculate();});
  results=make('div',null,fieldset);results.id='resultatFlood';
  make('p','Durées calculées : estimations. Si le jeu affiche une autre durée, recopie-la dans « Durée exacte du jeu ». Prises estimées sans combat ni mouvements extérieurs à cette préparation.',page).className='uptep-mf-note';
  draft=read('draft');
  if(!draft||draft.self?.id!==String(data.self)||!Array.isArray(draft.targets)||!draft.targets.length){refresh.disabled=true;reset.disabled=true;fieldset.hidden=true;showStatus('Sélectionne d’abord tes cibles depuis la page Membres.');}
  else{
   const seen=new Set();draft.targets=draft.targets.filter(p=>p&&typeof p.pseudo==='string'&&/^\d+$/.test(p.id)&&p.id!==String(data.self)&&!seen.has(p.id)&&seen.add(p.id));draft.excluded=Array.isArray(draft.excluded)?draft.excluded:[];
   renderForm();lock(true);
   getNative().then(live=>{if(disposed)return;native=live;calculate();showStatus(unresolved()?'Un envoi précédent reste à vérifier dans Armée avant de continuer.':'Terrains relevés à '+new Date(draft.observed).toLocaleTimeString('fr-FR')+'.');}).catch(e=>showStatus(e.message)).finally(()=>{if(!disposed)lock(false);});
   timingTimer=win.setInterval(updateTiming,100);
  }
 }
 return ()=>{disposed=true;win.clearInterval(timer);win.clearInterval(timingTimer);for(const controller of controllers)controller.abort();for(const n of added)n.remove();tools?.remove();page?.remove();style.remove();for(const [child,[display,priority]] of hidden){if(display)child.style.setProperty('display',display,priority);else child.style.removeProperty('display');}};
}
