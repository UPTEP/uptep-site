function mountConvoyManagement(context,{api,exclusive,addTab,status,box,tabs,refreshCurrent}){
 const {document:doc,window:win}=context;
 const el=(tag,text)=>{const n=doc.createElement(tag);if(text!==undefined)n.textContent=text;return n;};
 const fmt=value=>BigInt(value).toLocaleString('fr-FR');
 const date=value=>value?new Date(value).toLocaleString('fr-FR',{timeZone:'Europe/Paris'}):'—';
 const state=d=>d.annulee?'Annulée':d.fini?(d.fin_manuelle?'Terminée manuellement':'Terminée'):d.valide?'En cours':'À valider';
 function resources(apples,wood,{previous=null,remaining=false}={}){
  const amounts=el('div');amounts.className='uptep-demand-amounts';
  for(const [index,label,value,image] of [[0,'Pommes',apples,'icone_pomme.png'],[1,'Matériaux',wood,'icone_bois.png']]){
   const chip=el('span');chip.className='uptep-demand-resource';chip.dataset.zero=String(BigInt(value)===0n);const text=previous?fmt(previous[index])+' → '+fmt(value):fmt(value);chip.title=label+(remaining?' restants':'')+' : '+text;chip.setAttribute('aria-label',chip.title);
   const icon=el('img');icon.src='/images/icone/'+image;icon.alt='';icon.setAttribute('aria-hidden','true');chip.append(icon,doc.createTextNode(text));amounts.append(chip);
  }return amounts;
 }
 const key='uptep-convoys-s5:'+context.pseudo+':management';
 let permissions={is_chaine:false,player_id:''},stopped=false,editorVersion=0;
 const views={},notice=el('div'),editor=el('section'),dialog=el('dialog'),modalStyle=el('style'),modalHeader=el('header'),modalTitle=el('h2'),modalClose=el('button','×'),modalStatus=el('p');
 notice.setAttribute('role','status');editor.hidden=true;editor.setAttribute('aria-label','Gestion de la demande');tabs.after(notice,dialog);
 dialog.id='uptep-convoy-modal';dialog.setAttribute('aria-labelledby','uptep-convoy-modal-title');modalTitle.id='uptep-convoy-modal-title';modalTitle.tabIndex=-1;
 modalClose.type='button';modalClose.setAttribute('aria-label','Fermer la fenêtre');modalClose.className='uptep-modal-close';modalHeader.append(modalTitle,modalClose);modalStatus.setAttribute('role','status');modalStatus.className='uptep-modal-status';dialog.append(modalHeader,modalStatus,editor);
 modalStyle.textContent=`#uptep-convoy-modal{box-sizing:border-box;width:min(780px,calc(100% - 24px));max-width:calc(100% - 24px);max-height:calc(100dvh - 32px);padding:0;border:1px solid #6f643c;border-radius:8px;background:#e2d29c;color:#302b18;font:13px/1.5 Verdana,sans-serif;box-shadow:0 16px 60px #0006}#uptep-convoy-modal::backdrop{background:#151b18a6}#uptep-convoy-modal>header{position:sticky;top:0;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:16px;padding:14px 18px;background:#525543;color:#fff3d3}#uptep-convoy-modal h2{margin:0;font:700 17px/1.4 Verdana,sans-serif;color:inherit}#uptep-convoy-modal .uptep-modal-close{border:0;background:transparent;color:inherit;font:26px/1 Verdana;cursor:pointer;width:32px;height:32px;flex-shrink:0}#uptep-convoy-modal>section{padding:16px 18px}#uptep-convoy-modal p{margin:0 0 10px}#uptep-convoy-modal .uptep-modal-status{margin:0;padding:10px 18px 0;color:#842e24}#uptep-convoy-modal .uptep-modal-status:empty{display:none}#uptep-convoy-modal .uptep-detail-summary{display:flex;align-items:center;flex-wrap:wrap;gap:8px 12px;margin-bottom:12px}#uptep-convoy-modal .uptep-detail-state{display:inline-block;background:#525543;color:#fff3d3;border-radius:12px;padding:3px 10px;font-size:11px}#uptep-convoy-modal .uptep-detail-quantities{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(220px,100%),1fr));gap:10px;margin:12px 0}#uptep-convoy-modal .uptep-detail-card{padding:12px;background:#ffffff30;border:1px solid #b4a16b;border-radius:5px}#uptep-convoy-modal .uptep-detail-card h3{margin:0 0 6px;font-size:12px;font-weight:400;color:#655a37}#uptep-convoy-modal .uptep-detail-card p{margin:2px 0;font-size:15px;font-weight:700;overflow-wrap:anywhere}#uptep-convoy-modal .uptep-detail-dates{margin:10px 0;color:#655a37;font-size:11px}#uptep-convoy-modal .uptep-detail-dates p{margin:3px 0}#uptep-convoy-modal .uptep-detail-actions{display:flex;flex-wrap:wrap;gap:0 4px;margin:10px 0 14px}#uptep-convoy-modal details{border-top:1px solid #b4a16b;padding:10px 0}#uptep-convoy-modal summary{font-weight:700;cursor:pointer;margin-bottom:8px}#uptep-convoy-modal button:focus-visible,#uptep-convoy-modal summary:focus-visible{outline:2px solid #254e59;outline-offset:2px}#uptep-convoy-modal [hidden]{display:none!important}`;
 modalStyle.textContent+=`#uptep-convoys .uptep-demand-card{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:6px 14px;padding:10px 0;border-bottom:1px solid #ad995f}#uptep-convoys .uptep-demand-heading{display:flex;flex-wrap:wrap;align-items:center;gap:5px 9px;margin:0 0 4px;font:700 13px/1.35 Verdana,sans-serif}#uptep-convoys .uptep-demand-id{font-weight:400;color:#6d603a}#uptep-convoys .uptep-demand-state{padding:2px 7px;border-radius:10px;font:11px/1.4 Verdana,sans-serif;white-space:nowrap;background:var(--state-bg);color:var(--state-fg)}#uptep-convoys .uptep-demand-description{margin:0;font:12px/1.4 Verdana,sans-serif;color:#554a2c;overflow-wrap:anywhere}#uptep-convoys .uptep-demand-amounts{display:flex;flex-wrap:wrap;gap:5px;justify-content:flex-end}#uptep-convoys .uptep-demand-resource{display:inline-flex;align-items:center;gap:5px;padding:4px 7px;border:1px solid #b49e63;border-radius:4px;background:#ffffff24;font:700 12px/1.3 Verdana,sans-serif;white-space:nowrap}#uptep-convoys .uptep-demand-resource[data-zero="true"]{font-weight:400;color:#746742;background:transparent}#uptep-convoys .uptep-demand-resource img{width:18px;height:auto}#uptep-convoys .uptep-demand-actions{grid-column:1/-1;display:flex;flex-wrap:wrap;align-items:center;gap:5px}#uptep-convoys .uptep-demand-actions button{margin:0!important;padding:4px 9px!important;font:11px/1.4 Verdana,sans-serif;border:1px solid #938355;border-radius:3px;background:#ffffff38;color:#302b18}#uptep-convoys .uptep-demand-actions button:first-child{background:#525543;color:#fff3d3;border-color:#525543}#uptep-convoys .uptep-demand-card[data-closed="true"] .uptep-demand-actions{grid-column:2;justify-content:flex-end}#uptep-convoys .uptep-demand-card[data-closed="true"] .uptep-demand-identity{grid-row:1/3}@media(max-width:600px){#uptep-convoys .uptep-demand-card{grid-template-columns:minmax(0,1fr);gap:6px}#uptep-convoys .uptep-demand-amounts{justify-content:flex-start}#uptep-convoys .uptep-demand-card[data-closed="true"] .uptep-demand-actions{grid-column:1;justify-content:flex-start}#uptep-convoys .uptep-demand-card[data-closed="true"] .uptep-demand-identity{grid-row:auto}}`;
 modalStyle.textContent+=`#uptep-convoy-modal .uptep-demand-amounts{justify-content:flex-start;margin:4px 0}#uptep-convoy-modal .uptep-convoy-heading{display:flex;flex-wrap:wrap;align-items:center;gap:4px 12px;margin-bottom:4px}#uptep-convoy-modal .uptep-convoy-dates{font-size:11px;color:#655a37;margin:3px 0 6px}#uptep-convoy-modal .uptep-adjustment{padding:7px 0;border-top:1px solid #b4a16b}#uptep-convoy-modal .uptep-adjustment p{margin:0 0 5px;font-size:11px;color:#655a37}`;
 box.append(modalStyle);
 function closeEditor(){editorVersion++;editor.hidden=true;editor.replaceChildren();if(dialog.open)dialog.close();}
 function reportError(message){status.textContent=message;if(dialog.open)modalStatus.textContent=message;}
 const statusObserver=new win.MutationObserver(()=>{if(dialog.open)modalStatus.textContent=status.textContent;});statusObserver.observe(status,{childList:true,subtree:true,characterData:true});
 modalClose.addEventListener('click',closeEditor);dialog.addEventListener('cancel',event=>{event.preventDefault();closeEditor();});dialog.addEventListener('click',event=>{if(event.target!==dialog)return;const rect=dialog.getBoundingClientRect();if(event.clientX<rect.left||event.clientX>rect.right||event.clientY<rect.top||event.clientY>rect.bottom)closeEditor();});
 function button(parent,label,fn){const b=el('button',label);b.type='button';if(['Consulter','Consulter / Gérer','Valider','Confirmer'].includes(label))b.dataset.uptepPrimary='1';b.disabled=box.getAttribute('aria-busy')==='true';b.style.cssText='margin:5px 6px 5px 0;padding:6px 10px;cursor:pointer;';b.addEventListener('click',()=>Promise.resolve().then(fn).catch(e=>{if(!stopped)reportError(e.message);}));parent.append(b);return b;}
 function acceptPermissions(value){
  if(!value)return;const lost=permissions.is_chaine&&!value.is_chaine;permissions=value;
  if(lost)closeEditor();
 }
 function stored(){const raw=win.localStorage.getItem(key);if(!raw)return null;let record;try{record=JSON.parse(raw);}catch{throw Error('Suivi de gestion illisible. Vérifie les demandes sur UPTEP avant de continuer.');}if(!record?.job||record.job.action!=='convoy-manage'||!record.job.request_key||typeof record.summary!=='string')throw Error('Suivi de gestion invalide. Vérifie les demandes sur UPTEP.');return record;}
 async function execute(record){
  try{await api(record.job);}catch(e){
   // A definitive refusal permits a fresh form. Network/server failures retain
   // the original command so a retry cannot repeat an already committed write.
   if(e.status>=400&&e.status<500&&e.status!==429)win.localStorage.removeItem(key);
   if(e.status===403){try{await refreshCurrent();}catch{}}
   recovery();throw e;
  }
  win.localStorage.removeItem(key);if(stopped)return;closeEditor();recovery();
  status.textContent='✓ Opération enregistrée sur UPTEP.';
  await refreshCurrent();
  for(const view of Object.values(views))if(!view.panel.hidden)await loadView(view);
 }
 function recovery(){notice.replaceChildren();try{const record=stored();if(!record)return;notice.append(el('p','Opération à confirmer : '+record.summary));button(notice,'Réessayer la même opération',()=>exclusive(async()=>{const latest=stored();if(latest)await execute(latest);}));}catch(e){notice.append(el('p',e.message));}}
 function startEditor(title){editorVersion++;editor.hidden=false;editor.replaceChildren();modalTitle.textContent=title;modalStatus.textContent='';if(!dialog.open)dialog.showModal();modalTitle.focus();return editorVersion;}
 function stage(d,operation,values={},description=''){
  const labels={validation:'Valider',annulation:'Annuler',terminaison:'Terminer',suspension:'Suspendre',modification:'Modifier',ajustement:'Ajuster le restant',annulation_convoi:'Annuler la déclaration'};
  const summary=`${labels[operation]} · demande #${d.id} de ${d.pseudo_demandeur}`;
  startEditor(summary);editor.append(el('p',description||`${d.objet||'Ressources'}${d.niveau?' · niveau '+d.niveau:''} · ${fmt(d.nbpomme_restant)} pommes / ${fmt(d.nbmateriaux_restant)} matériaux restants.`));
  if(operation==='validation')editor.append(el('p',`Demandé : ${fmt(d.nbpomme_original)} pommes / ${fmt(d.nbmateriaux_original)} matériaux · date souhaitée : ${d.date_souhaitee||'Non renseignée'}. La validation déclenche l’annonce Discord habituelle.`));
  if(operation==='annulation')editor.append(el('p','La demande sera abandonnée. Les convois resteront dans l’historique.'));
  if(operation==='terminaison')editor.append(el('p','La demande sera fermée avec son restant actuel, sans ajouter de convoi.'));
  if(operation==='suspension')editor.append(el('p','La demande repassera en attente de validation. Le restant et les convois seront conservés.'));
  const job={action:'convoy-manage',operation,demand_id:String(d.id),version:d.version_modification,...values,confirmed:true,request_key:win.crypto.randomUUID()};
  button(editor,'Confirmer',()=>exclusive(async()=>{if(stored())throw Error('Reprends d’abord l’opération en attente avec le bouton prévu.');const record={job,summary};win.localStorage.setItem(key,JSON.stringify(record));recovery();await execute(record);}));
  button(editor,'Retour',closeEditor);
 }
 function edit(d){
  startEditor((d.valide?'Ajuster le restant':'Modifier la demande')+' #'+d.id);const form=el('form'),fields={};editor.append(form);
  form.append(el('p',d.valide?'Saisis les quantités encore nécessaires. Les convois déjà déclarés restent inchangés.':'La demande restera en attente de validation.'));
  const definitions=[['apples',d.valide?'Pommes encore nécessaires':'Pommes demandées','text',d.valide?d.nbpomme_restant:d.nbpomme_original],['wood',d.valide?'Matériaux encore nécessaires':'Matériaux demandés','text',d.valide?d.nbmateriaux_restant:d.nbmateriaux_original]];
  if(!d.valide)definitions.push(['object','Construction ou recherche','text',d.objet||''],['level','Niveau','number',d.niveau||'1'],['wanted_date','Date souhaitée (facultative)','date',d.date_souhaitee||'']);
  for(const [name,label,type,value] of definitions){const wrap=el('label',label),input=el('input');input.name=name;input.type=type;input.value=value;input.required=name!=='wanted_date';input.style.cssText='display:block;width:100%;max-width:340px;box-sizing:border-box;padding:6px;margin:4px 0 10px;';if(name==='apples'||name==='wood')input.inputMode='decimal';if(name==='level'){input.min='1';input.max='999';}if(name==='object')input.maxLength=160;wrap.append(input);form.append(wrap);fields[name]=input;}
  const save=el('button','Vérifier les modifications');save.type='submit';save.dataset.uptepPrimary='1';form.append(save);button(form,'Retour',closeEditor);
  form.addEventListener('submit',event=>{event.preventDefault();try{
   const values={apples:convoyInputNumber(fields.apples.value),wood:convoyInputNumber(fields.wood.value)};
   if(d.valide){values.expected_apples=d.nbpomme_restant;values.expected_wood=d.nbmateriaux_restant;}
   else{if(BigInt(values.apples)+BigInt(values.wood)===0n)throw Error('Renseigne au moins une quantité positive.');Object.assign(values,{object:fields.object.value.trim(),level:fields.level.value,wanted_date:fields.wanted_date.value||null});if(!values.object||!/^[1-9][0-9]{0,2}$/.test(values.level))throw Error('Renseigne la construction ou recherche et un niveau de 1 à 999.');}
   let description=`${fmt(values.apples)} pommes / ${fmt(values.wood)} matériaux${d.valide?' encore nécessaires':` · ${values.object} niveau ${values.level} · date souhaitée : ${values.wanted_date||'Non renseignée'}`}.`;
   if(d.valide&&values.apples==='0'&&values.wood==='0')description+=' La demande sera terminée manuellement.';
   stage(d,d.valide?'ajustement':'modification',values,description);
  }catch(e){reportError(e.message);}});
 }
 function actions(parent,d){
  if(d.fini||d.annulee)return;
  const manager=permissions.is_chaine,owner=String(d.iddemandeur)===String(permissions.player_id);
  if(manager&&!d.valide)button(parent,'Valider',()=>stage(d,'validation'));
  if(owner||manager){button(parent,'Modifier',()=>edit(d));button(parent,'Annuler la demande',()=>stage(d,'annulation'));if(d.valide)button(parent,'Terminer la demande',()=>stage(d,'terminaison'));}
  if(manager&&d.valide)button(parent,'Suspendre',()=>stage(d,'suspension'));
 }
 async function openDetail(id){
  const version=startEditor('Demande #'+id);editor.append(el('p','Chargement…'));const data=await api({action:'convoy-detail',demand_id:String(id)});if(stopped||version!==editorVersion)return;
  acceptPermissions(data.permissions);if(version!==editorVersion)return;const d=data.demand;modalTitle.textContent=`Demande #${d.id} · ${d.pseudo_demandeur}`;editor.replaceChildren();
  const summary=el('div');summary.className='uptep-detail-summary';const badge=el('span',state(d));badge.className='uptep-detail-state';summary.append(badge,el('strong',`${d.objet||'Ressources'}${d.niveau?' · niveau '+d.niveau:''}`));editor.append(summary);
  const quantities=el('div');quantities.className='uptep-detail-quantities';for(const [label,apples,wood] of [['Demandé',d.nbpomme_original,d.nbmateriaux_original],['Restant',d.nbpomme_restant,d.nbmateriaux_restant]]){const card=el('div');card.className='uptep-detail-card';card.append(el('h3',label),resources(apples,wood,{remaining:label==='Restant'}));quantities.append(card);}editor.append(quantities);
  const dates=el('div');dates.className='uptep-detail-dates';dates.append(el('p','Date souhaitée : '+(d.date_souhaitee||'Non renseignée')));for(const [label,value] of [['Créée',d.date_demande],['Validée',d.date_validation],['Terminée',d.date_fin],['Annulée',d.date_annulation]])if(value)dates.append(el('p',label+' le '+date(value)+' (Paris)'));editor.append(dates);
  const actionBar=el('div');actionBar.className='uptep-detail-actions';actions(actionBar,d);editor.append(actionBar);
  const history=el('details'),historyTitle=el('summary','Derniers ajustements · '+(d.ajustements?.length||0));history.append(historyTitle);for(const a of d.ajustements||[]){const row=el('div');row.className='uptep-adjustment';row.append(el('p',`${date(a.date_ajustement)} · ${a.pseudo_acteur}`),resources(a.pommes_apres,a.materiaux_apres,{previous:[a.pommes_avant,a.materiaux_avant]}));history.append(row);}if(!d.ajustements?.length)history.append(el('p','Aucun ajustement.'));
  const convoySection=el('details');convoySection.open=data.convoys.length>0;const convoyTitle=el('summary','Convois déclarés');convoySection.append(convoyTitle);const convoys=el('div');convoySection.append(convoys);editor.append(convoySection,history);
  function appendConvoys(rows){for(const c of rows){const row=el('article');row.style.cssText='border-top:1px solid #887749;padding:8px 0;';const heading=el('div');heading.className='uptep-convoy-heading';heading.append(el('span',`#${c.id} · ${c.pseudo_envoyeur} → ${d.pseudo_demandeur}${c.annule?' · Déclaration annulée':''}`),resources(c.nbpomme,c.nbmateriaux));const dates=el('p',`Départ : ${date(c.date_convoi)} · arrivée : ${date(c.date_arrivee)} (Paris).`);dates.className='uptep-convoy-dates';row.append(heading,dates);if(!c.annule&&(permissions.is_chaine||String(c.idenvoyeur)===String(permissions.player_id)))button(row,'Annuler la déclaration',()=>stage(d,'annulation_convoi',{convoy_id:String(c.id)},`Convoi #${c.id} : ${fmt(c.nbpomme)} pommes / ${fmt(c.nbmateriaux)} matériaux seront remis dans le restant. Une demande soldée automatiquement sera rouverte ; une demande annulée ou terminée manuellement restera fermée. Le trajet dans Fourmizzz continue.`));convoys.append(row);}}
  appendConvoys(data.convoys);if(!data.convoys.length)convoys.append(el('p','Aucun convoi déclaré.'));
  let next=data.next;const more=button(convoySection,'Voir plus de convois',async()=>{more.disabled=true;try{const page=await api({action:'convoy-detail',demand_id:String(id),after:next});if(stopped||version!==editorVersion)return;acceptPermissions(page.permissions);appendConvoys(page.convoys);next=page.next;more.hidden=!next;}finally{if(!stopped)more.disabled=false;}});more.hidden=!next;
  button(editor,'Fermer le détail',closeEditor);
 }
 function createView(scope,label){
  const panel=el('div'),filter=el('select'),rows=el('div');filter.setAttribute('aria-label','État des demandes');for(const [value,text] of [['all','Tous les états'],['open','Demandes ouvertes'],['attente','À valider'],['en_cours','En cours'],['terminee','Terminées'],['annulee','Annulées']]){const option=el('option',text);option.value=value;filter.append(option);}filter.value='all';const filters=el('div');filters.style.cssText='display:flex;flex-wrap:wrap;align-items:center;gap:0 8px;';filters.append(filter);let player=null;
  if(scope==='history'){player=el('select');player.setAttribute('aria-label','Joueur de l’alliance');const all=el('option','Tous les joueurs');all.value='';player.append(all);filters.append(player);}panel.append(filters,rows);
  const view={scope,panel,filter,player,rows,next:null,version:0};views[scope]=view;const tab=addTab(scope,label,panel,()=>loadView(view));view.tab=tab;
  const more=button(panel,'Voir plus de demandes',()=>loadView(view,true));more.hidden=true;view.more=more;for(const select of [filter,player].filter(Boolean))select.addEventListener('change',()=>loadView(view).catch(e=>{status.textContent=e.message;}));
 }
 async function loadView(view,append=false){
  const version=++view.version;view.more.disabled=true;
  try{const data=await api({action:'convoy-list',scope:view.scope,state:view.filter.value,after:append?view.next:'0',...(view.player?.value?{player_id:view.player.value}:{})});if(stopped||version!==view.version)return;acceptPermissions(data.permissions);
   if(view.player&&Array.isArray(data.players)){const selected=view.player.value;view.player.replaceChildren();const all=el('option','Tous les joueurs');all.value='';view.player.append(all);for(const player of data.players){const option=el('option',player.pseudo);option.value=String(player.player_id);view.player.append(option);}view.player.value=selected;if(selected&&!view.player.value){await loadView(view);return;}}
   if(!append)view.rows.replaceChildren();for(const d of data.demands){const row=el('article');row.className='uptep-demand-card';row.dataset.closed=String(d.fini||d.annulee);
   const identity=el('div');identity.className='uptep-demand-identity';const heading=el('h3');heading.className='uptep-demand-heading';const id=el('span','#'+d.id);id.className='uptep-demand-id';const badge=el('span',state(d));badge.className='uptep-demand-state';const colors=d.annulee?['#ead0b7','#743724']:d.fini?['#d3d1b7','#53543f']:d.valide?['#c0d0a4','#344b2b']:['#efdd9d','#70521c'];badge.style.setProperty('--state-bg',colors[0]);badge.style.setProperty('--state-fg',colors[1]);heading.append(id,doc.createTextNode(d.pseudo_demandeur),badge);
   const description=el('p',`${d.objet||'Ressources'}${d.niveau?' · niv. '+d.niveau:''}`);description.className='uptep-demand-description';identity.append(heading,description);
   const amounts=resources(d.nbpomme_restant,d.nbmateriaux_restant,{remaining:true});
   const controls=el('div');controls.className='uptep-demand-actions';button(controls,'Consulter',()=>openDetail(d.id));actions(controls,d);row.append(identity,amounts,controls);view.rows.append(row);}if(!append&&!data.demands.length)view.rows.append(el('p','Aucune demande.'));view.next=data.next;view.more.hidden=!view.next;
  }finally{if(!stopped&&version===view.version)view.more.disabled=false;}
 }
 createView('mine','Mes demandes');createView('history','Consulter / Historique');recovery();
 return {acceptPermissions,decorate:(row,d)=>{button(row,'Consulter / Gérer',()=>openDetail(d.id));if(permissions.is_chaine)button(row,'Valider',async()=>{const data=await api({action:'convoy-detail',demand_id:String(d.id)});if(stopped)return;acceptPermissions(data.permissions);if(!permissions.is_chaine)throw Error('Le droit chaîne est nécessaire.');stage(data.demand,'validation');});},refresh:async id=>{if(views[id])await loadView(views[id]);},cleanup:()=>{stopped=true;editorVersion++;for(const view of Object.values(views))view.version++;statusObserver.disconnect();if(dialog.open)dialog.close();notice.remove();dialog.remove();modalStyle.remove();}};
}
