const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
const root=process.env.UPTEP_SITE_ROOT||path.resolve(__dirname,'..');
const archive=fs.readFileSync(root+'/references/spyzzz-rc/page-source.html','utf8');
const names=archive.match(/const UNIT_SG = \[.*?\];/s)[0],army=archive.match(/const armyKey = .*?const ARMY_NAMES = .*?;/s)[0];
const engine=fs.readFileSync(root+'/references/spyzzz-rc/combat.js','utf8');
async function calculator(original){
 const nodes={'#rc-in':{value:''},'#rc-out':{innerHTML:''},'#rc-go':{},'[name=rcl]:checked':{value:'auto'}};
 const app={innerHTML:'',querySelector:s=>nodes[s]};const context={window:{},document:{getElementById:()=>app},app,$:s=>nodes[s],loadCombat:async()=>{},console};
 vm.createContext(context);vm.runInContext(engine,context);
 if(original){vm.runInContext(names+'\n'+army+'\n'+fs.readFileSync(root+'/references/spyzzz-rc/rc-calculator.excerpt.js','utf8'),context);await context.rcCalc();}
 else {vm.runInContext(fs.readFileSync(root+'/public/assets/tools-rc.js','utf8'),context);await new Promise(setImmediate);}
 return {context,nodes,run(text,place){nodes['#rc-in'].value=text;nodes['[name=rcl]:checked'].value=place;nodes['#rc-go'].onclick();return nodes['#rc-out'].innerHTML.replace(/ style="[^"]*"/g,'');}};
}
(async()=>{
 assert.equal(fs.readFileSync(root+'/public/assets/tools-combat.js','utf8'),engine,'original engine byte-for-byte');
 const original=await calculator(true),integrated=await calculator(false),Combat=original.context.window.Combat;
 const unitNames=vm.runInContext('UNIT_SG',original.context);const fixtures=[];
 for(const lieu of ['terrain','dome','loge'])for(const view of ['att','def']){
  const A=Array(14).fill(0),D=Array(14).fill(0);A[0]=10000;A[7]=300;D[0]=8000;D[5]=200;
  const options={aArm:12,dArm:9,aBou:8,dBou:10,lieu,niveau:12};const result=Combat.combat(A,D,options);
  const units=a=>a.flatMap((n,i)=>n?[`${n} ${unitNames[i]}`]:[]).join(', ');
  let report=`Troupes en attaque : ${units(A)}.\nTroupes en défense : ${units(D)}.\n`;
  if(lieu!=='terrain')report+='Lieu : '+(lieu==='dome'?'Dôme':'Loge impériale')+'\n';
  for(const turn of result.log){
   report+=`${view==='att'?'Vous infligez':'L’ennemie inflige'} ${Math.ceil(turn.aBase)} (+ ${Math.ceil(turn.aBonus)}) dégâts et tuez ${turn.killedD} ennemies.\n`;
   report+=`${view==='def'?'Vous infligez':'L’ennemie inflige'} ${Math.ceil(turn.dBase)} (+ ${Math.ceil(turn.dBonus)}) dégâts et tue ${turn.killedA} ennemies.\n`;
  }
  fixtures.push({report,lieu,view});
  assert.equal(integrated.run(report,'auto'),original.run(report,'auto'),lieu+' '+view+' auto');
  assert.equal(integrated.run(report,lieu),original.run(report,lieu),lieu+' '+view+' manual');
  assert(!integrated.run(report,lieu).includes('Aucune combinaison de niveaux'),'fixture must produce valid combinations');
 }
 for(const text of ['', 'texte invalide',fixtures[0].report.replaceAll('10000','10\u202f000'), fixtures[0].report.replaceAll('\n',' ')])assert.equal(integrated.run(text,'auto'),original.run(text,'auto'));
 assert.equal(integrated.context.window.UptepRc.parseRc(fixtures[0].report).view,'att');
 assert.equal(integrated.context.window.UptepRc.parseRc(fixtures[1].report).view,'def');
 if(process.env.RC_FIXTURE_OUTPUT)fs.writeFileSync(process.env.RC_FIXTURE_OUTPUT,fixtures[2].report);
 console.log('RC parity OK: identical engine; 16 report/location comparisons; both perspectives; terrain, dome, loge; invalid input and spaced numbers.');
})().catch(e=>{console.error(e);process.exit(1)});
