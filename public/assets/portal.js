'use strict';
(()=>{
 const $=id=>document.getElementById(id),status=$('access-status'),results=$('results'),notice=$('result-status'),input=$('search'),list=$('suggestions');
 const fmt=n=>n===null||n===undefined?'—':new Intl.NumberFormat('fr-FR').format(BigInt(n));
 const el=(tag,cls,text)=>{const n=document.createElement(tag);if(cls)n.className=cls;if(text!==undefined)n.textContent=text;return n;};
 let settings=null,activeMenu='statistics';
 const profileMode=new URLSearchParams(location.search).get('profile')==='1';
 const memberReportMode=new URLSearchParams(location.search).get('memberReport')==='1';
 if(profileMode||memberReportMode)document.body.classList.add('player-profile-mode');
 let memberTarget=null;
 const bridgeOnly=new URLSearchParams(location.search).get('bridge')==='1';
 let auth=null,parentOrigin='',kind='players',suggestions=[],selected=-1,timer=null,suggestVersion=0,searchVersion=0,requestController=null;
 function send(data){if(parentOrigin)parent.postMessage(data,parentOrigin);}
 new ResizeObserver(()=>send({type:'uptep-height',height:document.body.scrollHeight+12})).observe(document.body);
 async function api(data,signal){
  if(!auth)throw Error('Accès non initialisé.');
  const r=await fetch('/portal-api.php',{method:'POST',credentials:'omit',cache:'no-store',headers:{'Content-Type':'application/json',Authorization:'Bearer '+auth.token},body:JSON.stringify({...data,pseudo:auth.pseudo,alliance:'UPTEP'}),signal});
  const response=await r.json();
  if(r.status===403){auth=null;$('statistics').hidden=true;$('script-settings').hidden=true;results.replaceChildren();status.textContent='Accès UPTEP+ désactivé ou non autorisé.';const error=Error(response.error||status.textContent);error.status=403;throw error;}
  if(!r.ok||!response.ok){const error=Error(response.error||'Service indisponible.');error.status=r.status;throw error;}return response;
 }
 window.addEventListener('message',async event=>{
  if(event.source!==parent||!['https://s5.fourmizzz.fr','http://s5.fourmizzz.fr'].includes(event.origin)||event.data?.type!=='uptep-auth'||!(/^[a-f0-9]{64}$/).test(event.data.token)||typeof event.data.pseudo!=='string')return;
  if(auth)return;auth={token:event.data.token,pseudo:event.data.pseudo};parentOrigin=event.origin;
  if(new URLSearchParams(location.search).get('bridge')==='convoys'){
   const job=event.data.convoy;
   try{if(!job||!['convoy-demands','convoy-pending','convoy-create','convoy-check','convoy-declare','convoy-list','convoy-detail','convoy-manage','flood-check','flood-declare'].includes(job.action)||typeof job.requestId!=='string')throw Error('Déclaration invalide.');const {requestId,...payload}=job;const result=await api(payload);send({type:'uptep-convoy-result',ok:true,requestId,result,result_json:JSON.stringify(result)});}
   catch(e){send({type:'uptep-convoy-result',ok:false,requestId:job?.requestId,error:e.message,status:e.status||0});}return;
  }
  if(bridgeOnly){try{const collection=event.data.collection;if(!collection||!['buildings','research','army','economy'].includes(collection.group))throw Error('Relevé invalide.');await api({action:'collect',group:collection.group,values:collection.values});send({type:'uptep-collection-result',ok:true,requestId:collection.requestId});}catch(e){send({type:'uptep-collection-result',ok:false,error:e.message,requestId:event.data.collection?.requestId});}return;}
  if(memberReportMode){memberTarget=event.data.memberReport;await loadMemberReport('30');return;}
  if(profileMode){try{const target=event.data.playerProfile;if(!target||typeof target.pseudo!=='string'||!target.pseudo.trim()||target.pseudo.length>100)throw Error('Joueur du profil non reconnu.');input.value=target.pseudo;status.textContent='';$('statistics').hidden=false;await search(target.player_id||null);}catch(e){status.textContent=e.message;}return;}
  try{settings=await api({action:'settings'});renderSettings();if(!settings.preferences.statistics){status.textContent='';showMenu('settings');return;}const data=await api({action:'overview'});status.textContent='';$('statistics').hidden=false;results.append(el('p','welcome',`${fmt(data.counts.players)} joueurs et ${fmt(data.counts.alliances)} alliances. Recherche une fiche pour consulter ses statistiques.`));send({type:'uptep-height',height:document.body.scrollHeight+12});}catch(e){status.textContent=e.message;}
 });
 async function loadMemberReport(period){
  const version=++searchVersion;requestController?.abort();requestController=new AbortController();results.replaceChildren();results.classList.remove('member-report');status.textContent='Chargement du suivi membre…';
  try{if(!memberTarget||typeof memberTarget.pseudo!=='string')throw Error('Joueur non reconnu.');const data=await api({action:'member-report',q:memberTarget.pseudo,...(memberTarget.player_id?{player_id:memberTarget.player_id}:{}),period},requestController.signal);if(version!==searchVersion)return;status.textContent='';$('statistics').hidden=false;window.uptepMemberReport(results,data,loadMemberReport);}catch(e){if(e.name!=='AbortError'&&version===searchVersion)status.textContent=e.message;}
 }
 parent.postMessage({type:'uptep-ready'},'https://s5.fourmizzz.fr');parent.postMessage({type:'uptep-ready'},'http://s5.fourmizzz.fr');
 function closeSuggestions(){list.hidden=true;input.setAttribute('aria-expanded','false');input.removeAttribute('aria-activedescendant');selected=-1;}
 function choose(i){const item=suggestions[i];if(!item)return;input.value=item.value;closeSuggestions();search(item.player_id?String(item.player_id):null);}
 function highlight(i){selected=i;[...list.children].forEach((n,j)=>n.setAttribute('aria-selected',String(i===j)));if(i>=0){input.setAttribute('aria-activedescendant','suggest-'+i);list.children[i]?.scrollIntoView({block:'nearest'});}}
 input.addEventListener('input',()=>{clearTimeout(timer);const version=++suggestVersion;closeSuggestions();const term=input.value.trim();if(!term)return;
  timer=setTimeout(async()=>{try{const data=await api({action:'suggest',kind,q:term});if(version!==suggestVersion)return;suggestions=data.suggestions;list.replaceChildren();suggestions.forEach((item,i)=>{const n=el('li','',item.value+(item.label?' · '+item.label:''));n.id='suggest-'+i;n.setAttribute('role','option');n.setAttribute('aria-selected','false');n.addEventListener('mousedown',e=>e.preventDefault());n.addEventListener('click',()=>choose(i));list.append(n);});list.hidden=!suggestions.length;input.setAttribute('aria-expanded',String(!!suggestions.length));}catch(e){if(version===suggestVersion)notice.textContent=e.message;}},180);
 });
 input.addEventListener('keydown',e=>{if(e.key==='Escape'){closeSuggestions();return;}if(list.hidden)return;if(e.key==='ArrowDown'||e.key==='ArrowUp'){e.preventDefault();highlight(selected<0?(e.key==='ArrowDown'?0:suggestions.length-1):(selected+(e.key==='ArrowDown'?1:-1)+suggestions.length)%suggestions.length);}else if(e.key==='Enter'&&selected>=0){e.preventDefault();choose(selected);}});
 input.addEventListener('blur',()=>setTimeout(closeSuggestions,160));
 function changeKind(next){kind=next;suggestVersion++;searchVersion++;clearTimeout(timer);requestController?.abort();input.value='';closeSuggestions();results.replaceChildren();notice.textContent='';$('search-form').querySelector('label').textContent=kind==='players'?'Rechercher un joueur':'Rechercher une alliance';input.placeholder=kind==='players'?'Pseudo du joueur':'Tag ou nom de l’alliance';document.querySelectorAll('[data-kind]').forEach(b=>{b.setAttribute('aria-selected',String(b.dataset.kind===kind));b.tabIndex=b.dataset.kind===kind?0:-1;});$('stats-panel').setAttribute('aria-labelledby','tab-'+kind);input.focus();}
 document.querySelectorAll('[data-kind]').forEach(b=>{b.addEventListener('click',()=>changeKind(b.dataset.kind));b.addEventListener('keydown',e=>{if(['ArrowLeft','ArrowRight'].includes(e.key)){e.preventDefault();changeKind(kind==='players'?'alliances':'players');$('tab-'+kind).focus();}});});
 function showMenu(menu){if(!auth||!settings)return;activeMenu=menu==='statistics'&&!settings.preferences.statistics?'settings':menu;$('statistics').hidden=activeMenu!=='statistics';$('script-settings').hidden=activeMenu!=='settings';document.querySelector('main h1').textContent=activeMenu==='settings'?'Paramètres':'Statistiques';document.querySelector('.intro').hidden=activeMenu==='settings';for(const key of ['statistics','settings']){const button=$('menu-'+key);if(key===activeMenu)button.setAttribute('aria-current','page');else button.removeAttribute('aria-current');}if(activeMenu==='statistics')input.focus();}
 function renderSettings(){const options=$('settings-options');options.replaceChildren();const groups=[['Toutes les pages',['clock','traffic','economy']],['Armée',['army','floodDeclarations']],['Membres',['chaine','multiflood']],['Profils joueurs',['playerProfile']],['Laboratoire',['research','laboratory']],['Commerce · Convois',['convoys']],['Construction',['buildings']],['Page Statistiques UPTEP+',['statistics']]];const containers=new Map();for(const [title,keys] of groups){if(!keys.some(key=>settings.options[key]))continue;const group=el('fieldset','settings-page');group.append(el('legend','',title));options.append(group);for(const key of keys)containers.set(key,group);}for(const [key,[title,description]] of Object.entries(settings.options)){const label=el('label','script-option'),check=el('input');check.type='checkbox';check.name=key;check.checked=settings.preferences[key];const text=el('span');text.append(el('strong','',title),el('small','',description));if(['buildings','research','army','economy'].includes(key)){const stamp=settings.synced[key];text.append(el('small','sync-date',stamp?'Dernière collecte : '+new Intl.DateTimeFormat('fr-FR',{timeZone:'Europe/Paris',dateStyle:'short',timeStyle:'short'}).format(new Date(stamp))+' (Paris)':'Aucune collecte pour le moment.'));}label.append(check,text);(containers.get(key)||options).append(label);}$('menu-statistics').hidden=!settings.preferences.statistics;}
 $('menu-settings').addEventListener('click',async()=>{const button=$('menu-settings');button.disabled=true;try{settings=await api({action:'settings'});renderSettings();showMenu('settings');$('settings-status').textContent='';}catch(e){showMenu('settings');$('settings-status').textContent=e.message;}finally{button.disabled=false;}});
 $('settings-form').addEventListener('submit',async event=>{event.preventDefault();const button=$('settings-form').querySelector('button');button.disabled=true;$('settings-status').textContent='Enregistrement…';try{const preferences={};for(const check of $('settings-options').querySelectorAll('input'))preferences[check.name]=check.checked;settings=await api({action:'save-settings',preferences,version:settings.version});renderSettings();showMenu('settings');$('settings-status').textContent='Paramètres enregistrés.';}catch(e){$('settings-status').textContent=e.message;}finally{button.disabled=false;}});
 $('menu-statistics').addEventListener('click',()=>showMenu('statistics'));$('search-form').addEventListener('submit',e=>{e.preventDefault();search();});
 function entityLink(tag){if(profileMode){const a=el('a','text-link',tag);a.href='https://s5.fourmizzz.fr/classementAlliance.php?alliance='+encodeURIComponent(tag);a.target='_top';return a;}const a=el('button','text-link',tag);a.type='button';a.addEventListener('click',()=>{changeKind('alliances');input.value=tag;search();});return a;}
 async function search(playerId=null){
  if(!input.value.trim())return;const version=++searchVersion;suggestVersion++;clearTimeout(timer);closeSuggestions();requestController?.abort();requestController=new AbortController();notice.textContent='Chargement des statistiques…';results.replaceChildren();
  try{const data=await api({action:profileMode?'profile-stats':'stats',kind,q:input.value.trim(),...(playerId?{player_id:playerId}:{})},requestController.signal);if(version!==searchVersion)return;notice.textContent='';
   if(!data.entity){notice.textContent=data.candidates?.length?'Choisis une fiche :':'Aucune fiche trouvée.';for(const c of data.candidates||[]){const b=el('button','candidate',c.value+(c.label?' · '+c.label:''));b.addEventListener('click',()=>{input.value=c.value;search(c.player_id?String(c.player_id):null);});results.append(b);}return;}
   render(data);
  }catch(e){if(e.name!=='AbortError'&&version===searchVersion)notice.textContent=e.message;}
 }
 function renderPlayerEvents(events,players={}){
  const section=el('section','player-history');section.append(el('h2','','Historique'));
  section.append(el('p','history-note','Changements constatés entre les relevés · heure de Paris.'));
  if(!events.length){section.append(el('p','muted','Aucun changement observé dans les relevés disponibles.'));return section;}
  const list=el('ul','event-list');section.append(list);let offset=0;
  const more=el('button','more','Afficher 20 événements de plus');more.type='button';
  const dateFormat=new Intl.DateTimeFormat('fr-FR',{timeZone:'Europe/Paris',day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'});
  function playerLink(id){const player=players[id];if(!player)return el('span','muted','Joueur inconnu');const tag=player.alliance_tag?.trim(),label=player.pseudo+(tag?' ['+tag+']':' · Sans alliance');if(profileMode){const a=el('a','text-link',label);a.href='https://s5.fourmizzz.fr/Membre.php?Pseudo='+encodeURIComponent(player.pseudo);a.target='_top';return a;}const a=el('button','text-link',label);a.type='button';a.addEventListener('click',()=>{changeKind('players');input.value=player.pseudo;search(String(id));});return a;}

  function append(){
   for(const event of events.slice(offset,offset+20)){
    const row=el('li'),date=el('time','',dateFormat.format(event.timestamp));date.dateTime=new Date(event.timestamp).toISOString();date.title=event.datetime+' (Paris)';row.append(date,' : ');
    if(event.type==='alliance'){row.append(event.before?entityLink(event.before):'—',' → ',event.after?entityLink(event.after):'—');}
    else if(event.type==='grade')row.append('grade '+(event.before||'—')+' → '+(event.after||'—'));
    else if(event.type==='holiday')row.append(event.after?'Départ en vacances':'Retour de vacances');
    else if(event.type==='ban')row.append(event.after?'Bannissement':'Débannissement');
    else if(event.type==='colonization'){
     if(!event.after)row.append('Libéré de ',playerLink(event.before));
     else if(!event.before)row.append('Colonisé par ',playerLink(event.after));
     else row.append('Colonisateur : ',playerLink(event.before),' → ',playerLink(event.after));
    }
    list.append(row);
   }
   offset+=20;more.hidden=offset>=events.length;
  }
  more.addEventListener('click',append);append();section.append(more);return section;
 }
 function render(data){
  const e=data.entity,alliance=data.kind==='alliances';input.value=alliance?e.tag:e.pseudo;
  const header=el('section','entity-heading'),title=el('div','entity-title');title.append(el('h2','',input.value));if(!alliance&&e.alliance_tag){const badge=entityLink(e.alliance_tag);badge.className='entity-badge alliance-badge';badge.setAttribute('aria-label','Consulter l’alliance '+e.alliance_tag);title.append(badge);}header.append(title);
  if(alliance)header.append(el('p','',e.name+' · '+fmt(e.players_count)+' membres'));
  const v=e.export_version,stamp=/^\d{12,14}$/.test(v||'')?new Date(v.slice(0,4)+'-'+v.slice(4,6)+'-'+v.slice(6,8)+'T'+v.slice(8,10)+':'+v.slice(10,12)+':00Z'):null;
  if(stamp)header.append(el('p','muted','Dernier relevé : '+new Intl.DateTimeFormat('fr-FR',{timeZone:'Europe/Paris',dateStyle:'short',timeStyle:'short'}).format(stamp)+' (Paris)'));
  if(!e.is_present)header.append(el('p','status-note','Absent du dernier export : dernier état connu affiché.'));
  if(!alliance){const flags=el('div','entity-flags');if(e.on_holiday)flags.append(el('span','entity-badge badge-holiday','Vacances'));if(e.is_banned)flags.append(el('span','entity-badge badge-banned','Banni'));if(e.master_player_id&&String(e.master_player_id)!=='0'){const badge=el('span','entity-badge badge-colonized','Colonisé');badge.title='Colonisé par le joueur #'+e.master_player_id;flags.append(badge);}if(flags.childElementCount)header.append(flags);}
  results.append(header);const summary=el('section','metric-grid');for(const[key,[label,unit]]of Object.entries(data.metrics)){const card=el('article','metric-card');card.append(el('span','',label),el('strong','',fmt(e[key])),el('small','',unit));summary.append(card);}results.append(summary);if(!alliance)results.append(renderPlayerEvents(data.events||[],data.related_players||{}));else results.append(window.uptepAlliance(data,{el,entityLink,playerLink:(id,pseudo)=>{const b=el('button','text-link',pseudo);b.type='button';b.addEventListener('click',()=>{changeKind('players');input.value=pseudo;search(String(id));});return b;}}));
  const controls=el('div','history-heading');controls.append(el('h2','','Évolution historique'));
  for(const[id,label,options]of [['chart-mode','Affichage',[['hour','Par heure'],['day','Par jour']]],['chart-period','Période',[['all','Tout l’historique'],['7','7 jours'],['30','30 jours'],['90','90 jours']]]]){const l=el('label','',label+' '),select=el('select');select.id=id;for(const[value,text]of options){const option=el('option','',text);option.value=value;select.append(option);}l.append(select);controls.append(l);}results.append(controls,el('p','muted'));
  const note=results.lastChild;note.id='chart-note';
  if(!data.history.length)results.append(el('p','empty','Aucun historique disponible pour cette fiche.'));
  else{const charts=el('section','charts-grid');for(const[key,[label]]of Object.entries(data.metrics)){const card=el('article','chart-card');card.dataset.metric=key;const head=el('div','chart-heading');head.append(el('h3','',label),el('span','delta'));card.append(head,el('div','chart-surface'),el('p','chart-caption'));charts.append(card);}results.append(charts);window.uptepCharts(results,data.history,data.metrics);
   const section=el('section','history-details');section.append(el('h2','','Les relevés en détail'));const scroll=el('div','table-scroll'),table=el('table'),thead=el('thead'),tr=el('tr');for(const label of ['Date de l’export',...Object.values(data.metrics).map(m=>m[0]),...(alliance?[]:['Alliance'])]){const th=el('th','',label);th.scope='col';tr.append(th);}thead.append(tr);const tbody=el('tbody');table.append(thead,tbody);scroll.append(table);section.append(scroll);let offset=0;const rows=[...data.history].reverse(),more=el('button','more','Afficher 50 relevés de plus');function appendRows(){for(const point of rows.slice(offset,offset+50)){const row=el('tr');row.append(el('td','',point.datetime));for(const key of Object.keys(data.metrics))row.append(el('td','',fmt(point[key])));if(!alliance)row.append(el('td','',point.alliance_tag||'—'));tbody.append(row);}offset+=50;more.hidden=offset>=rows.length;}more.addEventListener('click',appendRows);appendRows();section.append(more);results.append(section);
  }
  if(data.truncated)results.append(el('p','muted','Affichage limité aux 5 000 derniers relevés.'));

 }
})();
