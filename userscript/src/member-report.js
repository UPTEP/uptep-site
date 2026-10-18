function mountMemberReport(context,auth){
 const doc=context.document,win=context.window;if(!auth||win.location.pathname.toLowerCase()!=='/membre.php'||doc.getElementById('uptep-member-report'))return ()=>{};
 const host=[...doc.querySelectorAll('#centre center')].find(node=>!node.closest('.contenuJoueur'));if(!host)return ()=>{};
 const boxes=[...host.children].filter(n=>n.matches('.boite_membre')),title=box=>[...box.children].find(n=>n.tagName==='H4')?.textContent.trim().toLowerCase();
 const info=boxes.find(b=>title(b)==='informations'),actions=boxes.find(b=>title(b)==='action'),pseudo=[...host.children].find(n=>n.tagName==='H2')?.textContent.trim();
 if(!pseudo||pseudo.length>100||!info)return ()=>{};
 let member=false,playerId=null;
 for(const a of info.querySelectorAll('a[href]')){try{const url=new URL(a.getAttribute('href'),win.location.href);if(url.origin===win.location.origin&&url.pathname.toLowerCase()==='/classementalliance.php'&&url.searchParams.get('alliance')?.toUpperCase()==='UPTEP')member=true;}catch{}}
 if(!member)return ()=>{};
 for(const a of actions?.querySelectorAll('a[href]')||[]){try{const url=new URL(a.getAttribute('href'),win.location.href),id=url.searchParams.get('ID');if(url.origin===win.location.origin&&url.pathname.toLowerCase()==='/commerce.php'&&/^[1-9][0-9]{0,17}$/.test(id||'')){playerId=id;break;}}catch{}}
 const block=doc.createElement('div');block.className='boite_membre';block.id='uptep-member-report';const details=doc.createElement('details'),summary=doc.createElement('summary');summary.textContent='Suivi alliance · '+pseudo+' · Accès chef';summary.style.cssText='cursor:pointer;font-weight:bold;padding:8px;text-align:left;';details.append(summary);block.append(details);
 const status=doc.createElement('p');status.setAttribute('role','status');status.textContent='Ouvre ce cadre pour consulter les relevés et la participation du membre.';details.append(status);
 let frame=null,stopped=false;
 function send(){if(frame&&!stopped)frame.contentWindow?.postMessage({type:'uptep-auth',token:auth.token,pseudo:context.pseudo,memberReport:{pseudo,...(playerId?{player_id:playerId}:{})}},'https://uptep.top');}
 function receive(e){if(!frame||stopped||e.origin!=='https://uptep.top'||e.source!==frame.contentWindow)return;if(e.data?.type==='uptep-ready')send();if(e.data?.type==='uptep-height'&&Number.isFinite(e.data.height)){frame.style.height=Math.min(25000,Math.max(350,e.data.height))+'px';status.hidden=true;}}
 function reset(){frame?.removeEventListener('load',send);frame?.remove();frame=null;status.hidden=false;}
 function toggle(){if(!details.open){reset();return;}if(frame||stopped)return;status.textContent='Vérification du droit chef et chargement…';frame=doc.createElement('iframe');frame.title='Suivi alliance de '+pseudo+' · réservé aux chefs';frame.src='https://uptep.top/portal.php?memberReport=1';frame.referrerPolicy='no-referrer';frame.style.cssText='display:block;width:100%;height:500px;border:0;background:#d7c384;';frame.addEventListener('load',send);details.append(frame);}
 details.addEventListener('toggle',toggle);win.addEventListener('message',receive);host.append(block);
 return ()=>{stopped=true;reset();details.removeEventListener('toggle',toggle);win.removeEventListener('message',receive);block.remove();};
}
