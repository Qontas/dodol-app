// Regression: REAL DOM typing and +/- (never Livewire.set()/page.evaluate setters).
// Prepare an isolated LOCAL operator with an active trip, >=40 mika in motor,
// variant 20 biji / Rp16.000 per pack. Optional BOOKING_KIOSK and PENDING_KIOSK
// names enable the related visit-modal checks. Does not create/reset DB data.
// OPERATOR_EMAIL=... node .claude/skills/verify-browser/cash-input-binding.cjs
// EXPECT_BROKEN=1 captures baseline without saving. Normal mode asserts + saves
// 20 walk-in mika and adds 20 motor stock; use only disposable local fixtures.
const fs = require('fs');
const path = require('path');
const assert = require('node:assert/strict');
const { chromium } = require('playwright-core');
const BASE = process.env.BASE || 'http://127.0.0.1:8123';
if (!['127.0.0.1', 'localhost'].includes(new URL(BASE).hostname)) throw Error('Local browser verification only');
if (!process.env.OPERATOR_EMAIL) throw Error('Set OPERATOR_EMAIL to a disposable local fixture');
const before = process.env.EXPECT_BROKEN === '1';
const shots = path.join(__dirname, 'shots', 'cash-binding');
const browserPath = [process.env.BROWSER_PATH,
  'C:/Program Files/Google/Chrome/Application/chrome.exe',
  'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
  'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
  'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
].filter(Boolean).find(p => fs.existsSync(p));
const failures = [];
function check(ok, message) {
  console.log(`${ok ? 'PASS' : 'FAIL'} ${message}`);
  if (!ok) failures.push(message);
}
(async () => {
  fs.mkdirSync(shots, { recursive: true });
  const browser = await chromium.launch({ executablePath: browserPath, headless: true });
  try {
    const context = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
    // No requests to third-party avatars/map tiles during verification.
    await context.route('**/*', r => r.request().url().startsWith(BASE + '/') ? r.continue() : r.abort());
    const page = await context.newPage();
    const errors = [];
    const pending = new Set();
    page.on('request', r => { if (r.url().includes('/livewire/update')) pending.add(r); });
    page.on('requestfinished', r => pending.delete(r));
    page.on('requestfailed', r => pending.delete(r));
    page.on('pageerror', e => errors.push(e.message));
    page.on('response', r => {
      if (r.url().includes('/livewire/update') && r.status() !== 200) errors.push(`Livewire HTTP ${r.status()}`);
    });
    const input = name => page.locator(`input[wire\\:model\\.live="${name}"], input[wire\\:model\\.live\\.number="${name}"], input[wire\\:model\\.live\\.debounce\\.500ms="${name}"]`);
    const modal = field => field.locator('xpath=ancestor::div[contains(@class,"relative")][1]');
    const settle = async () => {
      await page.waitForTimeout(650); // existing debounce max = 500ms
      for (let i = 0; pending.size && i < 200; i++) await page.waitForTimeout(100);
      assert.equal(pending.size, 0, 'Livewire requests must finish');
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(150); // allow DOM morph
    };
    const shot = name => page.screenshot({ path: path.join(shots, `${before ? 'before' : 'after'}-${name}.png`) });
    async function typeNumber(field, value, pauseWhenEmpty = false) {
      await field.click();
      await field.press('ControlOrMeta+A');
      await field.press('Backspace');
      if (pauseWhenEmpty) await settle();
      await field.pressSequentially(String(value), { delay: 60 });
      await settle();
    }
    async function step(field, direction) {
      await field.locator('..').getByRole('button', { name: direction, exact: true }).click();
      await settle();
    }
    await page.goto(`${BASE}/login`);
    await page.locator('#email').fill(process.env.OPERATOR_EMAIL);
    await page.locator('#password').fill(process.env.LOGIN_PW || 'password');
    await page.locator('button[type=submit]').click();
    await page.waitForURL('**/operator/dashboard');
    await page.goto(`${BASE}/operator/trip/active`);
    await page.getByRole('button', { name: 'Jual Cash Cepat', exact: true }).click();
    const walk = input('walkInMika'); await walk.waitFor(); await settle();
    const saveWalk = page.getByRole('button', { name: 'Simpan Penjualan', exact: true });
    check(await walk.inputValue() === '0' && await saveWalk.isDisabled(), 'walk-in initial 0: save disabled');
    await step(walk, '+');
    check((await modal(walk).innerText()).includes('1 mika × 20 biji × Rp 800') && await saveWalk.isEnabled(), 'walk-in + from zero refreshes total and enables save');
    // Reach precisely the field-report visual: input 20 while baseline total is 0.
    for (let i = 1; i < 20; i++) await step(walk, '+');
    await shot('stepper-20');
    check(await walk.inputValue() === '20' && (await modal(walk).innerText()).includes('Rp 320.000'), 'walk-in twenty + clicks: 20 mika / Rp320.000');
    await step(walk, '-');
    check((await modal(walk).innerText()).includes('19 mika × 20 biji × Rp 800'), 'walk-in minus refreshes summary');
    await typeNumber(walk, 20);
    check(await walk.inputValue() === '20' && (await modal(walk).innerText()).includes('20 mika × 20 biji × Rp 800') && await saveWalk.isEnabled(), 'walk-in keyboard replace with 20');
    await shot('typed-20');
    await typeNumber(walk, 20, true);
    check(await walk.inputValue() === '20' && (await modal(walk).innerText()).includes('Rp 320.000'), 'walk-in clear, wait for empty request, type 20');
    await step(walk, '+');
    check(await walk.inputValue() === '21' && (await modal(walk).innerText()).includes('Rp 336.000'), 'walk-in typed number then plus = 21, not concatenation/stale total');
    await step(walk, '-');
    await shot('typed-20-final');
    if (!before) {
      assert.equal(await walk.inputValue(), '20');
      await saveWalk.click(); await walk.waitFor({ state: 'detached' });
      check(true, 'walk-in save succeeds (modal closed)'); await shot('saved');
    } else await modal(walk).getByRole('button', { name: 'Batal', exact: true }).click();

    await page.getByRole('button', { name: /Tambah Stok di Motor/, exact: false }).first().click();
    const stock = input('tambahStokQty'); await stock.waitFor();
    const saveStock = modal(stock).getByRole('button', { name: 'Tambah Stok', exact: true });
    check(await stock.inputValue() === '0' && await saveStock.isDisabled(), 'stock initial 0 disabled');
    await step(stock, '+');
    check((await modal(stock).innerText()).includes('Tambah 1 mika ke stok motor?') && await saveStock.isEnabled(), 'stock + from zero refreshes confirmation and enables save');
    await typeNumber(stock, 20, true);
    check((await modal(stock).innerText()).includes('Tambah 20 mika ke stok motor?'), 'stock keyboard clear + type20');
    await step(stock, '+');
    check(await stock.inputValue() === '21' && (await modal(stock).innerText()).includes('Tambah 21 mika'), 'stock keyboard then plus = 21 with matching confirmation');
    await step(stock, '-'); await shot('stock-20');
    if (!before) {
      await saveStock.click(); await stock.waitFor({ state: 'detached' });
      check(true, 'stock save succeeds');
    } else await modal(stock).getByRole('button', { name: 'Batal', exact: true }).click();

    async function openKiosk(name) {
      await page.getByText(name, { exact: true }).first().click(); await settle();
    }
    async function checkDrop(action) {
      await page.getByRole('button', { name: action }).click(); await settle();
      const drop = input('dropBaru'); await drop.waitFor();
      const prefill = await drop.inputValue();
      check(Number.isFinite(Number(prefill)), `${action}: numeric prefill (${prefill})`);
      await typeNumber(drop, 20, true);
      if (String(action).includes('Titip Cash')) {
        check((await modal(drop).innerText()).includes('Rp 320.000'), 'Titip Cash typed20 updates cash total');
      }
      check(await drop.inputValue() === '20', `${action}: keyboard 20`);
      await step(drop, '+'); check(await drop.inputValue() === '21', `${action}: plus21`);
      await step(drop, '-'); check(await drop.inputValue() === '20', `${action}: minus20`);
      await drop.click(); await drop.press('ControlOrMeta+A'); await drop.press('Backspace'); await settle();
      await step(drop, '+'); check(await drop.inputValue() === '1', `${action}: clear then plus1 (no PHP TypeError)`);
      await page.locator('button[wire\\:click="closeVisitModal"]').click();
      await drop.waitFor({ state: 'detached' }); await settle();
    }
    if (process.env.BOOKING_KIOSK) {
      await openKiosk(process.env.BOOKING_KIOSK); await checkDrop(/Titip Cash/);
      await openKiosk(process.env.BOOKING_KIOSK); await checkDrop(/Mulai Titipan/);
    }
    if (process.env.PENDING_KIOSK) {
      await openKiosk(process.env.PENDING_KIOSK); await checkDrop(/Tagih.*Titip Ulang/);
    }
    if (!before && process.env.CREATE_KIOSK === '1') {
      await page.getByRole('link', { name: 'Kios Baru', exact: true }).click();
      await page.locator('#namaKios').fill('Cash Binding Created');
      await page.locator('#namaPemilik').fill('Browser Fixture');
      await page.locator('#clusterId').selectOption({ label: 'Cash Binding Area' });
      const qty = page.locator('#defaultQtyMika');
      await qty.waitFor();
      const prefill = await qty.inputValue();
      check(Number(prefill) >= 1, `Kios Baru numeric prefill (${prefill})`);
      await typeNumber(qty, 20, true);
      await page.getByRole('button', { name: 'Simpan Kios', exact: true }).click();
      await page.waitForURL('**/operator/trip/**');
      check(true, 'Kios Baru: clear and type20, save succeeds');
      await shot('kiosk-created');
    }
    check(errors.length === 0, `no browser/HTTP errors: ${errors.join('; ')}`);
    console.log(JSON.stringify({ phase: before ? 'before' : 'after', failures, screenshots: shots }));
    if (!before && failures.length) throw Error(`${failures.length} browser regression checks failed`);
    if (before && failures.length === 0) throw Error('Baseline did not reproduce the defect');
  } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exitCode = 1; });
