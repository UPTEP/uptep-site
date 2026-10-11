const fs=require('fs'),assert=require('node:assert/strict');
const puppeteer=require('/root/maudits-lighthouse/node_modules/puppeteer-core');
(async()=>{const browser=await puppeteer.launch({executablePath:'/usr/bin/chromium',args:['--no-sandbox'],headless:true});try{const page=await browser.newPage();await page.addScriptTag({content:fs.readFileSync('/root/uptep-site/userscript/collect.js','utf8')});
const result=await page.evaluate(()=>{
 const names=['Jeune Soldate Naine','Soldate Naine','Naine d’Elite','Jeune Soldate','Soldate','Soldate d’élite','Concierge','Concierge d’élite','Artilleuse','Artilleuse d’élite','Tank','Tank d’élite','Tueuse','Tueuse d’élite'];
 const parse=(rows)=>{const d=new DOMParser().parseFromString('<div id="centre"><table class="simulateur"><tr><td colspan="10">Troupes en Garnison</td></tr>'+rows+'</table></div>','text/html');return readGameArmy(d).values;};
 const row=(name,cells)=>'<tr><td><span class="pas_sur_telephone">'+name+'</span></td>'+cells+'</tr>';
 const blank='<td colspan="3"></td>';
 const screenshot=names.filter((_,i)=>i!==7&&i!==11).map((n,i)=>row(n,blank+(i<3?'<td>❮</td><td>'+[2157,243,4][i]+'</td><td>❯</td>':blank)+blank)).join('');
 const full=names.map(n=>row(n,'<td>1 000</td><td>→</td><td>←</td><td>2 000</td><td>→</td><td>←</td><td>3 000</td><td>→</td><td>←</td>')).join('');
 let malformed=false,incomplete=false;
 try{parse(screenshot.replace('2 157','bad').replace('2157','bad'));}catch(e){malformed=true;}
 try{parse(screenshot.replace(/<tr><td><span class="pas_sur_telephone">Soldate Naine[\s\S]*?<\/tr>/,''));}catch(e){incomplete=true;}
 return {screenshot:parse(screenshot),full:parse(full),malformed,incomplete};
});assert.equal(result.screenshot.jsn,'2157');assert.equal(result.screenshot.sn,'243');assert.equal(result.screenshot.ne,'4');assert.equal(result.screenshot.ce,'0');assert.equal(result.screenshot.te,'0');assert.equal(Object.keys(result.screenshot).length,14);assert(Object.values(result.full).every(n=>n==='6000'));assert(result.malformed);assert(result.incomplete);console.log('Army parser OK: merged blanks, middle counts, absent elite units, three-location totals, malformed/incomplete rejection.');}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
