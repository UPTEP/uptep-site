function mountChaine(context,data){
 const doc=context.document,win=context.window,params=new URLSearchParams(win.location.search);
 if(win.location.pathname.toLowerCase()!=='/alliance.php'||!params.has('Membres')||!data)return ()=>{};
 for(const [key,value] of params)if(/alliance|tag/i.test(key)&&value&&value!=='UPTEP')return ()=>{};
 const ranks=['Grenier','Passeur','Chasseur','A flooder','Ne pas toucher','Hors chaîne'],saved=new Map(data.players.map(p=>[p.pseudo,p]));
 const style=doc.createElement('style');style.textContent='.uptep-chain-cell{white-space:nowrap;text-align:center!important;padding:2px 5px!important}.uptep-chain-badge{display:inline-block;border-radius:2px;padding:3px 7px;font:11px Verdana,sans-serif;box-shadow:inset 0 1px 0 #ffffff18}.uptep-chain-0{background:#805710;color:#fff1cc}.uptep-chain-1{background:#2b638c;color:#f1f7ff}.uptep-chain-2{background:#3c702f;color:#f1f7df}.uptep-chain-3{background:#a44e17;color:#fff3dc}.uptep-chain-4{background:#963a42;color:#fff0ed}.uptep-chain-5{background:#62616a;color:#f5f3f1}.uptep-chain-move{font-size:18px;font-weight:700}.uptep-chain-up{color:#355c3b}.uptep-chain-down{color:#883924}.uptep-chain-aligned{color:#486033}tr.uptep-chain-self>td,tr.uptep-chain-self>th{background:#dfa08b!important}tr.uptep-chain-self>td:first-child,tr.uptep-chain-self>th:first-child{box-shadow:inset 4px 0 #992f24}tr.uptep-chain-target>td,tr.uptep-chain-target>th{background:#b8c58b!important}tr.uptep-chain-target>td:first-child,tr.uptep-chain-target>th:first-child{box-shadow:inset 4px 0 #566332}.uptep-chain-mark{display:inline-block;font:10px Verdana;margin-left:5px;color:#39421e}tr.uptep-chain-self .uptep-chain-mark{color:#74251e}';doc.head.append(style);
 const legend=doc.createElement('div');legend.className='uptep-chain-legend';legend.setAttribute('role','note');legend.setAttribute('aria-label','Légende des couleurs de la chaîne');
 legend.style.cssText='display:flex;flex-wrap:wrap;gap:8px 20px;align-items:center;margin:10px 0;padding:6px 0;color:#302b18;font:12px Verdana,sans-serif;text-align:left;';
 for(const [color,border,text] of [['#dfa08b','#992f24','Ta position'],['#b8c58b','#566332','Joueurs à portée de flood situés sous toi dans la chaîne']]){const item=doc.createElement('span'),swatch=doc.createElement('span');item.style.cssText='display:inline-flex;align-items:center;gap:7px;';swatch.setAttribute('aria-hidden','true');swatch.style.cssText='display:inline-block;flex:0 0 18px;width:18px;height:13px;background:'+color+';border-left:3px solid '+border+';';item.append(swatch,doc.createTextNode(text));legend.append(item);}
 const added=new Set(),marked=new Set(),removedNumbers=new Map();let previous='';
 const norm=s=>s.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().trim();
 const number=s=>{const text=s.replace(/[\s\u00a0\u202f]/g,'');return /^\d+$/.test(text)?BigInt(text):null;};
 function clear(){for(const [cell,{row,next}] of removedNumbers)row.insertBefore(cell,next?.parentNode===row?next:null);removedNumbers.clear();for(const n of added)n.remove();added.clear();for(const row of marked)row.classList.remove('uptep-chain-self','uptep-chain-target');marked.clear();}
 function append(row,tag,text,cls){const cell=doc.createElement(tag);cell.className='uptep-chain-cell '+(cls||'');cell.textContent=text;row.append(cell);added.add(cell);return cell;}
 function update(){
  let table,head,pseudoIndex,fieldIndex,stateIndex;
  for(const candidate of doc.querySelectorAll('#centre table, table')){const header=[...candidate.rows].find(r=>[...r.cells].some(c=>norm(c.textContent)==='pseudo')&&[...r.cells].some(c=>norm(c.textContent)==='terrain'));if(header){table=candidate;head=header;const labels=[...head.cells].map(c=>norm(c.textContent));pseudoIndex=labels.indexOf('pseudo');fieldIndex=labels.indexOf('terrain');stateIndex=labels.indexOf('etat');break;}}
  if(!table){legend.remove();if(added.size)clear();previous='';return;}
  const rows=[];
  for(const row of table.rows){if(row===head||row.cells.length<=fieldIndex)continue;const cell=row.cells[pseudoIndex];if(!cell)continue;
   let pseudo='';for(const link of cell.querySelectorAll('a[href]')){try{const url=new URL(link.getAttribute('href'),win.location.href);for(const [key,value] of url.searchParams)if(key.toLowerCase()==='pseudo')pseudo=value;}catch{}if(!pseudo&&saved.has(link.textContent.trim()))pseudo=link.textContent.trim();if(pseudo)break;}
   if(!pseudo){const clone=cell.cloneNode(true);clone.querySelectorAll('.uptep-chain-mark').forEach(n=>n.remove());pseudo=clone.textContent.trim();}
   const player=saved.get(pseudo),field=number(row.cells[fieldIndex].textContent);if(!pseudo||field===null)continue;
   const state=row.cells[stateIndex];const hints=state?norm([...state.querySelectorAll('[title],[alt],img')].map(n=>(n.getAttribute('title')||'')+' '+(n.getAttribute('alt')||'')+' '+(n.getAttribute('src')||'')).join(' ')+' '+state.textContent):'';
   const knownState=/vacanc|banni|actif|inactif/.test(hints);const blocked=knownState?/vacanc|banni/.test(hints):!!(player?.on_holiday||player?.is_banned);
   rows.push({row,cell,pseudo,field,blocked,id:String(player?.player_id||''),grade:player?.grade||null,order:player?.sort_order??null});
  }
  if(!rows.some(p=>p.id===String(data.self))){legend.remove();return;}
  if(legend.nextSibling!==table)table.before(legend);
  const signature=JSON.stringify(rows.map(p=>[p.pseudo,String(p.field),p.blocked]));if(signature===previous&&head.querySelector('.uptep-chain-cell')&&rows.every(p=>p.row.querySelector('.uptep-chain-cell')))return;previous=signature;clear();
  const numberIndex=[...head.cells].findIndex(c=>norm(c.textContent)==='rang')-1;
  if(numberIndex>=0){for(const row of [head,...rows.map(p=>p.row)]){const cell=row.cells[numberIndex];if(cell){removedNumbers.set(cell,{row,next:cell.nextSibling});cell.remove();}}}
  for(const name of ['Grade de chaîne','Position']){const th=append(head,'th',name,'sorter-false');th.scope='col';th.setAttribute('data-sorter','false');}
  const self=rows.find(p=>p.id===String(data.self));const eligible=rows.filter(p=>ranks.indexOf(p.grade)>=0&&ranks.indexOf(p.grade)<3);
  const compare=(a,b)=>ranks.indexOf(a.grade)-ranks.indexOf(b.grade)||(a.order??Infinity)-(b.order??Infinity)||0;
  for(const p of rows){
   const cell=append(p.row,'td',''),badge=doc.createElement('span');badge.className='uptep-chain-badge uptep-chain-'+ranks.indexOf(p.grade);badge.textContent=p.grade?(p.grade+(p.order!==null?' · '+p.order:'')):'—';cell.append(badge);
   let movement='excluded';if(eligible.includes(p)){const actualStart=eligible.filter(o=>o.field>p.field).length+1,actualEnd=actualStart+eligible.filter(o=>o.field===p.field).length-1,targetStart=eligible.filter(o=>compare(o,p)<0).length+1,targetEnd=targetStart+eligible.filter(o=>compare(o,p)===0).length-1;movement=actualStart>targetEnd?'up':actualEnd<targetStart?'down':'aligned';}
   const position=append(p.row,'td',({up:'↑',down:'↓',aligned:'✓',excluded:'—'})[movement],'uptep-chain-move uptep-chain-'+movement);position.title=({up:'Monter',down:'Descendre',aligned:'À sa place',excluded:'Hors classement de position'})[movement];
   let focus='';if(p===self)focus='self';else if(self&&eligible.includes(self)&&!self.blocked&&self.field>0n&&!p.blocked&&p.field>0n&&ranks.indexOf(p.grade)>=0&&ranks.indexOf(p.grade)<4){const difference=ranks.indexOf(p.grade)-ranks.indexOf(self.grade),below=difference>0||(difference===0&&self.order!==null&&p.order!==null&&p.order>self.order);if(below&&p.field*2n>=self.field&&p.field<=self.field*3n)focus='target';}
   if(focus){p.row.classList.add('uptep-chain-'+focus);marked.add(p.row);}
  }
 }
 update();const timer=win.setInterval(update,1000);return ()=>{win.clearInterval(timer);clear();legend.remove();style.remove();};
}
