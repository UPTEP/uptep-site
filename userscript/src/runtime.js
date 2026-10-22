const doc=context.document,win=context.window;
const preferences=typeof uptepPreferences==='undefined'?{}:uptepPreferences;
const collectionGroup={'/construction.php':'buildings','/laboratoire.php':'research','/acquerirterrain.php':'hunts','/ressources.php':'hunts'}[win.location.pathname.toLowerCase()]||null;
let collection=collectionGroup&&preferences[collectionGroup]?(collectionGroup==='hunts'?readGameHunts(doc,win):readGameLevels(doc,win.location.pathname)):null;
const portalPath='/alliance.php?uptep=1';
const entry=doc.createElement('li');entry.id='uptep-plus-alliance-entry';
const entryLink=doc.createElement('a');entryLink.className='boutonHome';entryLink.href=portalPath;
const entryIcon=doc.createElement('span');entryIcon.setAttribute('aria-hidden','true');entryLink.append(entryIcon,doc.createTextNode('UPTEP+'));entry.appendChild(entryLink);
// Alliance › Mouvements: the alliance's arrivals of the day (floods, convoys, hunts), same page as on uptep.top.
const movesEntry=doc.createElement('li');movesEntry.id='uptep-movements-alliance-entry';
const movesLink=doc.createElement('a');movesLink.dataset.uptepMovements='1';movesLink.href='/alliance.php?uptep=mouvements';
const movesIcon=doc.createElement('span');movesIcon.setAttribute('aria-hidden','true');movesIcon.style.backgroundPosition='-100px -302px';movesLink.append(movesIcon,doc.createTextNode('Mouvements'));movesEntry.appendChild(movesLink);
const uptepMode=win.location.pathname==='/alliance.php'?new URLSearchParams(win.location.search).get('uptep'):null;
const portal=doc.createElement('section');portal.id='uptep-plus-page';portal.setAttribute('aria-label','Espace UPTEP+');
portal.style.cssText='box-sizing:border-box;margin:0;width:100%;position:relative;';
const frame=doc.createElement('iframe');frame.src=uptepMode==='mouvements'?'https://uptep.top/portal.php?mouvements=1':'https://uptep.top/portal.php';frame.title=uptepMode==='mouvements'?'Mouvements de l’alliance':'Espace UPTEP+';frame.referrerPolicy='no-referrer';frame.style.cssText='display:block;width:100%;height:850px;border:0;border-radius:0;background:#c9ad63;';
portal.appendChild(frame);
function sendAuth(){if(typeof uptepPortalAuth!=='undefined')frame.contentWindow?.postMessage({type:'uptep-auth',token:uptepPortalAuth.token,pseudo:context.pseudo,...(collection?{collection}: {})},'https://uptep.top');}
function receivePortal(event){
 if(event.origin!=='https://uptep.top'||event.source!==frame.contentWindow)return;
 if(event.data?.type==='uptep-ready')sendAuth();
 if(event.data?.type==='uptep-collection-result'&&collection&&event.data.requestId===collection.requestId){
 if(huntBadges){win.clearTimeout(huntTimeout);huntBadges.set(event.data.ok?'ok':'error',event.data.error);}
 if(collection.group==='army'){win.clearTimeout(armyTimeout);armyButton.disabled=false;armyButton.removeAttribute('aria-busy');armyStatus.textContent=event.data.ok?'✓ Armée synchronisée — totaux des trois emplacements enregistrés.':'Échec : '+String(event.data.error||'synchronisation indisponible');return;}
collectionStatus.textContent=event.data.ok?(collection.group.startsWith('hunts')?'✓ Chasses synchronisées ('+collection.values.length+')':'✓ '+(collection.group==='buildings'?'Bâtiments':'Laboratoire')+' synchronisé'+(collection.group==='buildings'?'s':'')):'Collecte : '+String(event.data.error||'indisponible');}
 if(event.data?.type==='uptep-height'&&Number.isFinite(event.data.height))frame.style.height=Math.min(20000,Math.max(500,event.data.height))+'px';
}
frame.addEventListener('load',sendAuth);win.addEventListener('message',receivePortal);
const savedDisplay=new Map();
let centrePadding=null;
const isPortal=uptepMode==='1'||uptepMode==='mouvements';
function mountPortal(){
 const menu=doc.getElementById('menuAlliance');if(menu && entry.parentNode!==menu)menu.appendChild(entry);if(menu && movesEntry.parentNode!==menu)menu.appendChild(movesEntry);
 if(!isPortal)return;
 const centre=doc.getElementById('centre');if(!centre)return;
 // Keep the native navigation and player identity DOM; only hide the original page content.
 for(const child of centre.children){
  if(child===portal || child.matches('script,style,link,nav,#menu,#menuBoite') || child.querySelector('#menu,#menuBoite') || savedDisplay.has(child))continue;
  savedDisplay.set(child,[child.style.getPropertyValue('display'),child.style.getPropertyPriority('display')]);child.style.setProperty('display','none','important');
 }
 if(portal.parentNode!==centre)centre.appendChild(portal);
 const nativeMenu=doc.getElementById('menu');
 if(nativeMenu){
  if(!centrePadding)centrePadding=[centre,centre.style.getPropertyValue('padding-top'),centre.style.getPropertyPriority('padding-top')];
  const rect=nativeMenu.getBoundingClientRect();
  centre.style.setProperty('padding-top',rect.height+'px','important');
  portal.style.left=(rect.left-centre.getBoundingClientRect().left-(parseFloat(win.getComputedStyle(centre).paddingLeft)||0))+'px';
  portal.style.width=rect.width+'px';
 }

}
const panel=doc.createElement('div');
panel.id='uptep-plus-badge';panel.className='boite_compte_plus';
panel.setAttribute('aria-label','Menu UPTEP+');
panel.style.cssText='position:absolute;text-align:center;color:#d3d9b8;width:220px;';
const title=doc.createElement('a');title.className='titre_colonne_cliquable';title.textContent='UPTEP+';title.href='https://uptep.top/';title.target='_blank';title.rel='noopener noreferrer';title.title='UPTEP+ actif · '+context.pseudo;
const content=doc.createElement('div');content.className='contenu_boite_compte_plus';
const link=doc.createElement('a');link.href='https://uptep.top/';link.target='_blank';link.rel='noopener noreferrer';link.textContent='Accéder au site';link.style.cssText='display:block;padding:8px 10px 10px;text-decoration:none;';
content.appendChild(link);panel.append(title,content);
function position(){
 mountPortal();
 const host=doc.getElementById('menuBoite');
 const anchor=doc.getElementById('boiteComptePlus')||doc.getElementById('boiteInfo');
 if(!host||!anchor||!host.contains(anchor)){panel.remove();return;}
 if(panel.parentNode!==host)host.appendChild(panel);
 const parentRect=host.getBoundingClientRect(),anchorRect=anchor.getBoundingClientRect();
 // Native boxes contain absolutely positioned children, so their own height may be zero.
 let bottom=anchorRect.bottom;
 for(const node of anchor.querySelectorAll('*')){
  if(node.getClientRects().length && win.getComputedStyle(node).visibility!=='hidden')bottom=Math.max(bottom,node.getBoundingClientRect().bottom);
 }
 const left=(anchorRect.left-parentRect.left+host.scrollLeft)+'px';
 const top=(bottom-parentRect.top+host.scrollTop+20)+'px';
 if(panel.style.left!==left)panel.style.left=left;
 if(panel.style.top!==top)panel.style.top=top;
}
const collectionStatus=doc.createElement('div');collectionStatus.className='uptep-collection-status';collectionStatus.style.cssText='font-size:11px;padding:6px;color:#e5edce;';
// Ressources.php without any hunt shown stays silent: there is simply nothing to record.
if(collectionGroup&&preferences[collectionGroup]&&(collection||win.location.pathname.toLowerCase()!=='/ressources.php')){collectionStatus.textContent=collectionGroup==='hunts'?(collection?'Synchronisation des chasses…':'Chasses non reconnues : aucune donnée envoyée.'):(collection?'Synchronisation des niveaux…':'Niveaux non reconnus : aucune donnée envoyée.');content.append(collectionStatus);}
// Hunt pages: badges next to each hunt, also when the collection is off (read locally, nothing sent).
const huntBadges=collectionGroup==='hunts'?mountHuntBadges(doc,preferences.hunts?collection:readGameHunts(doc,win),!!preferences.hunts):null;
const huntTimeout=huntBadges&&collection?win.setTimeout(()=>huntBadges.set('error','UPTEP ne répond pas, recharge la page'),25000):null;
if(collection){frame.src='https://uptep.top/portal.php?bridge=1';portal.hidden=true;doc.body.append(portal);}
let armyTimeout=null;const armyTools=doc.createElement('div'),armyButton=doc.createElement('button'),armyStatus=doc.createElement('span');
if(win.location.pathname.toLowerCase()==='/armee.php'){
 armyTools.id='uptep-army-sync';armyTools.style.cssText='display:flex;flex-direction:column;align-items:center;justify-content:center;gap:9px;margin:16px 0;text-align:center;font:12px Verdana;color:#302b18;';armyButton.type='button';armyButton.textContent='Synchroniser mon armée sur UPTEP+';armyButton.style.cssText='padding:10px 18px;background:linear-gradient(#386d78,#254e59);color:#fff3d3;border:1px solid #1c414a;border-radius:5px;box-shadow:inset 0 1px 0 #ffffff25,0 2px 4px #302b1825;font:700 12px Verdana;cursor:pointer;max-width:100%;';armyStatus.setAttribute('role','status');armyTools.append(armyButton,armyStatus);
 const table=[...doc.querySelectorAll('#centre table.simulateur')].find(t=>t.rows[0]?.cells[0]?.colSpan===10);if(table)table.before(armyTools);else doc.querySelector('#centre')?.append(armyTools);
 if(!preferences.army){armyButton.disabled=true;armyStatus.textContent='Collecte de l’armée désactivée dans UPTEP+ → Paramètres.';}
 armyButton.addEventListener('click',()=>{try{collection=readGameArmy(doc);collection.requestId=String(Date.now());armyButton.disabled=true;armyButton.setAttribute('aria-busy','true');armyStatus.textContent='Synchronisation en cours…';portal.hidden=true;frame.src='https://uptep.top/portal.php?bridge=1&request='+collection.requestId;if(!portal.isConnected)doc.body.append(portal);win.clearTimeout(armyTimeout);armyTimeout=win.setTimeout(()=>{armyButton.disabled=false;armyButton.removeAttribute('aria-busy');armyStatus.textContent='Délai dépassé. Tu peux réessayer.';},20000);}catch(e){armyStatus.textContent=e.message;}});
}
const cleanupHourly=typeof uptepHourlyDue!=='undefined'&&uptepHourlyDue&&preferences.economy?mountHourlyCollection(context,uptepPortalAuth):()=>{};
const cleanupClock=preferences.clock===false?()=>{}:mountClock(context,content);
const cleanupChaine=preferences.chaine===false?()=>{}:mountChaine(context,typeof uptepChaineData==='undefined'?null:uptepChaineData);
const cleanupLaboratory=preferences.laboratory===false?()=>{}:mountLaboratory(context,typeof uptepLaboratoryData==='undefined'?null:uptepLaboratoryData);
const cleanupTraffic=preferences.traffic===false?()=>{}:mountTraffic(context,typeof uptepTrafficData==='undefined'?null:uptepTrafficData,typeof uptepTrafficServerTime==='undefined'?null:uptepTrafficServerTime);
const cleanupMultiflood=preferences.multiflood===false?()=>{}:mountMultiflood(context,typeof uptepChaineData==='undefined'?null:uptepChaineData,typeof uptepPortalAuth==='undefined'?null:uptepPortalAuth);
const cleanupConvoys=preferences.convoys===false?()=>{}:mountConvoys(context,typeof uptepPortalAuth==='undefined'?null:uptepPortalAuth,typeof uptepTrafficServerTime==='undefined'?null:uptepTrafficServerTime,typeof uptepChaineData==='undefined'?null:uptepChaineData);
const cleanupConstructionDemands=preferences.convoys===false?()=>{}:mountConstructionDemands(context,typeof uptepPortalAuth==='undefined'?null:uptepPortalAuth);
const cleanupFloodDeclarations=preferences.floodDeclarations===false?()=>{}:mountFloodDeclarations(context,typeof uptepPortalAuth==='undefined'?null:uptepPortalAuth,typeof uptepTrafficServerTime==='undefined'?null:uptepTrafficServerTime);
const cleanupPlayerProfile=preferences.playerProfile===false||typeof mountPlayerProfile!=='function'?()=>{}:mountPlayerProfile(context,typeof uptepPortalAuth==='undefined'?null:uptepPortalAuth);
const cleanupMemberReport=preferences.playerProfile===false||typeof uptepMemberReportAccess==='undefined'||!uptepMemberReportAccess?()=>{}:mountMemberReport(context,typeof uptepPortalAuth==='undefined'?null:uptepPortalAuth);
position();
const timer=win.setInterval(position,1000);
win.addEventListener('resize',position);
return ()=>{win.clearTimeout(huntTimeout);huntBadges?.remove();cleanupMemberReport();cleanupPlayerProfile();cleanupFloodDeclarations();cleanupConstructionDemands();cleanupConvoys();cleanupMultiflood();cleanupTraffic();cleanupLaboratory();cleanupHourly();win.clearTimeout(armyTimeout);armyTools.remove();cleanupClock();cleanupChaine();win.removeEventListener('message',receivePortal);frame.removeEventListener('load',sendAuth);win.clearInterval(timer);win.removeEventListener('resize',position);panel.remove();entry.remove();movesEntry.remove();portal.remove();for(const [element,[value,priority]] of savedDisplay){if(value)element.style.setProperty('display',value,priority);else element.style.removeProperty('display');}savedDisplay.clear();if(centrePadding){const [element,value,priority]=centrePadding;if(value)element.style.setProperty('padding-top',value,priority);else element.style.removeProperty('padding-top');}};
