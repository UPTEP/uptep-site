const assert = require('node:assert/strict');
const fs = require('node:fs');
const {execFileSync} = require('node:child_process');
const puppeteer = require('/root/maudits-lighthouse/node_modules/puppeteer-core');
const credentials = JSON.parse(fs.readFileSync('/root/uptep-revocation-test.json'));
function sql(statement){execFileSync('runuser',['-u','postgres','--','psql','-X','-v','ON_ERROR_STOP=1','-d','uptep','-c',statement],{stdio:'pipe'});}
(async()=>{
 const browser = await puppeteer.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox']});
 try {
  const p = await browser.newPage();
  await p.goto('https://uptep.top/login.php',{waitUntil:'networkidle0'});
  await p.type('#username',credentials.username);await p.type('#password',credentials.password);
  await Promise.all([p.waitForNavigation({waitUntil:'networkidle0'}),p.click('.primary-button')]);
  assert.ok(p.url().endsWith('/password.php'));
  sql("UPDATE users SET status='pending' WHERE username='revocationtest'");
  await p.goto('https://uptep.top/password.php',{waitUntil:'networkidle0'});
  assert.ok(p.url().endsWith('/login.php'));
  await p.type('#username',credentials.username);await p.type('#password',credentials.password);
  await Promise.all([p.waitForNavigation({waitUntil:'networkidle0'}),p.click('.primary-button')]);
  assert.ok(p.url().endsWith('/login.php'));assert.ok(await p.$('[role=alert]'));
  console.log('OK: compte en attente refusé et session existante révoquée.');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
