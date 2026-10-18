function mountPlayerProfile(context,auth){
 const doc=context.document,win=context.window;
 if(win.location.pathname.toLowerCase()!=='/membre.php'||doc.getElementById('uptep-player-statistics'))return ()=>{};
 const centre=doc.getElementById('centre'),host=[...centre?.querySelectorAll('center')||[]].find(node=>!node.closest('.contenuJoueur'));
 if(!host)return ()=>{};
 const heading=[...host.children].find(node=>node.tagName==='H2'),pseudo=heading?.textContent.trim();
 if(!pseudo||pseudo.length>100)return ()=>{};
 const boxes=[...host.children].filter(node=>node.matches('.boite_membre'));
 const title=box=>[...box.children].find(node=>node.tagName==='H4');
 const profile=boxes.find(box=>title(box)?.textContent.trim().toLocaleLowerCase('fr')==='profil');
 let restoreProfile=()=>{};
 if(profile){
  const original=[...profile.childNodes],h4=title(profile),details=doc.createElement('details'),summary=doc.createElement('summary');
  details.dataset.uptepProfile='1';summary.textContent='Profil';summary.style.cssText='cursor:pointer;font-weight:bold;padding:8px;text-align:left;';details.append(summary);
  const body=doc.createElement('div');for(const node of original)if(node!==h4)body.append(node);details.append(body);profile.replaceChildren(details);
  restoreProfile=()=>profile.replaceChildren(...original);
 }
 const block=doc.createElement('div');block.id='uptep-player-statistics';block.className='boite_membre';
 const details=doc.createElement('details'),summary=doc.createElement('summary');summary.textContent='Statistiques UPTEP+ · '+pseudo;summary.style.cssText='cursor:pointer;font-weight:bold;padding:8px;text-align:left;';details.append(summary);block.append(details);
 const status=doc.createElement('p');status.textContent='Ouvre ce bloc pour consulter les statistiques du joueur.';status.setAttribute('role','status');details.append(status);
 // IDs are taken from the native action box, never from the player-authored profile.
 const actions=boxes.find(box=>title(box)?.textContent.trim().toLocaleLowerCase('fr')==='action');let playerId=null;
 for(const a of actions?.querySelectorAll('a[href]')||[]){try{const url=new URL(a.getAttribute('href'),win.location.href),id=url.searchParams.get('ID');if(url.origin===win.location.origin&&url.pathname.toLowerCase()==='/commerce.php'&&/^[1-9][0-9]{0,17}$/.test(id||'')){playerId=id;break;}}catch{}}
 let frame=null,stopped=false,timeout=null;
 function sendAuth(){if(auth&&frame&&!stopped)frame.contentWindow?.postMessage({type:'uptep-auth',token:auth.token,pseudo:context.pseudo,playerProfile:{pseudo,...(playerId?{player_id:playerId}:{})}},'https://uptep.top');}
 function receive(event){
  if(stopped||!frame||event.origin!=='https://uptep.top'||event.source!==frame.contentWindow)return;
  if(event.data?.type==='uptep-ready')sendAuth();
  if(event.data?.type==='uptep-height'&&Number.isFinite(event.data.height)){frame.style.height=Math.min(20000,Math.max(300,event.data.height))+'px';status.hidden=true;win.clearTimeout(timeout);}
 }
 function open(){
  if(!details.open||frame||stopped)return;
  if(!auth){status.textContent='Accès aux statistiques indisponible. Recharge la page.';return;}
  status.textContent='Chargement des statistiques…';frame=doc.createElement('iframe');frame.title='Statistiques UPTEP+ de '+pseudo;frame.referrerPolicy='no-referrer';frame.style.cssText='display:block;width:100%;height:400px;border:0;background:#d7c384;';frame.src='https://uptep.top/portal.php?profile=1';frame.addEventListener('load',sendAuth);details.append(frame);
  timeout=win.setTimeout(()=>{if(!stopped&&!status.hidden)status.textContent='Le chargement prend du temps. Recharge la page pour réessayer.';},20000);
 }
 details.addEventListener('toggle',open);win.addEventListener('message',receive);
 if(profile)profile.after(block);else host.append(block);
 return ()=>{stopped=true;win.clearTimeout(timeout);details.removeEventListener('toggle',open);win.removeEventListener('message',receive);frame?.removeEventListener('load',sendAuth);block.remove();restoreProfile();};
}
