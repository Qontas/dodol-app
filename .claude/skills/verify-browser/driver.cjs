// Browser smoke test for dodol-app multi-tenant login + access control.
// Uses playwright-core driving the SYSTEM Chrome/Edge (no browser download).
// Exits non-zero if any role's expectation fails.
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright-core');

const BASE = process.env.BASE || 'http://127.0.0.1:8123';
const PW = process.env.LOGIN_PW || 'password';
const SHOTS = path.join(__dirname, 'shots');

// Expected behaviour per seeded role. Edit here if accounts/rules change.
const USERS = [
  { label: 'SUPER ADMIN', email: 'admin@cemilanqontas.id',    lands: '/admin',              admin: 200, operator: 403 },
  { label: 'OWNER',       email: 'owner@cemilanqontas.id',    lands: '/owner/dashboard',    admin: 200, operator: 403 },
  { label: 'OPERATOR',    email: 'operator@cemilanqontas.id', lands: '/operator/dashboard', admin: 403, operator: 200 },
];

const BROWSER_CANDIDATES = [
  process.env.BROWSER_PATH,
  'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
  'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
  'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
  'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
].filter(Boolean);

function findBrowser() {
  for (const p of BROWSER_CANDIDATES) if (fs.existsSync(p)) return p;
  throw new Error('No system Chrome/Edge found. Set BROWSER_PATH=/c/path/to/chrome.exe');
}

async function status(page, url) {
  // goto follows redirects; resolves with the final response (incl. 403 abort pages).
  const resp = await page.goto(url, { waitUntil: 'networkidle' }).catch(() => null);
  return resp ? resp.status() : 'ERR';
}

async function probe(browser, u) {
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  const slug = u.label.replace(/\s+/g, '_');

  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
  await page.fill('#email', u.email);
  await page.fill('#password', PW);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }).catch(() => {}),
    page.click('button[type=submit]'),
  ]);
  await page.waitForTimeout(1200);

  // Deterministic: hit /dashboard and let the role redirect resolve.
  await page.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle' }).catch(() => {});
  await page.waitForTimeout(600);
  const landed = page.url().replace(BASE, '');
  await page.screenshot({ path: path.join(SHOTS, `${slug}-landing.png`) });

  const adminStatus = await status(page, `${BASE}/admin`);
  await page.screenshot({ path: path.join(SHOTS, `${slug}-admin.png`) });
  const operatorStatus = await status(page, `${BASE}/operator/dashboard`);

  await ctx.close();

  const pass =
    landed.startsWith(u.lands) &&
    adminStatus === u.admin &&
    operatorStatus === u.operator;

  return { label: u.label, landed, expectedLanding: u.lands, adminStatus, expectedAdmin: u.admin,
           operatorStatus, expectedOperator: u.operator, pass };
}

(async () => {
  fs.mkdirSync(SHOTS, { recursive: true });
  const browser = await chromium.launch({ executablePath: findBrowser(), headless: true });
  const results = [];
  for (const u of USERS) {
    try { results.push(await probe(browser, u)); }
    catch (e) { results.push({ label: u.label, error: String(e).slice(0, 300), pass: false }); }
  }
  await browser.close();

  console.log('\n=== dodol-app browser verification ===');
  for (const r of results) {
    if (r.error) { console.log(`✗ ${r.label}: ERROR ${r.error}`); continue; }
    const mark = r.pass ? '✓' : '✗';
    console.log(`${mark} ${r.label}`);
    console.log(`    landed   : ${r.landed}  (expect ${r.expectedLanding})`);
    console.log(`    /admin   : ${r.adminStatus}  (expect ${r.expectedAdmin})`);
    console.log(`    /operator: ${r.operatorStatus}  (expect ${r.expectedOperator})`);
  }
  const failed = results.filter(r => !r.pass);
  console.log(`\n${results.length - failed.length}/${results.length} roles OK. Screenshots in ${SHOTS}`);
  process.exit(failed.length ? 1 : 0);
})();
