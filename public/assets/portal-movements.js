'use strict';
// UPTEP+ in the game, Alliance › Mouvements: one day of arrivals (floods, convoys, hunts) by hour.
// Started by portal.js once the userscript token is received; data comes from the "movements" action.
window.uptepMovements=(()=>{
 const el=(tag,cls,text)=>{const n=document.createElement(tag);if(cls)n.className=cls;if(text!==undefined)n.textContent=text;return n;};
 const fmt=n=>new Intl.NumberFormat('fr-FR').format(n);
 const paris=(ts,opts)=>new Intl.DateTimeFormat('fr-FR',{timeZone:'Europe/Paris',...opts}).format(new Date(ts*1000));
 const TYPES={flood:['Floods','Flood'],convoy:['Convois','Convoi'],hunt:['Chasses','Chasse']};
 const left=seconds=>{if(seconds<=0)return 'arrivé';const d=Math.floor(seconds/86400),h=Math.floor(seconds%86400/3600),m=Math.floor(seconds%3600/60);return d?`dans ${d} j ${h} h`:h?`dans ${h} h ${String(m).padStart(2,'0')}`:`dans ${m} min`;};
 let api=null,root=null,data=null,clock=null,version=0,showEarlier=false;
 // Keep a tooltip inside the frame: shift it left when it would pass the right edge, and show it above
 // the item when there is no room below (the in-game page is an iframe that clips what overflows).
 function fitTip(item,tip){
  tip.style.left='';tip.style.top='';tip.style.bottom='';
  const box=tip.getBoundingClientRect(),width=document.documentElement.clientWidth,height=document.documentElement.clientHeight;
  if(box.right>width-6)tip.style.left=Math.min(0,width-6-box.right)+'px';
  if(box.bottom>height-6&&item.getBoundingClientRect().top>box.height+12){tip.style.top='auto';tip.style.bottom='calc(100% + 5px)';}
 }

 const shown=new Set(Object.keys(TYPES));let onlyMe=false;
 async function load(day){
  const turn=++version;root.setAttribute('aria-busy','true');
  try{const result=await api({action:'movements',...(day?{day}:{})});if(turn!==version)return;if(result.day!==data?.day)showEarlier=false;data=result;render();}
  catch(e){if(turn===version){root.replaceChildren(el('p','pm-error',e.message||'Mouvements indisponibles.'));}}
  finally{if(turn===version)root.removeAttribute('aria-busy');}
 }
 function navButton(target,text,rel){const b=el('button','pm-step',text);b.type='button';if(!target){b.disabled=true;return b;}b.title=target.label;b.setAttribute('aria-label',(rel==='prev'?'Jour précédent : ':'Jour suivant : ')+target.label);b.addEventListener('click',()=>load(target.day));return b;}
 function render(){
  const offset=Date.now()/1000-data.now;// local clock vs server, for the countdowns
  const isToday=data.day===data.today;
  // Identical movements at the same minute share one chip "×n".
  const hours=new Map(),counts={flood:0,convoy:0,hunt:0};
  for(const e of data.events){
   counts[e.type]++;const minute=paris(e.at,{hour:'2-digit',minute:'2-digit',hourCycle:'h23'});const hour=Number(minute.slice(0,2));
   if(!hours.has(hour))hours.set(hour,new Map());const group=hours.get(hour),key=[e.type,e.label,e.amount??'',minute].join('|');
   if(group.has(key)){const g=group.get(key);g.count++;for(const p of e.players)if(!g.players.includes(p))g.players.push(p);}else group.set(key,{...e,minute,count:1,players:[...e.players]});
  }
  const nav=el('div','pm-nav');
  const form=el('form','pm-date');const input=el('input');input.type='date';input.value=data.day;input.min=data.first;input.max=data.last;input.setAttribute('aria-label','Jour affiché');
  input.addEventListener('change',()=>{if(input.value)load(input.value);});form.addEventListener('submit',ev=>{ev.preventDefault();if(input.value)load(input.value);});form.append(input);
  if(!isToday){const today=el('button','pm-today','Aujourd’hui');today.type='button';today.addEventListener('click',()=>load(null));form.append(today);}
  nav.append(navButton(data.prev,'‹ '+(data.prev?.label??''),'prev'),form,navButton(data.next,(data.next?.label??'')+' ›','next'));
  const filters=el('div','pm-filters');filters.setAttribute('role','group');filters.setAttribute('aria-label','Filtrer les mouvements');
  for(const [type,[plural]] of Object.entries(TYPES)){const b=el('button','pm-filter');b.type='button';b.setAttribute('aria-pressed',String(shown.has(type)));b.append(el('span','pm-dot is-'+type),document.createTextNode(plural+' '),el('span','pm-count',String(counts[type])));b.addEventListener('click',()=>{shown.has(type)?shown.delete(type):shown.add(type);render();});filters.append(b);}
  const me=el('button','pm-filter pm-me','Seulement moi');me.type='button';me.setAttribute('aria-pressed',String(onlyMe));me.addEventListener('click',()=>{onlyMe=!onlyMe;render();});filters.append(me);
  // Today: hours already past are folded behind a button.
  const currentHour=isToday?parseInt(paris(data.now,{hour:'2-digit',hourCycle:'h23'}),10):-1;let folded=0,foldedHours=0;
  const list=el('ol','pm-hours');let visible=0,n=0;
  for(const hour of [...hours.keys()].sort((a,b)=>a-b)){
   const items=el('div','pm-items');
   for(const e of hours.get(hour).values()){
    if(!shown.has(e.type)||(onlyMe&&!e.players.includes(data.me)))continue;visible++;n++;
    const chip=el('div','pm-item is-'+e.type+(isToday&&e.at<=data.now?' is-past':''));chip.tabIndex=0;const tipId='pm-tip-'+n;chip.setAttribute('aria-describedby',tipId);chip.dataset.at=e.at;
    chip.append(el('span','pm-dot is-'+e.type),el('span','pm-time',e.minute),el('span','pm-label',e.label+(e.type==='convoy'?' · '+fmt(e.amount):'')));
    if(e.count>1)chip.append(el('span','pm-times','×'+e.count));
    const tip=el('span','pm-tip');tip.id=tipId;tip.setAttribute('role','tooltip');
    tip.append(el('strong','',TYPES[e.type][1]+' · arrivée le '+paris(e.at,{day:'2-digit',month:'2-digit'})+' à '+e.minute));
    const remaining=el('span','pm-left',left(e.at-(Date.now()/1000-offset)));remaining.dataset.at=e.at;tip.append(remaining);
    const row=(k,v)=>{const r=el('span','pm-row');r.append(el('span','',k),el('span','',v));tip.append(r);};
    if(e.count>1)row(e.type==='flood'?'Floods identiques':e.type==='hunt'?'Chasses identiques':'Convois identiques',String(e.count)+(e.type==='hunt'?' · '+fmt(e.amount*e.count)+' cm² au total':''));
    for(const [k,v] of e.details)row(k,v);
    chip.append(tip);items.append(chip);
    for(const type of ['mouseenter','focus'])chip.addEventListener(type,()=>fitTip(chip,tip));
   }
   if(!items.childElementCount)continue;
   if(hour<currentHour){folded+=items.childElementCount;foldedHours++;if(!showEarlier){visible-=items.childElementCount;continue;}}
   const li=el('li','pm-hour');li.append(el('span','pm-hour-label',String(hour).padStart(2,'0')+' h'),items);list.append(li);
  }
  const body=[nav,el('h2','pm-title',data.label),filters];
  if(folded){const toggle=el('button','pm-earlier',showEarlier?`Masquer les heures précédentes (${folded} mouvement${folded>1?'s':''})`:`Afficher les heures précédentes · ${foldedHours} heure${foldedHours>1?'s':''}, ${folded} mouvement${folded>1?'s':''}`);toggle.type='button';toggle.setAttribute('aria-expanded',String(showEarlier));toggle.addEventListener('click',()=>{showEarlier=!showEarlier;render();});body.push(toggle);}
  if(!data.events.length)body.push(el('p','pm-empty',data.day<data.today?'Aucune arrivée enregistrée ce jour-là.':'Aucune arrivée prévue ce jour-là pour le moment.'));
  else if(!visible)body.push(el('p','pm-empty',folded&&!showEarlier?'Plus aucune arrivée prévue pour le reste de la journée.':'Aucun mouvement pour ces filtres.'));
  else body.push(list);
  body.push(el('p','pm-note','Floods et convois déclarés, chasses relevées par UPTEP+ ou déclarées sur Discord. Heures de Paris ; ce ne sont pas des confirmations du jeu.'));
  root.replaceChildren(...body);
  clearInterval(clock);clock=setInterval(()=>{const now=Date.now()/1000-offset;for(const node of root.querySelectorAll('.pm-left'))node.textContent=left(Number(node.dataset.at)-now);if(isToday)for(const chip of root.querySelectorAll('.pm-item'))chip.classList.toggle('is-past',Number(chip.dataset.at)<=now);
   // A new hour starts: the hour just ended joins the folded ones.
   if(isToday&&parseInt(paris(now,{hour:'2-digit',hourCycle:'h23'}),10)!==currentHour){data.now=Math.floor(now);render();}},30000);
 }
 return {start(apiFn,container){api=apiFn;root=container;load(null);}};
})();
