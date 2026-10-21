// Hunts in progress on AcquerirTerrain.php: area (cm²) and arrival time of each hunt.
// The premium page carries an exact countdown, reste(seconds,"chasse_<id>"); other layouts
// (standard account, rewritten by another script) only show a duration, parsed from the text.
function parseHuntDuration(text){
 // Reads consecutive "<number> <unit>" pairs from the start and stops at anything else
 // ("Arrivée à 09h26", "(aujourd'hui 23 h 55)"), so a clock time is never added.
 const units={j:86400,jour:86400,jours:86400,h:3600,heure:3600,heures:3600,m:60,mn:60,min:60,minute:60,minutes:60,s:1,sec:1,seconde:1,secondes:1};
 let rest=String(text).toLowerCase().replace(/arriv|retour|\(/g,' | $&').replace(/[  ]/g,' '),seconds=0,last=null,found=false;
 const pair=/^\s*(\d+)\s*(jours|jour|j|heures|heure|h|minutes|minute|min|mn|m|secondes|seconde|sec|s)?(?![a-zé])/;
 for(let m;(m=rest.match(pair));rest=rest.slice(m[0].length)){
  let factor=m[2]?units[m[2]]:(last===3600?60:undefined);// "3 h 08": a bare number after hours is minutes.
  if(factor===undefined)break;
  seconds+=Number(m[1])*factor;last=factor;found=true;
 }
 return found&&seconds>0&&seconds<=31*86400?seconds:null;
}
function huntArea(text){
 const m=String(text).replace(/[  ]/g,' ').match(/(?:conquérir|\+)\s*(\d[\d ]*)\s*cm(?:²|2)/i);if(!m)return null;
 const digits=m[1].replace(/\s/g,'');return /^\d{1,13}$/.test(digits)&&digits!=='0'?String(BigInt(digits)):null;
}
// Returns null when the page is not recognised or when fewer hunts are read than the game announces,
// so a layout change never erases hunts that are still running.
// AcquerirTerrain.php and the "Terrain de Chasse" box of Ressources.php (both carry the #AcquerirTerrain
// form) list every hunt ("hunts": missing ones are cancelled). Any other hunt text on Ressources.php is
// treated as partial and only adds or refreshes ("hunts_seen").
function readGameHunts(doc,win){
 const root=doc.getElementById('centre');if(!root)return null;
 const full=!!doc.getElementById('AcquerirTerrain');
 if(!full&&win.location.pathname.toLowerCase()!=='/ressources.php')return null;
 const nav=win.performance?.getEntriesByType?.('navigation')?.[0];
 const base=Number.isFinite(win.performance?.timeOrigin)&&nav&&nav.responseStart>0?win.performance.timeOrigin+nav.responseStart:Date.now();
 const iso=ms=>new Date(Math.round(ms/1000)*1000).toISOString().replace(/\.\d{3}Z$/,'Z');
 const countdowns=new Map();
 for(const script of root.querySelectorAll('script'))for(const m of script.textContent.matchAll(/reste\(\s*(\d+)\s*,\s*["']chasse_(\d+)["']\s*\)/g))countdowns.set(m[2],Number(m[1]));
 const hunts=[],seen=new Set();
 for(const span of root.querySelectorAll('span[id^="chasse_"]')){
  const id=span.id.slice(7);if(!/^\d{1,18}$/.test(id)||seen.has(id))continue;
  const area=huntArea(span.parentElement?.textContent||'');
  const seconds=countdowns.get(id)??parseHuntDuration(span.textContent);
  if(!area||!seconds||seconds>31*86400)continue;
  hunts.push({id,area,arrival_at:iso(base+seconds*1000)});seen.add(id);
 }
 if(!hunts.length){
  // Text layouts without identifiers: one hunt per "conquérir X cm² dans …" or "+X cm² · retour dans …".
  const text=(root.innerText||root.textContent||'').replace(/[  ]/g,' ')
   // Several hunts can be glued together when the page has no line breaks: start each one on its own line.
   .replace(/(vos chasseuses|arriv[ée]e|\+\s*\d)/gi,'\n$1');
  for(const m of text.matchAll(/(?:conquérir\s+\d[\d ]*\s*cm(?:²|2)\s+dans|\+\s*\d[\d ]*\s*cm(?:²|2)\s*·\s*retour\s+dans)\s+([^\n(]+)/gi)){
   const area=huntArea(m[0]),seconds=parseHuntDuration(m[1]);
   if(area&&seconds)hunts.push({id:null,area,arrival_at:iso(base+seconds*1000)});
  }
 }
 const announced=(root.textContent.match(/(\d+)\s*chasse\(s\)\s*en\s*cours/i)||[])[1];
 if(announced!==undefined&&hunts.length<Number(announced))return null;
 if(!hunts.length&&/conquérir|retour\s+dans/i.test(root.textContent))return null;
 if(hunts.length>50)return null;
 if(!full)return hunts.length?{group:'hunts_seen',values:hunts}:null;
 return {group:'hunts',values:hunts};
}
// One badge per hunt on the page, like the flood badges: whether UPTEP recorded it.
// Without game identifiers (text layouts), a single badge after the "Chasse en cours" title covers all hunts.
function mountHuntBadges(doc,collection,enabled){
 const root=doc.getElementById('centre'),made=[];
 if(!root)return {set(){},remove(){}};
 const make=after=>{const b=doc.createElement('span');b.dataset.uptepHuntBadge='1';b.setAttribute('role','status');b.style.cssText='display:inline-block;margin-left:8px;padding:3px 5px;background:#e9dfba;border:1px solid #887749;color:#302b18;font:11px Verdana;';after.after(b);made.push(b);return b;};
 const title=[...root.querySelectorAll('.titre')].find(n=>/chasse en cours/i.test(n.textContent));
 if(!collection){
  // Hunts shown but not read: say so where they are, so nobody believes they were recorded.
  if(enabled&&title){const b=make(title);b.textContent='Chasses non reconnues : rien n’a été enregistré sur UPTEP';}
  return {set(){},remove(){for(const b of made)b.remove();}};
 }
 const perHunt=[];let unnamed=0;
 for(const h of collection.values){
  if(!h.id){unnamed++;continue;}
  const anchor=root.querySelector('a[href*="annuler='+h.id+'"]')||doc.getElementById('chasse_'+h.id)?.parentElement;
  if(anchor)perHunt.push(make(anchor));else unnamed++;
 }
 const summary=unnamed&&(title||root.firstElementChild)?make(title||root.firstElementChild):null;
 const label=(one,many)=>summary&&unnamed>1?many.replace('{n}',unnamed):one;
 function set(state,message){
  const ok=state==='ok';
  const text=ok?['✓ Enregistrée sur UPTEP','✓ {n} chasses enregistrées sur UPTEP']:state==='pending'?['Enregistrement UPTEP…','Enregistrement UPTEP…']:state==='disabled'?['Non enregistrée · collecte des chasses désactivée (UPTEP+ → Paramètres)','Non enregistrées · collecte des chasses désactivée (UPTEP+ → Paramètres)']:['Non enregistrée : '+(message||'UPTEP indisponible'),'Non enregistrées : '+(message||'UPTEP indisponible')];
  for(const b of perHunt){b.textContent=text[0];b.style.background=ok?'#d3d9b8':'#e9dfba';b.title=state==='error'&&message?message:'';}
  if(summary){summary.textContent=label(text[0],text[1]);summary.style.background=ok?'#d3d9b8':'#e9dfba';}
 }
 set(enabled?'pending':'disabled');
 return {set,remove(){for(const b of made)b.remove();}};
}
