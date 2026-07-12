// Reproduce the "failed login -> blank white page" bug.
// Loads /login, submits WRONG credentials, and reports whether the visible
// error message appears or the page went blank after the Livewire morph.
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright-core');

const BASE = process.env.BASE || 'http://127.0.0.1:8123';
const SHOTS = path.join(__dirname, 'shots');

const BROWSER_CANDIDATES = [
  process.env.BROWSER_PATH,
  'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
  'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
  'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
  'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
].filter(Boolean);
function findBrowser() {
  for (const p of BROWSER_CANDIDATES) if (fs.existsSync(p)) return p;
  throw new Error('No system Chrome/Edge found.');
}

async function run(label, email, pw) {
  const browser = await chromium.launch({ executablePath: findBrowser(), headless: true });
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  const errs = [];
  page.on('pageerror', (e) => errs.push('PAGEERROR ' + e.message));
  page.on('console', (m) => { if (m.type() === 'error') errs.push('CONSOLE ' + m.text()); });

  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
  await page.fill('#email', email);
  await page.fill('#password', pw);
  await page.click('button[type=submit]');
  await page.waitForTimeout(1500);

  const bodyText = (await page.evaluate(() => document.body ? document.body.innerText.trim() : '(no body)')) || '';
  const bodyHtmlLen = (await page.evaluate(() => document.body ? document.body.innerHTML.length : 0));
  const hasForm = await page.$('#email') !== null;
  const slug = label.replace(/\s+/g, '_');
  await page.screenshot({ path: path.join(SHOTS, `login-${slug}.png`) });

  console.log(`\n=== ${label} (${email} / ${pw}) ===`);
  console.log('URL after submit :', page.url().replace(BASE, ''));
  console.log('body innerHTML len:', bodyHtmlLen);
  console.log('login form present:', hasForm);
  console.log('visible body text :', JSON.stringify(bodyText.slice(0, 200)));
  console.log('js errors         :', errs.length ? errs.slice(0, 4) : 'none');
  const blank = bodyText.length === 0 || (!hasForm && bodyHtmlLen < 50);
  console.log('VERDICT           :', blank ? 'BLANK PAGE ❌' : (hasForm ? 'form+message OK ✅' : 'redirected/other'));

  await browser.close();
  return blank;
}

(async () => {
  const a = await run('wrong password', 'operator@cemilanqontas.id', 'salahsalah');
  const b = await run('nonexistent email', 'lama-sebelum-diganti@cemilanqontas.id', 'password'); // simulates OLD email after change
  process.exit(a || b ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
