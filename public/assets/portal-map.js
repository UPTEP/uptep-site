'use strict';
window.uptepAllianceMap=(data,{el,playerLink})=>{
 const tag=data.entity.tag,section=el('section','player-history alliance-map');section.append(el('h2','','Sur la carte'));
 const toolbar=el('div','map-controls'),surface=el('div','map-surface'),canvas=el('canvas','alliance-canvas'),tip=el('div','map-hover');
 canvas.tabIndex=0;canvas.setAttribute('role','img');canvas.setAttribute('aria-label','Carte de '+tag+' : glisser pour déplacer, molette ou touches + et − pour zoomer, flèches pour déplacer.');tip.hidden=true;tip.setAttribute('role','status');surface.append(canvas,tip);
 const detail=el('div','map-position-detail');detail.setAttribute('aria-live','polite');detail.textContent='Clique sur une position pour consulter les joueurs.';
 section.append(toolbar,surface,el('p','history-note',`Les membres de ${tag} sont en couleur. Survole les points gris pour découvrir les autres joueurs, clique pour ouvrir leurs fiches.`),detail);
 const points=(data.map||[]).filter(p=>p.x!==null&&p.y!==null&&Number.isFinite(+p.x)&&Number.isFinite(+p.y)).map(p=>({...p,x:+p.x,y:+p.y})),members=points.filter(p=>p.alliance_tag===tag),grouped=new Map();
 for(const p of points){const key=p.x+':'+p.y;if(!grouped.has(key))grouped.set(key,{key,x:p.x,y:p.y,players:[]});grouped.get(key).players.push(p);}
 const groups=[...grouped.values()];for(const g of groups){g.members=g.players.filter(p=>p.alliance_tag===tag);g.players.sort((a,b)=>(b.alliance_tag===tag)-(a.alliance_tag===tag)||a.pseudo.localeCompare(b.pseudo));}
 // Draw highlighted groups last, but keep every player at shared coordinates selectable.
 groups.sort((a,b)=>a.members.length-b.members.length);
 const ctx=canvas.getContext('2d'),number=n=>new Intl.NumberFormat('fr-FR').format(n);
 const palette=['#ad5138','#bb8742','#507f89','#96718d','#8e793e','#526f9b','#b65f68','#88736a','#577f7a','#a87364'];
 function color(t){if(t===tag)return '#cf641c';if(!t)return '#9c8776';let n=0;for(const c of t)n=(n*31+c.charCodeAt(0))>>>0;return palette[n%palette.length];}
 let width=0,height=0,camera={x:0,y:0,scale:1},hover=null,chosen=null,drag=null,ready=false;
 const position=p=>({x:width/2+(p.x-camera.x)*camera.scale,y:height/2-(p.y-camera.y)*camera.scale});
 const world=(x,y)=>({x:camera.x+(x-width/2)/camera.scale,y:camera.y-(y-height/2)/camera.scale});
 const radius=g=>g.members.length||g===hover||g===chosen?Math.min(17,4+Math.log2(g.players.length+1)*2):3.5;
 function fit(){const visible=members.length?members:points;if(!visible.length)return;const xs=visible.map(p=>p.x),ys=visible.map(p=>p.y);camera.x=(Math.min(...xs)+Math.max(...xs))/2;camera.y=(Math.min(...ys)+Math.max(...ys))/2;camera.scale=Math.max(.01,Math.min(1000,(width-80)/Math.max(2,Math.max(...xs)-Math.min(...xs)),(height-80)/Math.max(2,Math.max(...ys)-Math.min(...ys))));hideTip();draw();}
 function draw(){if(!width||!height)return;ctx.clearRect(0,0,width,height);ctx.fillStyle='#e8d9b1';ctx.fillRect(0,0,width,height);
  const target=85/camera.scale,power=10**Math.floor(Math.log10(target)),step=[1,2,5,10].map(v=>v*power).find(v=>v>=target)||power*10,a=world(0,height),b=world(width,0);
  ctx.font='10px Verdana, sans-serif';ctx.textBaseline='middle';ctx.lineWidth=1;
  for(let x=Math.ceil(a.x/step)*step;x<=b.x;x+=step){const px=position({x,y:0}).x;ctx.strokeStyle='#b3987655';ctx.beginPath();ctx.moveTo(px,0);ctx.lineTo(px,height);ctx.stroke();ctx.fillStyle='#786943';ctx.textAlign='center';ctx.fillText(number(x),px,height-12);}
  for(let y=Math.ceil(a.y/step)*step;y<=b.y;y+=step){const py=position({x:0,y}).y;ctx.strokeStyle='#b3987655';ctx.beginPath();ctx.moveTo(0,py);ctx.lineTo(width,py);ctx.stroke();ctx.fillStyle='#786943';ctx.textAlign='left';ctx.fillText(number(y),7,py);}
  function point(g){const p=position(g),r=radius(g);if(p.x<-20||p.x>width+20||p.y<-20||p.y>height+20)return;const active=g===hover||g===chosen,colored=active||g.members.length;
   ctx.fillStyle=colored?color(g.members.length?tag:g.players[0].alliance_tag):'#9d998b';ctx.globalAlpha=colored?1:.45;ctx.shadowColor=ctx.fillStyle;ctx.shadowBlur=g===hover?8:0;ctx.beginPath();ctx.arc(p.x,p.y,r,0,Math.PI*2);ctx.fill();ctx.shadowBlur=0;ctx.globalAlpha=1;
   if(colored){ctx.strokeStyle=g===chosen?'#493c27':'#fff4d5';ctx.lineWidth=g===chosen?2.5:1.5;ctx.stroke();if(g.players.length>1){ctx.fillStyle='#fffaf4';ctx.font='bold 10px Verdana';ctx.textAlign='center';ctx.fillText(String(g.players.length),p.x,p.y);}}
   if(g.members.length){const label=g.members.map(p=>p.pseudo).join(', ');ctx.font='bold 11px Verdana';ctx.textAlign=p.x>width*.72?'right':'left';const x=p.x+(p.x>width*.72?-r-5:r+5);ctx.strokeStyle='#eee5c9';ctx.lineWidth=3;ctx.strokeText(label,x,p.y-9);ctx.fillStyle='#322c1b';ctx.fillText(label,x,p.y-9);}
  }
  for(const g of groups)if(g!==hover)point(g);if(hover)point(hover);
 }
 function hideTip(){hover=null;tip.hidden=true;}
 function zoom(factor,x=width/2,y=height/2){const p=world(x,y);camera.scale=Math.max(.01,Math.min(1000,camera.scale*factor));camera.x=p.x-(x-width/2)/camera.scale;camera.y=p.y+(y-height/2)/camera.scale;hideTip();draw();}
 function button(label,fn,aria){const b=el('button','',label);b.type='button';b.setAttribute('aria-label',aria||label);b.addEventListener('click',fn);toolbar.append(b);return b;}
 button('+',()=>zoom(1.5),'Zoom avant');button('−',()=>zoom(1/1.5),'Zoom arrière');button('↺',fit,'Recentrer sur les membres');const expand=button('Agrandir la carte',()=>{const expanded=section.classList.toggle('map-expanded');expand.textContent=expanded?'Réduire la carte':'Agrandir la carte';expand.setAttribute('aria-expanded',String(expanded));});expand.setAttribute('aria-expanded','false');
 function open(g){chosen=g;detail.replaceChildren(el('p','position-label',`X ${g.x} · Y ${g.y} — ${g.players.length} joueur${g.players.length>1?'s':''}`));for(const p of g.players){const row=el('div','map-player-row');row.append(playerLink(p.player_id,p.pseudo),el('span','',p.alliance_tag||'Sans alliance'));if(p.field!=null)row.append(el('span','',number(BigInt(p.field))+' de terrain'));detail.append(row);}draw();}
 const local=e=>{const r=canvas.getBoundingClientRect();return{x:e.clientX-r.left,y:e.clientY-r.top};};
 function hit(x,y){let best=null,distance=Infinity;for(const g of groups){const p=position(g),d=Math.hypot(p.x-x,p.y-y);if(d<=radius(g)+5&&d<distance){best=g;distance=d;}}return best;}
 canvas.addEventListener('wheel',e=>{e.preventDefault();const p=local(e);zoom(Math.exp(-Math.max(-150,Math.min(150,e.deltaY))*.003),p.x,p.y);},{passive:false});
 canvas.addEventListener('pointerdown',e=>{if(drag||e.button!==0)return;const p=local(e);drag={id:e.pointerId,x:p.x,y:p.y,cx:camera.x,cy:camera.y,moved:false};canvas.setPointerCapture(e.pointerId);hideTip();});
 canvas.addEventListener('pointermove',e=>{const p=local(e);if(drag&&drag.id===e.pointerId){const dx=p.x-drag.x,dy=p.y-drag.y;if(Math.hypot(dx,dy)>4)drag.moved=true;if(drag.moved){camera.x=drag.cx-dx/camera.scale;camera.y=drag.cy+dy/camera.scale;draw();}return;}if(drag)return;
  hover=hit(p.x,p.y);tip.hidden=!hover;canvas.style.cursor=hover?'pointer':'grab';if(hover){tip.replaceChildren(el('strong','',hover.players.map(p=>p.pseudo+' · '+(p.alliance_tag||'Sans alliance')).join(' / ')),el('span','',`X ${hover.x} · Y ${hover.y}`));tip.style.left=Math.max(8,Math.min(width-tip.offsetWidth-8,p.x+15))+'px';tip.style.top=Math.max(8,Math.min(height-tip.offsetHeight-8,p.y-45))+'px';}draw();
 });
 canvas.addEventListener('pointerup',e=>{if(!drag||drag.id!==e.pointerId)return;const p=local(e);if(!drag.moved){const g=hit(p.x,p.y);if(g)open(g);}drag=null;});for(const event of ['pointercancel','lostpointercapture'])canvas.addEventListener(event,()=>{drag=null;});canvas.addEventListener('pointerleave',()=>{hideTip();draw();});
 canvas.addEventListener('keydown',e=>{const step=70/camera.scale;if(e.key==='ArrowLeft')camera.x-=step;else if(e.key==='ArrowRight')camera.x+=step;else if(e.key==='ArrowUp')camera.y+=step;else if(e.key==='ArrowDown')camera.y-=step;else if(['+','='].includes(e.key)){zoom(1.5);e.preventDefault();return;}else if(e.key==='-'){zoom(1/1.5);e.preventDefault();return;}else return;e.preventDefault();hideTip();draw();});
 // A keyboard-accessible list also exposes players whose coordinates overlap.
 const accessible=el('details','map-player-list');accessible.append(el('summary','','Liste des joueurs sur la carte'));accessible.addEventListener('toggle',()=>{if(!accessible.open||accessible.childElementCount>1)return;for(const g of groups){const b=el('button','text-link',g.players.map(p=>p.pseudo).join(', ')+` · X ${g.x}, Y ${g.y}`);b.type='button';b.addEventListener('click',()=>{camera.x=g.x;camera.y=g.y;open(g);detail.scrollIntoView({block:'nearest'});});accessible.append(b);}});section.append(accessible);
 const resize=new ResizeObserver(()=>{if(!section.isConnected)return;width=surface.clientWidth;height=surface.clientHeight;const dpr=window.devicePixelRatio||1;canvas.width=Math.round(width*dpr);canvas.height=Math.round(height*dpr);ctx.setTransform(dpr,0,0,dpr,0,0);if(!ready){fit();ready=true;}else{hideTip();draw();}});resize.observe(surface);
 const cleanup=new MutationObserver(()=>{if(!section.isConnected){resize.disconnect();cleanup.disconnect();}});queueMicrotask(()=>{if(section.isConnected)cleanup.observe(section.parentNode.parentNode,{childList:true});else resize.disconnect();});
 if(!members.length)section.append(el('p','muted','Aucun membre avec des coordonnées dans le dernier relevé.'));return section;
};
