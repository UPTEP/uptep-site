'use strict';
window.uptepCharts=function(root,history,metrics){
 const ns='http://www.w3.org/2000/svg',fmt=new Intl.NumberFormat('fr-FR',{maximumFractionDigits:0}),short=new Intl.NumberFormat('fr-FR',{notation:'compact',maximumFractionDigits:1});
 const node=(tag,attrs={},text)=>{const n=document.createElementNS(ns,tag);for(const[k,v]of Object.entries(attrs))n.setAttribute(k,String(v));if(text!==undefined)n.textContent=text;return n;};
 const mode=root.querySelector('#chart-mode'),period=root.querySelector('#chart-period');
 function render(){
  const cutoff=period.value==='all'?-Infinity:(history.at(-1)?.timestamp||0)-Number(period.value)*86400000;
  let data=history.filter(d=>d.timestamp>=cutoff);
  if(mode.value==='day'){const days=new Map();for(const d of data)days.set(d.date,d);data=[...days.values()];}
  root.querySelector('#chart-note').textContent=`${data.length} relevés · ${mode.value==='day'?'dernier export par jour':'tous les exports'} · heure de Paris.`;
  for(const [index,[key,[label,unit,color]]]of Object.entries(metrics).entries()){
   const card=root.querySelector(`[data-metric="${key}"]`),surface=card.querySelector('.chart-surface');surface.replaceChildren();
   const valid=data.filter(d=>d[key]!==null&&Number.isFinite(Number(d[key])));
   if(!valid.length){surface.textContent='Aucun relevé sur cette période.';card.querySelector('.delta').textContent='';continue;}
   const delta=BigInt(valid.at(-1)[key])-BigInt(valid[0][key]);card.querySelector('.delta').textContent=(delta>0?'+':'')+fmt.format(delta)+(unit?' '+unit:'');
   // Keep extrema and endpoints for long histories, while the detail table retains every value.
   let points=valid;
   if(valid.length>700){const selected=new Set([valid[0],valid.at(-1)]),size=Math.ceil(valid.length/300);for(let i=0;i<valid.length;i+=size){const chunk=valid.slice(i,i+size);selected.add(chunk.reduce((a,b)=>Number(a[key])<Number(b[key])?a:b));selected.add(chunk.reduce((a,b)=>Number(a[key])>Number(b[key])?a:b));}points=[...selected].sort((a,b)=>a.timestamp-b.timestamp);}
   card.querySelector('.chart-caption').textContent=points.length<valid.length?`${points.length} points représentatifs sur ${valid.length} relevés. Toutes les valeurs sont dans le tableau.`:`${valid.length} relevés affichés.`;
   const w=520,h=240,L=64,R=16,T=18,B=42,values=valid.map(d=>Number(d[key])),min=Math.min(...values),max=Math.max(...values),pad=(max-min)*.15||Math.max(max*.05,1),low=Math.max(0,min-pad),high=max+pad,start=valid[0].timestamp,end=valid.at(-1).timestamp;
   const x=d=>start===end?(L+w-R)/2:L+(d.timestamp-start)/(end-start)*(w-L-R),y=v=>T+(high-Number(v))/(high-low)*(h-T-B);
   const svg=node('svg',{viewBox:`0 0 ${w} ${h}`,role:'img','aria-label':label+' : '+valid.length+' relevés'});svg.append(node('title',{},label+' du '+valid[0].date+' au '+valid.at(-1).date));
   for(let i=0;i<4;i++){const v=low+(high-low)*i/3;svg.append(node('line',{x1:L,x2:w-R,y1:y(v),y2:y(v),class:'axis-grid'}),node('text',{x:L-8,y:y(v)+4,'text-anchor':'end'},short.format(v)));}
   if(points.length>1)svg.append(node('polyline',{points:points.map(d=>x(d)+','+y(d[key])).join(' '),fill:'none',stroke:color,'stroke-width':2.5}));
   // One marker follows the nearest original reading; the line stays clear at any density.
   const marker=node('circle',{r:3.5,fill:color,stroke:'#eee5c9','stroke-width':1.5,'pointer-events':'none',visibility:'hidden'});
   const tip=document.createElement('div');tip.className='chart-tooltip';tip.hidden=true;tip.setAttribute('role','status');
   svg.setAttribute('tabindex','0');svg.classList.add('chart-focus');
   const baseLabel=label+' : '+valid.length+' relevés. Flèches gauche et droite pour parcourir les relevés, Début et Fin pour les extrêmes.';svg.setAttribute('aria-label',baseLabel);
   let selected=valid.length-1;
   function positionTip(clientX,clientY){
    const bounds=surface.getBoundingClientRect(),width=tip.offsetWidth,height=tip.offsetHeight;
    let left=clientX-bounds.left+14,top=clientY-bounds.top+14;
    if(left+width>bounds.width)left=clientX-bounds.left-width-14;
    if(top+height>bounds.height||bounds.top+top+height>window.innerHeight)top=clientY-bounds.top-height-14;
    tip.style.left=Math.max(0,Math.min(left,Math.max(0,bounds.width-width)))+'px';tip.style.top=Math.max(0,Math.min(top,Math.max(0,bounds.height-height)))+'px';
   }
   function show(i,event){
    selected=i;const d=valid[i],text=d.datetime+' · '+fmt.format(BigInt(d[key]))+(unit?' '+unit:'');
    if(tip.textContent!==text)tip.textContent=text;tip.hidden=false;marker.setAttribute('cx',x(d));marker.setAttribute('cy',y(d[key]));marker.setAttribute('visibility','visible');
    svg.setAttribute('aria-label',label+' : '+text+'. Flèches gauche et droite pour parcourir les relevés.');
    const point=svg.createSVGPoint();point.x=x(d);point.y=y(d[key]);const screen=point.matrixTransform(svg.getScreenCTM());
    positionTip(event?.clientX??screen.x,event?.clientY??screen.y);
   }
   function hide(){tip.hidden=true;marker.setAttribute('visibility','hidden');svg.setAttribute('aria-label',baseLabel);}
   function follow(event){
    const point=svg.createSVGPoint();point.x=event.clientX;point.y=event.clientY;const local=point.matrixTransform(svg.getScreenCTM().inverse());
    if(local.x<L||local.x>w-R||local.y<T||local.y>h-B){hide();return;}
    const stamp=start+(local.x-L)/(w-L-R)*(end-start);
    let lo=0,hi=valid.length-1;while(lo<hi){const mid=(lo+hi)>>1;if(valid[mid].timestamp<stamp)lo=mid+1;else hi=mid;}
    const i=lo>0&&stamp-valid[lo-1].timestamp<valid[lo].timestamp-stamp?lo-1:lo;show(i,event);
   }
   svg.addEventListener('pointermove',follow);svg.addEventListener('click',follow);svg.addEventListener('pointerleave',hide);svg.addEventListener('pointercancel',hide);
   svg.addEventListener('focus',()=>show(selected));svg.addEventListener('blur',hide);
   svg.addEventListener('keydown',event=>{let next=selected;if(event.key==='ArrowLeft')next--;else if(event.key==='ArrowRight')next++;else if(event.key==='Home')next=0;else if(event.key==='End')next=valid.length-1;else if(event.key==='Escape'){hide();return;}else return;event.preventDefault();show(Math.max(0,Math.min(next,valid.length-1)));});
   svg.append(marker);
   const date=new Intl.DateTimeFormat('fr-FR',{timeZone:'Europe/Paris',day:'2-digit',month:'2-digit',...(mode.value==='hour'?{hour:'2-digit',minute:'2-digit'}:{})});
   for(let i=0;i<(start===end?1:3);i++){const ratio=start===end?.5:i/2;svg.append(node('text',{x:L+ratio*(w-L-R),y:h-15,'text-anchor':i===0?'start':i===2?'end':'middle'},date.format(start+(end-start)*ratio)));}
   surface.append(svg,tip);
  }
 }
 mode.addEventListener('change',render);period.addEventListener('change',render);render();
};
