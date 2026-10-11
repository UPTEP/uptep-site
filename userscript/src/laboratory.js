// Game reference: prerequisites supplied from S5 Reine on 2026-10-11.
// Base seconds/food: toolzzz.fr/tdp.php; base combat stats: ZzzelpScript/Armee.js.
// Key order is explicit: UPTEP's army order differs from the game's order.
const laboratoryUnits = [
 ['jsn','Jeune soldate naine',300,16,3,8,{salle_combat:1},{}],
 ['sn','Soldate naine',450,20,5,10,{salle_combat:3},{}],
 ['ne','Naine d’élite',570,26,7,13,{caserne:4},{}],
 ['js','Jeune soldate',740,30,10,16,{salle_combat:7},{}],
 ['s','Soldate',1000,36,15,20,{caserne:9},{}],
 ['c','Concierge',1410,70,1,30,{salle_combat:10},{genetique:5}],
 ['ce','Concierge d’élite',null,100,1,40,null,null],
 ['a','Artilleuse',1440,30,30,10,{salle_combat:11},{acide:1}],
 ['ae','Artilleuse d’élite',1520,34,35,12,{caserne:13},{acide:5}],
 ['se','Soldate d’élite',1450,44,24,27,{caserne:17},{}],
 ['t','Tank',1860,100,55,35,{caserne:23},{genetique:15}],
 ['te','Tank d’élite',null,150,80,50,null,null],
 ['tu','Tueuse',2740,80,50,50,{salle_combat:25},{poison:4}],
 ['tue','Tueuse d’élite',2740,90,55,55,{caserne:28},{poison:7}]
];
function laboratoryInteger(value){
 if(typeof value==='bigint')return value>=0n?value:null;
 if(typeof value==='number'&&!Number.isSafeInteger(value))return null;
 if(!['string','number'].includes(typeof value)||!/^\d+$/.test(String(value)))return null;
 return BigInt(value);
}
function laboratoryLevel(value){const n=laboratoryInteger(value);return n!==null&&n<=999n?Number(n):null;}
function laboratoryUnlocked(unit,data){
 if(unit[2]===null)return false;
 let unknown=false;
 for(const [group,rules] of [['buildings',unit[6]],['research',unit[7]]])for(const [key,minimum] of Object.entries(rules)){
  const level=laboratoryLevel(data?.[group]?.[key]);
  if(level===null)unknown=true;else if(level<minimum)return false;
 }
 return unknown?null:true;
}
function laboratoryCompare(data,key,workers){
 if(!['armes','bouclier'].includes(key))throw Error('Recherche inconnue.');
 workers=laboratoryInteger(workers);if(workers===null)throw Error('Coût en ouvrières non reconnu.');
 const level=laboratoryLevel(data?.research?.[key]);
 const tdp=[data?.buildings?.couveuse,data?.buildings?.solarium,data?.research?.technique_ponte].map(laboratoryLevel);
 if(level===null||tdp.includes(null))throw Error('Complète les niveaux de couveuse, solarium, technique de ponte et de cette recherche dans UPTEP+.');
 const stat=key==='armes'?4:5;let base=0n;
 for(const unit of laboratoryUnits){const amount=laboratoryInteger(data?.army?.[unit[0]]);if(amount===null)throw Error('Armée incomplète : synchronise ton armée depuis la page Armée.');base+=amount*BigInt(unit[stat]);}
 const bonus=BigInt(10+level);
 // Bonuses are additive. Compare differences of whole-army integer totals.
 const gain=base*(bonus+1n)/10n-base*bonus/10n;
 const alternatives=[];let unknown=0;
 for(const unit of laboratoryUnits){const available=laboratoryUnlocked(unit,data);if(available===null){unknown++;continue;}if(!available)continue;
  // The common 0.9^TDP factor cancels; BigInt keeps large armies exact.
  const count=workers*60n/BigInt(unit[2]);
  const newBase=count*BigInt(unit[stat]);
  alternatives.push({key:unit[0],name:unit[1],count,gain:(base+newBase)*bonus/10n-base*bonus/10n,food:count*BigInt(unit[3])});
 }
 alternatives.sort((a,b)=>a.gain===b.gain?0:a.gain>b.gain?-1:1);
 return {level,workers,tdp:tdp.reduce((a,b)=>a+b,0),seconds:Number(workers)*60*Math.pow(.9,tdp.reduce((a,b)=>a+b,0)),gain,alternatives,unknown};
}
function laboratoryDuration(seconds){
 if(!Number.isFinite(seconds))return 'durée trop grande';
 const total=BigInt(Math.ceil(seconds)),days=total/86400n;
 return (days?days.toLocaleString('fr-FR')+' j ':'')+[total/3600n%24n,total/60n%60n,total%60n].map(v=>String(v).padStart(2,'0')).join(':');
}
function readLaboratoryOffer(row){
 const level=row.querySelector('.niveau_amelioration')?.textContent.trim().match(/^niveau\s+(\d+)\s*$/i);
 if(!level)throw Error('Recherche en cours ou en file : comparaison suspendue jusqu’à sa fin.');
 const number=selector=>{const nodes=row.querySelectorAll('.cout_amelioration '+selector);return nodes.length===1?laboratoryInteger(nodes[0].textContent.replace(/[\s\u00a0\u202f]/g,'')):null;};
 const workers=number('.ouvriere');if(workers===null)throw Error('Coût en ouvrières non reconnu : comparaison indisponible.');
 return {level:Number(level[1]),workers,food:number('.nourriture'),materials:number('.materiaux'),time:row.querySelector('.cout_amelioration .temps')?.textContent.trim()||null};
}
function mountLaboratory(context,snapshot){
 const doc=context.document,win=context.window;if(win.location.pathname.toLowerCase()!=='/laboratoire.php')return ()=>{};
 const added=[];const format=n=>n.toLocaleString('fr-FR');
 const element=(tag,text,parent)=>{const node=doc.createElement(tag);if(text!==null)node.textContent=text;if(parent)parent.append(node);return node;};
 const style=element('style',`.uptep-lab-analysis{padding:12px 15px;margin:6px 0 14px;background:#dfcf95;border:1px solid #82743d;border-left:4px solid #53633a;color:#292712;text-align:left;font:12px/1.6 Verdana}.uptep-lab-analysis h3{font-size:14px;margin:0 0 6px}.uptep-lab-analysis p{margin:5px 0}.uptep-lab-analysis table{width:100%;border-collapse:collapse;margin-top:8px}.uptep-lab-analysis th,.uptep-lab-analysis td{padding:5px 8px;text-align:right;border-bottom:1px solid #ad9b5a}.uptep-lab-analysis th:first-child,.uptep-lab-analysis td:first-child{text-align:left}.uptep-lab-analysis .uptep-lab-warning{color:#873b20}.uptep-lab-analysis summary{cursor:pointer;font-weight:bold}.uptep-lab-analysis small{display:block;color:#5c5330}`,doc.head);added.push(style);
 const normalize=s=>s.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().trim();
 for(const row of doc.querySelectorAll('#centre tr.ligneAmelioration')){
  const title=normalize(row.querySelector('h2')?.textContent||'');const key=title==='armes'?'armes':title==='bouclier thoracique'?'bouclier':null;if(!key)continue;
  const tr=element('tr',null);tr.className='uptep-lab-row';const td=element('td',null,tr);td.colSpan=[...row.cells].reduce((n,c)=>n+c.colSpan,0)||1;
  const panel=element('section',null,td);panel.className='uptep-lab-analysis';element('h3','UPTEP+ · Recherche ou ponte ?',panel);row.after(tr);added.push(tr);
  try{
   const offer=readLaboratoryOffer(row);const result=laboratoryCompare(snapshot?.data,key,offer.workers);
   if(result.level!==offer.level)throw Error('Niveau en BDD différent du jeu : actualise tes technologies dans UPTEP+ puis recharge cette page.');
   const stat=key==='armes'?'attaque':'vie',points=key==='armes'?'points d’attaque':'points de vie';
   element('p',`Niveau ${result.level} → ${result.level+1} : ${format(result.workers)} ouvrières, soit ${laboratoryDuration(result.seconds)} de ponte pour les remplacer (TDP ${result.tdp}).`,panel);
   element('p',`Gain sur ton armée actuelle : +${format(result.gain)} ${points}.`,panel);
   const best=result.alternatives[0];
   if(result.unknown){element('p',`${result.unknown} unité(s) aux prérequis inconnus : complète tes bâtiments et technologies. Comparaison partielle, aucun verdict global.`,panel).className='uptep-lab-warning';}
   if(best){
    element('p',`Pour le même temps : ${format(best.count)} × ${best.name}, soit +${format(best.gain)} ${points}.`,panel);
    if(!result.unknown){const minimum=result.gain<best.gain?result.gain:best.gain,maximum=result.gain>best.gain?result.gain:best.gain;const percent=minimum>0n?' (+'+format((maximum-minimum)*1000n/minimum/10n)+' %)':'';
     element('strong',result.gain===best.gain?'Gains équivalents.':(result.gain>best.gain?'La recherche apporte le meilleur gain':'La ponte apporte le meilleur gain')+percent+'.',panel);
    }
    const details=element('details',null,panel);element('summary','Comparer les unités débloquées',details);const wrap=element('div',null,details);wrap.style.overflowX='auto';const table=element('table',null,wrap);const head=element('tr',null,element('thead',null,table));for(const label of ['Unité','Quantité','Gain de '+stat,'Nourriture'])element('th',label,head);const body=element('tbody',null,table);
    for(const unit of result.alternatives){const line=element('tr',null,body);for(const value of [unit.name,format(unit.count),'+'+format(unit.gain),format(unit.food)])element('td',value,line);}
   }else if(!result.unknown)element('p','Aucune unité de combat débloquée selon les niveaux enregistrés.',panel);
   const costs=[];if(offer.food!==null)costs.push(format(offer.food)+' nourriture');if(offer.materials!==null)costs.push(format(offer.materials)+' matériaux');
   element('small','Coût de la recherche : '+(costs.join(' · ')||'ressources non reconnues')+'. Refaire les ouvrières coûte aussi '+format(result.workers*5n)+' nourriture.'+(offer.time?' Durée de recherche affichée : '+offer.time+'.':''),panel);
   element('small','Comparaison à temps de ponte égal, sans XP, sur les caractéristiques de base avec tes bonus Armes/Bouclier. Vie hors bonus de dôme/loge. Les ressources et la perte de production pendant le remplacement des ouvrières ne sont pas intégrées au verdict. Laboratoire et Reine travaillent en parallèle.',panel);
  }catch(error){element('p',error.message,panel).className='uptep-lab-warning';}
  for(const [group,label] of [['buildings','Bâtiments'],['research','Technologies'],['army','Armée']]){
   const stamp=snapshot?.synced?.[group];const date=stamp?new Date(stamp):null;const valid=date&&!Number.isNaN(date.getTime());const old=valid&&Date.now()-date.getTime()>86400000;
   const node=element('small',label+' : '+(valid?date.toLocaleString('fr-FR',{timeZone:'Europe/Paris'})+' (Paris)'+(old?' — relevé de plus de 24 h':''):'date de synchronisation inconnue')+'.',panel);if(old||!valid)node.className='uptep-lab-warning';
  }
 }
 return ()=>added.forEach(node=>node.remove());
}
