// Real login / real buttons, isolated perf DB server only. No Livewire setters.
const { chromium } = require('./node_modules/playwright-core');
const fs = require('fs');
const path = require('path');
const BASE = process.env.BASE || 'http://127.0.0.1:8136';
const SHOTS = path.join(__dirname, 'shots', 'kiosk-insights');
fs.mkdirSync(SHOTS, { recursive: true });
(async () => {
  const executablePath = ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].find(fs.existsSync);
  const browser = await chromium.launch({ executablePath, headless: true });
  const errors = [];
  const results = [];
  async function check(ok, label) { results.push({ ok: !!ok, label }); if (!ok) throw new Error(label); }
  async function login(context, email) {
    const page = await context.newPage();
    page.on('pageerror', e => errors.push(e.message));
    await page.goto(BASE + '/login');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill('password');
    await page.locator('button[type=submit]').click();
    await page.waitForURL(/\/owner\/dashboard|\/admin$|\/operator\/dashboard/, { timeout: 60000 });
    return page;
  }
  try {
    const owner = await browser.newContext({ viewport: { width: 390, height: 844 }, acceptDownloads: true });
    const page = await login(owner, 'perf.owner.a@perf.test');
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(BASE + '/owner-panel/kiosks');
    await check(await page.getByText('Unduh Laporan Kedai', { exact: true }).isVisible(), 'Filament header link visible');
    const closeSidebar = page.locator('.fi-sidebar-close-btn');
    if (await closeSidebar.isVisible().catch(() => false)) await closeSidebar.click();
    await page.getByRole('link', { name: 'Unduh Laporan Kedai', exact: true }).click();
    await page.waitForURL('**/kiosk-insights');
    await page.setViewportSize({ width: 390, height: 844 });
    await check(!await page.locator('#report-owner').count(), 'Owner has no owner selector');
    await page.screenshot({ path: path.join(SHOTS, 'owner-390.png'), fullPage: true });
    await page.getByRole('button', { name: 'Export Excel', exact: true }).click();
    const link = page.getByRole('link', { name: 'Unduh Laporan', exact: true });
    await link.waitFor({ timeout: 30000 });
    const ownerUrl = await link.getAttribute('href');
    const downloadEvent = page.waitForEvent('download');
    await link.click();
    const download = await downloadEvent;
    await check(download.suggestedFilename().endsWith('.xlsx'), 'Excel downloads with human filename');
    await page.screenshot({ path: path.join(SHOTS, 'owner-ready-390.png'), fullPage: true });
    await check(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'No horizontal overflow390');
    const other = await browser.newContext();
    const otherPage = await login(other, 'perf.owner.b@perf.test');
    const denied = await otherPage.goto(ownerUrl);
    await check(denied.status() === 404, 'OwnerB cannot fetch ownerA file');
    const op = await browser.newContext();
    const opPage = await login(op, 'perf.op.a1@perf.test');
    const forbidden = await opPage.goto(BASE + '/kiosk-insights');
    await check(forbidden.status() === 403, 'Operator403 report page');
    const admin = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const adminPage = await login(admin, 'perf.admin@perf.test');
    await adminPage.goto(BASE + '/kiosk-insights');
    await check(await adminPage.locator('#report-owner').isVisible(), 'Admin owner selector');
    await check(await adminPage.locator('#report-owner option').count() === 4, 'Admin all-owner plus3 owner choices');
    await adminPage.screenshot({ path: path.join(SHOTS, 'admin-1280.png'), fullPage: true });
    await check(errors.length === 0, 'No browser JS errors');
  } finally {
    fs.writeFileSync(path.join(SHOTS, 'results.json'), JSON.stringify({ results, errors }, null, 2));
    console.log(JSON.stringify({ results, errors }, null, 2));
    await browser.close();
  }
})().catch(e => { console.error(e); process.exitCode = 1; });
