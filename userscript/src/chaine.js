function chaineOrderValue(p){return p.order===null||p.order===undefined?Infinity:(p.grade==='Passeur'?-p.order:p.order);}
// Distance and one-way attack travel time, verified against https://toolzzz.fr/durees2.php.
function chaineTravel(origin,target,speed){
 const coord=value=>value!==null&&value!==undefined&&value!==''&&Number.isSafeInteger(Number(value))&&Number(value)>=0;
 if(!origin||!target||![origin.x,origin.y,target.x,target.y].every(coord))return {distance:null,seconds:null};
 const distance=Math.hypot(Number(target.x)-Number(origin.x),Number(target.y)-Number(origin.y));
 const knownSpeed=typeof speed==='number'&&Number.isInteger(speed)&&speed>=0&&speed<=999;
 return {distance,seconds:knownSpeed?Math.round(86400*(1-Math.exp(-distance/350))*7.375*Math.pow(0.9,speed)):null};
}
function chaineTravelDuration(seconds){
 if(seconds===null)return '—';
 const days=Math.floor(seconds/86400),hours=Math.floor(seconds%86400/3600),minutes=Math.floor(seconds%3600/60),remaining=seconds%60;
 return (days?days+'j ':'')+String(hours).padStart(2,'0')+':'+String(minutes).padStart(2,'0')+':'+String(remaining).padStart(2,'0');
}
function mountChaine(context,data){
 const doc=context.document,win=context.window,params=new URLSearchParams(win.location.search);
 if(win.location.pathname.toLowerCase()!=='/alliance.php'||!params.has('Membres')||!data)return ()=>{};
 for(const [key,value] of params)if(/alliance|tag/i.test(key)&&value&&value!=='UPTEP')return ()=>{};
 const ranks=['Grenier','Passeur','Chasseur','A flooder','Ne pas toucher','Hors chaîne'],saved=new Map(data.players.map(p=>[p.pseudo,p]));
 const origin=data.players.find(p=>String(p.player_id)===String(data.self));
 const style=doc.createElement('style');style.textContent='.uptep-chain-cell{white-space:nowrap;text-align:center!important;padding:2px 5px!important}.uptep-chain-badge{display:inline-block;border-radius:2px;padding:3px 7px;font:11px Verdana,sans-serif;box-shadow:inset 0 1px 0 #ffffff18}.uptep-chain-0{background:#805710;color:#fff1cc}.uptep-chain-1{background:#2b638c;color:#f1f7ff}.uptep-chain-2{background:#3c702f;color:#f1f7df}.uptep-chain-3{background:#a44e17;color:#fff3dc}.uptep-chain-4{background:#963a42;color:#fff0ed}.uptep-chain-5{background:#62616a;color:#f5f3f1}.uptep-chain-move{font-size:18px;font-weight:700}.uptep-chain-up{color:#355c3b}.uptep-chain-down{color:#883924}.uptep-chain-aligned{color:#486033}tr.uptep-chain-self>td,tr.uptep-chain-self>th{background:#dfa08b!important}tr.uptep-chain-self>td:first-child,tr.uptep-chain-self>th:first-child{box-shadow:inset 4px 0 #992f24}tr.uptep-chain-target>td,tr.uptep-chain-target>th{background:#b8c58b!important}tr.uptep-chain-target>td:first-child,tr.uptep-chain-target>th:first-child{box-shadow:inset 4px 0 #566332}.uptep-chain-mark{display:inline-block;font:10px Verdana;margin-left:5px;color:#39421e}tr.uptep-chain-self .uptep-chain-mark{color:#74251e}';doc.head.append(style);
 const legend=doc.createElement('div');legend.className='uptep-chain-legend';legend.setAttribute('role','note');legend.setAttribute('aria-label','Légende des couleurs de la chaîne');
 legend.style.cssText='display:flex;flex-wrap:wrap;gap:8px 20px;align-items:center;margin:10px 0;padding:6px 0;color:#302b18;font:12px Verdana,sans-serif;text-align:left;';
 for(const [color,border,text] of [['#dfa08b','#992f24','Ta position'],['#b8c58b','#566332','Joueurs à portée de flood situés sous toi dans la chaîne']]){const item=doc.createElement('span'),swatch=doc.createElement('span');item.style.cssText='display:inline-flex;align-items:center;gap:7px;';swatch.setAttribute('aria-hidden','true');swatch.style.cssText='display:inline-block;flex:0 0 18px;width:18px;height:13px;background:'+color+';border-left:3px solid '+border+';';item.append(swatch,doc.createTextNode(text));legend.append(item);}
 const added=new Set(),marked=new Set(),pseudoCells=new Set(),removedNumbers=new Map(),mergedStats=[],hiddenCells=[],movedState=[];let previous='';
 const norm=s=>s.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().trim();
 const number=s=>{const text=s.replace(/[\s\u00a0\u202f]/g,'');return /^\d+$/.test(text)?BigInt(text):null;};
 // Native headers can span multiple cells (for example Terrain and its attack icon).
 const columnOf=(row,cell)=>{let column=0;for(const current of row.cells){if(current===cell)return column;column+=current.colSpan;}return -1;};
 const cellAt=(row,column)=>{let start=0;for(const cell of row.cells){if(column>=start&&column<start+cell.colSpan)return cell;start+=cell.colSpan;}return null;};
 const headerColumn=(row,label)=>columnOf(row,[...row.cells].find(c=>norm(c.textContent)===label));
 function clear(){for(const cell of pseudoCells)cell.classList.remove('uptep-chain-pseudo');pseudoCells.clear();for(const [anchor,node] of movedState)anchor.replaceWith(node);movedState.length=0;for(const [anchor,cell] of hiddenCells)anchor.replaceWith(cell);hiddenCells.length=0;for(const {cell,originals} of mergedStats){cell.remove();for(const [anchor,original] of originals)anchor.replaceWith(original);}mergedStats.length=0;for(const [cell,{row,next}] of removedNumbers)row.insertBefore(cell,next?.parentNode===row?next:null);removedNumbers.clear();for(const n of added)n.remove();added.clear();for(const row of marked)row.classList.remove('uptep-chain-self','uptep-chain-target');marked.clear();}
 function append(row,tag,text,cls){const cell=doc.createElement(tag);cell.className='uptep-chain-cell '+(cls||'');cell.textContent=text;row.append(cell);added.add(cell);return cell;}
 const sortHeaders=new Map();let sortable=null,sortKey=null,sortDirection=1;
 const collator=new Intl.Collator('fr',{sensitivity:'base',numeric:true});
 function prepareSort(table,head,rows){
  sortable={table,head,rows};
  for(const cell of head.cells){const key=norm(cell.textContent);if(!['pseudo','terrain','distance','grade'].includes(key))continue;
   if(!sortHeaders.has(cell))sortHeaders.set(cell,new Map(['tabindex','role','aria-sort','data-uptep-sort'].map(name=>[name,cell.getAttribute(name)])));
   cell.setAttribute('tabindex','0');cell.setAttribute('role','button');cell.setAttribute('data-uptep-sort',key);
   cell.setAttribute('aria-sort',key===sortKey?(sortDirection===1?'ascending':'descending'):'none');
  }
 }
 function handleSort(event){
  const cell=event.target?.closest?.('th,td');if(!sortable||!cell||cell.parentNode!==sortable.head)return;
  // The native sorter caches old column indices and renumbers the first cell after sorting.
  // Intercept its mouse and keyboard events before they reach the modified table.
  if(event.type==='keydown'&&!['Enter',' '].includes(event.key))return;
  event.stopImmediatePropagation();event.preventDefault();
  if(event.type!=='click'&&!(event.type==='keydown'&&['Enter',' '].includes(event.key)))return;
  const key=cell.getAttribute('data-uptep-sort');if(!key)return;
  update();sortDirection=sortKey===key?-sortDirection:(key==='terrain'?-1:1);sortKey=key;
  const compare=(a,b)=>{if(key==='pseudo')return collator.compare(a.pseudo,b.pseudo);if(key==='grade')return ranks.indexOf(a.grade)-ranks.indexOf(b.grade)||chaineOrderValue(a)-chaineOrderValue(b)||0;const av=key==='distance'?a.distance:a.field,bv=key==='distance'?b.distance:b.field;return av<bv?-1:av>bv?1:0;};
  const parents=new Map();for(const entry of sortable.rows){const parent=entry.row.parentNode;if(!parents.has(parent))parents.set(parent,[]);parents.get(parent).push(entry);}
  for(const entries of parents.values()){
   const anchors=entries.map(p=>{const anchor=doc.createComment('uptep-sort-row');p.row.before(anchor);return anchor;});
   const ordered=[...entries].sort((a,b)=>(key==='grade'&&(ranks.indexOf(a.grade)<0||ranks.indexOf(b.grade)<0)?(ranks.indexOf(a.grade)<0?1:0)-(ranks.indexOf(b.grade)<0?1:0):key==='distance'&&(a.distance===null||b.distance===null)?(a.distance===null?1:0)-(b.distance===null?1:0):sortDirection*compare(a,b))||collator.compare(a.pseudo,b.pseudo));
   for(let i=0;i<anchors.length;i++)anchors[i].replaceWith(ordered[i].row);
  }
  update();prepareSort(sortable.table,sortable.head,sortable.rows);
 }
 const sortEvents=['click','mousedown','mouseup','keydown'];for(const name of sortEvents)win.addEventListener(name,handleSort,true);
 style.textContent+=' .uptep-chain-pseudo,th[data-uptep-sort="pseudo"]{text-align:left!important;padding-left:12px!important}th[data-uptep-sort]{cursor:pointer;background-image:none!important}th[data-uptep-sort][aria-sort="ascending"]::after{content:" ↑"}th[data-uptep-sort][aria-sort="descending"]::after{content:" ↓"}';
 style.textContent+=' .uptep-chain-grade,.uptep-chain-heading-grade{text-align:left!important;padding-left:12px!important;padding-right:8px!important}th.uptep-chain-heading-grade{width:14%}th[data-uptep-sort="pseudo"]{width:24%}th[data-uptep-sort="terrain"]{width:14%}th.uptep-bat-tech{width:11%}th.uptep-chain-heading-distance{width:10%}th.uptep-chain-heading-trajet{width:18%}th.uptep-chain-heading-position{width:9%}';
 function update(){
  let table,head,pseudoIndex,fieldIndex,stateIndex;
  for(const candidate of doc.querySelectorAll('#centre table, table')){const header=[...candidate.rows].find(r=>[...r.cells].some(c=>norm(c.textContent)==='pseudo')&&[...r.cells].some(c=>norm(c.textContent)==='terrain'));if(header){table=candidate;head=header;pseudoIndex=headerColumn(head,'pseudo');fieldIndex=headerColumn(head,'terrain');stateIndex=headerColumn(head,'etat');break;}}
  if(!table){sortable=null;legend.remove();if(added.size)clear();previous='';return;}
  const rows=[];
  for(const row of table.rows){if(row===head)continue;const fieldCell=cellAt(row,fieldIndex);if(!fieldCell)continue;const cell=cellAt(row,pseudoIndex);if(!cell)continue;
   let pseudo='';for(const link of cell.querySelectorAll('a[href]')){try{const url=new URL(link.getAttribute('href'),win.location.href);for(const [key,value] of url.searchParams)if(key.toLowerCase()==='pseudo')pseudo=value;}catch{}if(!pseudo&&saved.has(link.textContent.trim()))pseudo=link.textContent.trim();if(pseudo)break;}
   if(!pseudo){const clone=cell.cloneNode(true);clone.querySelectorAll('.uptep-chain-mark,.uptep-chain-status,.uptep-chain-colony').forEach(n=>n.remove());pseudo=clone.textContent.trim();}
   const player=saved.get(pseudo),field=number(fieldCell.textContent);if(!pseudo||field===null)continue;
   const state=cellAt(row,stateIndex)||(cell.querySelector('.uptep-chain-status')?cell:null);const hints=state?norm([...state.querySelectorAll('[title],[alt],img')].map(n=>(n.getAttribute('title')||'')+' '+(n.getAttribute('alt')||'')+' '+(n.getAttribute('src')||'')).join(' ')+' '+state.textContent):'';
   const knownState=/vacanc|banni|actif|inactif/.test(hints);const blocked=knownState?/vacanc|banni/.test(hints):!!(player?.on_holiday||player?.is_banned);
   rows.push({row,cell,pseudo,field,blocked,distance:chaineTravel(origin,player,data.attack_speed).distance,id:String(player?.player_id||''),grade:player?.grade||null,order:player?.sort_order??null});
  }
  if(!rows.some(p=>p.id===String(data.self))){sortable=null;legend.remove();return;}
  prepareSort(table,head,rows);
  // Keep the legend above the multiflood toolbar so their refreshes never swap them.
  const anchor=table.previousElementSibling?.classList.contains('uptep-mf-tools')?table.previousElementSibling:table;
  if(legend.nextElementSibling!==anchor)anchor.before(legend);
  const signature=JSON.stringify(rows.map(p=>[p.pseudo,String(p.field),p.blocked]));if(signature===previous&&head.querySelector('.uptep-chain-cell')&&rows.every(p=>p.row.querySelector('.uptep-chain-cell')))return;previous=signature;clear();
  const numberIndex=[...head.cells].findIndex(c=>norm(c.textContent)==='rang')-1;
  if(numberIndex>=0){for(const row of [head,...rows.map(p=>p.row)]){const cell=row.cells[numberIndex];if(cell){removedNumbers.set(cell,{row,next:cell.nextSibling});cell.remove();}}}
  const techIndex=headerColumn(head,'technologie'),buildingIndex=headerColumn(head,'fourmiliere');
  if(techIndex>=0&&buildingIndex>=0){
   for(const row of [head,...rows.map(p=>p.row)]){
    const tech=cellAt(row,techIndex),building=cellAt(row,buildingIndex);if(!tech||!building||tech===building)continue;
    if(row!==head&&(number(tech.textContent)===null||number(building.textContent)===null))continue;
    const cell=doc.createElement(row===head?'th':'td');cell.className='uptep-bat-tech sorter-false';cell.style.cssText='white-space:nowrap;text-align:center;padding:2px 5px';
    cell.textContent=row===head?'Bat/Tech':building.textContent.trim()+' / '+tech.textContent.trim();
    cell.title=row===head?'Points de fourmilière / Points de technologie':'Fourmilière : '+building.textContent.trim()+' · Technologie : '+tech.textContent.trim();
    if(row===head){cell.scope='col';cell.setAttribute('data-sorter','false');}
    const originals=[tech,building].map(original=>{const anchor=doc.createComment('uptep-bat-tech');original.before(anchor);return [anchor,original];});
    tech.before(cell);tech.remove();building.remove();mergedStats.push({cell,originals});
   }
  }
  // Move native status nodes rather than redraw them: keep icons, titles and links.
  const hideCell=cell=>{const anchor=doc.createComment('uptep-hidden-column');cell.before(anchor);cell.remove();hiddenCells.push([anchor,cell]);};
  const statusColumn=headerColumn(head,'etat'),rankColumn=headerColumn(head,'rang');
  const statusWidth=cellAt(head,statusColumn)?.colSpan||0;
  const hide=[];
  for(const row of [head,...rows.map(p=>p.row)]){
   const statuses=[...new Set(Array.from({length:statusWidth},(_,offset)=>cellAt(row,statusColumn+offset)).filter(Boolean))],rank=cellAt(row,rankColumn);
   if(statuses.length&&row!==head){
    const pseudoCell=rows.find(p=>p.row===row).cell;pseudoCell.classList.add('uptep-chain-pseudo');pseudoCells.add(pseudoCell);
    const left=doc.createElement('span'),right=doc.createElement('span');left.className='uptep-chain-status';right.className='uptep-chain-colony';
    left.style.cssText='display:inline-flex;align-items:center;gap:2px;margin-right:5px;vertical-align:middle';right.style.cssText='display:inline-flex;align-items:center;gap:2px;margin-left:5px;vertical-align:middle';
    function move(node){
     if(node.nodeType===1&&node.querySelectorAll('img').length>1){for(const child of [...node.childNodes])move(child);return;}
     if(node.nodeType===3&&!node.textContent.trim())return;
     const hints=norm((node.textContent||'')+' '+(node.outerHTML||''));
     const anchor=doc.createComment('uptep-state-icon');node.before(anchor);movedState.push([anchor,node]);
     (/colo|warning|attention/.test(hints)?right:left).append(node);
    }
    for(const status of statuses)for(const node of [...status.childNodes])move(node);
    pseudoCell.prepend(left);const link=[...pseudoCell.querySelectorAll('a[href]')].find(a=>/pseudo=/i.test(a.getAttribute('href')));if(link)link.after(right);else pseudoCell.append(right);
    added.add(left);added.add(right);
   }
   hide.push(...statuses);if(rank)hide.push(rank);
  }
  for(const cell of hide)hideCell(cell);
  for(const name of ['Distance','Trajet','Grade','Position']){const th=append(head,'th',name,'sorter-false uptep-chain-heading-'+norm(name));th.scope='col';th.setAttribute('data-sorter','false');if(name==='Grade')head.prepend(th);}
  const self=rows.find(p=>p.id===String(data.self));const eligible=rows.filter(p=>ranks.indexOf(p.grade)>=0&&ranks.indexOf(p.grade)<3);
  const compare=(a,b)=>ranks.indexOf(a.grade)-ranks.indexOf(b.grade)||chaineOrderValue(a)-chaineOrderValue(b)||0;
  for(const p of rows){
   const target=saved.get(p.pseudo),travel=chaineTravel(origin,target,data.attack_speed);
   const distanceCell=append(p.row,'td',travel.distance===null?'—':Math.ceil(travel.distance).toLocaleString('fr-FR'),'uptep-chain-distance');
   distanceCell.title=travel.distance===null?'Coordonnées indisponibles dans les relevés UPTEP.':'Distance depuis '+origin.pseudo+' ('+origin.x+', '+origin.y+') vers '+p.pseudo+' ('+target.x+', '+target.y+').';
   const travelCell=append(p.row,'td',chaineTravelDuration(travel.seconds),'uptep-chain-travel');
   travelCell.title=travel.distance===null?'Coordonnées indisponibles dans les relevés UPTEP.':travel.seconds===null?'Vitesse d’attaque non renseignée : synchronise le laboratoire ou complète Mes stats sur UPTEP.':'Trajet aller estimé · Vitesse d’attaque '+data.attack_speed+(data.research_synced_at?' · Laboratoire relevé le '+new Date(data.research_synced_at).toLocaleString('fr-FR',{timeZone:'Europe/Paris'}):'');
   const cell=append(p.row,'td','','uptep-chain-grade'),badge=doc.createElement('span');badge.className='uptep-chain-badge uptep-chain-'+ranks.indexOf(p.grade);badge.textContent=p.grade?(p.grade+(p.order!==null?' · '+p.order:'')):'—';cell.append(badge);p.row.prepend(cell);
   let movement='excluded';if(eligible.includes(p)){const actualStart=eligible.filter(o=>o.field>p.field).length+1,actualEnd=actualStart+eligible.filter(o=>o.field===p.field).length-1,targetStart=eligible.filter(o=>compare(o,p)<0).length+1,targetEnd=targetStart+eligible.filter(o=>compare(o,p)===0).length-1;movement=actualStart>targetEnd?'up':actualEnd<targetStart?'down':'aligned';}
   const position=append(p.row,'td',({up:'↑',down:'↓',aligned:'✓',excluded:'—'})[movement],'uptep-chain-move uptep-chain-'+movement);position.title=({up:'Monter',down:'Descendre',aligned:'À sa place',excluded:'Hors classement de position'})[movement];
   let focus='';if(p===self)focus='self';else if(self&&eligible.includes(self)&&!self.blocked&&self.field>0n&&!p.blocked&&p.field>0n&&ranks.indexOf(p.grade)>=0&&ranks.indexOf(p.grade)<4){const difference=ranks.indexOf(p.grade)-ranks.indexOf(self.grade),below=difference>0||(difference===0&&self.order!==null&&p.order!==null&&(self.grade==='Passeur'?p.order<self.order:p.order>self.order));if(below&&p.field*2n>=self.field&&p.field<=self.field*3n)focus='target';}
   if(focus){p.row.classList.add('uptep-chain-'+focus);marked.add(p.row);}
  }
  prepareSort(table,head,rows);
 }
 update();const timer=win.setInterval(update,1000);return ()=>{win.clearInterval(timer);for(const name of sortEvents)win.removeEventListener(name,handleSort,true);for(const [cell,attrs] of sortHeaders)for(const [name,value] of attrs){if(value===null)cell.removeAttribute(name);else cell.setAttribute(name,value);}clear();legend.remove();style.remove();};
}
