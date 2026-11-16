// Read-only browser regression for the shared report's native selects.
// Use the existing isolated hotfix fixture; never seed or export business data.
// BASE=http://127.0.0.1:8143 node .claude/skills/verify-browser/kiosk-insight-select-contrast.cjs
const { chromium } = require('./node_modules/playwright-core');
const fs = require('fs');
const path = require('path');
const assert = require('node:assert/strict');
const BASE = process.env.BASE || 'http://127.0.0.1:8143';
const SHOTS = path.join(__dirname, 'shots', 'kiosk-insight-select-contrast');
fs.mkdirSync(SHOTS, { recursive: true });

(async () => {
    const browser = await chromium.launch({
        executablePath: process.env.BROWSER_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
        headless: true,
    });
    const results = [];
    const errors = [];
    try {
        const context = await browser.newContext({ viewport: { width: 1600, height: 900 } });
        const page = await context.newPage();
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(BASE + '/login');
        await page.locator('#email').fill(process.env.REPORT_ADMIN_EMAIL || 'hotfix.admin@example.test');
        await page.locator('#password').fill(process.env.REPORT_ADMIN_PASSWORD || 'password');
        await page.locator('button[type=submit]').click();
        await page.waitForURL(/\/admin$/, { timeout: 30000 });
        await page.goto(BASE + '/admin/laporan-kedai');
        await page.locator('#report-owner').waitFor();
        await page.evaluate(() => window.Alpine.store('sidebar').close());
        assert.ok(await page.locator('#report-owner option').count() > 1, 'fixture must include a real owner option');

        // Opposite OS preferences prove the explicit panel theme wins, not OS inheritance.
        for (const theme of ['light', 'dark']) {
            await page.emulateMedia({ colorScheme: theme === 'light' ? 'dark' : 'light' });
            await page.evaluate(theme => {
                localStorage.setItem('theme', theme);
                window.dispatchEvent(new CustomEvent('theme-changed', { detail: theme }));
                document.documentElement.classList.toggle('dark', theme === 'dark');
            }, theme);
            await page.waitForFunction(dark => document.documentElement.classList.contains('dark') === dark, theme === 'dark');
            const controls = page.locator('select.kiosk-insight-select');
            assert.equal(await controls.count(), await page.locator('[wire\\:id] select').count(), 'all shared component selects have contrast styling');
            const styles = await controls.evaluateAll(selects => {
                const luminance = color => {
                    const channels = color.match(/[\d.]+/g).slice(0, 3).map(Number).map(x => {
                        x /= 255;
                        return x <= 0.04045 ? x / 12.92 : ((x + 0.055) / 1.055) ** 2.4;
                    });
                    return channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
                };
                return selects.flatMap(select => [select, ...select.querySelectorAll('option, optgroup')].map(element => {
                    const style = getComputedStyle(element);
                    const a = luminance(style.color), b = luminance(style.backgroundColor);
                    return {
                        tag: element.tagName,
                        color: style.color,
                        background: style.backgroundColor,
                        scheme: style.colorScheme,
                        contrast: (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05),
                    };
                }));
            });
            for (const style of styles) {
                assert.equal(style.scheme, theme);
                assert.equal(style.color, theme === 'dark' ? 'rgb(241, 245, 249)' : 'rgb(15, 23, 42)');
                assert.equal(style.background, theme === 'dark' ? 'rgb(30, 41, 59)' : 'rgb(255, 255, 255)');
                assert.ok(style.contrast >= 4.5, `${theme} ${style.tag} contrast ${style.contrast}`);
            }
            const select = page.locator('#report-owner');
            await select.selectOption({ index: 0 });
            await select.focus();
            await page.keyboard.press('ArrowDown');
            await page.keyboard.press('Enter');
            assert.notEqual(await select.inputValue(), '', 'native keyboard owner selection works');
            await page.screenshot({ path: path.join(SHOTS, `${theme}-selected.png`), fullPage: true });
            await page.keyboard.press('Alt+ArrowDown');
            await page.screenshot({ path: path.join(SHOTS, `${theme}-native-open.png`), fullPage: true });
            await page.keyboard.press('Escape');
            // OS popups may not be included in headless screenshots. A native listbox
            // of the same options provides inspectable pixels without changing CSS.
            await select.evaluate(element => { element.size = 4; });
            await page.screenshot({ path: path.join(SHOTS, `${theme}-options-listbox.png`), fullPage: true });
            await select.evaluate(element => element.removeAttribute('size'));
            results.push({ theme, checkedElements: styles.length, minimumContrast: Math.min(...styles.map(s => s.contrast)), keyboardSelection: 'PASS' });
        }
        assert.deepEqual(errors, [], 'no browser runtime errors');
        console.log(JSON.stringify({ results, errors, screenshots: SHOTS }, null, 2));
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
