const fs = require('node:fs');
const assert = require('node:assert/strict');
const puppeteer = require('/root/maudits-lighthouse/node_modules/puppeteer-core');
const origin = 'https://uptep.top';
const credentials = JSON.parse(fs.readFileSync('/root/uptep-test-credentials.json'));
const changedPassword = 'Une longue phrase UPTEP pour le test ' + require('node:crypto').randomBytes(10).toString('hex');
const results = [];
function ok(message) { results.push(message); console.log('OK: ' + message); }
(async () => {
  for (const path of ['/', '/index.php', '/password.php']) {
    const response = await fetch(origin + path, {redirect: 'manual'});
    assert.equal(response.status, 303); assert.equal(response.headers.get('location'), '/login.php');
  }
  for (const path of ['/config.json', '/app/bootstrap.php', '/index.html', '/admin.php', '/.env']) {
    const response = await fetch(origin + path, {redirect: 'manual'});
    assert.ok([403,404].includes(response.status), path);
  }
  assert.equal((await fetch('http://uptep.top/', {redirect:'manual'})).headers.get('location'), origin + '/');
  assert.equal((await fetch('https://www.uptep.top/', {redirect:'manual'})).headers.get('location'), origin + '/');
  ok('Accès privé, fichiers internes bloqués et redirections HTTPS');
  const b = await puppeteer.launch({executablePath:'/usr/bin/chromium', headless:true, args:['--no-sandbox']});
  try {
    const p = await b.newPage(); const consoleErrors = [];
    p.on('pageerror', error => consoleErrors.push(error.message));
    p.on('console', msg => { if (/Content Security Policy/.test(msg.text())) consoleErrors.push(msg.text()); });
    await p.setViewport({width:1440,height:1000,deviceScaleFactor:1});
    const response = await p.goto(origin + '/login.php', {waitUntil:'networkidle0'});
    assert.equal(response.status(), 200);
    for (const header of ['content-security-policy','strict-transport-security','x-content-type-options','x-frame-options']) assert.ok(response.headers()[header], header);
    assert.equal(response.headers()['cache-control'], 'no-store, private');
    await p.screenshot({path:'/root/uptep-site/previews/connexion-desktop.png'});
    assert.ok(await p.evaluate(() => [...document.images].every(image => image.complete && image.naturalWidth > 0)));
    await p.setViewport({width:390,height:844});
    await p.screenshot({path:'/root/uptep-site/previews/connexion-mobile.png', fullPage:true});
    assert.equal(await p.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
    assert.equal(await p.evaluate(async () => (await fetch('/login.php',{method:'POST',body:new URLSearchParams({username:'qatest',password:'incorrect',csrf:'invalid'})})).status),403);
    ok('Affichage connexion ordinateur/mobile, images, politique CSP et CSRF');
    const pre = (await p.cookies()).find(c => c.name === '__Host-UPTEP').value;
    await p.type('#username', credentials.username); await p.type('#password', 'incorrect');
    await Promise.all([p.waitForNavigation({waitUntil:'networkidle0'}),p.click('.primary-button')]);
    assert.ok(await p.$('[role=alert]'));
    await p.type('#password', credentials.password);
    await Promise.all([p.waitForNavigation({waitUntil:'networkidle0'}),p.click('.primary-button')]);
    assert.ok(p.url().endsWith('/password.php'));
    let cookie = (await p.cookies()).find(c => c.name === '__Host-UPTEP');
    assert.notEqual(cookie.value,pre); assert.equal(cookie.secure,true); assert.equal(cookie.httpOnly,true); assert.equal(cookie.sameSite,'Lax'); assert.equal(cookie.path,'/');
    await p.goto(origin + '/', {waitUntil:'networkidle0'}); assert.ok(p.url().endsWith('/password.php'));
    assert.equal(await p.evaluate(async () => {
      const data = new URLSearchParams({csrf:document.querySelector('[name=csrf]').value,password:'court',confirmation:'court'});
      return (await (await fetch('/password.php',{method:'POST',body:data})).text()).includes('15 à 128');
    }),true);
    await p.type('#password',changedPassword); await p.type('#confirmation',changedPassword);
    await Promise.all([p.waitForNavigation({waitUntil:'networkidle0'}),p.click('.primary-button')]);
    assert.equal(p.url(),origin + '/');
    cookie = (await p.cookies()).find(c => c.name === '__Host-UPTEP');
    assert.equal(await p.$$eval('.top-navigation a', els => els.length),1);
    assert.equal(await p.$eval('.top-navigation a', e=>e.textContent.trim()),'Accueil');
    assert.equal(await p.$eval('main', e => e.textContent.trim()),'Tableau de bord');
    await p.screenshot({path:'/root/uptep-site/previews/accueil-mobile.png',fullPage:true});
    assert.equal(await p.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
    await p.setViewport({width:1440,height:1000});
    await p.screenshot({path:'/root/uptep-site/previews/accueil-desktop.png'});
    ok('Connexion, rotation de session, changement obligatoire et accueil vide avec menu unique');
    assert.equal(await p.evaluate(async () => (await fetch('/logout.php')).status),405);
    assert.equal(await p.evaluate(async () => (await fetch('/logout.php',{method:'POST',body:new URLSearchParams({csrf:'invalid'})})).status),403);
    await p.reload({waitUntil:'networkidle0'}); assert.equal(p.url(), origin + '/');
    await Promise.all([p.waitForNavigation({waitUntil:'networkidle0'}),p.click('.logout-button')]);
    assert.ok(p.url().endsWith('/login.php'));
    const replay = await fetch(origin + '/', {redirect:'manual',headers:{Cookie:'__Host-UPTEP='+cookie.value}});
    assert.equal(replay.status,303);
    ok('Déconnexion protégée, destruction de session et rejet du rejeu');
    const csrf = await p.$eval('[name=csrf]',e=>e.value);
    const statuses = await p.evaluate(async token => {
      const statuses = [];
      for(let i=0;i<11;i++) statuses.push((await fetch('/login.php',{method:'POST',body:new URLSearchParams({csrf:token,username:'rate-limit-test',password:'incorrect'})})).status);
      return statuses;
    },csrf);
    assert.deepEqual(statuses.slice(0,10),Array(10).fill(401)); assert.equal(statuses[10],429);
    assert.deepEqual(consoleErrors,[]);
    ok('Limitation des tentatives et absence d’erreurs JavaScript/CSP');
    fs.writeFileSync('/root/uptep-site/tests/results.json',JSON.stringify({passed:results},null,2));
  } finally { await b.close(); }
})().catch(error => { console.error(error);process.exitCode=1; });
