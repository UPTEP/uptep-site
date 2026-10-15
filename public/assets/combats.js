'use strict';
document.querySelectorAll('[data-player-picker]').forEach(picker=>{
 const query=picker.querySelector('[data-player-query]'),id=picker.querySelector('[data-player-id]'),results=picker.querySelector('[data-player-results]'),status=picker.querySelector('[data-player-status]');
 let timer,controller;
 async function search(){
  const value=query.value.trim(); results.replaceChildren(); if(value.length<2)return;
  controller?.abort();controller=new AbortController();
  try{
   const response=await fetch('/combats.php?action=players&q='+encodeURIComponent(value),{signal:controller.signal});if(!response.ok)throw new Error();
   const players=await response.json();if(query.value.trim()!==value)return;
   const exact=players.filter(p=>p.pseudo.toLowerCase()===value.toLowerCase()||String(p.player_id)===value);
   if(exact.length===1){query.value=exact[0].pseudo;id.value=exact[0].player_id;status.textContent='Joueur associé : #'+exact[0].player_id;return;}
   status.textContent=players.length?'Sélectionne le joueur correspondant.':'Aucun joueur S5 trouvé.';
   players.forEach(player=>{const button=document.createElement('button');button.type='button';button.textContent=player.pseudo+' · #'+player.player_id;button.addEventListener('click',()=>{query.value=player.pseudo;id.value=player.player_id;results.replaceChildren();status.textContent='Joueur associé : #'+player.player_id;});results.append(button);});
  }catch(e){if(e.name!=='AbortError')status.textContent='Recherche indisponible. Réessaie.';}
 }
 query.addEventListener('input',()=>{id.value='';controller?.abort();clearTimeout(timer);timer=setTimeout(search,250);});
 query.addEventListener('keydown',event=>{if(event.key==='Escape'){results.replaceChildren();controller?.abort();}if(event.key==='ArrowDown'){const first=results.querySelector('button');if(first){event.preventDefault();first.focus();}}});
 if(id.value)status.textContent='Joueur associé : #'+id.value;else if(query.value.trim().length>=2)search();
});
document.querySelectorAll('.combat-page form[method="post"]').forEach(form=>form.addEventListener('submit',()=>{const button=form.querySelector('button[type="submit"]');if(button){button.disabled=true;button.textContent='Traitement en cours…';}}));
