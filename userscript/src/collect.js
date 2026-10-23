function readGameLevels(doc,pathname){
 const keys={buildings:['champignoniere','entrepot_nourriture','entrepot_materiaux','couveuse','solarium','laboratoire','salle_analyse','salle_combat','caserne','dome','loge_imperiale','etable_pucerons','etable_cochenilles'],research:['technique_ponte','bouclier','armes','architecture','communication','vitesse_chasse','vitesse_attaque','genetique','acide','poison']};
 const group=pathname.toLowerCase()==='/construction.php'?'buildings':pathname.toLowerCase()==='/laboratoire.php'?'research':null;if(!group)return null;
 const nodes=[...doc.querySelectorAll('#centre .niveau_amelioration')];if(nodes.length!==keys[group].length)return null;
 const values={};for(let i=0;i<nodes.length;i++){const match=nodes[i].textContent.match(/\bniveau\s+(\d+)\b/i);if(!match||Number(match[1])>999)return null;values[keys[group][i]]=Number(match[1]);}return {group,values};
}
function readGameArmy(doc){
 const normalize=s=>s.normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/[’']/g,'').toLowerCase().replace(/\s+/g,' ').trim();
 const units={jsn:'Jeune Soldate Naine',sn:'Soldate Naine',ne:'Naine d’Elite',js:'Jeune Soldate',s:'Soldate',se:'Soldate d’élite',c:'Concierge',ce:'Concierge d’élite',a:'Artilleuse',ae:'Artilleuse d’élite',t:'Tank',te:'Tank d’élite',tu:'Tueuse',tue:'Tueuse d’élite'};
 const table=[...doc.querySelectorAll('#centre table.simulateur')].find(t=>t.rows[0]?.cells[0]?.colSpan===10);if(!table)throw Error('Tableau de l’armée non reconnu. Aucune donnée envoyée.');
 // Empty locations use merged cells; count logical columns, not DOM cells.
 const values={};for(const row of table.rows){const label=row.cells[0]?.querySelector('.pas_sur_telephone');if(!label)continue;const key=Object.keys(units).find(k=>normalize(units[k])===normalize(label.textContent));if(!key)continue;if(key in values)throw Error('Ligne d’unité ambiguë. Aucune donnée envoyée.');
  let column=0;const groups=[[],[],[]];
  for(const cell of row.cells){
   const span=cell.colSpan||1;
   if(cell.rowSpan!==1 || column+span>10)throw Error('Ligne d’unité ambiguë. Aucune donnée envoyée.');
   if(column===0){if(span!==1)throw Error('Ligne d’unité ambiguë. Aucune donnée envoyée.');}
   else {
    const group=Math.floor((column-1)/3);
    if(Math.floor((column+span-2)/3)!==group)throw Error('Ligne d’unité ambiguë. Aucune donnée envoyée.');
    const text=cell.textContent.replace(/[\s\u00a0\u202f]/g,'');
    if(/^\d+$/.test(text))groups[group].push(BigInt(text));
    else if(text && !/^[←→‹›<>❮❯«»]+$/.test(text))throw Error('Effectif non reconnu pour '+units[key]+'.');
   }
   column+=span;
  }
  if(column!==10 || groups.some(g=>g.length>1))throw Error('Ligne d’unité ambiguë. Aucune donnée envoyée.');
  const total=groups.reduce((sum,g)=>sum+(g[0]??0n),0n);
  if(total>9223372036854775807n)throw Error('Effectif trop grand.');values[key]=String(total);
 }
 // The game can omit the two unavailable elite evolutions entirely.
 for(const key of ['ce','te'])if(!(key in values))values[key]='0';
 if(Object.keys(values).length!==14)throw Error('Armée incomplète : certaines unités ne sont pas visibles. Aucune donnée envoyée.');return {group:'army',values};
}

// Reine.php: the "Ouvrière" row carries the game's worker count in a hidden span (#armee_initial),
// premium or not. Read at every visit of the page.
function readGameWorkers(doc){
 const row=doc.querySelector('#centre tr.ligneAmelioration');if(!row||row.querySelector('h2')?.textContent.trim()!=='Ouvrière')return null;
 const text=doc.getElementById('armee_initial')?.textContent.replace(/[\s\u00a0\u202f]/g,'');
 if(!text||!/^\d{1,19}$/.test(text)||BigInt(text)>9223372036854775807n)return null;
 return {group:'workers',values:{total:BigInt(text).toString()}};
}
